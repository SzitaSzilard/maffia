<?php
declare(strict_types=1);

namespace Netmafia\Modules\Buildings;

class TravelConfig
{
    public const COOLDOWN_MINUTES = 15;
    public const FREE_DAILY_USES = 5;

    // Fuel costs in Liters for traveling TO a country
    // The key is the target country code.
    // Base cost is just an example, specific logic can override.
    public const FUEL_COSTS = [
        'US' => 45, // USA
        'GB' => 30, // England
        'JP' => 70, // Japan
        'FR' => 40, // France
        'CA' => 15, // Canada
        'CN' => 50, // China
        'DE' => 50, // Germany
        'IT' => 60, // Italy
        'RU' => 70, // Russia
        'CO' => 15, // Colombia
    ];

    // Sticker Levels
    public const STICKER_LEVEL_NONE = 0;
    public const STICKER_LEVEL_7 = 1;
    public const STICKER_LEVEL_10 = 2;
    public const STICKER_LEVEL_UNLIMITED = 3;

    // Sticker Limits (Daily Uses)
    public const LIMIT_DEFAULT = 5;
    public const LIMIT_7 = 7;
    public const LIMIT_10 = 10;
    public const LIMIT_UNLIMITED = 999999;

    // Sticker Durations
    public const DURATION_WEEK = 'week';
    public const DURATION_MONTH = 'month';

    // Sticker Prices [Level][Duration]
    public const STICKER_PRICES = [
        self::STICKER_LEVEL_7 => [
            self::DURATION_WEEK => 1000,
            self::DURATION_MONTH => 3000,
        ],
        self::STICKER_LEVEL_10 => [
            self::DURATION_WEEK => 3000,
            self::DURATION_MONTH => 9000,
        ],
        self::STICKER_LEVEL_UNLIMITED => [
            self::DURATION_WEEK => 12000,
            self::DURATION_MONTH => 35000,
        ],
    ];

    // Revenue Share (Owner gets 80%)
    public const OWNER_REVENUE_PERCENT = 80;

    // --- AIRPLANE TRAVEL (REPÜLŐTÉR) ---
    public const AIRPLANE_COOLDOWN_MINUTES = 60;

    // Ticket costs in $ for traveling TO a country
    public const AIRPLANE_PRICES = [
        'US' => 120, // USA (assumption based on general prices)
        'GB' => 68,  // Anglia
        'JP' => 100, // Japán
        'FR' => 85,  // Franciaország
        'CA' => 51,  // Kanada
        'CN' => 120, // Kína
        'DE' => 85,  // Németország
        'IT' => 120, // Olaszország
        'RU' => 100, // Oroszország
        'CO' => 51,  // Kolumbia
    ];
}
