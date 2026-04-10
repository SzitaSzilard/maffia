<?php
declare(strict_types=1);

namespace Netmafia\Modules\Buildings\Domain;

use Netmafia\Modules\Buildings\TravelConfig;

class TravelCalculator
{
    /**
     * Calculate effective cooldown minutes after reduction
     */
    public static function calculateEffectiveCooldown(float $reductionPercent, ?float $base = null): float
    {
        if ($base === null) {
            $base = TravelConfig::COOLDOWN_MINUTES;
        }
        // Clamp reduction between 0 and 100
        $reductionPercent = max(0, min(100, $reductionPercent));
        return $base * (1 - ($reductionPercent / 100));
    }

    /**
     * Calculate remaining seconds of cooldown
     */
    public static function calculateRemainingSeconds(?string $lastTravelTime, float $effectiveMinutes): int
    {
        if (!$lastTravelTime) {
            return 0;
        }

        $lastTime = new \DateTime($lastTravelTime);
        $now = new \DateTime();
        $diff = $now->getTimestamp() - $lastTime->getTimestamp();
        $cooldownSec = (int)($effectiveMinutes * 60);

        if ($diff < $cooldownSec) {
            return $cooldownSec - $diff;
        }

        return 0;
    }
}
