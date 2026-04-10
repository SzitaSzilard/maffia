<?php
declare(strict_types=1);

namespace Netmafia\Infrastructure;

use Doctrine\DBAL\Connection;

/**
 * AuditLogger - Biztonsági és admin események naplózása
 * 
 * Használat:
 * $auditLogger->log('login_blocked', $userId, ['ip' => $ip, 'reason' => '5 rossz próba']);
 */
class AuditLogger
{
    private Connection $db;
    
    // Audit típusok
    public const TYPE_LOGIN_BLOCKED  = 'login_blocked';
    public const TYPE_LOGIN_SUCCESS  = 'login_success';
    public const TYPE_LOGIN_FAILED   = 'login_failed';
    public const TYPE_ADMIN_ACTION   = 'admin_action';
    public const TYPE_BANK_TRANSFER  = 'bank_transfer';
    public const TYPE_BANK_DEPOSIT   = 'bank_deposit';
    public const TYPE_BANK_WITHDRAW  = 'bank_withdraw';
    public const TYPE_BANK_OPEN      = 'bank_open_account';
    public const TYPE_SUSPICIOUS     = 'suspicious';
    // [2026-02-28] FIX: Új típusok hozzáadva — compliance 9.2
    public const TYPE_SHOP_BUY       = 'shop_buy';
    public const TYPE_MARKET_BUY     = 'market_buy';
    public const TYPE_MARKET_LIST    = 'market_list';
    public const TYPE_MARKET_REVOKE  = 'market_revoke';
    public const TYPE_HOSPITAL_HEAL  = 'hospital_heal';
    public const TYPE_RESTAURANT_EAT = 'restaurant_eat';
    public const TYPE_KOCSMA_MESSAGE = 'kocsma_message';
    
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }
    
    /**
     * Esemény naplózása
     * 
     * @param string $type Esemény típusa (TYPE_* konstansok)
     * @param int|null $userId Érintett user ID (null ha ismeretlen)
     * @param array $details Extra információk (JSON-ként tárolva)
     * @param string|null $ip IP cím (auto-detect ha null)
     */
    public function log(string $type, ?int $userId = null, array $details = [], ?string $ip = null): void
    {
        try {
            $this->db->insert('audit_logs', [
                'type' => $type,
                'user_id' => $userId,
                // [2026-02-28] FIX: $_SERVER eltávolítva Service rétegből — IP-t mindig az Action/Middleware adja át
                'ip_address' => $ip ?? 'unknown',
                'details' => json_encode($details, JSON_UNESCAPED_UNICODE),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            // Silently fail - ne álljon meg az app ha a log nem megy
            error_log("AuditLogger error: " . $e->getMessage());
        }
    }
    
    /**
     * Audit logok lekérdezése (admin felülethez)
     */
    public function getRecent(int $limit = 100, ?string $type = null): array
    {
        $sql = "SELECT id, type, user_id, ip_address, details, created_at FROM audit_logs";
        $params = [];
        
        if ($type !== null) {
            $sql .= " WHERE type = ?";
            $params[] = $type;
        }
        
        // [2026-02-28] FIX: LIMIT paraméteres query-vel (SQL injection megelőzés)
        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;
        
        return $this->db->fetchAllAssociative($sql, $params);
    }
    
    /**
     * Adott IP audit logjai
     */
    public function getByIp(string $ip, int $limit = 50): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT id, type, user_id, ip_address, details, created_at FROM audit_logs WHERE ip_address = ? ORDER BY created_at DESC LIMIT ?",
            [$ip, $limit]
        );
    }
    
    /**
     * Adott user audit logjai
     */
    public function getByUser(int $userId, int $limit = 50): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT id, type, user_id, ip_address, details, created_at FROM audit_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT ?",
            [$userId, $limit]
        );
    }
}
