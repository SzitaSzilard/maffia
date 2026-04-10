<?php
declare(strict_types=1);

namespace Netmafia\Modules\Weed;

class WeedConfig
{
    public const MAX_PLANTS_PER_COUNTRY = 45;
    public const PLANT_COOLDOWN_HOURS = 12;
    public const HARVEST_COOLDOWN_HOURS = 24;

    // Item IDs
    public const ITEM_WEED_SEED = 149;  // Vadkender mag
    public const ITEM_WEED_EXTRA = 136; // Füves cigi Extra minőség
    public const ITEM_WEED_GOOD = 137;  // Füves cigi Jó minőség
    public const ITEM_WEED_AVERAGE = 135; // Füves cigi Átlag minőség
    public const ITEM_WEED_BEGINNER = 138; // Füves cigi Kezdő minőség
    public const ITEM_WEED_POOR = 139;  // Füves cigi Pocsék minőség

    /**
     * Bázis minőségi érték országonként.
     * USA = 30 (legrosszabb), Kolumbia = 70 (legjobb)
     */
    public static function getCountryBaseQuality(string $countryCode): int
    {
        return match($countryCode) {
            'CO' => 70, // Kolumbia
            'JM' => 65, // Jamaica
            'NL' => 60, // Hollandia
            'MX' => 50, // Mexikó
            'IT' => 45, // Olaszország
            'DE' => 40, // Németország
            'HU' => 35, // Magyarország
            'US' => 30, // USA
            default => 30,
        };
    }

    /**
     * Végleges minőség kategória meghatározása a score alapján.
     * score = base (30-70) + random (1-100) → max: 170, min: 31
     */
    public static function determineQualityItemId(int $score): int
    {
        if ($score >= 140) {
            return self::ITEM_WEED_EXTRA;
        }
        if ($score >= 115) {
            return self::ITEM_WEED_GOOD;
        }
        if ($score >= 90) {
            return self::ITEM_WEED_AVERAGE;
        }
        if ($score >= 65) {
            return self::ITEM_WEED_BEGINNER;
        }
        return self::ITEM_WEED_POOR;
    }

    public static function getItemName(int $itemId): string
    {
        return match($itemId) {
            self::ITEM_WEED_EXTRA    => 'Extra minőségű fűves cigi',
            self::ITEM_WEED_GOOD     => 'Jó minőségű fűves cigi',
            self::ITEM_WEED_AVERAGE  => 'Átlag minőségű fűves cigi',
            self::ITEM_WEED_BEGINNER => 'Kezdő minőségű fűves cigi',
            self::ITEM_WEED_POOR     => 'Pocsék minőségű fűves cigi',
            self::ITEM_WEED_SEED     => 'Vadkender mag',
            default => 'Fűves cigi',
        };
    }
}
