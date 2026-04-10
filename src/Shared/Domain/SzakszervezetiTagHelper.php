<?php
declare(strict_types=1);
/**
 * Szakszervezeti Tag Helper Service
 * 
 * [2025-12-29] Épület tulajdonosok automatikus szakszervezeti tag kezelése

 * [2026-02-21] FIX: Egységesítve is_union_member oszlopra (is_szakszervezeti_tag megszűnt)
 */

namespace Netmafia\Shared\Domain;

use Doctrine\DBAL\Connection;

class SzakszervezetiTagHelper
{
    /**
     * Frissíti a user szakszervezeti tag státuszát épület tulajdonlás alapján
     */
    public static function updateUserTag(Connection $db, int $userId): void
    {
        $hasBuilding = (bool) $db->fetchOne(
            "SELECT COUNT(*) FROM buildings WHERE owner_id = ?",
            [$userId]
        );
        
        try {
            $db->executeStatement("SET @audit_source = ?", ['SzakszervezetiTagHelper::updateUserTag']);
            $db->executeStatement(
                "UPDATE users SET is_union_member = ? WHERE id = ?",
                [$hasBuilding ? 1 : 0, $userId]
            );
        } finally {
            $db->executeStatement("SET @audit_source = NULL");
        }
    }
    
    /**
     * Frissíti MIND a usereket (pl. migration után)
     */
    public static function updateAllUsers(Connection $db): void
    {
        try {
            $db->executeStatement("SET @audit_source = ?", ['SzakszervezetiTagHelper::updateAllUsers']);
            $db->executeStatement("UPDATE users SET is_union_member = 0");
            
            $db->executeStatement(
                "UPDATE users 
                 SET is_union_member = 1 
                 WHERE id IN (
                     SELECT DISTINCT owner_id 
                     FROM buildings 
                     WHERE owner_id IS NOT NULL
                 )"
            );
        } finally {
            $db->executeStatement("SET @audit_source = NULL");
        }
    }
}
