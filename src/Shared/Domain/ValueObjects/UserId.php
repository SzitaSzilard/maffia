<?php
declare(strict_types=1);

namespace Netmafia\Shared\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * UserId Value Object
 * 
 * Értékobjektum a felhasználó azonosítók típusbiztos kezelésére.
 * Garantálja, hogy az ID mindig pozitív egész szám.
 * 
 * @immutable
 */
final class UserId
{
    private int $id;

    /**
     * @param int $id Felhasználó azonosító
     * @throws InvalidArgumentException Ha az ID nem pozitív
     */
    private function __construct(int $id)
    {
        if ($id <= 0) {
            throw new InvalidArgumentException(
                sprintf('A felhasználó azonosító pozitív szám kell legyen. Kapott érték: %d', $id)
            );
        }
        
        $this->id = $id;
    }

    /**
     * Factory metódus - UserId létrehozása
     */
    public static function of(int $id): self
    {
        return new self($id);
    }

    /**
     * Factory metódus - String-ből (pl. session-ből)
     */
    public static function fromString(string $id): self
    {
        $parsed = (int) $id;
        
        if ($parsed <= 0) {
            throw new InvalidArgumentException(
                sprintf('Érvénytelen felhasználó azonosító: "%s"', $id)
            );
        }
        
        return new self($parsed);
    }

    /**
     * Factory metódus - Nullable session értékből
     * 
     * @return self|null
     */
    public static function fromNullable(mixed $id): ?self
    {
        if ($id === null) {
            return null;
        }
        
        $parsed = (int) $id;
        
        if ($parsed <= 0) {
            return null;
        }
        
        return new self($parsed);
    }

    /**
     * ID lekérdezése
     */
    public function id(): int
    {
        return $this->id;
    }

    /**
     * Alias az id()-hoz (Doctrine kompatibilitás)
     */
    public function value(): int
    {
        return $this->id;
    }

    /**
     * Egyenlőség vizsgálat
     */
    public function equals(UserId $other): bool
    {
        return $this->id === $other->id;
    }

    /**
     * String reprezentáció
     */
    public function __toString(): string
    {
        return (string) $this->id;
    }

    /**
     * JSON serializáció
     */
    public function jsonSerialize(): int
    {
        return $this->id;
    }
}
