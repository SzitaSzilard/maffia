<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications;

use Netmafia\Modules\Notifications\Domain\NotificationService;
use Netmafia\Infrastructure\CacheService;
use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\Connection;

class NotificationServiceTest extends TestCase
{
    private $db;
    private $cache;
    private NotificationService $service;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->cache = $this->createMock(CacheService::class);
        $this->service = new NotificationService($this->db, $this->cache);
    }

    // --- Send tesztek ---

    public function testSendInsertsAndInvalidatesCache(): void
    {
        $this->db->expects($this->once())
            ->method('insert')
            ->with('notifications', $this->callback(function ($data) {
                return $data['user_id'] === 1
                    && $data['type'] === 'bank_transfer'
                    && $data['message'] === 'Teszt utalt $5000';
            }));

        $this->cache->expects($this->once())
            ->method('forget')
            ->with('unread_notifications:1');

        $result = $this->service->send(1, 'bank_transfer', 'Teszt utalt $5000', 'Bank', '/bank');
        $this->assertTrue($result);
    }

    public function testSendReturnsFalseOnDbError(): void
    {
        $this->db->method('insert')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->service->send(1, 'test', 'msg');
        $this->assertFalse($result);
    }

    // --- Unread count tesztek ---

    public function testGetUnreadCountReturnsCachedValue(): void
    {
        $this->cache->expects($this->once())
            ->method('get')
            ->with('unread_notifications:1')
            ->willReturn(7);

        $this->db->expects($this->never())->method('fetchOne');

        $count = $this->service->getUnreadCount(1);
        $this->assertSame(7, $count);
    }

    public function testGetUnreadCountQueriesDbAndCaches(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->db->method('fetchOne')->willReturn(3);

        $this->cache->expects($this->once())
            ->method('set')
            ->with('unread_notifications:1', 3, 300);

        $count = $this->service->getUnreadCount(1);
        $this->assertSame(3, $count);
    }

    // --- markAsRead ---

    public function testMarkAsReadUpdatesDbAndInvalidatesCache(): void
    {
        $this->db->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('UPDATE notifications SET is_read = TRUE'),
                [5, 1]
            );

        $this->cache->expects($this->once())
            ->method('forget')
            ->with('unread_notifications:1');

        $this->service->markAsRead(5, 1);
    }

    // --- markAllAsRead ---

    public function testMarkAllAsReadUpdatesAllAndInvalidatesCache(): void
    {
        $this->db->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('UPDATE notifications SET is_read = TRUE'),
                [1]
            );

        $this->cache->expects($this->once())
            ->method('forget')
            ->with('unread_notifications:1');

        $this->service->markAllAsRead(1);
    }

    // --- Delete tesztek ---

    public function testDeleteEmptyArrayReturnsZero(): void
    {
        $this->db->expects($this->never())->method('executeStatement');
        $result = $this->service->delete(1, []);
        $this->assertSame(0, $result);
    }

    public function testDeleteValidIdsCallsDb(): void
    {
        $this->db->expects($this->once())
            ->method('executeStatement')
            ->willReturn(3);

        $result = $this->service->delete(1, [10, 20, 30]);
        $this->assertSame(3, $result);
    }

    // --- Cleanup tesztek ---

    public function testCleanupOldSkipsWhenUnder50(): void
    {
        $this->db->method('fetchOne')->willReturn(30);

        // Should NOT try to delete
        $this->db->expects($this->never())
            ->method('executeStatement');

        $result = $this->service->cleanupOld(1);
        $this->assertSame(0, $result);
    }

    public function testCleanupOldDeletesWhenOver50(): void
    {
        $this->db->method('fetchOne')->willReturn(60);
        $this->db->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('DELETE FROM notifications'),
                [1]
            )
            ->willReturn(10);

        $result = $this->service->cleanupOld(1);
        $this->assertSame(10, $result);
    }
}
