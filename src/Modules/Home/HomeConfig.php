<?php
declare(strict_types=1);

namespace Netmafia\Modules\Home;

final class HomeConfig
{
    /**
     * Minimum alvás idő (óra)
     */
    public const SLEEP_MIN_HOURS = 1;

    /**
     * Maximum alvás idő (óra)
     */
    public const SLEEP_MAX_HOURS = 9;

    /**
     * Utcai alvás esetén életerő regeneráció (%/óra)
     */
    public const STREET_HEALTH_REGEN = 2;

    /**
     * Utcai alvás esetén energia regeneráció (%/óra)
     */
    public const STREET_ENERGY_REGEN = 3;

    /**
     * Ingatlan eladáskor visszakapott ár aránya (60%)
     */
    public const PROPERTY_SELL_RATIO = 0.6;
}
