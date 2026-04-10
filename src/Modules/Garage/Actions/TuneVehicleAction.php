<?php
declare(strict_types=1);

namespace Netmafia\Modules\Garage\Actions;

use Netmafia\Modules\Garage\Domain\TuningService;
use Netmafia\Modules\Garage\Domain\VehicleRepository;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Modules\Auth\Domain\AuthService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class TuneVehicleAction
{
    private Twig $view;
    private TuningService $tuningService;
    private VehicleRepository $repository; // Needed to re-fetch data for view
    private AuthService $authService;

    public function __construct(Twig $view, TuningService $tuningService, VehicleRepository $repository, AuthService $authService)
    {
        $this->view = $view;
        $this->tuningService = $tuningService;
        $this->repository = $repository;
        $this->authService = $authService;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return $response->withStatus(401);
        }

        $vehicleId = (int) $args['id'];
        $data = $request->getParsedBody();
        $partType = $data['part'] ?? null;
        $error = null;

        if (!$partType) {
            $error = 'Hiányzó tuning típus.';
        } else {
            // Check if vehicle allows tuning (only non-average cars)
            // Need to fetch vehicle here first to check category?
            // Actually, we fetch it below anyway. Let's fetch it earlier.
            $vehicle = $this->repository->getVehicleDetails($vehicleId);
            // Categories that allow tuning (NOT average)
            $premiumCategories = ['sport', 'suv', 'motor', 'luxury', 'muscle'];
            $isAverage = !in_array($vehicle['category'], $premiumCategories);
            
            if ($isAverage) {
                // Cannot tune average cars
                 $error = 'Átlagos kategóriájú autót nem lehet tuningolni!';
            } else {
                try {
                    if ($partType === 'full') {
                        $this->tuningService->tuneVehicleFull(UserId::of((int)$userId), $vehicleId);
                    } else {
                        $this->tuningService->tuneVehicle(UserId::of((int)$userId), $vehicleId, $partType);
                    }
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }

        // Re-render the modal content with updated data
        // $vehicle is already fetched (or re-fetched to get latest stats? Tuning service updates DB, so we should re-fetch)
        // Wait, if I fetched it above before tuning, it has OLD stats.
        // Tuning service commits to DB.
        // So I must re-fetch OR assume the service call updated it?
        // Service updates DB only. 
        // So I should re-fetch.
        $vehicle = $this->repository->getVehicleDetails($vehicleId);
        $isInGarage = ($vehicle['location'] ?? 'garage') === 'garage';
        $tuningData = $this->tuningService->getVehicleTuningData($vehicle);

        // 1. Render modal content
        $modalHtml = $this->view->fetch('garage/_vehicle_details_modal.twig', [
            'vehicle' => $vehicle,
            'is_in_garage' => $isInGarage,
            'tuning' => $tuningData,
            'error' => $error
        ]);

        $response->getBody()->write($modalHtml);
        
        return $response->withHeader('HX-Trigger', json_encode([
            'updateStats' => true,
            'refreshGarage' => true
        ]));
    }
}
