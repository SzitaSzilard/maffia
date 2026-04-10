<?php
declare(strict_types=1);

namespace Netmafia\Modules\CarTheft\Domain;

use Doctrine\DBAL\Connection;
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Modules\Health\Domain\HealthService;
use Netmafia\Modules\Xp\Domain\XpService;
use Netmafia\Infrastructure\AuditLogger;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Modules\CarTheft\CarTheftConfig;
use Netmafia\Modules\Garage\Domain\VehicleRepository;
use Netmafia\Modules\Item\Domain\BuffService;
use Netmafia\Modules\Home\Domain\SleepService;

class CarTheftService
{
    private Connection $db;
    private HealthService $healthService;
    private XpService $xpService;
    private VehicleRepository $vehicleRepo;
    private AuditLogger $logger;
    private BuffService $buffService;
    private SleepService $sleepService;

    public function __construct(
        Connection $db,
        HealthService $healthService,
        XpService $xpService,
        VehicleRepository $vehicleRepo,
        AuditLogger $logger,
        BuffService $buffService,
        SleepService $sleepService
    ) {
        $this->db = $db;
        $this->healthService = $healthService;
        $this->xpService = $xpService;
        $this->vehicleRepo = $vehicleRepo;
        $this->logger = $logger;
        $this->buffService = $buffService;
        $this->sleepService = $sleepService;
    }

    /**
     * Utcán lévő autók lekérdezése egy adott országban
     */
    public function getStreetVehicles(string $countryCode, int $excludeUserId): array
    {
        // Csak azok az autók, amik az utcán vannak, az adott országban, és nem a miénk
        return $this->db->fetchAllAssociative(
            "SELECT uv.id as user_vehicle_id, uv.user_id as owner_id, u.username as owner_name, u.xp as owner_xp,
                    uv.damage_percent, uv.tuning_percent, 
                    v.name as vehicle_name, v.category, v.image_path
             FROM user_vehicles uv
             JOIN vehicles v ON v.id = uv.vehicle_id
             JOIN users u ON u.id = uv.user_id
             WHERE uv.country = ? AND uv.location = 'street' AND uv.user_id != ?
             ORDER BY u.xp DESC",
            [$countryCode, $excludeUserId]
        );
    }

    /**
     * Lopási esély kiszámítása — próbálkozásszám-alapú logaritmus
     * Képlet: min(85, BASE_CHANCE + K * log(1 + attempts)) + buff
     * K=17 → ~50 próba után kb. 82%, plafon: 85%
     */
    public function calculateTheftChance(int $attempts, int $thiefId = 0): float
    {
        $baseChance = CarTheftConfig::BASE_CHANCE_PERCENT;
        $k = CarTheftConfig::THEFT_K_FACTOR;

        $chance = $baseChance + $k * log(1 + $attempts);

        // Buff bónusz (ha aktív)
        if ($thiefId > 0) {
            $bonusBuff = $this->buffService->getActiveBonus($thiefId, 'theft_bonus', 'car_theft');
            $chance += $bonusBuff;
        }

        return min(85.0, round($chance, 2));
    }

    /**
     * Lopási kísérlet
     */
    public function attemptTheft(UserId $thiefId, int $targetUserVehicleId): array
    {
        if ($this->sleepService->isUserSleeping($thiefId)) {
            throw new GameException('Épp alszol, ezért nem tudsz tevékenykedni!');
        }

        $this->db->beginTransaction();
        try {
            // 1. Tolvaj adatainak lekérése (FOR UPDATE a race condition elkerülésére!)
            $thief = $this->db->fetchAssociative(
                "SELECT id, energy, xp, car_theft_cooldown_until FROM users WHERE id = ? FOR UPDATE", 
                [$thiefId->id()]
            );

            // Energia ellenőrzés (a lehetséges maximális költséget kell fedeznie)
            $minRequiredEnergy = max(CarTheftConfig::ENERGY_COST_SUCCESS, CarTheftConfig::ENERGY_COST_FAIL_MAX);
            if ($thief['energy'] < $minRequiredEnergy) {
                throw new GameException("Nincs elég energiád! Legalább {$minRequiredEnergy} energia szükséges a biztonságos kísérlethez.");
            }

            // Cooldown ellenőrzés
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if ($thief['car_theft_cooldown_until'] !== null) {
                $cooldownTime = new \DateTimeImmutable($thief['car_theft_cooldown_until'], new \DateTimeZone('UTC'));
                if ($cooldownTime > $now) {
                    $diffMinutes = (int)ceil(($cooldownTime->getTimestamp() - $now->getTimestamp()) / 60);
                    throw new GameException("Még {$diffMinutes} percig nem lophatsz autót!");
                }
            }

            // 2. Célpont adatainak lekérése FOR UPDATE-tel
            $targetVehicle = $this->db->fetchAssociative(
                "SELECT uv.*, u.username as owner_name, u.xp as owner_xp, v.name as vehicle_name 
                 FROM user_vehicles uv
                 JOIN users u ON u.id = uv.user_id
                 JOIN vehicles v ON v.id = uv.vehicle_id
                 WHERE uv.id = ? FOR UPDATE",
                [$targetUserVehicleId]
            );

            if (!$targetVehicle) {
                throw new GameException("Ez a jármű nem található!");
            }

            if ($targetVehicle['user_id'] == $thiefId->id()) {
                throw new GameException("A saját autódat nem lophatod el!");
            }

            if ($targetVehicle['location'] !== 'street') {
                throw new GameException("Ezt a járművet időközben már bevitték a garázsba!");
            }

            // [FIX] Ország-egyezés ellenőrzés — Anti-spoofing: a POST-ból jött vehicle_id
            // akár egy másik ország autója is lehet. Szerveroldalon kikényszerítjük.
            $thiefCountry = $this->db->fetchOne(
                "SELECT country_code FROM users WHERE id = ? FOR UPDATE",
                [$thiefId->id()]
            );
            if ($targetVehicle['country'] !== $thiefCountry) {
                throw new GameException("Csak a saját országodban lévő autókat lophatod el!");
            }

            // 3. Matek (Most már átadjuk a tolvaj ID-ját is a buffokhoz)
            $attempts = (int)($thief['car_theft_attempts'] ?? 0);
            $chance = $this->calculateTheftChance($attempts, $thiefId->id());

            $roll = random_int(1, 100);
            
            $isSuccess = $roll <= $chance;

            // 4. Eredmény feldolgozása
            if ($isSuccess) {
                $energyCost = CarTheftConfig::ENERGY_COST_SUCCESS;
                $xpReward = random_int(CarTheftConfig::XP_REWARD_MIN, CarTheftConfig::XP_REWARD_MAX);
                $msg = "Sikeres lopás történt, az autó ({$targetVehicle['vehicle_name']}) tulajdonosa nem figyelt, így a tiéd lett.";

                // [FIX] Garázs kapacitás: ha van hely, garázsba kerül, egybélent az utcára
                $garageCapacity = $this->vehicleRepo->getGarageCapacity($thiefId->id(), (string)$thiefCountry);
                $garageCount = $this->vehicleRepo->countVehiclesInGarage($thiefId->id(), (string)$thiefCountry);
                $newLocation = ($garageCount < $garageCapacity) ? 'garage' : 'street';

                // Tulajdonos váltás és helyre tétel
                $this->db->executeStatement(
                    "UPDATE user_vehicles SET user_id = ?, location = ?, location_changed_at = UTC_TIMESTAMP() WHERE id = ?",
                    [$thiefId->id(), $newLocation, $targetUserVehicleId]
                );

                $locationMsg = $newLocation === 'garage'
                    ? "az autó ({$targetVehicle['vehicle_name']}) a garázsba került."
                    : "az autó ({$targetVehicle['vehicle_name']}) az utcára került (garázsod tele van).";
                $msg = "Sikeres lopás! {$targetVehicle['owner_name']} nem figyelt, " . $locationMsg;

                // Értesítés a régi tulajnak
                $this->db->insert('notifications', [
                    'user_id' => $targetVehicle['user_id'],
                    'message' => "Valaki ellopta a(z) {$targetVehicle['vehicle_name']} típusú autódat az utcáról!",
                    'type' => 'alert',
                    'source_module' => 'car_theft'
                ]);

            } else {
                $energyCost = random_int(CarTheftConfig::ENERGY_COST_FAIL_MIN, CarTheftConfig::ENERGY_COST_FAIL_MAX);
                $xpReward = random_int(CarTheftConfig::XP_FAIL_MIN, CarTheftConfig::XP_FAIL_MAX);
                $msg = "Sajnos nem sikerült a lopás.";
            }

            // 5. XP és Energia levonás (XP service, Energia egyenesen DB-ben audit source-szal)
            $this->xpService->addXp($thiefId, $xpReward, 'car_theft');

            // Energia levonás és Buff alapú Cooldown számítás
            $newEnergy = max(0, $thief['energy'] - $energyCost);
            
            $cooldownMinutes = CarTheftConfig::COOLDOWN_MINUTES;
            $cooldownReduction = $this->buffService->getActiveBonus($thiefId->id(), 'cooldown_reduction', 'car_theft');
            
            if ($cooldownReduction > 0) {
            // Csökkentjük az időt (pl 25% esetén 12 perc -> 9 perc)
                $cooldownMinutes = (int) max(1, round($cooldownMinutes * (1 - ($cooldownReduction / 100))));
            }

            $cooldownUntil = $now->modify("+{$cooldownMinutes} minutes")->format('Y-m-d H:i:s');

            try {
                $this->db->executeStatement("SET @audit_source = 'CarTheftService::attemptTheft'");
                $this->db->executeStatement(
                    "UPDATE users SET energy = ?, car_theft_cooldown_until = ?, car_theft_attempts = car_theft_attempts + 1 WHERE id = ?",
                    [$newEnergy, $cooldownUntil, $thiefId->id()]
                );
            } finally {
                $this->db->executeStatement("SET @audit_source = NULL");
            }

            $this->logger->log(
                $isSuccess ? 'car_theft_success' : 'car_theft_fail',
                $thiefId->id(),
                ['target_user' => $targetVehicle['user_id'], 'vehicle' => $targetVehicle['vehicle_name'], 'chance' => $chance],
                null
            );

            $this->db->commit();

            return [
                'success' => $isSuccess,
                'message' => $msg,
                'energy_cost' => $energyCost,
                'xp_reward' => $xpReward,
                'new_energy' => $newEnergy
            ];

        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    // ========================================================
    // SZALONLOPÁS (Dealership Theft)
    // ========================================================

    /**
     * Adott ország szalonjából elérhető autók (3 db véletlenszerű)
     * Ha az országnak nincs autója, üres tömböt ad vissza.
     */
    public function getDealershipVehicles(string $countryCode): array
    {
        // Country mapping (GB → UK a vehicles táblában)
        $countryCodes = CarTheftConfig::COUNTRY_VEHICLE_MAP[$countryCode] ?? [$countryCode];
        $placeholders = implode(',', array_fill(0, count($countryCodes), '?'));

        $vehicles = $this->db->fetchAllAssociative(
            "SELECT id, name, category, origin_country, max_fuel, speed, safety
             FROM vehicles 
             WHERE origin_country IN ($placeholders) 
             ORDER BY RAND() 
             LIMIT 3",
            $countryCodes
        );

        return $vehicles;
    }

    /**
     * Szalonlopás esélyszámítás (attempts alapú, logaritmikus görbe)
     * 30% base, max 99%, scale factor 2315
     */
    public function calculateDealershipChance(int $attempts, int $offset = 0): float
    {
        $base = CarTheftConfig::DEALERSHIP_BASE_CHANCE;
        $max = CarTheftConfig::DEALERSHIP_MAX_CHANCE;
        $range = $max - $base; // 69
        $factor = CarTheftConfig::DEALERSHIP_SCALE_FACTOR;

        $chance = $base + $range * (1 - exp(-$attempts / $factor));
        $chance += $offset; // 5-8%-os eltérések az egyes autóknál

        return min($max, max(1, round($chance, 2)));
    }

    /**
     * Szalonlopás kísérlet végrehajtása
     */
    public function attemptDealershipTheft(UserId $thiefId, int $vehicleId): array
    {
        if ($this->sleepService->isUserSleeping($thiefId)) {
            throw new GameException('Épp alszol, ezért nem tudsz tevékenykedni!');
        }

        $this->db->beginTransaction();

        try {
            // 1. Lopó adatok
            $thief = $this->db->fetchAssociative(
                "SELECT id, energy, car_theft_cooldown_until, car_theft_attempts, country_code 
                 FROM users WHERE id = ? FOR UPDATE",
                [$thiefId->id()]
            );

            if (!$thief) {
                throw new GameException('Felhasználó nem található!');
            }

            // 2. Cooldown ellenőrzés
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if (!empty($thief['car_theft_cooldown_until'])) {
                $cooldownTime = new \DateTimeImmutable($thief['car_theft_cooldown_until'], new \DateTimeZone('UTC'));
                if ($cooldownTime > $now) {
                    throw new GameException('Még várakozási idő alatt állsz!');
                }
            }

            // 3. Jármű ellenőrzés (létezik-e és az adott ország márkája-e)
            $countryCodes = CarTheftConfig::COUNTRY_VEHICLE_MAP[$thief['country_code']] ?? [$thief['country_code']];
            $placeholders = implode(',', array_fill(0, count($countryCodes), '?'));

            $vehicle = $this->db->fetchAssociative(
                "SELECT id, name, category, origin_country, max_fuel, speed, safety
                 FROM vehicles WHERE id = ? AND origin_country IN ($placeholders)",
                array_merge([$vehicleId], $countryCodes)
            );

            if (!$vehicle) {
                throw new GameException('Ez a jármű nem elérhető!');
            }

            // 4. Esélyszámítás
            $attempts = (int)($thief['car_theft_attempts'] ?? 0);
            $chance = $this->calculateDealershipChance($attempts);

            // 5. Energia ellenőrzés (biztonságos tartalék a max költségig)
            $minEnergy = max(CarTheftConfig::ENERGY_COST_SUCCESS, CarTheftConfig::ENERGY_COST_FAIL_MAX);
            if ($thief['energy'] < $minEnergy) {
                throw new GameException("Nincs elég energiád! (Minimum: {$minEnergy})");
            }

            // 6. Dobás — [FIX] random_int() → random_int() (CSPRNG, nem kiszámítható)
            $roll = random_int(1, 100);
            $isSuccess = $roll <= $chance;

            if ($isSuccess) {
                $energyCost = CarTheftConfig::ENERGY_COST_SUCCESS;
                $xpReward = random_int(CarTheftConfig::XP_REWARD_MIN, CarTheftConfig::XP_REWARD_MAX);
                $msg = "Sikeresen elloptál egy {$vehicle['name']}-t a szalonból!";

                // [FIX] Garázs kapacitás: ha van hely, garázsba kerül, egyélbent az utcára
                $garageCapacity = $this->vehicleRepo->getGarageCapacity($thiefId->id(), $thief['country_code']);
                $garageCount = $this->vehicleRepo->countVehiclesInGarage($thiefId->id(), $thief['country_code']);
                $newLocation = ($garageCount < $garageCapacity) ? 'garage' : 'street';

                // Autó létrehozása a user garázsában (vagy utcáján)
                $fuelAmount = random_int(0, 100);
                $this->db->insert('user_vehicles', [
                    'user_id' => $thiefId->id(),
                    'vehicle_id' => $vehicle['id'],
                    'country' => $thief['country_code'],
                    'location' => $newLocation,
                    'damage_percent' => 0,
                    'fuel_amount' => $vehicle['max_fuel'],
                    'current_fuel' => $fuelAmount,
                    'tuning_percent' => 0,
                    'is_default' => 0,
                    'tuning_engine' => 0,
                    'tuning_tires' => 0,
                    'tuning_exhaust' => 0,
                    'tuning_brakes' => 0,
                    'tuning_nitros' => 0,
                    'tuning_body' => 0,
                    'tuning_shocks' => 0,
                    'tuning_wheels' => 0,
                ]);

                $locationMsg = $newLocation === 'garage' ? 'a garázsodba került' : 'az utcára került (garázsod tele van)';
                $msg = "Sikeresen elloptál egy {$vehicle['name']}-t a szalonból — $locationMsg!";
            } else {
                $energyCost = random_int(CarTheftConfig::ENERGY_COST_FAIL_MIN, CarTheftConfig::ENERGY_COST_FAIL_MAX);
                $xpReward = random_int(CarTheftConfig::XP_FAIL_MIN, CarTheftConfig::XP_FAIL_MAX);
                $msg = "Elkaptak a(z) {$vehicle['name']} lopása közben!";
            }

            // 7. XP
            $this->xpService->addXp($thiefId, $xpReward, 'car_theft_dealership');

            // 8. Energia + Cooldown + Attempts
            $newEnergy = max(0, $thief['energy'] - $energyCost);

            $cooldownMinutes = CarTheftConfig::COOLDOWN_MINUTES;
            $cooldownReduction = $this->buffService->getActiveBonus($thiefId->id(), 'cooldown_reduction', 'car_theft');
            if ($cooldownReduction > 0) {
                $cooldownMinutes = (int) max(1, round($cooldownMinutes * (1 - ($cooldownReduction / 100))));
            }
            $cooldownUntil = $now->modify("+{$cooldownMinutes} minutes")->format('Y-m-d H:i:s');

            try {
                $this->db->executeStatement("SET @audit_source = 'CarTheftService::attemptDealershipTheft'");
                $this->db->executeStatement(
                    "UPDATE users SET energy = ?, car_theft_cooldown_until = ?, car_theft_attempts = car_theft_attempts + 1 WHERE id = ?",
                    [$newEnergy, $cooldownUntil, $thiefId->id()]
                );
            } finally {
                $this->db->executeStatement("SET @audit_source = NULL");
            }

            $this->logger->log(
                $isSuccess ? 'dealership_theft_success' : 'dealership_theft_fail',
                $thiefId->id(),
                ['vehicle' => $vehicle['name'], 'chance' => $chance, 'roll' => $roll],
                null
            );

            $this->db->commit();

            return [
                'success' => $isSuccess,
                'message' => $msg,
                'energy_cost' => $energyCost,
                'xp_reward' => $xpReward,
                'new_energy' => $newEnergy
            ];

        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
