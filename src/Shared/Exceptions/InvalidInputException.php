<?php
declare(strict_types=1);

namespace Netmafia\Shared\Exceptions;

/**
 * InvalidInputException — Érvénytelen felhasználói bemenet
 */
class InvalidInputException extends GameException
{
    private string $field;

    public function __construct(string $message, string $field = '')
    {
        $this->field = $field;
        parent::__construct($message);
    }

    public function getField(): string { return $this->field; }
}
