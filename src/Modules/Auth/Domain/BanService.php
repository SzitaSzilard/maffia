<?php
declare(strict_types=1);

namespace Netmafia\Modules\Auth\Domain;

use Doctrine\DBAL\Connection;

/**
 * BanService - Centralizált ban logika kezelése
 * 
 * Felelős a user bannolásért, ban feloldásért és audit trail rögzítéséért.
 * Minden ban művelet naplózva van az audit_logs táblában.
 */
class BanService
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * User bannolása
     * 
     * @param int $userId A bannolni kívánt user ID-ja
     * @param int $adminId A bannolást végző admin ID-ja
     * @param string $reason A bannolás oka (audit célból)
     * @throws \Exception Ha a tranzakció nem sikerül
     */
    public function banUser(int $userId, int $adminId, string $reason, ?string $ip = null): void
    {
        $this->db->beginTransaction();
        
        try {
            // User bannolása
            try {
                $this->db->executeStatement("SET @audit_source = ?", ['BanService::banUser']);
                $this->db->executeStatement(
                    "UPDATE users SET is_banned = 1 WHERE id = ?",
                    [$userId]
                );
            } finally {
                $this->db->executeStatement("SET @audit_source = NULL");
            }
            
            // Audit log rögzítése
            $this->db->insert('audit_logs', [
                'user_id' => $userId,
                'action' => 'user_banned',
                'details' => json_encode([
                    'banned_by' => $adminId,
                    'reason' => $reason
                ]),
                'ip_address' => $ip,
                'created_at' => gmdate('Y-m-d H:i:s')
            ]);
            
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * User ban feloldása
     * 
     * @param int $userId A feloldani kívánt user ID-ja
     * @param int $adminId A feloldást végző admin ID-ja
     * @throws \Exception Ha a tranzakció nem sikerül
     */
    public function unbanUser(int $userId, int $adminId, ?string $ip = null): void
    {
        $this->db->beginTransaction();
        
        try {
            // Ban feloldása
            try {
                $this->db->executeStatement("SET @audit_source = ?", ['BanService::unbanUser']);
                $this->db->executeStatement(
                    "UPDATE users SET is_banned = 0 WHERE id = ?",
                    [$userId]
                );
            } finally {
                $this->db->executeStatement("SET @audit_source = NULL");
            }
            
            // Audit log rögzítése
            $this->db->insert('audit_logs', [
                'user_id' => $userId,
                'action' => 'user_unbanned',
                'details' => json_encode(['unbanned_by' => $adminId]),
                'ip_address' => $ip,
                'created_at' => gmdate('Y-m-d H:i:s')
            ]);
            
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * User banned státusz ellenőrzése
     * 
     * @param int $userId Ellenőrizni kívánt user ID
     * @return bool true ha bannolva van, false ha nincs
     */
    public function isBanned(int $userId): bool
    {
        return (bool) $this->db->fetchOne(
            "SELECT is_banned FROM users WHERE id = ?",
            [$userId]
        );
    }

    /**
     * Bannolt userek listázása (admin használatra)
     * 
     * @param int $limit Maximum hány rekordot adjon vissza
     * @return array Bannolt userek listája
     */
    public function getBannedUsers(int $limit = 50): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT id, username, email, created_at, last_activity 
             FROM users 
             WHERE is_banned = 1 
             ORDER BY last_activity DESC 
             LIMIT ?",
            [$limit]
        );
    }

    /**
     * Ban history lekérdezése egy userhez
     * 
     * @param int $userId User ID akinek a történetét lekérdezzük
     * @return array Ban események listája
     */
    public function getBanHistory(int $userId): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT id, user_id, action, details, ip_address, created_at FROM audit_logs 
             WHERE user_id = ? 
             AND action IN ('user_banned', 'user_unbanned')
             ORDER BY created_at DESC",
            [$userId]
        );
    }
}
