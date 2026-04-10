<?php
declare(strict_types=1);

namespace Netmafia\Modules\Garage\Domain;

use Doctrine\DBAL\Connection;
use Exception;
use Netmafia\Infrastructure\AuditLogger;
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

class GarageService
{
    private Connection $db;
    private VehicleRepository $repository;
    private \Netmafia\Infrastructure\AuditLogger $logger;
    private \Netmafia\Modules\Money\Domain\MoneyService $moneyService;
    private \Netmafia\Modules\Home\Domain\PropertyService $propertyService;

    public function __construct(
        Connection $db, 
        VehicleRepository $repository, 
        \Netmafia\Infrastructure\AuditLogger $logger,
        \Netmafia\Modules\Money\Domain\MoneyService $moneyService,
        \Netmafia\Modules\Home\Domain\PropertyService $propertyService
    ) {
        $this->db = $db;
        $this->repository = $repository;
        $this->logger = $logger;
        $this->moneyService = $moneyService;
        $this->propertyService = $propertyService;
    }

    public function buyGarageSlots(int $userId, string $countryCode, int $slots, int $cost): void
    {
        // 1. Input Validation
        if ($userId <= 0) throw new InvalidInputException("Értéktelen userId: $userId");
        if (empty($countryCode)) throw new InvalidInputException("Értéktelen countryCode");

        // Whitelist: csak engedélyezett országkód (§10.1)
        if (!in_array($countryCode, \Netmafia\Modules\Garage\GarageConfig::ALLOWED_COUNTRIES, true)) {
            throw new InvalidInputException("Nem engedélyezett ország: $countryCode");
        }

        // Validate package size
        if (!in_array($slots, \Netmafia\Modules\Garage\GarageConfig::EXPANSION_PACKAGES)) {
            throw new InvalidInputException("Értéktelen csomag méret: $slots");
        }

        // Validate cost (backend calculation to be sure)
        $expectedCost = $slots * \Netmafia\Modules\Garage\GarageConfig::SLOT_PRICE_PER_UNIT;
        if ($cost !== $expectedCost) {
            throw new InvalidInputException("Ár hiba! (Várt: $expectedCost, Kapott: $cost)");
        }

        $this->db->beginTransaction();
        try {
            // [FIX §3.8] Property check tranzakción belül FOR UPDATE-tal, hogy az ingatlan ne adható el közben
            $hasProperty = $this->db->fetchOne(
                "SELECT 1 FROM user_properties WHERE user_id = ? AND country_code = ? FOR UPDATE",
                [$userId, $countryCode]
            );
            if (!$hasProperty) {
                throw new \Netmafia\Shared\Exceptions\GameException("Csak akkor bővítheted a garázst, ha van ingatlanod ebben az országban!");
            }

            // [FIX §9.2] Dupla lekérdezés kiküszöbölve: csak a for-update változat kell
            $currentPackages = $this->repository->getPurchasedPackagesForUpdate($userId, $countryCode);
            if (in_array($slots, $currentPackages)) {
                throw new \Netmafia\Shared\Exceptions\GameException("Ezt a csomagot már megvásároltad ebben az országban!");
            }

            // Deduct money via MoneyService
            $this->moneyService->spendMoney(
                UserId::of($userId),
                $cost,
                'purchase',
                "Garázs bővítés: $slots hely ($countryCode)",
                'garage_slots',
                null
            );

            // Add slots
            $this->repository->addGarageSlots($userId, $countryCode, $slots);
            
            // Record purchase
            $this->repository->recordPackagePurchase($userId, $countryCode, $slots);

            $this->db->commit();

            // [FIX §9.2] Log commit után, hogy ne maradjon fals log ha commit meghal
            $this->logger->log('garage_buy_success', $userId, [
                'slots' => $slots,
                'cost' => $cost,
                'country' => $countryCode
            ]);

        } catch (\Netmafia\Modules\Money\Domain\InsufficientBalanceException $e) {
            // [FIX §5.1] Típusos exception catch, nem string-összehasonlítás
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e; // Nincs szükség logolni — normál játékmenet
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $this->logger->log('garage_buy_error', $userId, ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    // ... (calculateSellPrice and moveVehicle methods remain unchanged) ...

    /** // [OMITTED START] Eladási ár kalkuláció központosítva [OMITTED END] */
    public function calculateSellPrice(int $slots): int
    {
        $basePrice = $slots * \Netmafia\Modules\Garage\GarageConfig::SLOT_PRICE_PER_UNIT;
        return (int) ($basePrice * \Netmafia\Modules\Garage\GarageConfig::SELL_PRICE_RATIO);
    }
    
    /** // [OMITTED START] Jármű mozgatása [OMITTED END] */
    public function moveVehicle(int $userId, int $vehicleId, string $targetLocation): void
    {
        $this->db->beginTransaction();
        try {
            // [FIX] 2026-03-19: Pesszimista lock magára a járműre mozgatás előtt, hogy parallel eladás/zúzás ne tudjon bekavarni
            $vehicleRow = $this->db->fetchAssociative(
                "SELECT location, country, user_id FROM user_vehicles WHERE id = ? AND user_id = ? FOR UPDATE",
                [$vehicleId, $userId]
            );

            if (!$vehicleRow) {
                throw new GameException("Jármű nem található vagy nem a tiéd.");
            }

            $currentLocation = $vehicleRow['location'] ?? 'street';
            
            // Ha már ott van, nincs teendő (korai visszatérésnél is kell commit vagy rollback, rollback gyorsabb)
            if ($currentLocation === $targetLocation) {
                $this->db->rollBack();
                return;
            }

            if ($targetLocation === 'garage') {
                $country = $vehicleRow['country'];
                
                // [2026-02-15] FIX: Race Condition védelem - Lockoljuk a user ingatlanjait az országban
                // Így egyszerre csak egy kérés futhat a garázsba helyezésnél.
                $this->db->executeQuery(
                    "SELECT id FROM user_properties WHERE user_id = ? AND country_code = ? FOR UPDATE",
                    [$userId, $country]
                );

                // Kapacitás és jelenlegi telítettség lekérése
                // [N+1 optimization]: Csak a szükséges count-ot kérjük le
                $capacity = $this->repository->getGarageCapacity($userId, $country);
                $currentCount = $this->repository->countVehiclesInGarage($userId, $country);
                
                if ($currentCount >= $capacity) {
                    throw new GameException("Betelt a garázsod ebben az országban! ($currentCount / $capacity)");
                }
                
                $this->repository->updateVehicleLocation($vehicleId, 'garage');
            } elseif ($targetLocation === 'street') {
                $this->repository->updateVehicleLocation($vehicleId, 'street');
            } else {
                 throw new GameException("Érvénytelen célállomás: $targetLocation");
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** // [OMITTED START] Garázs főoldal (List) adatainak összeállítása [OMITTED END] */
    public function getGarageOverview(int $userId, string $currentCountry, string $sortBy = 'id', string $sortDir = 'asc'): array
    {
        $vehicles = $this->repository->getUserVehicles($userId);
        
        // Sorting in PHP because effective stats are calculated on the fly
        $allowedSortColumns = ['id', 'speed', 'safety', 'tuning_percent'];
        if (in_array($sortBy, $allowedSortColumns)) {
            usort($vehicles, function($a, $b) use ($sortBy, $sortDir) {
                // Determine numerical value correctly, treat nulls as 0
                $valA = (float)($a[$sortBy] ?? 0);
                $valB = (float)($b[$sortBy] ?? 0);

                if ($valA === $valB) {
                    // Tiebreaker: azonos értéknél ID szerint növekvő (§6 domain fix)
                    return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
                }

                return ($sortDir === 'desc') ? ($valB <=> $valA) : ($valA <=> $valB);
            });
        }

        $localVehiclesCount = 0;
        $countries = [];
        foreach ($vehicles as $v) {
            if ($v['country'] === $currentCountry) {
                $localVehiclesCount++;
            }
            if (!in_array($v['country'], $countries)) {
                $countries[] = $v['country'];
            }
        }

        // Tiebreaker: azonos értéknél ID szerint növekvő (§6 domain)
        
        // [2026-02-15] FIX: Batch lekérdezés – összes ország kapacitása 2 SQL-ben (N helyett)
        $capacities = $this->repository->getAllGarageCapacities($userId, $countries);
        
        // Jelenlegi ország kapacitása
        $capacity = $capacities[$currentCountry] ?? 0;
        $freeSlots = max(0, $capacity - $localVehiclesCount);
        $totalVehicles = count($vehicles);

        // Check property ownership
        $hasProperty = $this->propertyService->hasPropertyInCountry($userId, $currentCountry);

        return [
            'vehicles' => $vehicles,
            'total_vehicles' => $totalVehicles,
            'capacity' => $capacity,
            'free_slots' => $freeSlots,
            'capacities' => $capacities,
            'local_vehicles_count' => $localVehiclesCount,
            'has_property_in_country' => $hasProperty
        ];
    }

    /**
     * Garázs bővítés (Expand) adatainak összeállítása
     */
    public function getExpansionPageData(int $userId, string $currentCountry): array
    {
        // 1. Meglévő garázsok és slotok
        $propertyGarages = $this->repository->getPropertyGarages($userId);
        $purchasedSlots = $this->repository->getPurchasedSlots($userId);
        
        // 2. Összkapacitás számítás
        $totalCapacity = 0;
        foreach ($propertyGarages as $pg) {
            $totalCapacity += (int)$pg['capacity'];
        }
        foreach ($purchasedSlots as $ps) {
            $totalCapacity += (int)$ps['slots'];
        }

        // 3. Jelenlegi országhoz eladási ár
        $currentPurchasedSlots = $this->repository->getPurchasedSlotsForCountry($userId, $currentCountry);
        $sellPrice = $this->calculateSellPrice($currentPurchasedSlots);

        // 4. Egyedi eladási árak kalkulálása a listához
        foreach ($purchasedSlots as &$slotInfo) {
            $slotInfo['calculated_sell_price'] = $this->calculateSellPrice((int)$slotInfo['slots']);
        }
        unset($slotInfo);

        // 5. Csomagok (Configból) + Vásárlási státusz
        $configPackages = \Netmafia\Modules\Garage\GarageConfig::EXPANSION_PACKAGES;
        $purchasedPackages = $this->repository->getPurchasedPackages($userId, $currentCountry);
        
        $packages = [];
        foreach ($configPackages as $size) {
            $packages[] = [
                'slots' => $size,
                'price' => $size * \Netmafia\Modules\Garage\GarageConfig::SLOT_PRICE_PER_UNIT,
                'is_purchased' => in_array($size, $purchasedPackages)
            ];
        }

        return [
            'property_garages' => $propertyGarages,
            'purchased_slots' => $purchasedSlots,
            'current_purchased_slots' => $currentPurchasedSlots,
            'sell_price' => $sellPrice,
            'total_capacity' => $totalCapacity,
            'packages' => $packages
        ];
    }

    public function setDefaultVehicle(int $userId, int $vehicleId): void
    {
        $this->db->beginTransaction();
        try {
            // [FIX §2.6] FOR UPDATE: megakadályozza, hogy két tabon egyszerre kerüljön be két default
            $vehicle = $this->db->fetchAssociative(
                "SELECT id, user_id FROM user_vehicles WHERE id = ? AND user_id = ? FOR UPDATE",
                [$vehicleId, $userId]
            );
            if (!$vehicle) {
                throw new GameException("Jármű nem található vagy nem a tiéd.");
            }
            $this->repository->setDefaultVehicle($userId, $vehicleId);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) $this->db->rollBack();
            throw $e;
        }
        $this->logger->log('vehicle_set_default', $userId, ['vehicle_id' => $vehicleId]);
    }

    public function unsetDefaultVehicle(int $userId, int $vehicleId): void
    {
        $this->db->beginTransaction();
        try {
            $vehicle = $this->db->fetchAssociative(
                "SELECT id, user_id FROM user_vehicles WHERE id = ? AND user_id = ? FOR UPDATE",
                [$vehicleId, $userId]
            );
            if (!$vehicle) {
                throw new GameException("Jármű nem található vagy nem a tiéd.");
            }
            $this->repository->unsetDefaultVehicle($userId, $vehicleId);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) $this->db->rollBack();
            throw $e;
        }
        $this->logger->log('vehicle_unset_default', $userId, ['vehicle_id' => $vehicleId]);
    }

    /**
     * Jármű javítása tranzakcióban (Race condition védelem)
     */
    public function repairVehicle(int $userId, int $vehicleId): void
    {
        $this->db->beginTransaction();
        try {
            // 1. Lock & Fetch vehicle
            // FOR UPDATE biztosítja, hogy senki más nem módosítja közben
            $vehicle = $this->db->fetchAssociative(
                "SELECT id, user_id, vehicle_id, country, current_fuel, damage_percent, is_default FROM user_vehicles WHERE id = ? AND user_id = ? FOR UPDATE",
                [$vehicleId, $userId]
            );

            if (!$vehicle) {
                throw new GameException("Jármű nem található vagy nem a tiéd.");
            }

            $damage = (int) $vehicle['damage_percent'];
            if ($damage <= 0) {
                throw new GameException("A jármű nem sérült.");
            }

            // 2. Calculate Cost
            // Nem kell joinolni a vehicles táblát a névért, csak a damage kell
            $cost = $damage * \Netmafia\Modules\Garage\GarageConfig::REPAIR_COST_PER_PERCENT;

            // 3. Deduct Money
            $this->moneyService->spendMoney(
                UserId::of($userId), 
                $cost, 
                'vehicle_repair',
                "Jármű javítás: ID #$vehicleId ($damage%)"
            );

            // 4. Update Vehicle
            $this->repository->repairVehicle($userId, $vehicleId);

            $this->db->commit();
            
            $this->logger->log('vehicle_repair', $userId, ['vehicle_id' => $vehicleId, 'cost' => $cost, 'damage' => $damage]);

        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
    public function batchQuickSell(int $userId, array $vehicleIds): int
    {
        if (empty($vehicleIds)) {
            return 0;
        }

        $this->db->beginTransaction();
        try {
            // ORDER BY uv.id ASC: kötelező több sor FOR UPDATE-nél a deadlock elkerüléséhez (§2.7)
            $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));
            $vehicles = $this->db->fetchAllAssociative(
                "SELECT uv.id, v.category, uv.damage_percent, uv.is_default, uv.vehicle_id
                 FROM user_vehicles uv
                 JOIN vehicles v ON uv.vehicle_id = v.id
                 WHERE uv.user_id = ? AND uv.id IN ($placeholders)
                 ORDER BY uv.id ASC
                 FOR UPDATE",
                array_merge([$userId], $vehicleIds)
            );

            if (count($vehicles) !== count($vehicleIds)) {
                throw new GameException("Egy vagy több jármű nem található vagy nem a tiéd.");
            }

            foreach ($vehicles as $vehicle) {
                // [FIX §1.2] Config konstans használata hardcoded lista helyett
                if (in_array($vehicle['category'], \Netmafia\Modules\Garage\GarageConfig::PREMIUM_CATEGORIES, true)) {
                    throw new GameException("Csak átlagos járműveket lehet a Bontóban értékesíteni. A prémium autókat a Piacon tudod eladni!");
                }

                // [FIX domain] Sérült roncsot teljes áron eladni nem lehet
                if ((int)$vehicle['damage_percent'] >= 80) {
                    throw new GameException("Egy vagy több jármű túlig sérült (80%+) az azonnali eladáshoz. Előbb javítsd meg!");
                }

                // [FIX domain] Default jármű nem adható el
                if (!empty($vehicle['is_default'])) {
                    throw new GameException("Az alapértelmezett jármű nem adható el. Először másikat állíts be alapértelmezettnek!");
                }

                // [FIX domain] Futó szervezett bűnözéshez rendelt jármű nem adható el
                $inActiveCrime = $this->db->fetchOne(
                    "SELECT 1 FROM organized_crime_members ocm
                     JOIN organized_crimes oc ON oc.id = ocm.crime_id
                     WHERE ocm.vehicle_id = ? AND oc.status IN ('gathering', 'in_progress')",
                    [$vehicle['id']]
                );
                if ($inActiveCrime) {
                    throw new GameException("Az egyik jármű aktív szervezett bűnözéshez van rendelve, nem adható el!");
                }
            }

            // [FIX §1.2] Magic number kiváltva Config konstanssal
            $totalEarned = count($vehicleIds) * \Netmafia\Modules\Garage\GarageConfig::QUICK_SELL_PRICE_PER_VEHICLE;

            $this->moneyService->addMoney(
                UserId::of($userId), 
                $totalEarned, 
                'sell', 
                "Gyors eladás: " . count($vehicleIds) . " db jármű"
            );

            $this->repository->batchDeleteVehicles($userId, $vehicleIds);
            
            $this->db->commit();
            
            $this->logger->log('vehicle_batch_quicksell', $userId, [
                'count' => count($vehicleIds), 
                'earned' => $totalEarned
            ]);

            return $totalEarned;
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Biztonsági fejlesztés vásárlása tranzakcióban
     */
    public function buySecurityUpgrade(int $userId, int $vehicleId, string $upgradeType): void
    {
        if (!isset(\Netmafia\Modules\Garage\GarageConfig::UPGRADE_PRICES[$upgradeType])) {
            throw new GameException("Érvénytelen fejlesztés.");
        }

        // Map type to DB column
        $columnMap = [
            'bulletproof_glass' => 'has_bulletproof_glass',
            'steel_body' => 'has_steel_body',
            'runflat_tires' => 'has_runflat_tires',
            'explosion_proof_tank' => 'has_explosion_proof_tank',
            'large_tank' => 'has_large_tank',
        ];

        $dbColumn = $columnMap[$upgradeType];
        $price = \Netmafia\Modules\Garage\GarageConfig::UPGRADE_PRICES[$upgradeType];

        $this->db->beginTransaction();
        try {
            // 1. Lock & Fetch
            $vehicle = $this->db->fetchAssociative(
                "SELECT id, user_id, vehicle_id, country, current_fuel, damage_percent, is_default, has_bulletproof_glass, has_steel_body, has_runflat_tires, has_explosion_proof_tank, has_large_tank FROM user_vehicles WHERE id = ? AND user_id = ? FOR UPDATE",
                [$vehicleId, $userId]
            );

            if (!$vehicle) {
                throw new GameException("Jármű nem található vagy nem a tiéd.");
            }

            // Check if already bought
            if (!empty($vehicle[$dbColumn])) {
                throw new GameException("Ezt a fejlesztést már megvetted!");
            }

            // 2. Deduct Money
            $this->moneyService->spendMoney(
                UserId::of($userId), 
                $price, 
                'purchase', 
                "Biztonsági fejlesztés: $upgradeType (ID #$vehicleId)"
            );

            // 3. Apply Upgrade
            $this->repository->buySecurityUpgrade($userId, $vehicleId, $dbColumn);

            $this->db->commit();
            
            $this->logger->log('vehicle_upgrade', $userId, ['vehicle_id' => $vehicleId, 'upgrade' => $upgradeType, 'cost' => $price]);

        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
