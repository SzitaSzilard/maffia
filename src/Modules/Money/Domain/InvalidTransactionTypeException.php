<?php
declare(strict_types=1);

namespace Netmafia\Modules\Money\Domain;

/**
 * Érvénytelen tranzakció típus
 */
class InvalidTransactionTypeException extends \InvalidArgumentException
{
    private string $invalidType;
    private array $allowedTypes;

    public function __construct(string $invalidType, array $allowedTypes)
    {
        $this->invalidType = $invalidType;
        $this->allowedTypes = $allowedTypes;

        parent::__construct(
            sprintf(
                'Érvénytelen tranzakció típus: %s. Engedélyezett: %s',
                $invalidType,
                implode(', ', $allowedTypes)
            ),
            2001 // Error code for invalid type
        );
    }

    public function getInvalidType(): string
    {
        return $this->invalidType;
    }

    public function getAllowedTypes(): array
    {
        return $this->allowedTypes;
    }
}
