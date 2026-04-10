<?php
declare(strict_types=1);

namespace Netmafia\Modules\Garage\Domain;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

use Doctrine\DBAL\Connection;

class VehicleRepository
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function getUserVehicles(int $userId): array
    {
        $qb = $this->db->createQueryBuilder();
        $qb->select('uv.*',
                    'v.name', 'v.origin_country', 'v.image_path', 'v.max_fuel', 'v.speed', 'v.safety', 'v.category',
                    'uv.tuning_engine', 'uv.tuning_tires', 'uv.tuning_exhaust', 'uv.tuning_brakes',
                    'uv.tuning_nitros', 'uv.tuning_body', 'uv.tuning_shocks', 'uv.tuning_wheels',
                    'uv.current_fuel')
           ->from('user_vehicles', 'uv')
           ->join('uv', 'vehicles', 'v', 'uv.vehicle_id = v.id')
           ->where('uv.user_id = :userId')
           ->setParameter('userId', $userId);

        $vehicles = $qb->executeQuery()->fetchAllAssociative();

        return array_map([$this, 'calculateEffectiveStats'], $vehicles);
    }

    public function getVehicleDetails(int $id): ?array
    {
         $qb = $this->db->createQueryBuilder();
        $qb->select('uv.*',
                    'v.name', 'v.origin_country', 'v.image_path', 'v.max_fuel', 'v.speed', 'v.safety', 'v.category',
                    'uv.tuning_engine', 'uv.tuning_tires', 'uv.tuning_exhaust', 'uv.tuning_brakes',
                    'uv.tuning_nitros', 'uv.tuning_body', 'uv.tuning_shocks', 'uv.tuning_wheels',
                    'uv.current_fuel')
           ->from('user_vehicles', 'uv')
           ->join('uv', 'vehicles', 'v', 'uv.vehicle_id = v.id')
           ->where('uv.id = :id')
           ->setParameter('id', $id);

        $vehicle = $qb->executeQuery()->fetchAssociative();

        return $vehicle ? $this->calculateEffectiveStats($vehicle) : null;
    }

    private function calculateEffectiveStats(array $vehicle): array
    {
        $baseSpeed = (int)$vehicle['speed'];
        $baseSafety = (int)$vehicle['safety'];

        // Multipliers (5% = 0.05, 2% = 0.02)
        $speedMultiplier = 1.0;
        $safetyMultiplier = 1.0;

        // Speed oriented parts (+5% speed)
        $speedMultiplier += ((int)$vehicle['tuning_engine'] * 0.05);
        $speedMultiplier += ((int)$vehicle['tuning_exhaust'] * 0.05);
        $speedMultiplier += ((int)$vehicle['tuning_nitros'] * 0.05);

        // Safety oriented parts (+5% safety)
        $safetyMultiplier += ((int)$vehicle['tuning_tires'] * 0.05);
        $safetyMultiplier += ((int)$vehicle['tuning_brakes'] * 0.05);
        $safetyMultiplier += ((int)$vehicle['tuning_body'] * 0.05);

        // Mixed parts (+2% both)
        $mixedLevel = (int)$vehicle['tuning_shocks'] + (int)$vehicle['tuning_wheels'];
        $speedMultiplier += ($mixedLevel * 0.02);
        $safetyMultiplier += ($mixedLevel * 0.02);

        // Security Upgrades (+2% Safety each)
        if (!empty($vehicle['has_bulletproof_glass'])) {
            $safetyMultiplier += \Netmafia\Modules\Garage\GarageConfig::UPGRADE_SAFETY_BONUS;
        }
        if (!empty($vehicle['has_steel_body'])) {
            $safetyMultiplier += \Netmafia\Modules\Garage\GarageConfig::UPGRADE_SAFETY_BONUS;
        }
        if (!empty($vehicle['has_runflat_tires'])) {
            $safetyMultiplier += \Netmafia\Modules\Garage\GarageConfig::UPGRADE_SAFETY_BONUS;
        }
        if (!empty($vehicle['has_explosion_proof_tank'])) {
            $safetyMultiplier += \Netmafia\Modules\Garage\GarageConfig::UPGRADE_SAFETY_BONUS;
        }

        // Apply multipliers
        $vehicle['speed'] = (int)($baseSpeed * $speedMultiplier);
        $vehicle['safety'] = (int)($baseSafety * $safetyMultiplier);

        // Large Tank Override
        if (!empty($vehicle['has_large_tank'])) {
            $vehicle['max_fuel'] = \Netmafia\Modules\Garage\GarageConfig::LARGE_TANK_CAPACITY;
        }

        return $vehicle;
    }

    public function getGarageCapacity(int $userId, string $countryCode): int
    {
        // Get base capacity from property (highest capacity property in that country owned by user)
        // AND sum purchased slots
        
        // 1. Get property capacity
        $sqlProperty = "
            SELECT MAX(p.garage_capacity) 
            FROM user_properties up
            JOIN properties p ON up.property_id = p.id
            WHERE up.user_id = :userId AND up.country_code = :country";
            
        $baseCapacityResult = $this->db->fetchOne($sqlProperty, ['userId' => $userId, 'country' => $countryCode]);
        
        // [FIX] 2026-02-28: Ha nincs ingatlana az országban, a kapacitás 0 (a vásárolt slotok is csak ingatlannal aktívak)
        if ($baseCapacityResult === null || $baseCapacityResult === false) {
            return 0;
        }

        $baseCapacity = (int) $baseCapacityResult;

        // 2. Get purchased slots
        $sqlSlots = "
            SELECT slots 
            FROM user_garage_slots 
            WHERE user_id = :userId AND country = :country";
            
        $__fetchResult = $this->db->fetchOne($sqlSlots, ['userId' => $userId, 'country' => $countryCode]);
        $purchasedSlots = ($__fetchResult !== false) ? (int) $__fetchResult : 0;

        return $baseCapacity + $purchasedSlots;
    }

    /**
     * Összes ország garázs kapacitásának batch lekérdezése
     * 
     * [2026-02-15] FIX: N+1 query kiváltása – korábban minden országhoz
     * 2 külön SQL futott (property + slot). Most 2 GROUP BY query-vel
     * az összes ország kapacitását egyszerre lekérjük.
     * 
     * @param int $userId
     * @param array $countries Érintett ország kódok listája
     * @return array<string, int> Ország kód => kapacitás
     */
    public function getAllGarageCapacities(int $userId, array $countries): array
    {
        if (empty($countries)) {
            return [];
        }

        // Eredmény inicializálás nullával
        $capacities = array_fill_keys($countries, 0);

        // 1. Property kapacitások batch lekérdezés (GROUP BY country)
        $placeholders = implode(',', array_fill(0, count($countries), '?'));
        $propertyRows = $this->db->fetchAllAssociative(
            "SELECT up.country_code, MAX(p.garage_capacity) as max_capacity
             FROM user_properties up
             JOIN properties p ON up.property_id = p.id
             WHERE up.user_id = ? AND up.country_code IN ($placeholders)
             GROUP BY up.country_code",
            array_merge([$userId], $countries)
        );
        
        $propertyActiveCountries = [];
        foreach ($propertyRows as $row) {
            $country = $row['country_code'];
            $capacities[$country] += (int) $row['max_capacity'];
            $propertyActiveCountries[] = $country;
        }

        // 2. Vásárolt slotok batch lekérdezés (csak ott adjuk hozzá, ahol van ingatlan)
        $slotRows = $this->db->fetchAllAssociative(
            "SELECT country, slots
             FROM user_garage_slots
             WHERE user_id = ? AND country IN ($placeholders)",
            array_merge([$userId], $countries)
        );
        
        foreach ($slotRows as $row) {
            if (in_array($row['country'], $propertyActiveCountries)) {
                $capacities[$row['country']] += (int) $row['slots'];
            }
        }

        return $capacities;
    }

    public function addGarageSlots(int $userId, string $countryCode, int $slots): void
    {
        // Upsert logic for slots
        $sql = "
            INSERT INTO user_garage_slots (user_id, country, slots) 
            VALUES (:userId, :country, :slots) 
            ON DUPLICATE KEY UPDATE slots = slots + :slots";
            
        $this->db->executeStatement($sql, [
            'userId' => $userId, 
            'country' => $countryCode, 
            'slots' => $slots
        ]);
        
        // After adding slots, recalculate vehicle locations for this country
        $this->recalculateVehicleLocations($userId, $countryCode);
    }

    /**
     * Jármű helyzetek újraszámolása az aktuális garázs kapacitás alapján
     * Az első N jármű garázsba kerül, a többi utcára.
     * Hívandó: garázs slot vásárlás, ingatlan vétel/eladás után.
     * 
     * [2026-02-15] FIX: N+1 UPDATE javítás – korábban minden járműhöz
     * külön UPDATE futott. Most egyetlen CASE WHEN query-vel frissítjük
     * az összes jármű helyzetét egyszerre.
     */
    public function recalculateVehicleLocations(int $userId, string $countryCode): void
    {
        $capacity = $this->getGarageCapacity($userId, $countryCode);
        
        // 1. Csak a GARÁZSBAN lévő járműveket kérdezzük le
        // Ha többen vannak mint a kapacitás, akkor a többletet ki kell rakni az utcára.
        // (Fordítva NEM: ha van hely, nem rakjuk be automatikusan az utcáról!)
        $vehiclesInGarage = $this->db->fetchAllAssociative(
            "SELECT id FROM user_vehicles 
             WHERE user_id = :userId AND country = :country AND location = 'garage'
             ORDER BY id DESC", // A legújabbakat (vagy legnagyobb ID) rakjuk ki először? Vagy fordítva?
                                // Általában FIFO vagy LIFO. Legyen LIFO: az utoljára bekerültek essenek ki?
                                // Vagy ID szerint növekvő? Maradjunk az eredeti logikánál: 
                                // "A többletet ki kell rakni".
                                // Ha ID DESC szerint rendezünk, akkor a legmagasabb ID-jűek (legújabbak) kerülnek ki.
            ['userId' => $userId, 'country' => $countryCode]
        );
        
        $count = count($vehiclesInGarage);
        
        if ($count <= $capacity) {
            // Nincs teendő, beleférnek.
            return;
        }

        // 2. Kikell rakni a felesleget
        // A $vehiclesInGarage tömböt megvágjuk: az első ($count - $capacity) darabot kell kirakni.
        // Mivel DESC rendezés van, a legújabbak lesznek elöl -> ezek mennek utcára.
        $vehiclesToEject = array_slice($vehiclesInGarage, 0, ($count - $capacity));
        $idsToEject = array_column($vehiclesToEject, 'id');
        
        if (!empty($idsToEject)) {
            $placeholders = implode(',', array_fill(0, count($idsToEject), '?'));
            $this->db->executeStatement(
                "UPDATE user_vehicles SET location = 'street', location_changed_at = UTC_TIMESTAMP() 
                 WHERE id IN ($placeholders)", // AND location = 'garage' (redundant but safe)
                $idsToEject
            );
        }
    }

    /**
     * Manually update a single vehicle's location
     * Used for: "Kivesz utcára" / "Betesz garázsba" actions
     */
    public function updateVehicleLocation(int $vehicleId, string $location): bool
    {
        if (!in_array($location, ['garage', 'street'])) {
            return false;
        }
        
        $affected = $this->db->executeStatement(
            "UPDATE user_vehicles 
             SET location = :location, location_changed_at = UTC_TIMESTAMP() 
             WHERE id = :id",
            ['location' => $location, 'id' => $vehicleId]
        );
        
        return $affected > 0;
    }

    /**
     * Get count of vehicles on street (theft targets) in a country
     */
    public function getStreetVehicles(string $countryCode): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT uv.*, v.name, u.username as owner_name
             FROM user_vehicles uv
             JOIN vehicles v ON uv.vehicle_id = v.id
             JOIN users u ON uv.user_id = u.id
             WHERE uv.location = 'street' AND uv.country = :country",
            ['country' => $countryCode]
        );
    }

    /**
     * Get property-based garage capacities for all countries the user has properties in
     */
    public function getPropertyGarages(int $userId): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT up.country_code, MAX(p.garage_capacity) as capacity
             FROM user_properties up
             JOIN properties p ON up.property_id = p.id
             WHERE up.user_id = :userId AND p.garage_capacity > 0
             GROUP BY up.country_code",
            ['userId' => $userId]
        );
    }

    /**
     * Get all purchased garage slots for user (all countries)
     */
    public function getPurchasedSlots(int $userId): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT country, slots FROM user_garage_slots WHERE user_id = :userId AND slots > 0",
            ['userId' => $userId]
        );
    }

    /**
     * Get purchased slots for specific country
     */
    public function getPurchasedSlotsForCountry(int $userId, string $countryCode): int
    {
        return (int) $this->db->fetchOne(
            "SELECT slots FROM user_garage_slots WHERE user_id = :userId AND country = :country",
            ['userId' => $userId, 'country' => $countryCode]
        );
    }

    /**
     * Count vehicles currently in garage in a specific country
     */
    public function countVehiclesInGarage(int $userId, string $countryCode): int
    {
        return (int) $this->db->fetchOne(
            "SELECT COUNT(id) FROM user_vehicles 
             WHERE user_id = :userId AND country = :country AND location = 'garage'",
            ['userId' => $userId, 'country' => $countryCode]
        );
    }

    /**
     * Sell garage slots (removes slots, returns money based on 80% of original price)
     */
    public function sellGarageSlots(int $userId, string $countryCode, int $slots): int
    {
        $this->db->beginTransaction();
        try {
            $currentSlotsRow = $this->db->fetchOne(
                "SELECT slots FROM user_garage_slots WHERE user_id = :userId AND country = :country FOR UPDATE",
                ['userId' => $userId, 'country' => $countryCode]
            );

            if ($currentSlotsRow === false) {
                throw new GameException("Nincsenek eladható slotjaid ebben az országban!");
            }
            
            $currentSlots = (int)$currentSlotsRow;
        
        if ($currentSlots < $slots) {
            throw new GameException("Nincs elég eladható slot!");
        }
        
        $newSlots = $currentSlots - $slots;
        
        if ($newSlots === 0) {
            // Delete the record
            $this->db->executeStatement(
                "DELETE FROM user_garage_slots WHERE user_id = :userId AND country = :country",
                ['userId' => $userId, 'country' => $countryCode]
            );
            
            // [FIX] Also clear purchase history so packages can be bought again
            $this->db->executeStatement(
                "DELETE FROM user_garage_purchases WHERE user_id = :userId AND country_code = :country",
                 ['userId' => $userId, 'country' => $countryCode]
            );
        } else {
            // Update
            $this->db->executeStatement(
                "UPDATE user_garage_slots SET slots = :slots WHERE user_id = :userId AND country = :country",
                ['slots' => $newSlots, 'userId' => $userId, 'country' => $countryCode]
            );
        }
        
        // Recalculate vehicle locations after selling slots
        $this->recalculateVehicleLocations($userId, $countryCode);
        
        $this->db->commit();
        return $slots;
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
    /**
     * Get list of purchased package sizes for a user in a country
     */
    public function getPurchasedPackages(int $userId, string $countryCode): array
    {
        return $this->db->fetchFirstColumn(
            "SELECT package_size FROM user_garage_purchases WHERE user_id = :userId AND country_code = :country",
            ['userId' => $userId, 'country' => $countryCode]
        );
    }

    /**
     * Get list of purchased package sizes for a user in a country FOR UPDATE (Race Condition fix)
     */
    public function getPurchasedPackagesForUpdate(int $userId, string $countryCode): array
    {
        return $this->db->fetchFirstColumn(
            "SELECT package_size FROM user_garage_purchases WHERE user_id = :userId AND country_code = :country FOR UPDATE",
            ['userId' => $userId, 'country' => $countryCode]
        );
    }

    /**
     * Record a package purchase
     */
    public function recordPackagePurchase(int $userId, string $countryCode, int $packageSize): void
    {
        $this->db->executeStatement(
            "INSERT INTO user_garage_purchases (user_id, country_code, package_size) VALUES (:userId, :country, :size)",
            ['userId' => $userId, 'country' => $countryCode, 'size' => $packageSize]
        );
    }

    /**
     * Set a vehicle as default for the user.
     * Unsets any existing default vehicle first.
     */
    public function setDefaultVehicle(int $userId, int $vehicleId): void
    {
        $this->db->beginTransaction();
        try {
            // 1. Reset current default(s)
            $this->db->executeStatement(
                "UPDATE user_vehicles SET is_default = 0 WHERE user_id = :userId",
                ['userId' => $userId]
            );

            // 2. Set new default
            $this->db->executeStatement(
                "UPDATE user_vehicles SET is_default = 1 WHERE id = :vehicleId AND user_id = :userId",
                ['vehicleId' => $vehicleId, 'userId' => $userId]
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Unset a vehicle as default (put away).
     */
    public function unsetDefaultVehicle(int $userId, int $vehicleId): void
    {
        $this->db->executeStatement(
            "UPDATE user_vehicles SET is_default = 0 WHERE id = :vehicleId AND user_id = :userId",
            ['vehicleId' => $vehicleId, 'userId' => $userId]
        );
    }
    public function deleteVehicle(int $userId, int $vehicleId): void
    {
        $this->db->executeStatement(
            "DELETE FROM user_vehicles WHERE id = :vehicleId AND user_id = :userId",
            ['vehicleId' => $vehicleId, 'userId' => $userId]
        );
    }

    public function batchDeleteVehicles(int $userId, array $vehicleIds): void
    {
        if (empty($vehicleIds)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));
        $this->db->executeStatement(
            "DELETE FROM user_vehicles WHERE user_id = ? AND id IN ($placeholders)",
            array_merge([$userId], $vehicleIds)
        );
    }

    public function buySecurityUpgrade(int $userId, int $vehicleId, string $column): void
    {
        // Whitelist allowed columns to prevent SQL injection (though key is checked in Action)
        $allowedColumns = [
            'has_bulletproof_glass', 
            'has_steel_body', 
            'has_runflat_tires', 
            'has_explosion_proof_tank', 
            'has_large_tank'
        ];

        if (!in_array($column, $allowedColumns)) {
            throw new InvalidInputException("Invalid upgrade column: $column");
        }

        $this->db->update(
            'user_vehicles',
            [$column => 1],
            ['id' => $vehicleId, 'user_id' => $userId]
        );
    }

    public function repairVehicle(int $userId, int $vehicleId): void
    {
        $this->db->executeStatement(
            "UPDATE user_vehicles SET damage_percent = 0 WHERE id = :vehicleId AND user_id = :userId",
            ['vehicleId' => $vehicleId, 'userId' => $userId]
        );
    }
}
