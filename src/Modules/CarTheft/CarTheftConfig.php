<?php
declare(strict_types=1);

namespace Netmafia\Modules\CarTheft;

class CarTheftConfig
{
    public const COOLDOWN_MINUTES = 12;
    public const BASE_CHANCE_PERCENT = 15;
    public const THEFT_K_FACTOR = 9.6; // log-alapú szorzó: 85% elérhető ~1500 próbánál (≈1-2 hónap)
    
    // Siker esetén (utcai + szalon)
    public const ENERGY_COST_SUCCESS = 6;
    public const XP_REWARD_MIN = 8;
    public const XP_REWARD_MAX = 17;

    // Kudarc esetén (utcai + szalon)
    public const ENERGY_COST_FAIL_MIN = 7;
    public const ENERGY_COST_FAIL_MAX = 18;
    public const XP_FAIL_MIN = 3;
    public const XP_FAIL_MAX = 6;

    // Szalonlopás specifikus
    public const DEALERSHIP_BASE_CHANCE = 30;
    public const DEALERSHIP_MAX_CHANCE = 99;
    public const DEALERSHIP_SCALE_FACTOR = 2315;

    // Country code mapping (countries tábla GB, vehicles tábla UK)
    public const COUNTRY_VEHICLE_MAP = [
        'GB' => ['GB', 'UK'],
    ];
}
