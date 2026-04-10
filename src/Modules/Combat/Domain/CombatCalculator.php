<?php
declare(strict_types=1);

namespace Netmafia\Modules\Combat\Domain;

class CombatCalculator
{
    /**
     * Calculate defense bonus percentage based on ammo count.
     * Formula:
     * - 1 ammo = 0.4%
     * - 999 ammo = 10%
     * - Linear interpolation between.
     */
    public static function calculateDefenseBonus(int $ammo): float
    {
        if ($ammo <= 0) {
            return 0.0;
        }

        if ($ammo === 1) {
            return 0.4;
        }

        if ($ammo >= 999) {
            return 10.0;
        }

        // Linear interpolation:
        // slope = (10 - 0.4) / (999 - 1) = 9.6 / 998 ≈ 0.00961923847
        return 0.4 + ($ammo - 1) * 0.00961923847;
    }

    /**
     * Calculate attack bonus percentage based on ammo count.
     * Formula:
     * - 1 ammo = 5%
     * - 999 ammo = 15%
     * - Linear interpolation between.
     */
    public static function calculateAttackBonus(int $ammo): float
    {
        if ($ammo <= 0) {
            return 0.0;
        }
        
        if ($ammo >= 999) {
            return 15.0;
        }

        // Valós lineáris skálázás (1 db = 5%, 999 db = 15%)
        return 5 + (($ammo - 1) * 10 / 998);
    }

    /**
     * Calculate remaining cooldown in seconds.
     * Returns 0 if no cooldown.
     */
    /**
     * Calculate remaining cooldown in seconds.
     * Returns 0 if no cooldown.
     * 
     * @param string|null $lastAttackTime
     * @param int $cooldownMinutes Base cooldown (default 15)
     * @param int $reductionPercent Reduction in percentage (0-100)
     */
    public static function calculateCooldownRemaining(?string $lastAttackTime, int $cooldownMinutes = 15, int $reductionPercent = 0): int
    {
        if (!$lastAttackTime) {
            return 0;
        }

        // Apply reduction
        // e.g. 25% reduction -> 15 * 0.75 = 11.25 minutes
        $effectiveMinutes = $cooldownMinutes * (1 - ($reductionPercent / 100));
        // Időzóna-független (UTC) cooldown számítás
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $lastAttack = new \DateTimeImmutable($lastAttackTime, new \DateTimeZone('UTC'));
        
        $effectiveSeconds = (int)ceil($effectiveMinutes * 60);
        $cooldownEnds = $lastAttack->modify("+{$effectiveSeconds} seconds");
        
        $remaining = $cooldownEnds->getTimestamp() - $now->getTimestamp();

        return $remaining > 0 ? $remaining : 0;
    }
}
