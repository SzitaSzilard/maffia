<?php
declare(strict_types=1);

namespace Netmafia\Shared\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Money Value Object
 * 
 * Értékobjektum a pénzösszegek típusbiztos kezelésére.
 * Garantálja, hogy a pénz sosem lehet negatív és az összehasonlítások
 * mindig helyes módon történnek.
 * 
 * @immutable
 */
final class Money
{
    private int $amount;

    /**
     * @param int $amount Összeg (centben vagy egész számként)
     * @throws InvalidArgumentException Ha az összeg negatív
     */
    private function __construct(int $amount)
    {
        if ($amount < 0) {
            throw new InvalidArgumentException(
                sprintf('A pénzösszeg nem lehet negatív. Kapott érték: %d', $amount)
            );
        }
        
        $this->amount = $amount;
    }

    /**
     * Factory metódus - Money létrehozása összegből
     */
    public static function of(int $amount): self
    {
        return new self($amount);
    }

    /**
     * Factory metódus - Nulla összeg
     */
    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Factory metódus - String-ből (pl. form inputból)
     */
    public static function fromString(string $amount): self
    {
        $cleaned = preg_replace('/[^0-9]/', '', $amount);
        return new self((int) $cleaned);
    }

    /**
     * Összeg lekérdezése
     */
    public function amount(): int
    {
        return $this->amount;
    }

    /**
     * Összeadás - új Money objektumot ad vissza
     */
    public function add(Money $other): self
    {
        return new self($this->amount + $other->amount);
    }

    /**
     * Kivonás - új Money objektumot ad vissza
     * 
     * @throws InvalidArgumentException Ha az eredmény negatív lenne
     */
    public function subtract(Money $other): self
    {
        $result = $this->amount - $other->amount;
        
        if ($result < 0) {
            throw new InvalidArgumentException('Nincs elegendő összege.');
        }
        
        return new self($result);
    }

    /**
     * Szorzás - új Money objektumot ad vissza
     */
    public function multiply(int $multiplier): self
    {
        if ($multiplier < 0) {
            throw new InvalidArgumentException('A szorzó nem lehet negatív');
        }
        
        return new self($this->amount * $multiplier);
    }

    /**
     * Százalék számítása
     */
    public function percentage(int $percent): self
    {
        return new self((int) floor($this->amount * $percent / 100));
    }

    /**
     * Van-e elég pénz?
     */
    public function hasEnough(Money $required): bool
    {
        return $this->amount >= $required->amount;
    }

    /**
     * Nulla-e?
     */
    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    /**
     * Nagyobb-e mint a másik?
     */
    public function isGreaterThan(Money $other): bool
    {
        return $this->amount > $other->amount;
    }

    /**
     * Kisebb-e mint a másik?
     */
    public function isLessThan(Money $other): bool
    {
        return $this->amount < $other->amount;
    }

    /**
     * Egyenlő-e a másikkal?
     */
    public function equals(Money $other): bool
    {
        return $this->amount === $other->amount;
    }

    /**
     * Formázott string (pl. "$1,234,567")
     */
    public function formatted(): string
    {
        return '$' . number_format($this->amount, 0, '.', ',');
    }

    /**
     * String reprezentáció
     */
    public function __toString(): string
    {
        return $this->formatted();
    }

    /**
     * JSON serializáció
     */
    public function jsonSerialize(): int
    {
        return $this->amount;
    }
}
