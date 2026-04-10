<?php
declare(strict_types=1);

namespace Netmafia\Shared\Domain\ValueObjects;

/**
 * BuildingType - Épület típusok központi definíciója
 * 
 * Magic stringek elkerülése - minden épület típus konstansként definiálva.
 */
class BuildingType
{
    // Főbb épület típusok
    public const RESTAURANT = 'restaurant';
    public const HOSPITAL = 'hospital';
    public const AMMO_FACTORY = 'ammo_factory';
    public const GAS_STATION = 'gas_station';
    public const HIGHWAY = 'highway';
    public const LOTTERY = 'lottery';
    public const AIRPORT = 'airport';
    
    /**
     * Összes valid épület típus
     * 
     * [2025-12-29 14:43:20] Csak a kész épületek szerepelnek
     * GAS_STATION kivéve amíg nincs implementálva.
     * 
     * @return array<string>
     */
    public static function getAll(): array
    {
        return [
            self::RESTAURANT,
            self::HOSPITAL,
            self::AMMO_FACTORY,
            self::GAS_STATION,
            self::HIGHWAY,
            self::LOTTERY,
            self::AIRPORT,
        ];
    }
    
    /**
     * Épület típus validálása
     * 
     * @param string $type Ellenőrizendő típus
     * @return bool true ha valid, false egyébként
     */
    public static function isValid(string $type): bool
    {
        return in_array($type, self::getAll(), true);
    }
}
