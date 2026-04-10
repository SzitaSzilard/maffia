<?php
declare(strict_types=1);

namespace Netmafia\Modules\Item\Domain;

use Doctrine\DBAL\Connection;

/**
 * BuffService - Aktív buff-ok kezelése
 * 
 * [2025-12-30] Max 2 timed buff, ugyanaz nem stackelhető
 */
class BuffService
{
    private Connection $db;
    
    // Maximum aktív timed buff
    private const MAX_ACTIVE_BUFFS = 2;
    
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }
    
    /**
     * Aktív buff-ok lekérdezése
     * 
     * [2026-02-15] FIX: cleanExpiredBuffs() hívás eltávolítva.
     * A WHERE clause már szűri a lejárt buff-okat (expires_at > NOW()),
     * így felesleges minden olvasásnál DELETE-et futtatni.
     */
    public function getActiveBuffs(int $userId): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT ub.*, i.name as item_name
             FROM user_buffs ub
             JOIN items i ON i.id = ub.item_id
             WHERE ub.user_id = ? AND ub.expires_at > NOW()
             ORDER BY ub.expires_at",
            [$userId]
        );
    }
    
    /**
     * Aktív bónusz lekérdezése adott context-ben
     * 
     * [2026-02-15] FIX: cleanExpiredBuffs() hívás eltávolítva.
     * A WHERE clause szűri a lejárt buff-okat.
     * 
     * @param int $userId
     * @param string $type effect_type (attack_bonus, defense_bonus, etc)
     * @param string $context 'combat', 'gang', 'kocsma', etc
     * @return int Összesített bónusz %
     */
    public function getActiveBonus(int $userId, string $type, string $context): int
    {
        $buffs = $this->db->fetchAllAssociative(
            "SELECT value, context FROM user_buffs 
             WHERE user_id = ? AND effect_type = ? AND expires_at > NOW()",
            [$userId, $type]
        );
        
        $totalBonus = 0;
        
        foreach ($buffs as $buff) {
            // Check if context matches
            if ($buff['context'] === null) {
                // NULL context = applies everywhere
                $totalBonus += (int)$buff['value'];
            } else {
                // Check if context is in the comma-separated list
                $contexts = explode(',', $buff['context']);
                if (in_array($context, $contexts)) {
                    $totalBonus += (int)$buff['value'];
                }
            }
        }
        
        return $totalBonus;
    }

    /**
     * Aktív bónusz források lekérdezése (mely tárgyak adják)
     * 
     * @return array<string> Tárgy nevek listája
     */
    public function getActiveBuffSources(int $userId, string $type, string $context): array
    {
        // 1. Lekérjük a buffokat JOIN-olva az items táblával a név miatt
        $buffs = $this->db->fetchAllAssociative(
            "SELECT ub.value, ub.context, i.name as item_name
             FROM user_buffs ub
             JOIN items i ON i.id = ub.item_id
             WHERE ub.user_id = ? AND ub.effect_type = ? AND ub.expires_at > NOW()",
            [$userId, $type]
        );
        
        $sources = [];
        
        foreach ($buffs as $buff) {
            // Check if context matches
            if ($buff['context'] === null) {
                $sources[] = $buff['item_name'];
            } else {
                $contexts = explode(',', $buff['context']);
                if (in_array($context, $contexts)) {
                    $sources[] = $buff['item_name'];
                }
            }
        }
        
        return $sources;
    }
    
    /**
     * Buff hozzáadása
     * FONTOS: Az ItemService már ellenőrizte a canUseBuff-ot!
     * NOTE: MySQL NOW()-t használjuk, nem PHP date()-t a timezone konzisztenciáért!
     */
    public function addBuff(int $userId, int $itemId, array $effect): void
    {

        
        $durationMinutes = (int)$effect['duration_minutes'];
        
        // Use MySQL time to avoid PHP/MySQL timezone mismatch
        $this->db->executeStatement(
            "INSERT INTO user_buffs (user_id, item_id, effect_type, value, context, expires_at) 
             VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))",
            [
                $userId,
                $itemId,
                $effect['effect_type'],
                $effect['value'],
                $effect['context'],
                $durationMinutes
            ]
        );
    }
    
    /**
     * Ellenőrzés: lehet-e buff-ot adni
     * - Max 2 aktív buff
     * - Ugyanaz a tárgy nem lehet kétszer aktív
     * 
     * [2026-02-15] FIX: cleanExpiredBuffs() hívás eltávolítva.
     * A WHERE clause szűri a lejárt buff-okat.
     */
    public function canUseBuff(int $userId, int $itemId): bool
    {
        
        // Check if same item already active
        $sameItem = $this->db->fetchOne(
            "SELECT COUNT(*) FROM user_buffs 
             WHERE user_id = ? AND item_id = ? AND expires_at > NOW()",
            [$userId, $itemId]
        );
        
        if ((int)$sameItem > 0) {
            return false;
        }
        
        // Check total active buffs (by unique item_id)
        $activeCount = $this->db->fetchOne(
            "SELECT COUNT(DISTINCT item_id) FROM user_buffs 
             WHERE user_id = ? AND expires_at > NOW()",
            [$userId]
        );
        
        return (int)$activeCount < self::MAX_ACTIVE_BUFFS;
    }
    
    /**
     * Lejárt buff-ok törlése
     */
    public function cleanExpiredBuffs(): void
    {
        $this->db->executeStatement(
            "DELETE FROM user_buffs WHERE expires_at <= NOW()"
        );
    }
    
    /**
     * User összes buff-jának törlése (pl. halálkor)
     */
    public function clearAllBuffs(int $userId): void
    {
        $this->db->executeStatement(
            "DELETE FROM user_buffs WHERE user_id = ?",
            [$userId]
        );
    }
    
    /**
     * Hátralévő idő lekérdezése adott buff-hoz
     */
    public function getRemainingTime(int $userId, int $itemId): ?int
    {
        $result = $this->db->fetchOne(
            "SELECT TIMESTAMPDIFF(SECOND, NOW(), expires_at) 
             FROM user_buffs 
             WHERE user_id = ? AND item_id = ? AND expires_at > NOW()
             LIMIT 1",
            [$userId, $itemId]
        );
        
        return $result !== false ? (int)$result : null;
    }
}
