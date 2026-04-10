<?php
declare(strict_types=1);

namespace Netmafia\Modules\Money\Domain;

/**
 * Nincs elég pénz a műveletre
 */
class InsufficientBalanceException extends \RuntimeException
{
    private int $currentBalance;
    private int $requiredAmount;

    public function __construct(int $currentBalance, int $requiredAmount)
    {
        $this->currentBalance = $currentBalance;
        $this->requiredAmount = $requiredAmount;

        parent::__construct(
            'Nincs elegendő összege.',
            1001 // Error code for insufficient balance
        );
    }

    public function getCurrentBalance(): int
    {
        return $this->currentBalance;
    }

    public function getRequiredAmount(): int
    {
        return $this->requiredAmount;
    }
}
