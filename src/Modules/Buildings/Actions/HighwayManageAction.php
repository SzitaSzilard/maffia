<?php
declare(strict_types=1);

namespace Netmafia\Modules\Buildings\Actions;

use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Buildings\Domain\BuildingService;
use Netmafia\Infrastructure\SessionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class HighwayManageAction
{
    private Twig $view;
    private AuthService $authService;
    private BuildingService $buildingService;
    // [2026-02-28] FIX: SessionService injektálva $_SESSION közvetlen használata helyett
    private SessionService $session;

    public function __construct(
        Twig $view,
        AuthService $authService,
        BuildingService $buildingService,
        SessionService $session
    ) {
        $this->view = $view;
        $this->authService = $authService;
        $this->buildingService = $buildingService;
        $this->session = $session;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');

        $user = $this->authService->getAuthenticatedUser((int)$userId);
        $userCountry = $user['country_code'];
        
        // Find Highway for this country
        $highway = $this->buildingService->getByCountryAndType($userCountry, 'highway');

        // Check ownership
        if (!$highway || $highway['owner_id'] != $userId) {
             return $response->withHeader('Location', '/utazas')->withStatus(302);
        }

        return $this->view->render($response, 'game/buildings/highway/manage.twig', [
            'user' => $user,
            'building' => $highway,
            'is_ajax' => $request->hasHeader('HX-Request')
        ]);
    }
}
