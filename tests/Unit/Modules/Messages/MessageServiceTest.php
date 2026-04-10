<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\Messages;

use Netmafia\Modules\Messages\Domain\MessageService;
use Netmafia\Infrastructure\CacheService;
use Netmafia\Shared\Exceptions\InvalidInputException;
use Netmafia\Shared\Exceptions\GameException;
use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\Connection;

class MessageServiceTest extends TestCase
{
    private $db;
    private $cache;
    private MessageService $service;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->cache = $this->createMock(CacheService::class);
        $this->service = new MessageService($this->db, $this->cache);
    }

    // --- Validáció tesztek ---

    public function testSendToSelfThrowsException(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('Magadnak nem küldhetsz üzenetet');
        $this->service->sendMessage(1, 1, 'Subject', 'Body text');
    }

    public function testSendEmptySubjectThrowsException(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('tárgy mező kötelező');
        $this->service->sendMessage(1, 2, '', 'Body text');
    }

    public function testSendEmptyBodyThrowsException(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('üzenet szövege kötelező');
        $this->service->sendMessage(1, 2, 'Subject', '');
    }

    public function testSendTooLongSubjectThrowsException(): void
    {
        $longSubject = str_repeat('a', \Netmafia\Modules\Messages\MessageConfig::SUBJECT_MAX_LENGTH + 1);
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('tárgy maximum');
        $this->service->sendMessage(1, 2, $longSubject, 'Body');
    }

    public function testSendTooLongBodyThrowsException(): void
    {
        $longBody = str_repeat('a', \Netmafia\Modules\Messages\MessageConfig::BODY_MAX_LENGTH + 1);
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('üzenet maximum');
        $this->service->sendMessage(1, 2, 'Subject', $longBody);
    }

    // --- getQueryConfig tesztek ---

    public function testGetMessagesInboxUsesCorrectConfig(): void
    {
        // Mock: no spam, return empty result
        $this->db->method('fetchAllAssociative')->willReturn([]);
        $this->db->method('fetchOne')->willReturn(0);

        $result = $this->service->getMessages(1, 'inbox');
        $this->assertIsArray($result);
    }

    public function testGetMessagesOutboxUsesCorrectConfig(): void
    {
        $this->db->method('fetchAllAssociative')->willReturn([]);
        $this->db->method('fetchOne')->willReturn(0);

        $result = $this->service->getMessages(1, 'outbox');
        $this->assertIsArray($result);
    }

    // --- Unread count tesztek ---

    public function testGetUnreadCountReturnsDbValue(): void
    {
        $this->db->method('fetchOne')->willReturn(5);
        $count = $this->service->getUnreadCount(1);
        $this->assertSame(5, $count);
    }

    public function testGetUnreadCountReturnsZeroWhenNone(): void
    {
        $this->db->method('fetchOne')->willReturn(0);
        $count = $this->service->getUnreadCount(1);
        $this->assertSame(0, $count);
    }

    // --- Delete tesztek ---

    public function testDeleteEmptyArrayReturns0(): void
    {
        $this->db->expects($this->never())->method('executeStatement');
        $result = $this->service->deleteMessages(1, [], 'inbox');
        $this->assertSame(0, $result);
    }

    public function testDeleteValidIdsCallsDb(): void
    {
        $this->db->expects($this->once())
            ->method('executeStatement')
            ->willReturn(2);

        $result = $this->service->deleteMessages(1, [10, 20], 'inbox');
        $this->assertSame(2, $result);
    }

    // --- findUserByUsername tesztek ---

    public function testFindUserByUsernameReturnsUser(): void
    {
        $this->db->method('fetchAssociative')->willReturn(['id' => 5, 'username' => 'Teszt']);
        $user = $this->service->findUserByUsername('Teszt');
        $this->assertSame(5, $user['id']);
    }

    public function testFindUserByUsernameReturnsNull(): void
    {
        $this->db->method('fetchAssociative')->willReturn(false);
        $user = $this->service->findUserByUsername('NemLetezo');
        $this->assertNull($user);
    }
}
