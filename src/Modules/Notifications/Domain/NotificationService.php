<?php
declare(strict_types=1);

namespace Netmafia\Modules\Notifications\Domain;

use Doctrine\DBAL\Connection;
use Netmafia\Infrastructure\CacheService;

/**
 * NotificationService - Központi értesítő rendszer
 * 
 * Bármely modul használhatja értesítések küldésére:
 * $notificationService->send($userId, 'bank_transfer', 'Teszt utalt $5000', '/bank');
 */
class NotificationService
{
    private Connection $db;
    private CacheService $cache;

    public function __construct(Connection $db, CacheService $cache)
    {
        $this->db = $db;
        $this->cache = $cache;
    }

    /**
     * Értesítés küldése
     */
    public function send(
        int $userId,
        string $type,
        string $message,
        ?string $sourceModule = null,
        ?string $link = null
    ): bool {
        try {
            $this->db->insert('notifications', [
                'user_id' => $userId,
                'type' => $type,
                'source_module' => $sourceModule,
                'message' => $message,
                'link' => $link,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            
            // Cache invalidation - user unread count-ja változott
            $this->cache->forget("unread_notifications:{$userId}");
            
            return true;
        } catch (\Throwable $e) {
            error_log("[NotificationService] send failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Felhasználó értesítéseinek lekérdezése
     */
    public function getAll(int $userId, int $limit = 50): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT id, user_id, type, source_module, message, link, is_read, created_at FROM notifications 
             WHERE user_id = ? 
             ORDER BY created_at DESC 
             LIMIT ?",
            [$userId, $limit],
            [\PDO::PARAM_INT, \PDO::PARAM_INT]
        );
    }

    /**
     * Olvasatlan értesítések száma
     * 
     * [2025-12-29 14:21:55] Optimalizálás: Cache-elés hozzáadása
     * Korábban minden header render DB query-t jelentett. Most cache-ből
     * szolgáljuk ki az unread count-ot 5 perces TTL-lel.
     */
    public function getUnreadCount(int $userId): int
    {
        $cacheKey = "unread_notifications:{$userId}";
        
        // [2025-12-29 14:21:55] Cache-ből próbálkozás először
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return (int) $cached;
        }
        
        // DB lekérdezés
        $count = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM notifications 
             WHERE user_id = ? AND is_read = FALSE",
            [$userId]
        );
        
        // [2025-12-29 14:21:55] Cache-be mentés (5 perc TTL)
        $this->cache->set($cacheKey, $count, 300);
        
        return $count;
    }

    /**
     * Egyetlen értesítés olvasottnak jelölése
     * 
     * [2025-12-29 14:21:55] Hiányzó funkció hozzáadása
     * Korábban csak összes értesítést lehetett olvasottnak jelölni.
     * Most egyetlen értesítést is lehet egyesével.
     */
    public function markAsRead(int $notificationId, int $userId): void
    {
        $this->db->executeStatement(
            "UPDATE notifications SET is_read = TRUE 
             WHERE id = ? AND user_id = ?",
            [$notificationId, $userId]
        );
        
        // [2025-12-29 14:21:55] Cache invalidation - unread count változott
        $this->cache->forget("unread_notifications:{$userId}");
    }

    /**
     * Összes értesítés olvasottnak jelölése
     */
    public function markAllAsRead(int $userId): void
    {
        $this->db->executeStatement(
            "UPDATE notifications SET is_read = TRUE WHERE user_id = ? AND is_read = FALSE",
            [$userId]
        );
        
        // Cache invalidation
        $this->cache->forget("unread_notifications:{$userId}");
    }

    /**
     * Értesítések törlése
     */
    public function delete(int $userId, array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($ids, [$userId]);
        
        return $this->db->executeStatement(
            "DELETE FROM notifications WHERE id IN ($placeholders) AND user_id = ?",
            $params
        );
    }

    /**
     * Régi értesítések automatikus törlése
     * 30 napnál régebbi, ha 50-nél több van összesen
     */
    public function cleanupOld(int $userId): int
    {
        $count = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM notifications WHERE user_id = ?",
            [$userId]
        );

        if ($count <= 50) {
            return 0;
        }

        return $this->db->executeStatement(
            "DELETE FROM notifications 
             WHERE user_id = ? 
             AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
            [$userId]
        );
    }
}
