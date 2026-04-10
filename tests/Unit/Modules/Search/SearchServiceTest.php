<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\Search;

use Netmafia\Modules\Search\Domain\SearchService;
use Netmafia\Shared\Exceptions\InvalidInputException;
use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\Connection;

class SearchServiceTest extends TestCase
{
    private $db;
    private SearchService $service;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->service = new SearchService($this->db);
    }

    public function testSearchTooShortQueryThrows(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('Legalább 3 karaktert');
        $this->service->searchUsers('ab');
    }

    public function testSearchSingleCharThrows(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->service->searchUsers('x');
    }

    public function testSearchNullUsernameReturnsResults(): void
    {
        // null username = no filter, returns all (up to 100)
        $qb = $this->createMock(\Doctrine\DBAL\Query\QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('fetchAllAssociative')->willReturn([]);

        $this->db->method('createQueryBuilder')->willReturn($qb);

        $result = $this->service->searchUsers(null);
        $this->assertIsArray($result);
    }
}
