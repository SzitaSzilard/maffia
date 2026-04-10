<?php
declare(strict_types=1);

namespace Netmafia\Modules\Buildings\Actions;

use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Buildings\Domain\BuildingService;
use Netmafia\Modules\Buildings\Domain\HospitalService;
use Netmafia\Infrastructure\SessionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class HospitalViewAction
{
    private Twig $view;
    private AuthService $authService;
    private HospitalService $hospitalService;
    private BuildingService $buildingService;
    private SessionService $sessionService;

    public function __construct(Twig $view, AuthService $authService, HospitalService $hospitalService, BuildingService $buildingService, SessionService $sessionService)
    {
        $this->view = $view;
        $this->authService = $authService;
        $this->hospitalService = $hospitalService;
        $this->buildingService = $buildingService;
        $this->sessionService = $sessionService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        // Find nearest Hospital (in a real game, this would be based on location/city)
        // For now, we pick the first available hospital or a specific one "Amerikai kórház" (ID 41 based on seed log expectation)
        // We'll search by name to be safe or type.
        // Let's grab the first building with name like 'kórház'
        
        // This logic should probably be in Service, but for MVP Action can find the ID.
        // Actually, let's hardcode getting ID 41 or search dynamically.
        // We will assume 'Amerikai kórház' is the one for 'USA'.
        // Wait, user has countries. We should respect that. But for now, let's just find ANY hospital.
        
        $user = $this->authService->getAuthenticatedUser($userId);
        $countryCode = $user['country_code'] ?? 'US';

        // Find Hospital for this country
        // Assuming 'hospital' is the type identifier or we need to check DB structure.
        // If type column is string 'hospital' or 'korhaz'. Earlier seed check used LIKE '%kórház%'.
        // BuildingService::getByCountryAndType expects a type string. 
        // Let's try 'hospital' first, adhering to likely English keys, or 'korhaz' if HU.
        // Given Restaurant module uses 'restaurant', 'hospital' is consistent.
        // If that fails, we might need to search by name.
        
        // Let's use getByCountryAndType if type is reliable.
        // If not, we search via name.
        
        $hospital = $this->buildingService->getByCountryAndType($countryCode, 'hospital');
         
        // Fallback: If no dedicated hospital type found, try name search manually
        if (!$hospital) {
             $allBuildings = $this->buildingService->getByCountry($countryCode);
             foreach ($allBuildings as $b) {
                 if (stripos($b['name_hu'], 'kórház') !== false || stripos($b['name_hu'], 'hospital') !== false) {
                     $hospital = $b;
                     break;
                 }
             }
        }
        
        // Absolute fallback to preventing crash if nothing found (e.g. initial DB state)
        if (!$hospital) {
             // Mock data to prevent crash
             $hospital = [
                 'id' => 0,
                 'name_hu' => 'Kórház',
                 'country_name' => $countryCode,
                 'owner_id' => null,
                 'owner_name' => 'Állam',
                 'owner_cut_percent' => 0
             ];
             $pricePerHp = 52; // Default
        } else {
             $pricePerHp = $this->hospitalService->getPrice((int)$hospital['id']);
        }
        
        $hospitalId = (int)$hospital['id'];

        // Ensure user health is up to date
        $currentHp = (int)$user['health'];
        $missingHp = 100 - $currentHp;
        $totalCost = $missingHp * $pricePerHp;

        $isOwner = ($hospital['id'] !== 0 && $hospital['owner_id'] === $userId);

        return $this->view->render($response, 'hospital/index.twig', [
            'user'        => $user,
            'hospital'    => $hospital,
            'price_per_hp' => $pricePerHp,
            'missing_hp'  => $missingHp,
            'total_cost'  => $totalCost,
            'is_owner'    => $isOwner,
            'is_ajax'     => $request->hasHeader('HX-Request'),
        ]);
    }
}
