<?php
declare(strict_types=1);

namespace Netmafia\Modules\Garage\Actions;

use Netmafia\Modules\Garage\Domain\GarageService;
use Netmafia\Modules\Garage\Domain\VehicleRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class VehicleSetDefaultAction
{
    private Twig $view;
    private GarageService $garageService;
    private VehicleRepository $repository;

    public function __construct(Twig $view, GarageService $garageService, VehicleRepository $repository)
    {
        $this->view = $view;
        $this->garageService = $garageService;
        $this->repository = $repository;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return $response->withStatus(401);
        }

        $vehicleId = (int) $args['id'];
        $error = null;

        try {
            $this->garageService->setDefaultVehicle((int)$userId, $vehicleId);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        // Re-render the modal content to reflect changes
        $vehicle = $this->repository->getVehicleDetails($vehicleId);
        $isInGarage = ($vehicle['location'] ?? 'garage') === 'garage';

        $response->getBody()->write($this->view->fetch('garage/_vehicle_details_modal.twig', [
            'vehicle' => $vehicle,
            'is_in_garage' => $isInGarage,
            'error' => $error
        ]));

        return $response->withHeader('HX-Trigger', json_encode(['refreshGarage' => true]));
    }
}
