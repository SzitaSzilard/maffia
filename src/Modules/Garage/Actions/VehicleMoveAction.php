<?php
declare(strict_types=1);

namespace Netmafia\Modules\Garage\Actions;

use Netmafia\Modules\Garage\Domain\GarageService;
use Netmafia\Modules\Garage\Domain\VehicleRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class VehicleMoveAction
{
    private GarageService $service;
    private VehicleRepository $repository;
    private Twig $view;

    public function __construct(GarageService $service, VehicleRepository $repository, Twig $view)
    {
        $this->service = $service;
        $this->repository = $repository;
        $this->view = $view;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return $response->withStatus(401);
        }

        $vehicleId = (int) $args['id'];
        $data = $request->getParsedBody();
        $target = $data['target'] ?? null;

        if (!$target || !in_array($target, ['garage', 'street'])) {
            $response->getBody()->write(json_encode(['error' => 'Érvénytelen célállomás']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $this->service->moveVehicle((int)$userId, $vehicleId, $target);
            
            // Refetch vehicle to render updated modal
            $vehicle = $this->repository->getVehicleDetails($vehicleId);
            $isInGarage = ($vehicle['location'] ?? 'garage') === 'garage';

            $response->getBody()->write($this->view->fetch('garage/_vehicle_details_modal.twig', [
                'vehicle' => $vehicle,
                'is_in_garage' => $isInGarage
            ]));

            return $response
                ->withHeader('HX-Trigger', json_encode(['refreshGarage' => true]))
                ->withStatus(200);

        } catch (\Throwable $e) {
            // Error handling: Re-render the modal with error message
            $vehicle = $this->repository->getVehicleDetails($vehicleId);
            
            if (!$vehicle) {
                return $response->withStatus(404);
            }

            $isInGarage = ($vehicle['location'] ?? 'garage') === 'garage';

            return $this->view->render($response, 'garage/_vehicle_details_modal.twig', [
                'vehicle' => $vehicle,
                'is_in_garage' => $isInGarage,
                'error' => $e->getMessage()
            ]); // Return 200 OK to allow swap
        }
    }
}
