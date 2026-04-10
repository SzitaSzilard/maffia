<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\Garage;

use PHPUnit\Framework\TestCase;
use Netmafia\Modules\Garage\Domain\VehicleRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;

class VehicleRepositoryTest extends TestCase
{
    private $db;
    private $repository;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->repository = new VehicleRepository($this->db);
    }

    // --- getUserVehicles ---

    public function testGetUserVehiclesReturnsArray(): void
    {
        $qb = $this->createMock(QueryBuilder::class);
        
        $this->db->method('createQueryBuilder')->willReturn($qb);
        
        // Mock fluent interface
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('join')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();

        // Mock result
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([['id' => 1]]);
        $qb->method('executeQuery')->willReturn($result);

        $vehicles = $this->repository->getUserVehicles(1);
        $this->assertCount(1, $vehicles);
    }

    // --- getGarageCapacity Edge Cases ---

    public function testCapacityNoPropertyNoSlots(): void
    {
        // 1. Property Query -> 0
        // 2. Slots Query -> 0
        $this->db->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(0, 0);

        $capacity = $this->repository->getGarageCapacity(1, 'US');
        $this->assertEquals(0, $capacity);
    }

    public function testCapacityOnlyProperty(): void
    {
        $this->db->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(10, 0);

        $capacity = $this->repository->getGarageCapacity(1, 'US');
        $this->assertEquals(10, $capacity);
    }

    public function testCapacityOnlySlots(): void
    {
        $this->db->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(0, 20);

        $capacity = $this->repository->getGarageCapacity(1, 'US');
        $this->assertEquals(20, $capacity);
    }

    public function testCapacityPropertyAndSlots(): void
    {
        $this->db->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(10, 20);

        $capacity = $this->repository->getGarageCapacity(1, 'US');
        $this->assertEquals(30, $capacity);
    }

    // --- addGarageSlots ---

    public function testAddGarageSlotsInsert(): void
    {
        // Logic: The repository uses ON DUPLICATE KEY UPDATE, so we just verify one executeStatement call with correct SQL structure
        $this->db->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('INSERT INTO user_garage_slots'),
                $this->callback(function($params) {
                    return $params['userId'] === 1 
                        && $params['country'] === 'US' 
                        && $params['slots'] === 5;
                })
            );

        $this->repository->addGarageSlots(1, 'US', 5);
    }

    // --- getVehicleDetails ---

    public function testGetVehicleDetailsFound(): void
    {
        $qb = $this->createMock(QueryBuilder::class);
        $this->db->method('createQueryBuilder')->willReturn($qb);
        
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('join')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(['id' => 1, 'name' => 'Car']);
        $qb->method('executeQuery')->willReturn($result);

        $details = $this->repository->getVehicleDetails(1);
        $this->assertIsArray($details);
        $this->assertEquals('Car', $details['name']);
    }

    public function testGetVehicleDetailsNotFound(): void
    {
        $qb = $this->createMock(QueryBuilder::class);
        $this->db->method('createQueryBuilder')->willReturn($qb);
        
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('join')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(false); // Not found
        $qb->method('executeQuery')->willReturn($result);

        $details = $this->repository->getVehicleDetails(999);
        $this->assertNull($details);
    }
}
