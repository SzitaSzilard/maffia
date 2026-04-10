<?php
declare(strict_types=1);

namespace Netmafia\Modules\Garage\Actions;

use Netmafia\Modules\Garage\Domain\VehicleRepository;
use Netmafia\Modules\Garage\Domain\GarageService;
use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Garage\Domain\TuningService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class VehicleBuyUpgradeAction
{
    private Twig $view;
    private VehicleRepository $repository;
    private GarageService $garageService;
    private AuthService $authService;
    private TuningService $tuningService;

    public function __construct(
        Twig $view, 
        VehicleRepository $repository, 
        GarageService $garageService,
        AuthService $authService,
        TuningService $tuningService
    ) {
        $this->view = $view;
        $this->repository = $repository;
        $this->garageService = $garageService;
        $this->authService = $authService;
        $this->tuningService = $tuningService;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return $response->withStatus(401);
        }

        $vehicleId = (int) $args['id'];
        $data = $request->getParsedBody();
        $upgradeType = $data['upgrade'] ?? null;

        if (!$upgradeType) {
             $response->getBody()->write('Érvénytelen fejlesztés.');
             return $response->withStatus(400);
        }

        try {
            // Use Service for Transactional Upgrade
            $this->garageService->buySecurityUpgrade((int)$userId, $vehicleId, $upgradeType);

            // Success - Refresh Modal
            // We need to re-fetch vehicle to get updated stats and flags
            $vehicle = $this->repository->getVehicleDetails($vehicleId);
            $tuningData = $this->tuningService->getVehicleTuningData($vehicle);
            $isInGarage = ($vehicle['location'] ?? 'garage') === 'garage';
            
            // Render modal content
            $modalHtml = $this->view->fetch('garage/_vehicle_details_modal.twig', [
                'vehicle' => $vehicle,
                'is_in_garage' => $isInGarage,
                'tuning' => $tuningData,
                'error' => null
            ]);

            $response->getBody()->write($modalHtml);

            return $response->withHeader('HX-Trigger', json_encode([
                'updateStats' => true,
                'refreshGarage' => true
            ]));

        } catch (\Throwable $e) {
            // We need to fetch vehicle to display error in modal context if possible
            $vehicle = $this->repository->getVehicleDetails($vehicleId);
            
            if (!$vehicle) {
                $response->getBody()->write($e->getMessage());
                return $response->withStatus(404);
            }

             $tuningData = $this->tuningService->getVehicleTuningData($vehicle);
             $isInGarage = ($vehicle['location'] ?? 'garage') === 'garage';

             return $this->view->render($response, 'garage/_vehicle_details_modal.twig', [
                'vehicle' => $vehicle,
                'is_in_garage' => $isInGarage,
                'tuning' => $tuningData,
                'error' => $e->getMessage()
            ]);
        }
    }
}
