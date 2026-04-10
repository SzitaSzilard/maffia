<?php
declare(strict_types=1);

namespace Netmafia\Shared\Exceptions;

/**
 * InsufficientFundsException — Nincs elég pénz/kredit
 */
class InsufficientFundsException extends GameException
{
    private int $currentBalance;
    private int $requiredAmount;

    public function __construct(int $currentBalance, int $requiredAmount, string $currency = 'pénz')
    {
        $this->currentBalance = $currentBalance;
        $this->requiredAmount = $requiredAmount;
        parent::__construct('Nincs elegendő összege.');
    }

    public function getCurrentBalance(): int { return $this->currentBalance; }
    public function getRequiredAmount(): int { return $this->requiredAmount; }
}
