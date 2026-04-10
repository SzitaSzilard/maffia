<?php
declare(strict_types=1);

namespace Netmafia\Shared\Exceptions;

/**
 * CooldownActiveException — Cooldown időszak még aktív
 */
class CooldownActiveException extends GameException
{
    private int $remainingSeconds;

    public function __construct(int $remainingSeconds, string $action = 'művelet')
    {
        $this->remainingSeconds = $remainingSeconds;
        $minutes = (int) ceil($remainingSeconds / 60);
        parent::__construct(
            sprintf('Még várnod kell %d percet a következő %s előtt!', $minutes, $action)
        );
    }

    public function getRemainingSeconds(): int { return $this->remainingSeconds; }
}
