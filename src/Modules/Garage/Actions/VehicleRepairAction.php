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

class VehicleRepairAction
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

        try {
            // Use Service for Transactional Repair
            $this->garageService->repairVehicle((int)$userId, $vehicleId);

            // Success - Refresh Modal
            // We need to re-fetch vehicle to get updated stats
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
             // Fetch vehicle again to show error on valid object (if possible)
             $vehicle = $this->repository->getVehicleDetails($vehicleId);
             
             // If vehicle not found (e.g. deleted), we can't show modal.
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
