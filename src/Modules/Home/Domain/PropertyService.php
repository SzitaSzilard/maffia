<?php
declare(strict_types=1);

namespace Netmafia\Modules\Home\Domain;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

use Doctrine\DBAL\Connection;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Modules\Money\Domain\MoneyService;

/**
 * PropertyService - Ingatlanok kezelése
 * 
 * [2025-12-29 15:46:00] Ingatlan vásárlás/eladás, garázs, alvás regeneráció
 */
class PropertyService
{
    private Connection $db;
    private MoneyService $moneyService;
    private \Netmafia\Modules\Garage\Domain\VehicleRepository $vehicleRepository;
    
    public function __construct(
        Connection $db, 
        MoneyService $moneyService,
        \Netmafia\Modules\Garage\Domain\VehicleRepository $vehicleRepository
    ) {
        $this->db = $db;
        $this->moneyService = $moneyService;
        $this->vehicleRepository = $vehicleRepository;
    }

    /**
     * Összes elérhető ingatlan
     */
    public function getAvailableProperties(): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT id, name, country_restriction, garage_capacity, sleep_health_regen_percent, sleep_energy_regen_percent, price, image_path FROM properties ORDER BY price DESC"
        );
    }
    
    /**
     * User ingatlanjai országonként
     */
    public function getUserProperties(int $userId): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT up.id, up.user_id, up.property_id, up.country_code, up.purchase_price, up.purchased_at,
                    p.name, p.garage_capacity, p.sleep_health_regen_percent, 
                    p.sleep_energy_regen_percent, p.image_path
             FROM user_properties up
             JOIN properties p ON p.id = up.property_id
             WHERE up.user_id = ?
             ORDER BY up.purchased_at DESC",
            [$userId]
        );
    }

    /**
     * Vásárolhat-e user adott országban
     */
    public function canPurchaseInCountry(int $userId, string $countryCode): bool
    {
        return !$this->hasPropertyInCountry($userId, $countryCode);
    }

    /**
     * Van-e ingatlana az adott országban?
     */
    public function hasPropertyInCountry(int $userId, string $countryCode): bool
    {
        $count = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM user_properties WHERE user_id = ? AND country_code = ?",
            [$userId, $countryCode]
        );
        return $count > 0;
    }
    
    /** // [OMITTED START] Ingatlan vásárlás [OMITTED END] */
    public function purchaseProperty(UserId $userId, int $propertyId, string $userCountryCode): void
    {
        // Üres country_code validáció
        if (empty(trim($userCountryCode))) {
            throw new GameException('Érvénytelen ország kód!');
        }
        
        $this->db->beginTransaction();
        
        try {
            // Ingatlan adatok lekérdezése (nem kell FOR UPDATE, mert ez a katalógus tábla)
            $property = $this->db->fetchAssociative(
                "SELECT id, name, country_restriction, garage_capacity, sleep_health_regen_percent, sleep_energy_regen_percent, price, image_path FROM properties WHERE id = ?",
                [$propertyId]
            );
            
            if (!$property) {
                throw new GameException('Az ingatlan nem létezik!');
            }
            
            // 2. Ország check (ha van korlátozás)
            if ($property['country_restriction'] !== null && $property['country_restriction'] !== $userCountryCode) {
                throw new GameException('Ez az ingatlan csak ' . $property['country_restriction'] . ' országban vásárolható meg!');
            }
            
            // [2026-02-15] FIX: FOR UPDATE lock a user_properties táblán
            $existingProperty = $this->db->fetchOne(
                "SELECT COUNT(*) FROM user_properties WHERE user_id = ? AND country_code = ? FOR UPDATE",
                [$userId->id(), $userCountryCode]
            );
            
            if ((int)$existingProperty > 0) {
                throw new GameException('Már van ingatlanod ebben az országban!');
            }
            
            // 4. Pénz levonás
            $this->moneyService->spendMoney(
                $userId,
                (int)$property['price'],
                'purchase',
                "Ingatlan vásárlás: {$property['name']} ({$userCountryCode})",
                'property',
                $propertyId
            );
            
            // Ingatlan hozzáadása
            $this->db->insert('user_properties', [
                'user_id' => $userId->id(),
                'property_id' => $propertyId,
                'country_code' => $userCountryCode,
                'purchase_price' => $property['price']
            ]);

            // [FIX] Járművek helyzetének újraszámolása (ha vett garázst, bekerülhetnek autók)
            $this->vehicleRepository->recalculateVehicleLocations((int)$userId->id(), $userCountryCode);
            
            $this->db->commit();
            
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
    
    /**
     * Ingatlan eladás (60% visszakapás)
     * 
     * [2025-12-29 15:46:00] Transaction + FOR UPDATE + 60% ár számítás
     */
    public function sellProperty(UserId $userId, int $userPropertyId): void
    {
        $this->db->beginTransaction();
        
        try {
            // FOR UPDATE lock
            $userProperty = $this->db->fetchAssociative(
                "SELECT id, user_id, property_id, country_code, purchase_price FROM user_properties WHERE id = ? AND user_id = ? FOR UPDATE",
                [$userPropertyId, $userId->id()]
            );
            
            if (!$userProperty) {
                throw new GameException('Nincs ilyen ingatlanod!');
            }
            
            $countryCode = $userProperty['country_code'];
            
            // Eladási ár (60%)
            $sellPrice = (int) ($userProperty['purchase_price'] * \Netmafia\Modules\Home\HomeConfig::PROPERTY_SELL_RATIO);
            
            // 3. Pénz hozzáadása (60%)
            $this->moneyService->addMoney(
                $userId,
                $sellPrice,
                'sell',
                "Ingatlan eladás: 60% visszakapás (\${$userProperty['purchase_price']} → \${$sellPrice})",
                'property',
                $userPropertyId
            );
            
            // Ingatlan törlése
            $this->db->delete('user_properties', ['id' => $userPropertyId]);

            // [FIX] Járművek helyzetének újraszámolása (kapacitás csökkent -> utcára kerülhetnek)
            $this->vehicleRepository->recalculateVehicleLocations((int)$userId->id(), $countryCode);
            
            $this->db->commit();
            
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
    
    /**
     * Összes garázs kapacitás (később Járművek modul használja)
     */
    public function getTotalGarageCapacity(int $userId): int
    {
        $capacity = $this->db->fetchOne(
            "SELECT SUM(p.garage_capacity) 
             FROM user_properties up
             JOIN properties p ON p.id = up.property_id
             WHERE up.user_id = ?",
            [$userId]
        );
        
        return (int) ($capacity ?? 0);
    }
    
    /**
     * Alvás regeneráció - ország alapú (ahol tartózkodik)
     * 
     * [2025-12-29 15:46:00] B) verzió: ahol a user van (country_code alapján)
     */
    public function getSleepRegenerationForCountry(int $userId, string $countryCode): array
    {
        $regen = $this->db->fetchAssociative(
            "SELECT p.sleep_health_regen_percent, p.sleep_energy_regen_percent
             FROM user_properties up
             JOIN properties p ON p.id = up.property_id
             WHERE up.user_id = ? AND up.country_code = ?
             LIMIT 1",
            [$userId, $countryCode]
        );
        
        if (!$regen) {
            return ['health_regen_percent' => 0, 'energy_regen_percent' => 0];
        }
        
        return [
            'health_regen_percent' => (int) $regen['sleep_health_regen_percent'],
            'energy_regen_percent' => (int) $regen['sleep_energy_regen_percent']
        ];
    }
}
