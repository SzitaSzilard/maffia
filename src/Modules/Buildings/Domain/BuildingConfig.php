<?php
declare(strict_types=1);

namespace Netmafia\Modules\Buildings\Domain;

/**
 * BuildingConfig - Épület rendszer konfigurációs konstansok
 * 
 * Központosított konfiguráció hardcoded értékek helyett.
 */
class BuildingConfig
{
    // === User Resource Limits ===
    public const MAX_ENERGY = 100;
    public const MAX_HEALTH = 100;
    
    // === Hospital Configuration ===
    public const DEFAULT_HOSPITAL_PRICE_PER_HP = 52;
    
    // === Ammo Factory Configuration ===
    public const DEFAULT_AMMO_PRICE = 5;
    public const AMMO_PRODUCTION_RATE_SECONDS = 20; // másodperc per töltény
    public const AMMO_COST_PER_BULLET = 5;         // gyártási költség
    
    // === Owner Revenue Configuration ===
    public const FIXED_OWNER_CUT_PERCENT = 80;
    
    // === Payout Modes ===
    public const PAYOUT_MODE_INSTANT = 'instant';
    public const PAYOUT_MODE_DAILY = 'daily';
    public const PAYOUT_MODE_WEEKLY = 'weekly';
    
    /**
     * Valid payout mode ellenőrzés
     */
    public static function isValidPayoutMode(string $mode): bool
    {
        return in_array($mode, [
            self::PAYOUT_MODE_INSTANT,
            self::PAYOUT_MODE_DAILY,
            self::PAYOUT_MODE_WEEKLY
        ], true);
    }
}
