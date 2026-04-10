<?php
declare(strict_types=1);

namespace Netmafia\Modules\Buildings\Actions;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

use Netmafia\Modules\Buildings\Domain\HospitalService;
use Netmafia\Modules\Buildings\Domain\BuildingService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class HospitalManageAction
{
    private Twig $view;
    private HospitalService $hospitalService;
    private BuildingService $buildingService;
    private \Netmafia\Modules\Auth\Domain\AuthService $authService;

    public function __construct(
        Twig $view, 
        HospitalService $hospitalService, 
        BuildingService $buildingService,
        \Netmafia\Modules\Auth\Domain\AuthService $authService
    ) {
        $this->view = $view;
        $this->hospitalService = $hospitalService;
        $this->buildingService = $buildingService;
        $this->authService = $authService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = (int)$request->getAttribute('user_id');
        
        $user = $this->authService->getAuthenticatedUser($userId);
        $countryCode = $user['country_code'] ?? 'US';

        // 1. Find Hospital for this country (Same logic as ViewAction)
        $hospital = $this->buildingService->getByCountryAndType($countryCode, 'hospital');
        
        // Fallback search
        if (!$hospital) {
             $allBuildings = $this->buildingService->getByCountry($countryCode);
             foreach ($allBuildings as $b) {
                 if (stripos($b['name_hu'], 'kórház') !== false || stripos($b['name_hu'], 'hospital') !== false) {
                     $hospital = $b;
                     break;
                 }
             }
        }
        
        if (!$hospital) {
             // If theoretically no hospital exists, you can't own it.
             return $this->view->render($response, 'errors/404.twig', ['message' => 'Nincs kórház ebben az országban!']);
        }
        
        $hospitalId = (int)$hospital['id'];

        // 2. Refresh building data to be sure
        $building = $this->buildingService->getById($hospitalId);
        
        if ($building['owner_id'] !== $userId) {
             return $this->view->render($response, 'errors/403.twig', ['message' => 'Nem te vagy a tulajdonos!']);
        }

        if ($request->getMethod() === 'POST') {
            $data = $request->getParsedBody();
            
            try {
                // 1. Update Price
                if (isset($data['price'])) {
                    $newPrice = (int)$data['price'];
                    $this->hospitalService->updatePrice($userId, $hospitalId, $newPrice);
                }



                // 3. Update Payout Mode
                if (isset($data['payout_mode'])) {
                    $mode = $data['payout_mode'];
                    // Validation in service
                    if (!$this->buildingService->setPayoutMode($hospitalId, $userId, $mode)) {
                        throw new InvalidInputException("Érvénytelen kifizetési mód.");
                    }
                }

                $success = "Beállítások sikeresen frissítve!";
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $currentPrice = $this->hospitalService->getPrice($hospitalId);
        // Refresh building data to get updated settings
        $building = $this->buildingService->getById($hospitalId);

        return $this->view->render($response, 'hospital/manage.twig', [
            'user' => $user,
            'hospital' => $building,
            'price' => $currentPrice,
            'success' => $success ?? null,
            'error' => $error ?? null,
            'is_ajax' => $request->hasHeader('HX-Request')
        ]);
    }
}
