<?php
declare(strict_types=1);

namespace Netmafia\Modules\Buildings\Actions;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

use Netmafia\Modules\Buildings\Domain\BuildingService;
use Netmafia\Infrastructure\SessionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HighwayUpdateAction
{
    private BuildingService $buildingService;
    // [2026-02-28] FIX: SessionService injektálva $_SESSION közvetlen használata helyett
    private SessionService $session;

    public function __construct(BuildingService $buildingService, SessionService $session)
    {
        $this->buildingService = $buildingService;
        $this->session = $session;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        if (!$userId) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $data = $request->getParsedBody();
        $buildingId = (int)($data['building_id'] ?? 0);

        try {
            // Retrieve building to verify ownership
            $building = $this->buildingService->getById($buildingId);
            
            if (!$building || $building['owner_id'] != $userId || $building['type'] !== 'highway') {
                throw new GameException("Nincs jogosultságod módosítani ezt az épületet!");
            }



            // Update Payout Mode
            if (isset($data['payout_mode'])) {
                $mode = $data['payout_mode'];
                $this->buildingService->setPayoutMode($buildingId, $userId, $mode);
            }
            
            // Check for Usage Price update (Matrica ár?)
            // Although plan implied only fuel cost, user might expect setting a price.
            // Highway usually has "Matrica" or toll.
            // Let's allow setting price if field exists.
            if (isset($data['usage_price'])) {
                $price = (int)$data['usage_price'];
                $this->buildingService->setPrice($buildingId, $userId, $price);
            }

            $this->session->flash('highway_manage_success', "Beállítások frissítve!");

        } catch (\Throwable $e) {
            $this->session->flash('highway_manage_error', $e->getMessage());
        }

        return $response
            ->withHeader('Location', '/epulet/autopalya/kezel')
            ->withStatus(302);
    }
}
