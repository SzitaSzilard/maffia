<?php
declare(strict_types=1);

namespace Netmafia\Modules\Buildings\Actions;

use Netmafia\Modules\Buildings\Domain\RestaurantService;
use Netmafia\Infrastructure\SessionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class RestaurantUpdateAction
{
    private RestaurantService $restaurantService;
    private \Netmafia\Modules\Buildings\Domain\BuildingService $buildingService;
    // [2026-02-28] FIX: SessionService injektálva $_SESSION közvetlen használata helyett
    private SessionService $session;

    public function __construct(
        RestaurantService $restaurantService,
        \Netmafia\Modules\Buildings\Domain\BuildingService $buildingService,
        SessionService $session
    ) {
        $this->restaurantService = $restaurantService;
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
        
        try {
            // Case 1: Menu Item Update
            if (isset($data['item_id'])) {
                $itemId = (int)$data['item_id'];
                $price = (int)($data['price'] ?? 0);
                $energy = (int)($data['energy'] ?? 0);
                
                $this->restaurantService->updateItem($userId, $itemId, $price, $energy);
                $this->session->flash('restaurant_manage_success', "Sikeres módosítás!");
            } 
            // Case 2: Global Settings Update
            else {
                // Determine building ID (Restaurant in User's Country)
                // Since this action doesn't know the buildingId directly, we must find it.
                // OR we can pass building_id in the form (hidden input). Passing hidden input is better.
                // But let's look it up to be safe and consistent with other actions.
                // Actually, RestaurantService methods generally verify ownership but updateItem takes userId.
                // BuildingService setters take buildingId.
                
                // Let's expect building_id in POST or lookup. Lookup is safer.
                // Re-instantiating Auth/Building lookup is heavy but correct.
                // For now, let's assume we pass building_id in form for performance, but verify ownership.
                $buildingId = (int)($data['building_id'] ?? 0);
                
                // Verify ownership inside setters? Service has check.
                


                if (isset($data['payout_mode'])) {
                    $mode = $data['payout_mode'];
                    $this->buildingService->setPayoutMode($buildingId, $userId, $mode);
                }
                
                $this->session->flash('restaurant_manage_success', "Beállítások frissítve!");
            }

        } catch (\Throwable $e) {
            $this->session->flash('restaurant_manage_error', $e->getMessage());
        }

        return $response
            ->withHeader('Location', '/etterem/kezeles')
            ->withStatus(302);
    }
}
