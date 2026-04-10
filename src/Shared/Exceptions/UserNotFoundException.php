<?php
declare(strict_types=1);

namespace Netmafia\Shared\Exceptions;

/**
 * UserNotFoundException — Felhasználó nem található
 */
class UserNotFoundException extends GameException
{
    private int $userId;

    public function __construct(int $userId)
    {
        $this->userId = $userId;
        parent::__construct(sprintf('Felhasználó nem található (ID: %d)', $userId));
    }

    public function getUserId(): int { return $this->userId; }
}
