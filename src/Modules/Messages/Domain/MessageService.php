<?php
declare(strict_types=1);

namespace Netmafia\Modules\Messages\Domain;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

use Doctrine\DBAL\Connection;
use Netmafia\Infrastructure\CacheService;

/**
 * MessageService - Privát üzenetküldő rendszer
 */
class MessageService
{
    private Connection $db;
    private CacheService $cache;

    public function __construct(Connection $db, CacheService $cache)
    {
        $this->db = $db;
        $this->cache = $cache;
    }

    /**
     * Üzenetek lekérdezése (Bejövő vagy Kimenő)
     */
    public function getMessages(int $userId, string $type, int $limit = \Netmafia\Modules\Messages\MessageConfig::DEFAULT_PAGE_SIZE, int $offset = 0): array
    {
        $limit = (int) $limit;
        $limit = max(1, min($limit, \Netmafia\Modules\Messages\MessageConfig::INBOX_LIMIT));
        $offset = max(0, (int) $offset);

        $config = $this->getQueryConfig($type);
        
        // Dynamic SQL construction based on type
        // Alias 'u' is joined user (Sender for Inbox, Recipient for Outbox)
        $sql = "SELECT m.id, m.sender_id, m.recipient_id, m.subject, m.body, m.is_read, m.created_at, u.username as {$config['name_alias']} 
                FROM messages m 
                JOIN users u ON m.{$config['partner_col']} = u.id 
                WHERE m.{$config['user_col']} = ? AND m.{$config['deleted_col']} = FALSE 
                ORDER BY m.created_at DESC 
                LIMIT {$limit} OFFSET {$offset}";

        return $this->db->fetchAllAssociative($sql, [$userId]);
    }

    public function getMessageCount(int $userId, string $type): int
    {
        $config = $this->getQueryConfig($type);

        $sql = "SELECT COUNT(*) FROM messages 
                WHERE {$config['user_col']} = ? AND {$config['deleted_col']} = FALSE";

        $__fetchResult = $this->db->fetchOne($sql, [$userId]);
        if ($__fetchResult === false) {
            throw new \Netmafia\Shared\Exceptions\GameException("A kért adat nem található az adatbázisban!");
        }
        return (int) $__fetchResult;
    }

    private function getQueryConfig(string $type): array
    {
        if ($type === 'outbox') {
            return [
                'user_col' => 'sender_id',        // Whose messages? (Mine as sender)
                'partner_col' => 'recipient_id',  // Who is the other party?
                'deleted_col' => 'sender_deleted', // Which delete flag?
                'name_alias' => 'recipient_name'   // What to call the partner's name?
            ];
        }
        
        // Default to inbox
        return [
            'user_col' => 'recipient_id',
            'partner_col' => 'sender_id',
            'deleted_col' => 'recipient_deleted',
            'name_alias' => 'sender_name'
        ];
    }

    /**
     * Üzenet küldése
     * 
     * [2025-12-29 14:17:29] Javítások:
     * - Spam check hívás hozzáadása (15s cooldown)
     * - Input validáció (subject/body hossz)
     * - Saját magadnak küldés tiltása
     */
    public function sendMessage(int $senderId, int $recipientId, string $subject, string $body): bool
    {
        // [2025-12-29 14:17:29] Validációk
        if ($senderId === $recipientId) {
            throw new InvalidInputException("Magadnak nem küldhetsz üzenetet!");
        }
        
        if (strlen($subject) > \Netmafia\Modules\Messages\MessageConfig::SUBJECT_MAX_LENGTH) {
            throw new InvalidInputException("A tárgy maximum " . \Netmafia\Modules\Messages\MessageConfig::SUBJECT_MAX_LENGTH . " karakter lehet!");
        }
        
        if (strlen($body) > \Netmafia\Modules\Messages\MessageConfig::BODY_MAX_LENGTH) {
            throw new InvalidInputException("Az üzenet maximum " . \Netmafia\Modules\Messages\MessageConfig::BODY_MAX_LENGTH . " karakter lehet!");
        }
        
        if (empty(trim($subject))) {
            throw new InvalidInputException("A tárgy mező kötelező!");
        }
        
        if (empty(trim($body))) {
            throw new InvalidInputException("Az üzenet szövege kötelező!");
        }
        
        // [2025-12-29 14:17:29] Spam ellenőrzés (15s cooldown)
        $this->validateSpam($senderId);
        
        try {
            $this->db->insert('messages', [
                'sender_id' => $senderId,
                'recipient_id' => $recipientId,
                'subject' => $subject,
                'body' => $body,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            
            // Cache invalidation - címzett unread count-ja változott
            $this->cache->forget("unread_messages:{$recipientId}");
            
            return true;
        } catch (\Throwable $e) {
            // [FIX] Exception NEM nyelődik el — logoljuk és továbbdobjuk
            error_log("[MessageService] sendMessage failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Üzenetek törlése (soft delete)
     * @param string $type 'inbox' vagy 'outbox'
     */
    public function deleteMessages(int $userId, array $messageIds, string $type): int
    {
        if (empty($messageIds)) {
            return 0;
        }

        // Limitáljuk a törlendő elemek számát (max 50 egyszerre)
        if (count($messageIds) > \Netmafia\Modules\Messages\MessageConfig::DEFAULT_PAGE_SIZE) {
            $messageIds = array_slice($messageIds, 0, \Netmafia\Modules\Messages\MessageConfig::DEFAULT_PAGE_SIZE);
        }

        // Int-té konvertálás biztonság kedvéért (bár a caller már elvileg megtette)
        $messageIds = array_map('intval', $messageIds);

        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        
        if ($type === 'inbox') {
            // Bejövő - recipient_deleted = TRUE
            $sql = "UPDATE messages SET recipient_deleted = TRUE 
                    WHERE id IN ($placeholders) AND recipient_id = ?";
        } else {
            // Kimenő - sender_deleted = TRUE
            $sql = "UPDATE messages SET sender_deleted = TRUE 
                    WHERE id IN ($placeholders) AND sender_id = ?";
        }
        
        $params = array_merge($messageIds, [$userId]);
        return $this->db->executeStatement($sql, $params);
    }

    /**
     * Üzenet olvasottnak jelölése
     * 
     * [2025-12-29 14:17:29] Javítás: Cache invalidation hozzáadása
     * Korábban az unread count cache nem frissült amikor egy üzenetet
     * olvasottnak jelöltünk. Most a cache-t is invalidáljuk.
     */
    public function markAsRead(int $messageId, int $userId): void
    {
        $this->db->executeStatement(
            "UPDATE messages SET is_read = TRUE WHERE id = ? AND recipient_id = ?",
            [$messageId, $userId]
        );
        
        // [2025-12-29 14:17:29] Cache invalidation - unread count változott
        $this->cache->forget("unread_messages:{$userId}");
    }

    /**
     * Olvasatlan üzenetek száma
     */
    public function getUnreadCount(int $userId): int
    {
        return (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM messages 
             WHERE recipient_id = ? AND is_read = FALSE AND recipient_deleted = FALSE",
            [$userId]
        );
    }

    /**
     * Összes bejövő üzenet olvasottnak jelölése
     */
    public function markAllAsRead(int $userId): void
    {
        $this->db->executeStatement(
            "UPDATE messages SET is_read = TRUE WHERE recipient_id = ? AND is_read = FALSE",
            [$userId]
        );
        
        // Cache invalidation - user unread count-ja változott
        $this->cache->forget("unread_messages:{$userId}");
    }

    /**
     * Felhasználó keresése username alapján
     */
    public function findUserByUsername(string $username): ?array
    {
        $user = $this->db->fetchAssociative(
            "SELECT id, username FROM users WHERE username = ?",
            [$username]
        );
        return $user ?: null;
    }

    /**
     * Egyetlen üzenet lekérdezése
     */
    public function getMessage(int $messageId, int $userId): ?array
    {
        $message = $this->db->fetchAssociative(
            "SELECT m.id, m.sender_id, m.recipient_id, m.subject, m.body, m.is_read, m.created_at, 
                    sender.username as sender_name,
                    recipient.username as recipient_name
             FROM messages m 
             JOIN users sender ON m.sender_id = sender.id
             JOIN users recipient ON m.recipient_id = recipient.id
             WHERE m.id = ? AND (
                (m.sender_id = ? AND m.sender_deleted = FALSE) OR 
                (m.recipient_id = ? AND m.recipient_deleted = FALSE)
             )",
            [$messageId, $userId, $userId]
        );
        return $message ?: null;
    }

    /**
     * Utolsó elküldött üzenet idejének lekérdezése (spam védelemhez)
     */
    public function getLastMessageTime(int $userId): int
    {
        $lastTime = $this->db->fetchOne(
            "SELECT created_at FROM messages WHERE sender_id = ? ORDER BY created_at DESC LIMIT 1",
            [$userId]
        );

        return $lastTime ? strtotime($lastTime) : 0;
    }

    /**
     * Spam ellenőrzés
     * @throws \RuntimeException Ha túl gyorsan küld
     */
    public function validateSpam(int $userId): void
    {
        $lastTime = $this->getLastMessageTime($userId);
        $timeSinceLastMessage = time() - $lastTime;
        $cooldownSeconds = \Netmafia\Modules\Messages\MessageConfig::SPAM_COOLDOWN_SECONDS;

        if ($timeSinceLastMessage < $cooldownSeconds) {
            $remaining = $cooldownSeconds - $timeSinceLastMessage;
            throw new GameException("Túl gyorsan küldesz üzeneteket! Várj még {$remaining} másodpercet.");
        }
    }
    /**
     * "Lusta" (JIT) Takarítás: a top 200-on kívüli, 30 napnál régebbi üzenetek soft-delete-je.
     */
    public function cleanupOldMessages(int $userId, string $type): void
    {
        $config = $this->getQueryConfig($type);
        
        // Lekérdezzük a 200. üzenet utáni összes ID-t és dátumot csökkenő sorrendben
        $sql = "SELECT id, created_at FROM messages 
                WHERE {$config['user_col']} = ? AND {$config['deleted_col']} = FALSE 
                ORDER BY created_at DESC 
                LIMIT 10000 OFFSET 200";

        $messages = $this->db->fetchAllAssociative($sql, [$userId]);

        if (empty($messages)) {
            return;
        }

        $idsToDelete = [];
        $cutoff = (new \DateTime('-30 days'))->format('Y-m-d H:i:s');

        foreach ($messages as $msg) {
            // Ha az üzenet régebbi mint 30 nap, mehet a listára
            if ($msg['created_at'] < $cutoff) {
                $idsToDelete[] = $msg['id'];
            }
        }

        if (!empty($idsToDelete)) {
            $this->deleteMessages($userId, $idsToDelete, $type);
        }
    }
}
