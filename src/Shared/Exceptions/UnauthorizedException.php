<?php
declare(strict_types=1);

namespace Netmafia\Shared\Exceptions;

/**
 * UnauthorizedException — Nincs jogosultság a művelethez
 */
class UnauthorizedException extends GameException
{
    public function __construct(string $message = 'Nincs jogosultságod ehhez a művelethez!')
    {
        parent::__construct($message);
    }
}
