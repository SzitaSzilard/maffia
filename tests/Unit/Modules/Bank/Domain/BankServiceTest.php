<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\Bank\Domain;

use Netmafia\Modules\Bank\Domain\BankService;
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\Connection;

class BankServiceTest extends TestCase
{
    private $db;
    private $moneyService;
    private $notificationService;
    private $bankService;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->moneyService = $this->createMock(MoneyService::class);
        $this->notificationService = $this->createMock(\Netmafia\Modules\Notifications\Domain\NotificationService::class);
        $this->bankService = new BankService($this->db, $this->moneyService, $this->notificationService);
    }

    public function testDepositMaintainsFee()
    {
        $userId = UserId::of(1);
        $amount = 1000;
        $accountId = 10;
        
        // Has account validation
        $this->db->method('fetchOne')->willReturnOnConsecutiveCalls(
            $accountId, // getAccountId check 1
            5000 // updateBalance fetch current balance
        );

        // Expect MoneyService spend (Full amount)
        $this->moneyService->expects($this->once())
            ->method('spendMoney')
            ->with($userId, $amount); // 1000

        // Expect DB Update (Amount - 5%) -> 1000 - 50 = 950
        $this->db->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('UPDATE bank_accounts SET balance ='),
                [5950, $accountId] // 5000 + 950
            );

        $this->bankService->deposit($userId, $amount);
    }

    public function testWithdrawChecksBalance()
    {
        $userId = UserId::of(1);
        $amount = 1000;
        $accountId = 10;
        
        $this->db->method('fetchOne')->willReturnOnConsecutiveCalls(
            $accountId, 
            500 // Current balance only 500
        );

        $this->moneyService->expects($this->never())->method('addMoney');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Nincs fedezet");

        $this->bankService->withdraw($userId, $amount);
    }
}
