<?php
declare(strict_types=1);

namespace Netmafia\Modules\Garage\Actions;

use Netmafia\Modules\Garage\Domain\GarageService;
use Netmafia\Shared\Exceptions\GameException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class VehiclesQuickSellBatchAction
{
    private GarageService $garageService;

    public function __construct(GarageService $garageService)
    {
        $this->garageService = $garageService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return $response->withStatus(401);
        }

        $parsedBody = $request->getParsedBody();
        $selectedVehicles = $parsedBody['selected_vehicles'] ?? [];

        if (!is_array($selectedVehicles) || empty($selectedVehicles)) {
            $errorHtml = '<div style="text-align:center; padding: 10px; background: #ffebee; color: #c62828; border: 1px solid #ef9a9a; border-radius: 4px;">' .
                         'Nincs jármű kijelölve az eladáshoz!' .
                         '</div>';
            $response->getBody()->write($errorHtml);
            return $response->withStatus(200);
        }

        // Cast all to integers securely
        $vehicleIds = array_map('intval', $selectedVehicles);

        try {
            $totalEarned = $this->garageService->batchQuickSell((int)$userId, $vehicleIds);

            $successHtml = '<div style="text-align:center; padding: 10px; background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; border-radius: 4px;">' .
                           'Sikeresen eladtál ' . count($vehicleIds) . ' db járművet a bontóban összesen <strong>$' . number_format($totalEarned) . '</strong> összegért!' .
                           '</div>';
                           
            $response->getBody()->write($successHtml);
            
            // Trigger UI updates safely (header stats + garage list itself via hx-trigger mechanism)
            return $response
                ->withHeader('HX-Trigger', json_encode([
                    'updateStats' => true,
                    'refreshGarage' => true
                ]))
                ->withStatus(200);

        } catch (GameException $e) {
            $errorHtml = '<div style="text-align:center; padding: 10px; background: #ffebee; color: #c62828; border: 1px solid #ef9a9a; border-radius: 4px;">' .
                         htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') .
                         '</div>';
            $response->getBody()->write($errorHtml);
            return $response->withStatus(200);
        }
    }
}
