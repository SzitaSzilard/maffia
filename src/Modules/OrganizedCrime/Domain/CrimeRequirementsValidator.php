<?php
declare(strict_types=1);

namespace Netmafia\Modules\OrganizedCrime\Domain;

use Doctrine\DBAL\Connection;

class CrimeRequirementsValidator
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function validateInvite(int $organizerId, string $targetUsername, string $role): array
    {
        // 1. Megkeressük a célszemélyt
        $target = $this->db->fetchAssociative("SELECT id, country_code, oc_cooldown_until, xp, is_union_member, is_admin FROM users WHERE username = ?", [$targetUsername]);
        if (!$target) {
            return ['success' => false, 'error' => 'Ilyen nevű felhasználó nem létezik!'];
        }

        // 2. Minimum Katona rang (Index >= 5, 2500 XP) ellenőrzése
        $rankIndex = \Netmafia\Shared\Domain\RankCalculator::getRankInfo((int)$target['xp'])['index'];
        if (empty($target['is_admin']) && $rankIndex < 5) {
            return ['success' => false, 'error' => "A(z) {$targetUsername} felhasználó nem éri el a minimum követelményt (Katona rang)!"];
        }

        // 3. Szerep-specifikus ellenőrzések
        if ($role === 'union_member' && empty($target['is_union_member'])) {
            return ['success' => false, 'error' => "A(z) {$targetUsername} felhasználó nem rendelkezik Szakszervezeti Tagsággal (épület vagy kocsma bérleti jog)!"];
        }

        if ($role === 'gang_leader') {
            $isLeader = $this->db->fetchOne("SELECT is_leader FROM gang_members WHERE user_id = ?", [$target['id']]);
            if (!$isLeader) {
                return ['success' => false, 'error' => "A(z) {$targetUsername} felhasználó nem bandafőnök!"];
            }
        }

        // 4. Megkeressük a szervezőt
        $organizer = $this->db->fetchAssociative("SELECT country_code FROM users WHERE id = ?", [$organizerId]);

        // 5. Azonos ország ellenőrzése
        if ($organizer['country_code'] !== $target['country_code']) {
            return ['success' => false, 'error' => 'Nem egy országban vagytok, nem hívhatod meg!'];
        }

        // 6. Cooldown ellenőrzése
        if ($target['oc_cooldown_until'] !== null) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $cooldownTime = new \DateTimeImmutable($target['oc_cooldown_until'], new \DateTimeZone('UTC'));
            if ($cooldownTime > $now) {
                $diffMinutes = ceil(($cooldownTime->getTimestamp() - $now->getTimestamp()) / 60);
                return ['success' => false, 'error' => "A(z) {$targetUsername} felhasználónak még {$diffMinutes} perce van vissza a következő bűnözésig!"];
            }
        }

        // 5. Nincs-e már benne egy másik aktív bűnözésben (ahol elfogadta vagy már bent van)
        $activeSql = "
            SELECT 1 FROM organized_crime_members m
            JOIN organized_crimes c ON m.crime_id = c.id
            WHERE m.user_id = ? AND c.status IN ('gathering', 'in_progress') AND m.status != 'declined'
        ";
        $isActive = $this->db->fetchOne($activeSql, [$target['id']]);
        if ($isActive) {
            return ['success' => false, 'error' => "A(z) {$targetUsername} felhasználó már részt vesz egy másik folyamatban lévő bűnözésben!"];
        }

        return ['success' => true, 'target_id' => (int)$target['id']];
    }
}
