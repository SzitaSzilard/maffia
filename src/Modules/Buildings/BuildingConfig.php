<?php
declare(strict_types=1);

namespace Netmafia\Modules\Buildings;

final class BuildingConfig
{
    /**
     * Minimum XP az épület birtoklásához (Legenda rang)
     */
    public const MIN_XP_FOR_OWNERSHIP = 228000;

    /**
     * Kórház alapértelmezett gyógyítási ára / HP
     */
    public const HOSPITAL_DEFAULT_PRICE_PER_HP = 52;

    /**
     * Maximum életerő
     */
    public const MAX_HEALTH = 100;

    /**
     * Maximum energia
     */
    public const MAX_ENERGY = 100;
}
