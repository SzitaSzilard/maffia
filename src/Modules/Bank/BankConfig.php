<?php
declare(strict_types=1);

namespace Netmafia\Modules\Bank;

final class BankConfig
{
    /**
     * Számlaszám generálás minimum értéke (10000)
     */
    public const ACCOUNT_NUMBER_MIN = 10000;

    /**
     * Számlaszám generálás maximum értéke (99999)
     */
    public const ACCOUNT_NUMBER_MAX = 99999;

    /**
     * Számlaszám generálás maximális próbálkozások száma
     */
    public const MAX_GENERATION_ATTEMPTS = 100;

    /**
     * Befizetés kezelési költsége (5%)
     */
    public const DEPOSIT_FEE_PERCENT = 0.05;
}
