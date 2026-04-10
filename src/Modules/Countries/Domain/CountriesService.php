<?php
declare(strict_types=1);

namespace Netmafia\Modules\Countries\Domain;

use Netmafia\Modules\Buildings\Domain\BuildingService;
use Netmafia\Modules\AmmoFactory\Domain\AmmoFactoryService;
use Netmafia\Modules\Buildings\Domain\HospitalService;
use Netmafia\Infrastructure\CacheService;

class CountriesService
{
    private BuildingService $buildingService;
    private AmmoFactoryService $ammoFactoryService;
    private HospitalService $hospitalService;
    private CacheService $cache;

    public function __construct(
        BuildingService $buildingService,
        AmmoFactoryService $ammoFactoryService,
        HospitalService $hospitalService,
        CacheService $cache
    ) {
        $this->buildingService = $buildingService;
        $this->ammoFactoryService = $ammoFactoryService;
        $this->hospitalService = $hospitalService;
        $this->cache = $cache;
    }

    /**
     * Adott típusú épületek listázása extra adatokkal
     * 
     * [2025-12-29 14:30:39] Optimalizálás: Cache-elés hozzáadása
     * Korábban minden Countries oldal betöltés 7x hívta ezt a metódust,
     * ami sok DB query-t jelentett. Most 5 perces cache-szel gyorsítjuk.
     */
    public function getBuildingList(string $type): array
    {
        $cacheKey = "countries_buildings:{$type}";
        
        // [2025-12-29 14:30:39] Cache-ből próbálkozás először
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
        
        // 1. Alap épület adatok (ország, tulaj, alap ár)
        $buildings = $this->buildingService->getBuildingsByType($type);
        
        // 2. Extra adatok betöltése típus alapján
        $extraData = [];
        
        switch ($type) {
            case 'ammo_factory':
                $extraData = $this->ammoFactoryService->getAllFactoriesData();
                break;
            
            case 'hospital':
                $extraData = $this->hospitalService->getAllHospitalsData();
                break;
            
            // Itt bővíthető további típusokkal (pl. Lottery, Restaurant ha van extra adat)
        }

        // 3. Adatok összefésülése és Aktivitás jelző (is_active)
        foreach ($buildings as &$building) {
            $id = $building['id'] ?? null;
            $hasBuilding = ($id !== null); // Alapból: van-e rekord

            if ($id && isset($extraData[$id])) {
                // Merge extra data
                $building = array_merge($building, $extraData[$id]);
            }

            // [2026-02-22] Default értékek beállítása ha nincs extra data rekord
            // Pl. ha van ammo_factory building de nincs ammo_factory_production sor hozzá
            if ($hasBuilding && $type === 'ammo_factory') {
                $building['ammo_stock'] = $building['ammo_stock'] ?? 0;
                $building['ammo_price'] = $building['ammo_price'] ?? \Netmafia\Modules\AmmoFactory\AmmoFactoryConfig::DEFAULT_AMMO_PRICE;
            }
            if ($hasBuilding && $type === 'hospital') {
                $building['price_per_hp'] = $building['price_per_hp'] ?? \Netmafia\Modules\Buildings\Domain\BuildingConfig::DEFAULT_HOSPITAL_PRICE_PER_HP;
            }

            // --- Aktivitás vizsgálat (Egyszerűsített) ---
            // Ha van rekord az adatbázisban (id nem null), akkor aktívnak tekintjük.
            // Ha nincs rekord, akkor "Fejlesztés alatt".
            
            $building['is_active'] = $hasBuilding;
        }
        
        // [2025-12-29 14:30:39] Cache-be mentés (5 perc TTL)
        $this->cache->set($cacheKey, $buildings, \Netmafia\Modules\Countries\CountriesConfig::CACHE_TTL_BUILDING_LIST);

        return $buildings;
    }
}
