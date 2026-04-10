<?php
declare(strict_types=1);

namespace Netmafia\Modules\Garage\Actions;

use Netmafia\Modules\Garage\Domain\VehicleRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

use Netmafia\Modules\Garage\Domain\TuningService;

class VehicleDetailsAction
{
    private Twig $view;
    private VehicleRepository $repository;
    private TuningService $tuningService;

    public function __construct(Twig $view, VehicleRepository $repository, TuningService $tuningService)
    {
        $this->view = $view;
        $this->repository = $repository;
        $this->tuningService = $tuningService;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        // Authentication check
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return $response->withStatus(401);
        }

        $vehicleId = (int) $args['id'];
        $vehicle = $this->repository->getVehicleDetails($vehicleId);

        if (!$vehicle) {
            return $response->withStatus(404);
        }

        // Ownership check - only show vehicle details if it belongs to the user
        if ((int) $vehicle['user_id'] !== (int) $userId) {
            return $response->withStatus(403);
        }

        // Use stored location field (no longer calculated)
        $isInGarage = ($vehicle['location'] ?? 'garage') === 'garage';
        
        // Get Tuning Data
        $tuningData = $this->tuningService->getVehicleTuningData($vehicle);

        return $this->view->render($response, 'garage/_vehicle_details_modal.twig', [
            'vehicle' => $vehicle,
            'is_in_garage' => $isInGarage,
            'tuning' => $tuningData
        ]);
    }
}
