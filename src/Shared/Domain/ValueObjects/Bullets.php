<?php
declare(strict_types=1);

namespace Netmafia\Shared\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Bullets Value Object
 * 
 * Értékobjektum a töltények típusbiztos kezelésére.
 * Hasonló a Money-hoz, de töltény-specifikus logikával.
 * 
 * @immutable
 */
final class Bullets
{
    private int $count;

    /**
     * @param int $count Töltények száma
     * @throws InvalidArgumentException Ha a szám negatív
     */
    private function __construct(int $count)
    {
        if ($count < 0) {
            throw new InvalidArgumentException(
                sprintf('A töltények száma nem lehet negatív. Kapott érték: %d', $count)
            );
        }
        
        $this->count = $count;
    }

    /**
     * Factory metódus
     */
    public static function of(int $count): self
    {
        return new self($count);
    }

    /**
     * Nulla töltény
     */
    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Töltények száma
     */
    public function count(): int
    {
        return $this->count;
    }

    /**
     * Hozzáadás
     */
    public function add(Bullets $other): self
    {
        return new self($this->count + $other->count);
    }

    /**
     * Kivonás (pl. lövés)
     * 
     * @throws InvalidArgumentException Ha nincs elég töltény
     */
    public function subtract(Bullets $other): self
    {
        $result = $this->count - $other->count;
        
        if ($result < 0) {
            throw new InvalidArgumentException(
                sprintf(
                    'Nincs elég töltény! Jelenlegi: %d, Szükséges: %d',
                    $this->count,
                    $other->count
                )
            );
        }
        
        return new self($result);
    }

    /**
     * Van-e elég töltény?
     */
    public function hasEnough(Bullets $required): bool
    {
        return $this->count >= $required->count;
    }

    /**
     * Üres-e?
     */
    public function isEmpty(): bool
    {
        return $this->count === 0;
    }

    /**
     * Egyenlőség
     */
    public function equals(Bullets $other): bool
    {
        return $this->count === $other->count;
    }

    /**
     * Formázott kijelzés
     */
    public function formatted(): string
    {
        return number_format($this->count, 0, '.', ',');
    }

    public function __toString(): string
    {
        return $this->formatted();
    }

    public function jsonSerialize(): int
    {
        return $this->count;
    }
}
