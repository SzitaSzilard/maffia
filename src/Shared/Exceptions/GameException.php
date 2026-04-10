<?php
declare(strict_types=1);

namespace Netmafia\Shared\Exceptions;

/**
 * GameException — Alap exception minden játéklogikai hibához
 * 
 * Minden custom exception ebből származik.
 * Az Action-ök ezt kapják el és konvertálják flash message-re.
 */
class GameException extends \RuntimeException
{
    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
