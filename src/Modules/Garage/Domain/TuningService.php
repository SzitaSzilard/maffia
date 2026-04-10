<?php
declare(strict_types=1);

namespace Netmafia\Modules\Garage\Domain;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

use Doctrine\DBAL\Connection;
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Shared\Domain\ValueObjects\UserId;

class TuningService
{
    private Connection $db;
    private MoneyService $moneyService;
    private VehicleRepository $repository; // Needed to refresh vehicle stats

    // Tuning types and their database columns + display names
    public const TUNING_PARTS = [
        'engine' => ['col' => 'tuning_engine', 'name' => 'Motor', 'stat' => 'speed', 'percent' => 5],
        'tires' => ['col' => 'tuning_tires', 'name' => 'Gumik', 'stat' => 'safety', 'percent' => 5],
        'exhaust' => ['col' => 'tuning_exhaust', 'name' => 'Kipufogó', 'stat' => 'speed', 'percent' => 5],
        'brakes' => ['col' => 'tuning_brakes', 'name' => 'Fékek', 'stat' => 'safety', 'percent' => 5],
        'nitros' => ['col' => 'tuning_nitros', 'name' => 'NOS', 'stat' => 'speed', 'percent' => 5],
        'body' => ['col' => 'tuning_body', 'name' => 'Body kit', 'stat' => 'safety', 'percent' => 5],
        'shocks' => ['col' => 'tuning_shocks', 'name' => 'Belső tuning', 'stat' => 'mixed', 'percent' => 2], // Mixed: +2% speed, +2% safety
        'wheels' => ['col' => 'tuning_wheels', 'name' => 'Felnik', 'stat' => 'mixed', 'percent' => 2],
    ];

    public const MAX_TUNING_LEVEL = 3;

    public function __construct(Connection $db, MoneyService $moneyService, VehicleRepository $repository)
    {
        $this->db = $db;
        $this->moneyService = $moneyService;
        $this->repository = $repository;
    }

    /**
     * Calculate cost for a single tuning level based on vehicle base speed.
     * Formula: Base Speed * 1.2
     */
    public function calculateTuningCost(int $baseSpeed): int
    {
        return (int)($baseSpeed * 1.2);
    }

    /**
     * Perform a tuning upgrade
     */
    public function tuneVehicle(UserId $userId, int $vehicleId, string $partType): void
    {
        if (!array_key_exists($partType, self::TUNING_PARTS)) {
            throw new GameException("Érvénytelen tuning típus.");
        }

        $partConfig = self::TUNING_PARTS[$partType];
        $column = $partConfig['col'];

        $this->db->beginTransaction();
        try {
            // 1. Lock and fetch vehicle
            $vehicle = $this->db->fetchAssociative(
                "SELECT uv.*, v.speed, v.name 
                 FROM user_vehicles uv 
                 JOIN vehicles v ON v.id = uv.vehicle_id 
                 WHERE uv.id = ? AND uv.user_id = ? 
                 FOR UPDATE",
                [$vehicleId, $userId->id()]
            );

            if (!$vehicle) {
                throw new GameException("Jármű nem található vagy nem a tiéd.");
            }

            // 2. Check max level
            $currentLevel = (int)$vehicle[$column];
            if ($currentLevel >= self::MAX_TUNING_LEVEL) {
                throw new GameException("Ez az alkatrész már maximálisan fejlesztve van!");
            }

            // 3. Calculate Cost
            $cost = $this->calculateTuningCost((int)$vehicle['speed']);

            // 4. Pay (spendMoney inherently checks balance and locks users table)
            $this->moneyService->spendMoney($userId, $cost, 'purchase', "Tuning: {$partConfig['name']} (Szint: " . ($currentLevel + 1) . ")");

            // 5. Update Tuning Level
            $this->db->update(
                'user_vehicles', 
                [
                    $column => $currentLevel + 1,
                    'tuning_percent' => (int)($vehicle['tuning_percent'] ?? 0) + 1
                ], 
                ['id' => $vehicleId]
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
     * Calculate total cost for full tuning
     */
    public function calculateFullTuningCost(array $vehicle): int
    {
        $baseSpeed = (int)$vehicle['speed'];
        $costPerLevel = $this->calculateTuningCost($baseSpeed);
        $totalCost = 0;

        foreach (self::TUNING_PARTS as $config) {
            $currentLevel = (int)($vehicle[$config['col']] ?? 0);
            $remainingLevels = max(0, self::MAX_TUNING_LEVEL - $currentLevel);
            $totalCost += $remainingLevels * $costPerLevel;
        }

        return $totalCost;
    }

    /**
     * Perform full tuning upgrade
     */
    public function tuneVehicleFull(UserId $userId, int $vehicleId): void
    {
        $this->db->beginTransaction();
        try {
            // 1. Lock and fetch vehicle
            $vehicle = $this->db->fetchAssociative(
                "SELECT uv.*, v.speed, v.name 
                 FROM user_vehicles uv 
                 JOIN vehicles v ON v.id = uv.vehicle_id 
                 WHERE uv.id = ? AND uv.user_id = ? 
                 FOR UPDATE",
                [$vehicleId, $userId->id()]
            );

            if (!$vehicle) {
                throw new GameException("Jármű nem található vagy nem a tiéd.");
            }

            // 2. Calculate Total Cost
            $totalCost = $this->calculateFullTuningCost($vehicle);

            if ($totalCost === 0) {
                 throw new GameException("A jármű már teljesen fel van tuningolva!");
            }

            // 3. Pay (spendMoney inherently checks balance and locks users table)
            $this->moneyService->spendMoney($userId, $totalCost, 'purchase', "Full Tuning: {$vehicle['name']}");

            // 4. Update All Parts to Max Level
            $updateData = [];
            foreach (self::TUNING_PARTS as $config) {
                $updateData[$config['col']] = self::MAX_TUNING_LEVEL;
            }
            $updateData['tuning_percent'] = 100;

            $this->db->update('user_vehicles', $updateData, ['id' => $vehicleId]);
            
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Get Tuning Data for a vehicle (structure for UI)
     */
    public function getVehicleTuningData(array $vehicle): array
    {
        $baseSpeed = (int)$vehicle['speed'];
        $cost = $this->calculateTuningCost($baseSpeed);
        
        $tuningData = [
            'parts' => [],
            'full_tuning_cost' => $this->calculateFullTuningCost($vehicle)
        ];

        foreach (self::TUNING_PARTS as $type => $config) {
            $level = (int)($vehicle[$config['col']] ?? 0);
            $tuningData['parts'][$type] = [
                'name' => $config['name'],
                'level' => $level,
                'max_level' => self::MAX_TUNING_LEVEL,
                'cost' => $cost,
                'can_upgrade' => $level < self::MAX_TUNING_LEVEL
            ];
        }
        return $tuningData;
    }
}
