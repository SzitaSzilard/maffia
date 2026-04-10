<?php
declare(strict_types=1);

namespace Netmafia\Shared\Domain;

class RankCalculator
{
    // RANKS moved to RankConfig

    public static function getRank(int $xp): string
    {
        $currentRank = 'Zsebtolvaj';
        
        foreach (\Netmafia\Shared\RankConfig::RANKS as $limit => $name) {
            if ($xp >= $limit) {
                $currentRank = $name;
            } else {
                break;
            }
        }
        
        return $currentRank;
    }

    /**
     * Visszaadja az aktuális rang info-t
     * @return array{name: string, minXp: int, index: int}
     */
    public static function getRankInfo(int $xp): array
    {
        $ranks = array_keys(\Netmafia\Shared\RankConfig::RANKS);
        $names = array_values(\Netmafia\Shared\RankConfig::RANKS);
        
        $currentIndex = 0;
        foreach ($ranks as $i => $limit) {
            if ($xp >= $limit) {
                $currentIndex = $i;
            } else {
                break;
            }
        }
        
        return [
            'name' => $names[$currentIndex],
            'minXp' => $ranks[$currentIndex],
            'index' => $currentIndex,
        ];
    }

    /**
     * Halálkor: visszaadja az előző rang minimum XP-jét
     * Ha már a legalacsonyabb rangon vagy, 0-t ad vissza
     */
    public static function getPreviousRankMinXp(int $currentXp): array
    {
        $currentRankInfo = self::getRankInfo($currentXp);
        $currentIndex = $currentRankInfo['index'];
        
        // Ha már a legalacsonyabb rangon vagyunk
        if ($currentIndex === 0) {
            return [
                'previousRankName' => $currentRankInfo['name'],
                'previousRankMinXp' => 0,
                'rankDropped' => false,
            ];
        }
        
        // Előző rang
        $ranks = array_keys(\Netmafia\Shared\RankConfig::RANKS);
        $names = array_values(\Netmafia\Shared\RankConfig::RANKS);
        $previousIndex = $currentIndex - 1;
        
        return [
            'previousRankName' => $names[$previousIndex],
            'previousRankMinXp' => $ranks[$previousIndex],
            'rankDropped' => true,
            'oldRankName' => $currentRankInfo['name'],
        ];
    }

    /**
     * Következő rang progresszió kiszámítása
     * 
     * @return array{nextRankName: ?string, nextRankXp: ?int, xpRemaining: ?int, progressPercent: float}
     */
    public static function getNextRankProgress(int $xp): array
    {
        $ranks = array_keys(\Netmafia\Shared\RankConfig::RANKS);
        $names = array_values(\Netmafia\Shared\RankConfig::RANKS);

        $currentIndex = 0;
        foreach ($ranks as $i => $limit) {
            if ($xp >= $limit) {
                $currentIndex = $i;
            } else {
                break;
            }
        }

        // Legmagasabb rang elérve
        if ($currentIndex >= count($ranks) - 1) {
            return [
                'nextRankName' => null,
                'nextRankXp' => null,
                'xpRemaining' => null,
                'progressPercent' => 100.0,
            ];
        }

        $currentRankMinXp = $ranks[$currentIndex];
        $nextRankMinXp = $ranks[$currentIndex + 1];
        $nextRankName = $names[$currentIndex + 1];
        $xpRemaining = $nextRankMinXp - $xp;
        $rangeTotal = $nextRankMinXp - $currentRankMinXp;
        $rangeProgress = $xp - $currentRankMinXp;
        $progressPercent = ($rangeTotal > 0) ? round(($rangeProgress / $rangeTotal) * 100, 1) : 0.0;

        return [
            'nextRankName' => $nextRankName,
            'nextRankXp' => $nextRankMinXp,
            'xpRemaining' => $xpRemaining,
            'progressPercent' => $progressPercent,
        ];
    }
}

