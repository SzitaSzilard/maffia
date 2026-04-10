<?php
declare(strict_types=1);

namespace Netmafia\Modules\Buildings\Actions;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

use Doctrine\DBAL\Connection;
use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Buildings\TravelConfig;
use Netmafia\Modules\Garage\Domain\VehicleRepository;
use Netmafia\Infrastructure\SessionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HighwayTravelAction
{
    private VehicleRepository $vehicleRepository;
    private Connection $db;
    private \Netmafia\Modules\Item\Domain\BuffService $buffService;
    // [2026-02-28] FIX: SessionService injektálva $_SESSION közvetlen használata helyett
    private SessionService $session;

    public function __construct(
        VehicleRepository $vehicleRepository,
        Connection $db,
        \Netmafia\Modules\Item\Domain\BuffService $buffService,
        SessionService $session
    ) {
        $this->vehicleRepository = $vehicleRepository;
        $this->db = $db;
        $this->buffService = $buffService;
        $this->session = $session;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');

        $data = $request->getParsedBody();
        $targetCountry = $data['target_country'] ?? null;

        if (!$targetCountry) {
            $this->session->flash('highway_error', 'Érvénytelen célország!');
            return $response->withHeader('Location', '/epulet/autopalya')->withStatus(302);
        }

        // Start Transaction for Race Condition Protection
        $this->db->beginTransaction();
        try {
            // 1. Lock User Row (Prevents concurrent travel/limit exploits)
            $user = $this->db->fetchAssociative("SELECT id, username, country_code, money, last_travel_time, daily_highway_usage, highway_sticker_level, highway_sticker_expiry FROM users WHERE id = ? FOR UPDATE", [$userId]);
            
            if (!$user) {
                throw new GameException("Felhasználó nem található.");
            }

            // 2. Validate Default Vehicle
            // Note: Ideally we should lock vehicle row too, but locking user is usually enough for single-user actions 
            // unless vehicle is shared (which it isn't).
            // However, to be safe against "Vehicle Swapping" exploit during travel, we fetch vehicle fresh.
            $vehicles = $this->vehicleRepository->getUserVehicles((int)$userId);
            $defaultVehicle = null;
            foreach ($vehicles as $v) {
                if (!empty($v['is_default'])) {
                    $defaultVehicle = $v;
                    break;
                }
            }

            if (!$defaultVehicle) {
                throw new GameException('Nincs beállítva alapértelmezett járműved!');
            }

            // 2.1 Validate Vehicle Location
            if ($defaultVehicle['country'] !== $user['country_code']) {
                throw new GameException("A választott járműved ({$defaultVehicle['name']}) nem ebben az országban van!");
            }

            // 3. Validate Cooldown (using Calculator)
            $reductionParams = $this->buffService->getActiveBonus((int)$userId, 'cooldown_reduction', 'travel');
            $effectiveMinutes = \Netmafia\Modules\Buildings\Domain\TravelCalculator::calculateEffectiveCooldown((float)$reductionParams);
            
            $remSeconds = \Netmafia\Modules\Buildings\Domain\TravelCalculator::calculateRemainingSeconds(
                $user['last_travel_time'] ?? null, 
                $effectiveMinutes
            );

            if ($remSeconds > 0) {
                $remMin = ceil($remSeconds / 60);
                throw new GameException("Még várnod kell {$remMin} percet az utazásig!");
            }

            // 4. Validate Daily Limit (with Lazy Reset)
            $lastTravel = $user['last_travel_time'];
            $isNewDay = (!$lastTravel || date('Y-m-d', strtotime($lastTravel)) !== date('Y-m-d'));
            
            $dailyUsage = $isNewDay ? 0 : (int)($user['daily_highway_usage'] ?? 0);

            // Determine Limit based on Sticker
            $limit = TravelConfig::LIMIT_DEFAULT;
            $stickerLevel = (int)($user['highway_sticker_level'] ?? 0);
            $stickerExpiry = $user['highway_sticker_expiry'] ?? null;

            if ($stickerLevel > 0 && $stickerExpiry && strtotime($stickerExpiry) > time()) {
                $additionalLimit = match($stickerLevel) {
                    TravelConfig::STICKER_LEVEL_7 => TravelConfig::LIMIT_7,
                    TravelConfig::STICKER_LEVEL_10 => TravelConfig::LIMIT_10,
                    TravelConfig::STICKER_LEVEL_UNLIMITED => TravelConfig::LIMIT_UNLIMITED,
                    default => 0
                };
                $limit += $additionalLimit;
            }

            if ($dailyUsage >= $limit) {
                throw new GameException('Elérted a napi utazási limitedet! (' . $limit . ' alkalom)');
            }

            // 5. Validate Fuel
            $fuelCost = TravelConfig::FUEL_COSTS[$targetCountry] ?? 50;
            // Use current_fuel for check!
            $currentFuel = (int)($defaultVehicle['current_fuel'] ?? 0);
            
            if ($currentFuel < $fuelCost) {
                throw new GameException("Nincs elég benzin a járművedben! (Szükséges: {$fuelCost}L)");
            }

            // 6. Execute Updates
            $newUsage = $dailyUsage + 1;
            
            // Calculate absolute target timestamp for UI tracking (in UTC)
            $targetTs = time() + (int)($effectiveMinutes * 60);
            $dateStr = gmdate('Y-m-d H:i:s', $targetTs);

            // Update User
            try {
                $this->db->executeStatement("SET @audit_source = ?", ['HighwayTravelAction::invoke']);
                $this->db->executeStatement(
                    "UPDATE users SET country_code = ?, last_travel_time = UTC_TIMESTAMP(), travel_cooldown_until = ?, daily_highway_usage = ? WHERE id = ?",
                    [$targetCountry, $dateStr, $newUsage, $userId]
                );
            } finally {
                $this->db->executeStatement("SET @audit_source = NULL");
            }

            // Update Vehicle
            $this->db->executeStatement(
                "UPDATE user_vehicles SET country = ?, current_fuel = current_fuel - ? WHERE id = ?",
                [$targetCountry, $fuelCost, $defaultVehicle['id']]
            );

            $this->db->commit();
            
            $this->session->flash('highway_success', 'Sikeres utazás ' . $targetCountry . ' országba!');
            return $response->withHeader('Location', '/epulet/autopalya')->withStatus(302);

        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $this->session->flash('highway_error', $e->getMessage());
            return $response->withHeader('Location', '/epulet/autopalya')->withStatus(302);
        }
    }
}
