<?php
declare(strict_types=1);

namespace Netmafia\Modules\Garage\Actions;

use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Garage\Domain\GarageService;
use Netmafia\Modules\Garage\Domain\VehicleRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

use Netmafia\Infrastructure\AuditLogger;

class GarageExpandAction
{
    private Twig $view;
    private AuthService $authService;
    private AuditLogger $logger;
    private GarageService $garageService;

    public function __construct(Twig $view, AuthService $authService, AuditLogger $logger, GarageService $garageService)
    {
        $this->view = $view;
        $this->authService = $authService;
        $this->logger = $logger;
        $this->garageService = $garageService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            $this->logger->log('auth_failed', null, ['reason' => 'no_user_id_in_session', 'action' => 'GarageExpandAction']);
             return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $user = $this->authService->getAuthenticatedUser((int)$userId);
        $currentCountry = $user['country_code'];
        
        
        $currentCountry = $user['country_code'];
        $expansionData = $this->garageService->getExpansionPageData((int)$userId, $currentCountry);

        $this->logger->log('garage_expand_view', $user['id'], ['is_ajax' => $request->hasHeader('HX-Request')]);
        
        return $this->view->render($response, 'garage/expand.twig', array_merge(
            ['user' => $user, 'current_country' => $currentCountry, 'page_title' => 'Garázs Bővítés', 'is_ajax' => $request->hasHeader('HX-Request')],
            $expansionData
        ));
    }
}
