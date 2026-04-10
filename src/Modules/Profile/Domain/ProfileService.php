<?php
declare(strict_types=1);

namespace Netmafia\Modules\Profile\Domain;

use Doctrine\DBAL\Connection;
use Netmafia\Shared\Domain\RankCalculator;

class ProfileService
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * User profil lekérdezése ID vagy username alapján
     * 
     * [2025-12-29 14:41:11] KRITIKUS FIX: SQL Injection vulnerability
     * Korábban a $field változó direkt beépült a query-be, ami SQL injection
     * lehetőséget nyitott. Most külön query-ket használunk biztonságosan.
     */
    public function getUserProfile(int|string $identifier): ?array
    {
        // [2025-12-29 14:41:11] Biztonságos query-k külön-külön
        // Ha numerikus, akkor ID alapján keresünk
        if (is_numeric($identifier)) {
            $user = $this->db->fetchAssociative(
                "SELECT u.*, c.name_hu as country_name 
                 FROM users u
                 LEFT JOIN countries c ON u.country_code = c.code 
                 WHERE u.id = ?",
                [(int)$identifier]
            );
        } else {
            // Egyébként felhasználónév alapján
            $user = $this->db->fetchAssociative(
                "SELECT u.*, c.name_hu as country_name 
                 FROM users u
                 LEFT JOIN countries c ON u.country_code = c.code 
                 WHERE u.username = ?",
                [$identifier]
            );
        }

        if (!$user) {
            return null;
        }

        // Calculate Rank
        $user['rank_name'] = RankCalculator::getRank((int)$user['xp']);

        // [2026-02-15] Fetch Default Vehicle
        // Csak a nevét kérjük le, ha van beállítva
        $defaultVehicle = $this->db->fetchOne(
            "SELECT v.name 
             FROM user_vehicles uv 
             JOIN vehicles v ON v.id = uv.vehicle_id 
             WHERE uv.user_id = ? AND uv.is_default = 1",
            [$user['id']]
        );
        $user['default_vehicle_name'] = $defaultVehicle ?: null;

        // [2026-02-15] Fetch Equipped Weapon
        // Csak a nevét kérjük le, ha van felszerelve
        $equippedWeapon = $this->db->fetchOne(
            "SELECT i.name 
             FROM user_items ui 
             JOIN items i ON i.id = ui.item_id 
             WHERE ui.user_id = ? AND ui.equipped = 1 AND i.type = 'weapon'",
            [$user['id']]
        );
        $user['equipped_weapon_name'] = $equippedWeapon ?: null;

        return $user;
    }
}
