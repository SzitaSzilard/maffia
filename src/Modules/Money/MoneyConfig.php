<?php
declare(strict_types=1);

namespace Netmafia\Modules\Money;

final class MoneyConfig
{
    /**
     * Maximális tranzakciós összeg (9 trillió)
     * BIGINT Overflow védelem
     */
    public const MAX_TRANSACTION_AMOUNT = 9000000000000000000;
}
