<?php
declare(strict_types=1);

namespace Netmafia\Modules\Buildings\Actions;

use Netmafia\Modules\Buildings\Domain\RestaurantService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Infrastructure\SessionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class RestaurantConsumeAction
{
    private RestaurantService $restaurantService;
    // [2026-02-28] FIX: SessionService injektálva $_SESSION közvetlen használata helyett
    private SessionService $session;

    public function __construct(RestaurantService $restaurantService, SessionService $session)
    {
        $this->restaurantService = $restaurantService;
        $this->session = $session;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');

        $data = $request->getParsedBody();
        $itemId = (int)($data['item_id'] ?? 0);

        try {
            $result = $this->restaurantService->consumeItem(UserId::of((int)$userId), $itemId);
            
            // [2026-02-28] FIX: SessionService flash() használata $_SESSION helyett
            $this->session->flash('restaurant_success', $result['message']);

        } catch (\Throwable $e) {
            $this->session->flash('restaurant_error', $e->getMessage());
        }

        return $response
            ->withHeader('Location', '/etterem')
            ->withStatus(302);
    }
}
