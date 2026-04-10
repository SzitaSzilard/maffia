<?php
declare(strict_types=1);

namespace Netmafia\Modules\Xp\Domain;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

use Doctrine\DBAL\Connection;
use Netmafia\Shared\Domain\RankCalculator;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Modules\Notifications\Domain\NotificationService;
use Netmafia\Modules\Item\Domain\BuffService;

/**
 * XpService - XP kezelése rang előléptetés logolással
 */
class XpService
{
    private Connection $db;
    private ?NotificationService $notificationService;
    private BuffService $buffService;

    public function __construct(
        Connection $db,
        BuffService $buffService,
        ?NotificationService $notificationService = null
    ) {
        $this->db = $db;
        $this->buffService = $buffService;
        $this->notificationService = $notificationService;
    }

    /**
     * XP hozzáadás rang előléptetés ellenőrzéssel
     * 
     * @return array{old_xp: int, new_xp: int, ranked_up: bool, old_rank: string, new_rank: string}
     */
    public function addXp(UserId $userId, int $amount, string $source = 'unknown'): array
    {
        if ($amount <= 0) {
            return ['old_xp' => 0, 'new_xp' => 0, 'ranked_up' => false, 'old_rank' => '', 'new_rank' => ''];
        }

        $this->db->beginTransaction();

        try {
            // 1. Jelenlegi XP és rang lekérdezése
            $user = $this->db->fetchAssociative(
                "SELECT xp FROM users WHERE id = ? FOR UPDATE",
                [$userId->id()]
            );

            if (!$user) {
                $this->db->rollBack();
                throw new InvalidInputException('User not found');
            }

            $oldXp = (int) $user['xp'];
            $oldRank = RankCalculator::getRank($oldXp);

            // [BUFF] xp_bonus alkalmazása (pl. Ecstasy: +50% XP minden forrásból)
            $xpBonusPercent = $this->buffService->getActiveBonus($userId->id(), 'xp_bonus', $source);
            $bonusAmount = ($xpBonusPercent > 0) ? (int)($amount * ($xpBonusPercent / 100)) : 0;
            $effectiveAmount = $amount + $bonusAmount;

            $newXp = $oldXp + $effectiveAmount;
            $newRank = RankCalculator::getRank($newXp);

            // 2. XP frissítése
            // [NULL-SAFE] @audit_source → trigger tudja mi okozta az XP változást
            try {
                $this->db->executeStatement("SET @audit_source = ?", ['XpService::' . $source]);
                $this->db->executeStatement(
                    "UPDATE users SET xp = ? WHERE id = ?",
                    [$newXp, $userId->id()]
                );
            } finally {
                $this->db->executeStatement("SET @audit_source = NULL");
            }

            // 3. Rang változás ellenőrzése
            $rankedUp = ($oldRank !== $newRank);

            if ($rankedUp) {
                $this->logRankProgression($userId, $oldRank, $newRank, $newXp);
                
                // Értesítés küldése
                if ($this->notificationService !== null) {
                    $rankInfo = RankCalculator::getRankInfo($newXp);
                    $message = "🎉 Gratulálunk! Előléptél! Az új rangod: {$newRank}.";
                    
                    $unlocked = [];
                    if ($rankInfo['index'] === 2) {
                        $unlocked[] = "Elérhetővé vált számodra a Piac!";
                    } elseif ($rankInfo['index'] === 3) {
                        $unlocked[] = "Új funkciók nyíltak meg: Posta, Küzdelmek és Vadkender ültetés!";
                    } elseif ($rankInfo['index'] === 5) {
                        $unlocked[] = "Új lehetőség a Szervezett Bűnözésben: Kaszinó kirablása!";
                    }
                    
                    if (!empty($unlocked)) {
                        $message .= " " . implode(' ', $unlocked);
                    }

                    $this->notificationService->send(
                        $userId->id(),
                        'rank_up',
                        $message,
                        'xp',
                        null
                    );
                }
            }

            $this->db->commit();

            return [
                'old_xp' => $oldXp,
                'new_xp' => $newXp,
                'ranked_up' => $rankedUp,
                'old_rank' => $oldRank,
                'new_rank' => $newRank,
            ];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Rang előléptetés naplózása
     */
    private function logRankProgression(UserId $userId, string $previousRank, string $newRank, int $xpAtPromotion): void
    {
        // Előző rang elérési idejének lekérdezése
        $previousEntry = $this->db->fetchAssociative(
            "SELECT reached_at FROM rank_progression_log 
             WHERE user_id = ? 
             ORDER BY reached_at DESC 
             LIMIT 1",
            [$userId->id()]
        );

        $timeToReach = null;
        if ($previousEntry) {
            $previousTime = strtotime($previousEntry['reached_at']);
            $timeToReach = time() - $previousTime;
        } else {
            // Első rang - a regisztrációtól számítjuk
            $user = $this->db->fetchAssociative(
                "SELECT created_at FROM users WHERE id = ?",
                [$userId->id()]
            );
            if ($user && $user['created_at']) {
                $createdTime = strtotime($user['created_at']);
                $timeToReach = time() - $createdTime;
            }
        }

        $this->db->insert('rank_progression_log', [
            'user_id' => $userId->id(),
            'rank_name' => $newRank,
            'previous_rank' => $previousRank,
            'xp_at_promotion' => $xpAtPromotion,
            'time_to_reach_seconds' => $timeToReach,
        ]);
    }

    /**
     * Felhasználó rang progresszió történetének lekérdezése
     */
    public function getRankProgression(UserId $userId): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT rank_name, previous_rank, reached_at, 
                    time_to_reach_seconds,
                    SEC_TO_TIME(time_to_reach_seconds) as time_formatted,
                    xp_at_promotion
             FROM rank_progression_log 
             WHERE user_id = ? 
             ORDER BY reached_at ASC",
            [$userId->id()]
        );
    }

    /**
     * Rang statisztikák - átlagos idő az egyes rangok eléréséhez
     */
    public function getRankStats(): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT rank_name, 
                    COUNT(*) as total_players,
                    AVG(time_to_reach_seconds) as avg_seconds,
                    MIN(time_to_reach_seconds) as min_seconds,
                    MAX(time_to_reach_seconds) as max_seconds,
                    SEC_TO_TIME(AVG(time_to_reach_seconds)) as avg_time_formatted
             FROM rank_progression_log 
             GROUP BY rank_name
             ORDER BY MIN(xp_at_promotion) ASC"
        );
    }
}
