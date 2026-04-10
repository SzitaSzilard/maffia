<?php
declare(strict_types=1);

namespace Netmafia\Modules\Health\Domain;

use Doctrine\DBAL\Connection;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Shared\Domain\RankCalculator;
use Netmafia\Modules\Notifications\Domain\NotificationService;
use InvalidArgumentException;

/**
 * HealthService - Élet és energia kezelése
 * 
 * - Health: 0-100, ha 0 → halál
 * - Energy: 0-100, ha 0 → nem bűnözhet
 */
class HealthService
{
    private Connection $db;
    private ?NotificationService $notificationService;

    public function __construct(Connection $db, ?NotificationService $notificationService = null)
    {
        $this->db = $db;
        $this->notificationService = $notificationService;
    }

    /**
     * Sebzés - csökkenti az életet, ha 0 → halál
     * 
     * @return array{health: int, died: bool, message: string}
     */
    public function damage(
        UserId $userId,
        int $amount,
        string $cause = 'unknown',
        ?int $killerId = null,
        array $details = []
    ): array {
        if ($amount < 0) {
            throw new InvalidArgumentException('Damage amount must be positive');
        }

        // [FIX] Nested transaction awareness — CombatService-ből hívva már tranzakcióban vagyunk
        $isNested = $this->db->getTransactionNestingLevel() > 0;
        if (!$isNested) {
            $this->db->beginTransaction();
        }

        try {
            // 1. Lekérdezzük a jelenlegi health-et FOR UPDATE
            $user = $this->db->fetchAssociative(
                "SELECT health, xp, username FROM users WHERE id = ? FOR UPDATE",
                [$userId->id()]
            );

            if (!$user) {
                throw new InvalidArgumentException('User not found');
            }

            $currentHealth = (int) $user['health'];
            $newHealth = max(0, $currentHealth - $amount);

            // 2. Frísstünk a health-et
            // [NULL-SAFE] try-finally garantálja a @audit_source reset-et exception esetén is
            try {
                $this->db->executeStatement("SET @audit_source = ?", [$cause]);
                $this->db->executeStatement(
                    "UPDATE users SET health = ? WHERE id = ?",
                    [$newHealth, $userId->id()]
                );
            } finally {
                $this->db->executeStatement("SET @audit_source = NULL");
            }

            $died = false;
            $message = "Sebződtél! -{$amount} HP (Maradék: {$newHealth})";

            // 3. Ha health = 0 → HALÁL
            if ($newHealth === 0) {
                $deathResult = $this->handleDeath($userId, (int) $user['xp'], $cause, $killerId, $details);
                $died = true;
                $message = $deathResult['message'];
            }

            if (!$isNested) {
                $this->db->commit();
            }

            return [
                'health' => $died ? 100 : $newHealth,
                'died' => $died,
                'message' => $message,
            ];

        } catch (\Throwable $e) {
            if (!$isNested && $this->db->isTransactionActive()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    /**
     * Gyógyítás - növeli az életet (max 100)
     */
    public function heal(UserId $userId, int $amount, string $source = 'system'): int
    {
        if ($amount < 0) {
            throw new InvalidArgumentException('Heal amount must be positive');
        }

        // [FIX] Nested transaction awareness — SleepService-ből hívva már tranzakcióban vagyunk
        $isNested = $this->db->getTransactionNestingLevel() > 0;
        if (!$isNested) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->fetchOne("SELECT health FROM users WHERE id = ? FOR UPDATE", [$userId->id()]);

            // [NULL-SAFE] try-finally garantálja a @audit_source reset-et
            try {
                $this->db->executeStatement("SET @audit_source = ?", [$source]);
                $this->db->executeStatement(
                    "UPDATE users SET health = LEAST(100, health + ?) WHERE id = ?",
                    [$amount, $userId->id()]
                );
            } finally {
                $this->db->executeStatement("SET @audit_source = NULL");
            }

            $newHealth = $this->getHealth($userId);
            if (!$isNested) {
                $this->db->commit();
            }
            return $newHealth;
        } catch (\Throwable $e) {
            if (!$isNested && $this->db->isTransactionActive()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    /**
     * Energia használat (min 0)
     * 
     * [2026-02-15] FIX: Tranzakció + FOR UPDATE lock hozzáadva.
     * Korábban két párhuzamos kérés átjuthatott az energia-ellenőrzésen
     * és negatív energiát okozhatott. Most pesszimista lock védi.
     */
    public function useEnergy(UserId $userId, int $amount, string $source = 'system'): int
    {
        if ($amount < 0) {
            throw new InvalidArgumentException('Energy amount must be positive');
        }

        // [FIX] Nested transaction awareness
        $isNested = $this->db->getTransactionNestingLevel() > 0;
        if (!$isNested) {
            $this->db->beginTransaction();
        }
        
        try {
            $currentEnergy = (int) $this->db->fetchOne(
                "SELECT energy FROM users WHERE id = ? FOR UPDATE",
                [$userId->id()]
            );
            
            if ($currentEnergy < $amount) {
                if (!$isNested && $this->db->isTransactionActive()) { $this->db->rollBack(); }
                throw new InvalidArgumentException(
                    sprintf('Nincs elég energiád! Szükséges: %d, Megvan: %d', $amount, $currentEnergy)
                );
            }

            // [NULL-SAFE] try-finally garantálja a @audit_source reset-et
            try {
                $this->db->executeStatement("SET @audit_source = ?", [$source]);
                $this->db->executeStatement(
                    "UPDATE users SET energy = GREATEST(0, energy - ?) WHERE id = ?",
                    [$amount, $userId->id()]
                );
            } finally {
                $this->db->executeStatement("SET @audit_source = NULL");
            }
            
            if (!$isNested) {
                $this->db->commit();
            }

            return $currentEnergy - $amount;
            
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if (!$isNested && $this->db->isTransactionActive()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    /**
     * Energia visszatöltés (max 100)
     */
    public function restoreEnergy(UserId $userId, int $amount, string $source = 'system'): int
    {
        if ($amount < 0) {
            throw new InvalidArgumentException('Energy amount must be positive');
        }

        // [FIX] Nested transaction awareness
        $isNested = $this->db->getTransactionNestingLevel() > 0;
        if (!$isNested) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->fetchOne("SELECT energy FROM users WHERE id = ? FOR UPDATE", [$userId->id()]);

            // [NULL-SAFE] try-finally garantálja a @audit_source reset-et
            try {
                $this->db->executeStatement("SET @audit_source = ?", [$source]);
                $this->db->executeStatement(
                    "UPDATE users SET energy = LEAST(100, energy + ?) WHERE id = ?",
                    [$amount, $userId->id()]
                );
            } finally {
                $this->db->executeStatement("SET @audit_source = NULL");
            }

            $newEnergy = $this->getEnergy($userId);
            if (!$isNested) {
                $this->db->commit();
            }
            return $newEnergy;
        } catch (\Throwable $e) {
            if (!$isNested && $this->db->isTransactionActive()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    /**
     * Jelenlegi health lekérdezés
     */
    public function getHealth(UserId $userId): int
    {
        return (int) $this->db->fetchOne(
            "SELECT health FROM users WHERE id = ?",
            [$userId->id()]
        );
    }

    /**
     * Jelenlegi energy lekérdezés
     */
    public function getEnergy(UserId $userId): int
    {
        return (int) $this->db->fetchOne(
            "SELECT energy FROM users WHERE id = ?",
            [$userId->id()]
        );
    }

    /**
     * Halál kezelése - visszaesés az előző rang minimum XP-jére
     */
    private function handleDeath(
        UserId $userId,
        int $currentXp,
        string $cause,
        ?int $killerId,
        array $details
    ): array {
        // 1. Előző rang minimum XP kiszámítása
        $rankInfo = RankCalculator::getPreviousRankMinXp($currentXp);
        $newXp = $rankInfo['previousRankMinXp'];
        $rankDropped = $rankInfo['rankDropped'];
        $oldRankName = $rankInfo['oldRankName'] ?? RankCalculator::getRank($currentXp);
        $newRankName = $rankInfo['previousRankName'];

        // 2. Health reset 100-ra, XP = előző rang minimuma
        $this->db->executeStatement(
            "UPDATE users SET health = 100, xp = ? WHERE id = ?",
            [$newXp, $userId->id()]
        );

        // 3. Death log
        $this->db->insert('death_log', [
            'user_id' => $userId->id(),
            'cause' => $cause,
            'killer_id' => $killerId,
            'old_rank' => $currentXp,
            'new_rank' => $newXp,
            'details' => json_encode(array_merge($details, [
                'old_rank_name' => $oldRankName,
                'new_rank_name' => $newRankName,
            ]), JSON_UNESCAPED_UNICODE),
        ]);

        // 4. Értesítés
        if ($rankDropped) {
            $message = "💀 Meghaltál! Visszaestél a '{$newRankName}' rangra.";
        } else {
            $message = "💀 Meghaltál! Már a legalacsonyabb rangon voltál, így rangvesztés nem történt.";
        }

        // 5. Notification küldése (ha van service)
        if ($this->notificationService !== null) {
            $this->notificationService->send(
                $userId->id(),
                'death',
                $message,
                'health',
                null
            );
        }

        return [
            'message' => $message,
            'rank_dropped' => $rankDropped,
            'old_rank_name' => $oldRankName,
            'new_rank_name' => $newRankName,
            'old_xp' => $currentXp,
            'new_xp' => $newXp,
        ];
    }

    /**
     * Halál history lekérdezése
     */
    public function getDeathHistory(UserId $userId, int $limit = 20): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT d.*, u.username as killer_name
             FROM death_log d
             LEFT JOIN users u ON u.id = d.killer_id
             WHERE d.user_id = ?
             ORDER BY d.died_at DESC
             LIMIT ?",
            [$userId->id(), $limit]
        );
    }

    /**
     * Összes halál száma
     */
    public function getDeathCount(UserId $userId): int
    {
        return (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM death_log WHERE user_id = ?",
            [$userId->id()]
        );
    }
}

