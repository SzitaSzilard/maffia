<?php
declare(strict_types=1);

namespace Netmafia\Modules\AmmoFactory;

final class AmmoFactoryConfig
{
    /**
     * Percenkénti gyártási sebesség
     */
    public const PRODUCTION_RATE_PER_MIN = 1500;

    /**
     * Tulajdonosi bónusz percenként
     */
    public const OWNER_BONUS_PER_MIN = 100;

    /**
     * Manuális indítás maximális mennyisége (db)
     */
    public const MANUAL_LIMIT = 500000;

    /**
     * Napi gyártási limit (db)
     */
    public const DAILY_LIMIT = 2160000;

    /**
     * Egy lőszer előállítási költsége ($)
     */
    public const COST_PER_UNIT = 1.8;

    /**
     * Minimális vásárlási mennyiség (db)
     */
    public const MIN_PURCHASE_QTY = 100;

    /**
     * Minimális eladási ár ($)
     */
    public const MIN_PRICE = 1;

    /**
     * Alapértelmezett töltény ár ha nincs beállítva ($)
     */
    public const DEFAULT_AMMO_PRICE = 5;
}
