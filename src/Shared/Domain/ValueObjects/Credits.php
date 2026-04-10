<?php
declare(strict_types=1);

namespace Netmafia\Shared\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Credits Value Object
 * 
 * Prémium kredit kezelése - valódi pénzért vásárolt érték,
 * ezért extra szigorú validációval.
 * 
 * @immutable
 */
final class Credits
{
    private int $amount;

    /**
     * @param int $amount Kreditek száma
     * @throws InvalidArgumentException Ha az összeg negatív
     */
    private function __construct(int $amount)
    {
        if ($amount < 0) {
            throw new InvalidArgumentException(
                sprintf('A kredit összeg nem lehet negatív. Kapott érték: %d', $amount)
            );
        }
        
        $this->amount = $amount;
    }

    /**
     * Factory metódus - Kredit létrehozása összegből
     */
    public static function of(int $amount): self
    {
        return new self($amount);
    }

    /**
     * Nulla kredit
     */
    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Kredit mennyiség lekérdezése
     */
    public function amount(): int
    {
        return $this->amount;
    }

    /**
     * Hozzáadás (pl. vásárlás, admin jóváírás)
     */
    public function add(Credits $other): self
    {
        return new self($this->amount + $other->amount);
    }

    /**
     * Kivonás (pl. költés)
     * 
     * @throws InvalidArgumentException Ha nincs elég kredit
     */
    public function subtract(Credits $other): self
    {
        $result = $this->amount - $other->amount;
        
        if ($result < 0) {
            throw new InvalidArgumentException(
                sprintf(
                    'Nincs elég kredit! Egyenleg: %d, Szükséges: %d',
                    $this->amount,
                    $other->amount
                )
            );
        }
        
        return new self($result);
    }

    /**
     * Van-e elég kredit?
     */
    public function hasEnough(Credits $required): bool
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
     * Egyenlőség vizsgálat
     */
    public function equals(Credits $other): bool
    {
        return $this->amount === $other->amount;
    }

    /**
     * Formázott kijelzés
     */
    public function formatted(): string
    {
        return number_format($this->amount, 0, '.', ',') . ' kredit';
    }

    public function __toString(): string
    {
        return (string) $this->amount;
    }

    public function jsonSerialize(): int
    {
        return $this->amount;
    }
}
