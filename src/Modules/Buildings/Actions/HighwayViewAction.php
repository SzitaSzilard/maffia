<?php
declare(strict_types=1);

namespace Netmafia\Modules\Buildings\Actions;

use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Buildings\Domain\BuildingService;
use Netmafia\Modules\Buildings\TravelConfig;
use Netmafia\Modules\Garage\Domain\VehicleRepository;
use Netmafia\Infrastructure\SessionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

use Netmafia\Modules\Item\Domain\BuffService;

class HighwayViewAction
{
    private Twig $view;
    private AuthService $authService;
    private BuildingService $buildingService;
    private VehicleRepository $vehicleRepository;
    private BuffService $buffService;
    // [2026-02-28] FIX: SessionService injektálva $_SESSION közvetlen használata helyett
    private SessionService $session;

    public function __construct(
        Twig $view,
        AuthService $authService,
        BuildingService $buildingService,
        VehicleRepository $vehicleRepository,
        BuffService $buffService,
        SessionService $session
    ) {
        $this->view = $view;
        $this->authService = $authService;
        $this->buildingService = $buildingService;
        $this->vehicleRepository = $vehicleRepository;
        $this->buffService = $buffService;
        $this->session = $session;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');

        $user = $this->authService->getAuthenticatedUser((int)$userId);
        $userCountry = $user['country_code'];
        
        // Find Highway building for this country (just for owner info display)
        $highway = $this->buildingService->getByCountryAndType($userCountry, 'highway');
        
        // Fallback if not found (system owned)
        if (!$highway) {
             // Create a dummy structure if DB record missing, or just ignore owner info
             $highway = [
                 'name_hu' => 'Autópálya',
                 'owner_name' => 'Állam',
                 'owner_id' => null
             ];
        }

        // --- AIRPORT DATA ---
        $airport = $this->buildingService->getByCountryAndType($userCountry, 'airport');
        if (!$airport) {
             $airport = [
                 'name_hu' => 'Repülőtér',
                 'owner_name' => 'Állam',
                 'owner_id' => null
             ];
        }

        // Get Default Vehicle
        $vehicles = $this->vehicleRepository->getUserVehicles((int)$userId);
        $defaultVehicle = null;
        
        foreach ($vehicles as $v) {
            if (!empty($v['is_default'])) {
                $defaultVehicle = $v;
                break;
            }
        }
        
        // Get all countries for the list
        $allCountries = $this->buildingService->getAllCountries();
        
        // --- Cooldown Calculation with Buffs ---
        $lastTravel = $user['last_travel_time'] ?? null;
        
        // Get Cooldown Reduction
        $reductionParams = $this->buffService->getActiveBonus((int)$userId, 'cooldown_reduction', 'travel');
        $buffSources = $this->buffService->getActiveBuffSources((int)$userId, 'cooldown_reduction', 'travel'); // Fetch names
        
        // Use Calculator
        $effectiveMinutes = \Netmafia\Modules\Buildings\Domain\TravelCalculator::calculateEffectiveCooldown((float)$reductionParams);
        $cooldownRemaining = \Netmafia\Modules\Buildings\Domain\TravelCalculator::calculateRemainingSeconds($lastTravel, $effectiveMinutes);
        $cooldownTarget = ($cooldownRemaining > 0 && $lastTravel) ? (strtotime($lastTravel) + (int)($effectiveMinutes * 60)) : 0;
        
        // --- AIRPLANE JET CALCULATION ---
        // A leggyorsabb (legkisebb defense értékű) jet-et keressük a játékosnál
        $db = $this->buildingService->getDb(); // Hozzáadjuk a Db lekérdezést
        $bestJet = $db->fetchAssociative(
            "SELECT i.defense as travel_time 
             FROM user_items ui
             JOIN items i ON i.id = ui.item_id
             WHERE ui.user_id = ? AND i.type = 'jet' AND ui.quantity > 0
             ORDER BY i.defense ASC LIMIT 1",
            [$userId]
        );
        $hasJet = $bestJet !== false;
        $baseAirplaneCooldownMinutes = $hasJet ? (int)$bestJet['travel_time'] : TravelConfig::AIRPLANE_COOLDOWN_MINUTES;

        // --- Airplane Cooldown with Buffs ---
        $lastAirplaneTravel = $user['last_airplane_travel_time'] ?? null;
        $airplaneEffectiveMinutes = \Netmafia\Modules\Buildings\Domain\TravelCalculator::calculateEffectiveCooldown((float)$reductionParams, $baseAirplaneCooldownMinutes);
        $airplaneCooldownRemaining = \Netmafia\Modules\Buildings\Domain\TravelCalculator::calculateRemainingSeconds($lastAirplaneTravel, $airplaneEffectiveMinutes);
        $airplaneCooldownTarget = ($airplaneCooldownRemaining > 0 && $lastAirplaneTravel) ? (strtotime($lastAirplaneTravel) + (int)($airplaneEffectiveMinutes * 60)) : 0;

        
        // Prepare view data
        $travelOptions = [];
        $fuelCosts = TravelConfig::FUEL_COSTS;
        
        foreach ($allCountries as $c) {
            // Skip current country
            if ($c['code'] === $userCountry) {
                continue;
            }
            
            $fuelCost = $fuelCosts[$c['code']] ?? 50; // Default fallback
            
            // Flag mapping: check if file exists or use default/placeholder
            // We assume standard naming: public/images/flags/{code}.png
            $flagPath = '/images/flags/' . strtolower($c['code']) . '.png';
            
            $travelOptions[] = [
                'code' => $c['code'],
                'name' => $c['name_hu'],
                'flag' => $c['flag_emoji'],
                'flag_path' => $flagPath, // New field for image
                'fuel_cost' => $fuelCost,
                'airplane_cost' => $hasJet ? 0 : (TravelConfig::AIRPLANE_PRICES[$c['code']] ?? 100),
                'can_afford' => $defaultVehicle && ($defaultVehicle['current_fuel'] ?? 0) >= $fuelCost 
            ];
        }

        // Check if vehicle is in the same country as user
        $isVehicleHere = $defaultVehicle && ($defaultVehicle['country'] === $userCountry);


        return $this->view->render($response, 'game/buildings/highway/index.twig', [
            'user' => $user,
            'highway' => $highway,
            'default_vehicle' => $defaultVehicle,
            'is_vehicle_here' => $isVehicleHere, // Pass the flag
            'travel_options' => $travelOptions,
            'cooldown_remaining' => $cooldownRemaining,
            'cooldown_target' => $cooldownTarget,
            'total_cooldown_minutes' => round($effectiveMinutes, 1),
            'cooldown_reduction_percent' => $reductionParams,
            'buff_sources' => $buffSources, // Pass names
            'daily_usage' => (!$lastTravel || date('Y-m-d', strtotime($lastTravel)) !== date('Y-m-d')) ? 0 : ($user['daily_highway_usage'] ?? 0),
            
            // Sticker Info
            'sticker_level' => $user['highway_sticker_level'] ?? 0,
            'sticker_expiry' => $user['highway_sticker_expiry'] ?? null,
            'max_daily_usage' => TravelConfig::LIMIT_DEFAULT + (
                (strtotime($user['highway_sticker_expiry'] ?? '') > time()) 
                ? match((int)($user['highway_sticker_level'] ?? 0)) {
                    TravelConfig::STICKER_LEVEL_7 => TravelConfig::LIMIT_7,
                    TravelConfig::STICKER_LEVEL_10 => TravelConfig::LIMIT_10,
                    TravelConfig::STICKER_LEVEL_UNLIMITED => TravelConfig::LIMIT_UNLIMITED,
                    default => 0
                } 
                : 0
            ),

            'airport' => $airport,
            'airplane_cooldown_remaining' => $airplaneCooldownRemaining,
            'airplane_cooldown_target' => $airplaneCooldownTarget,
            'has_jet' => $hasJet,
            'total_airplane_cooldown_minutes' => round($airplaneEffectiveMinutes, 1),
            
            // Dynamic Sticker Prices
            'sticker_prices' => [
                'level7_week' => 1000 * (($highway['usage_price'] ?? 1000) ?: 1000) / 1000,
                'level7_month' => 3000 * (($highway['usage_price'] ?? 1000) ?: 1000) / 1000,
                'level10_week' => 3000 * (($highway['usage_price'] ?? 1000) ?: 1000) / 1000,
                'level10_month' => 9000 * (($highway['usage_price'] ?? 1000) ?: 1000) / 1000,
                'unlimited_week' => 12000 * (($highway['usage_price'] ?? 1000) ?: 1000) / 1000,
                'unlimited_month' => 35000 * (($highway['usage_price'] ?? 1000) ?: 1000) / 1000,
            ],

            'active_tab' => $request->getQueryParams()['tab'] ?? 'car',
            'is_ajax' => $request->hasHeader('HX-Request')
        ]);
    }
}
