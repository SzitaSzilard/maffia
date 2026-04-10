<?php
declare(strict_types=1);

namespace Netmafia\Modules\Money\Domain;

/**
 * User nem található
 */
class UserNotFoundException extends \RuntimeException
{
    private int $userId;

    public function __construct(int $userId)
    {
        $this->userId = $userId;

        parent::__construct(
            sprintf('User nem található: #%d', $userId),
            3001 // Error code for user not found
        );
    }

    public function getUserId(): int
    {
        return $this->userId;
    }
}
