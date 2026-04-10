<?php
declare(strict_types=1);

namespace Netmafia\Shared\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Percentage Value Object
 * 
 * Értékobjektum százalékos értékekhez (0-100).
 * Használható: energia, élet, részegség szint, stb.
 * 
 * @immutable
 */
final class Percentage
{
    private int $value;

    /**
     * @param int $value Százalékos érték (0-100)
     * @throws InvalidArgumentException Ha az érték tartományon kívüli
     */
    private function __construct(int $value)
    {
        if ($value < 0 || $value > 100) {
            throw new InvalidArgumentException(
                sprintf('A százalékos érték 0 és 100 között kell legyen. Kapott érték: %d', $value)
            );
        }
        
        $this->value = $value;
    }

    /**
     * Factory metódus
     */
    public static function of(int $value): self
    {
        return new self($value);
    }

    /**
     * Teljes (100%)
     */
    public static function full(): self
    {
        return new self(100);
    }

    /**
     * Üres (0%)
     */
    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Biztonságos létrehozás (határok közé szorítva)
     */
    public static function clamped(int $value): self
    {
        return new self(max(0, min(100, $value)));
    }

    /**
     * Érték lekérdezése
     */
    public function value(): int
    {
        return $this->value;
    }

    /**
     * Növelés (maximum 100)
     */
    public function increase(int $amount): self
    {
        return new self(min(100, $this->value + $amount));
    }

    /**
     * Csökkentés (minimum 0)
     */
    public function decrease(int $amount): self
    {
        return new self(max(0, $this->value - $amount));
    }

    /**
     * Teljes-e (100%)?
     */
    public function isFull(): bool
    {
        return $this->value === 100;
    }

    /**
     * Üres-e (0%)?
     */
    public function isEmpty(): bool
    {
        return $this->value === 0;
    }

    /**
     * Alacsony-e (< 20%)?
     */
    public function isLow(): bool
    {
        return $this->value < 20;
    }

    /**
     * Kritikus-e (< 10%)?
     */
    public function isCritical(): bool
    {
        return $this->value < 10;
    }

    /**
     * Egyenlőség
     */
    public function equals(Percentage $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Nagyobb-e?
     */
    public function isGreaterThan(Percentage $other): bool
    {
        return $this->value > $other->value;
    }

    /**
     * Formázott kijelzés
     */
    public function formatted(): string
    {
        return $this->value . '%';
    }

    public function __toString(): string
    {
        return $this->formatted();
    }

    public function jsonSerialize(): int
    {
        return $this->value;
    }
}
