<?php
declare(strict_types=1);

namespace Netmafia\Modules\Buildings\Actions;

use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Buildings\Domain\BuildingService;
use Netmafia\Modules\Buildings\Domain\RestaurantService;
use Netmafia\Infrastructure\SessionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class RestaurantViewAction
{
    private Twig $view;
    private AuthService $authService;
    private BuildingService $buildingService;
    private RestaurantService $restaurantService;
    // [2026-02-28] FIX: SessionService injektálva $_SESSION közvetlen használata helyett
    private SessionService $session;

    public function __construct(
        Twig $view, 
        AuthService $authService, 
        BuildingService $buildingService,
        RestaurantService $restaurantService,
        SessionService $session
    ) {
        $this->view = $view;
        $this->authService = $authService;
        $this->buildingService = $buildingService;
        $this->restaurantService = $restaurantService;
        $this->session = $session;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');

        $user = $this->authService->getAuthenticatedUser((int)$userId);
        $countryCode = $user['country_code'] ?? 'US';

        // Find Restaurant for this country
        $restaurant = $this->buildingService->getByCountryAndType($countryCode, 'restaurant');
        
        // If not found, fallback to 'US' or any restaurant (safety)
        if (!$restaurant) {
             $restaurant = $this->buildingService->getByCountryAndType('US', 'restaurant');
        }

        $menu = [];
        if ($restaurant) {
            $menu = $this->restaurantService->getMenu((int)$restaurant['id']);
        }

        return $this->view->render($response, 'restaurant/index.twig', [
            'user' => $user,
            'restaurant' => $restaurant,
            'menu' => $menu,
            'page_title' => 'Étterem',
            'is_ajax' => $request->hasHeader('HX-Request')
        ]);
    }
}
