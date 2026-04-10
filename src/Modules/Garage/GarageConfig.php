<?php
declare(strict_types=1);

namespace Netmafia\Modules\Garage;

final class GarageConfig
{
    /**
     * Alapár / slot egység (50 férőhely ára osztva 8100-zal?)
     * A kódban 8100 szerepelt mint "pricePerSlot".
     */
    public const SLOT_PRICE_PER_UNIT = 8100;

    /**
     * Eladási ár aránya a vételi árhoz képest (80%)
     */
    public const SELL_PRICE_RATIO = 0.8;

    /**
     * Elérhető bővítési csomagok (slot)
     * Minden csomag csak egyszer vásárolható meg adott országban.
     */
    public const EXPANSION_PACKAGES = [5, 10, 12, 25, 50];

    /**
     * Biztonsági fejlesztések árai
     */
    public const UPGRADE_PRICES = [
        'bulletproof_glass' => 2176,
        'steel_body' => 2584,
        'runflat_tires' => 1428,
        'explosion_proof_tank' => 1700,
        'large_tank' => 680,
    ];

    public const UPGRADE_SAFETY_BONUS = 0.02; // 2%
    public const LARGE_TANK_CAPACITY = 140;

    /**
     * Javítási költség / %
     */
    public const REPAIR_COST_PER_PERCENT = 120;

    /**
     * Gyors eladás (Bontó) fix ár járművenként
     */
    public const QUICK_SELL_PRICE_PER_VEHICLE = 24500;

    /**
     * Prémium kategóriák, amiket csak a Piacon lehet eladni (Bontóban nem)
     */
    public const PREMIUM_CATEGORIES = ['sport', 'suv', 'motor', 'luxury', 'muscle'];

    /**
     * Engedélyezett országkódok
     */
    public const ALLOWED_COUNTRIES = ['HU', 'DE', 'FR', 'IT', 'ES', 'RO', 'AT', 'SK', 'CZ', 'PL'];
}
