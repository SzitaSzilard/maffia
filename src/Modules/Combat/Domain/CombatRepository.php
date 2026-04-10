<?php
declare(strict_types=1);

namespace Netmafia\Modules\Combat\Domain;

use Doctrine\DBAL\Connection;

class CombatRepository
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Támadható játékosok lekérdezése
     * 
     * Feltételek:
     * - Azonos ország
     * - Azonos szint (RankCalculator-ban meghatározott min/max XP alapján)
     * - Aktív az elmúlt 15 percben
     * - Nem saját maga
     */
    public function getAttackableUsers(int $userId, string $countryCode, int $minXp, int $maxXp): array
    {
        // 15 perces aktivitás határ
        $activeLimit = date('Y-m-d H:i:s', strtotime('-15 minutes'));

        return $this->db->fetchAllAssociative(
            "SELECT u.id, u.username, u.xp, u.country_code, u.last_activity, u.wins
             FROM users u
             WHERE u.country_code = ?
               AND u.xp >= ?
               AND u.xp < ?
               AND u.last_activity >= ?
               AND u.id != ?
               AND u.is_banned = 0
               AND NOT EXISTS (SELECT 1 FROM user_sleep WHERE user_id = u.id)
             ORDER BY u.last_activity DESC
             LIMIT 50",
            [$countryCode, $minXp, $maxXp, $activeLimit, $userId]
        );
    }

    public function getCombatSettings(int $userId): array
    {
        $settings = $this->db->fetchAssociative(
            "SELECT id, user_id, use_vehicle, defense_ammo FROM user_combat_settings WHERE user_id = ?",
            [$userId]
        );

        if (!$settings) {
            return [
                'use_vehicle' => 0,
                'defense_ammo' => 0
            ];
        }

        return $settings;
    }

    public function saveCombatSettings(int $userId, bool $useVehicle, int $defenseAmmo): void
    {
        $exists = $this->db->fetchOne("SELECT count(*) FROM user_combat_settings WHERE user_id = ?", [$userId]);

        if ($exists) {
            $this->db->update('user_combat_settings', [
                'use_vehicle' => $useVehicle ? 1 : 0,
                'defense_ammo' => $defenseAmmo
            ], ['user_id' => $userId]);
        } else {
            $this->db->insert('user_combat_settings', [
                'user_id' => $userId,
                'use_vehicle' => $useVehicle ? 1 : 0,
                'defense_ammo' => $defenseAmmo
            ]);
        }
    }

    public function logFight(array $data): void
    {
        $this->db->insert('combat_log', $data);
    }

    public function getCombatHistory(int $userId, int $limit = 50): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT cl.*, 
                    attacker.username as attacker_name, 
                    defender.username as defender_name,
                    winner.username as winner_name
             FROM combat_log cl
             LEFT JOIN users attacker ON cl.attacker_id = attacker.id
             LEFT JOIN users defender ON cl.defender_id = defender.id
             LEFT JOIN users winner ON cl.winner_id = winner.id
             WHERE (cl.attacker_id = ? OR cl.defender_id = ?)
               AND cl.created_at >= DATE_SUB(NOW(), INTERVAL 5 DAY)
             ORDER BY cl.created_at DESC
             LIMIT $limit",
            [$userId, $userId]
        );
    }

    public function getTotalWins(int $userId): int
    {
        return (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM combat_log WHERE winner_id = ?",
            [$userId]
        );
    }
    public function markAsRead(int $userId): void
    {
        $this->db->executeStatement(
            "UPDATE combat_log SET is_read = 1 WHERE defender_id = ?",
            [$userId]
        );
    }

    public function getLastAttackTime(int $userId): ?string
    {
        return $this->db->fetchOne(
            "SELECT created_at FROM combat_log WHERE attacker_id = ? ORDER BY created_at DESC LIMIT 1",
            [$userId]
        ) ?: null;
    }
}
