<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\Garage;

use PHPUnit\Framework\TestCase;
use Netmafia\Modules\Garage\Domain\GarageService;
use Netmafia\Modules\Garage\Domain\VehicleRepository;
use Netmafia\Infrastructure\AuditLogger;
use Doctrine\DBAL\Connection;
use Exception;

class GarageServiceTest extends TestCase
{
    private $db;
    private $repository;
    private $logger;
    private $service;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->repository = $this->createMock(VehicleRepository::class);
        $this->logger = $this->createMock(AuditLogger::class);
        $this->service = new GarageService($this->db, $this->repository, $this->logger);
    }

    // --- Happy Path ---

    public function testBuyGarageSlotsSuccess(): void
    {
        $userId = 1;
        $country = 'US';
        $slots = 5;
        $cost = 700;
        $userMoney = 1000;

        $this->db->expects($this->once())
            ->method('fetchOne')
            ->willReturn($userMoney);

        $this->db->expects($this->once())
            ->method('executeStatement')
            ->with("UPDATE users SET money = money - :cost WHERE id = :id", [
                'cost' => $cost,
                'id' => $userId
            ]);

        $this->repository->expects($this->once())
            ->method('addGarageSlots')
            ->with($userId, $country, $slots);

        // Verify Logging
        $this->logger->expects($this->once())
            ->method('log')
            ->with('garage_buy_success', $userId, [
                'slots' => $slots,
                'cost' => $cost,
                'country' => $country
            ]);

        $this->db->expects($this->once())->method('commit');

        $this->service->buyGarageSlots($userId, $country, $slots, $cost);
    }

    // --- Input Validation Tests ---

    public function testBuyGarageSlotsInvalidUserId(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Invalid userId: 0");
        $this->service->buyGarageSlots(0, 'US', 5, 700);
    }

    public function testBuyGarageSlotsInvalidCountry(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Invalid countryCode");
        $this->service->buyGarageSlots(1, '', 5, 700);
    }

    public function testBuyGarageSlotsInvalidSlots(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Invalid slots: 0");
        $this->service->buyGarageSlots(1, 'US', 0, 700);
    }

    public function testBuyGarageSlotsNegativeCost(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Invalid cost: -100");
        $this->service->buyGarageSlots(1, 'US', 5, -100);
    }

    // --- Business Logic & Logging Tests ---

    public function testBuyGarageSlotsInsufficientFunds(): void
    {
        $userId = 1;
        $cost = 700;
        $userMoney = 500;

        $this->db->method('fetchOne')->willReturn($userMoney);
        
        // Logging Check
        $this->logger->expects($this->once())
            ->method('log')
            ->with('garage_buy_failed', $userId, [
                'reason' => 'insufficient_funds',
                'needed' => $cost,
                'available' => $userMoney
            ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Nincs elég pénzed!");
        
        $this->service->buyGarageSlots($userId, 'US', 5, $cost);
    }

    public function testUserNotFound(): void
    {
        $this->db->method('fetchOne')->willReturn(false);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("User not found for money check");

        // Should log error in catch block
        $this->logger->expects($this->once())
            ->method('log')
            ->with('garage_buy_error', 1, ['error' => 'User not found for money check']);

        $this->service->buyGarageSlots(1, 'US', 5, 700);
    }

    public function testRepositoryExceptionLogsError(): void
    {
        $this->db->method('fetchOne')->willReturn(1000);
        $this->repository->method('addGarageSlots')->willThrowException(new Exception("DB connection failed"));

        $this->logger->expects($this->once())
            ->method('log')
            ->with('garage_buy_error', 1, ['error' => 'DB connection failed']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("DB connection failed");

        $this->service->buyGarageSlots(1, 'US', 5, 700);
    }
}
