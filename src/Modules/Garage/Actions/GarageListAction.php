<?php
declare(strict_types=1);

namespace Netmafia\Modules\Garage\Actions;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Garage\Domain\GarageService;
use Netmafia\Modules\Garage\Domain\VehicleRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

use Netmafia\Infrastructure\AuditLogger;

class GarageListAction
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
            $this->logger->log('auth_failed', null, ['reason' => 'no_user_id_in_session', 'action' => 'GarageListAction']);
             return $response->withHeader('Location', '/login')->withStatus(302);
        }

        try {
            $user = $this->authService->getAuthenticatedUser((int)$userId);
            if (!$user) {
                 throw new GameException("User not found");
            }
            
            
            // Delegate logic to Service
            $currentCountry = $user['country_code'];
            
            // Safe Input reading and Whitelist default application for sorting
            $sortBy = $request->getQueryParams()['sort'] ?? 'id';
            $sortDir = $request->getQueryParams()['dir'] ?? 'asc';
            if (!in_array($sortBy, ['id', 'speed', 'safety', 'tuning_percent'])) { $sortBy = 'id'; }
            if (!in_array($sortDir, ['asc', 'desc'])) { $sortDir = 'asc'; }

            $overviewData = $this->garageService->getGarageOverview((int)$userId, $currentCountry, $sortBy, $sortDir);

             if ($request->hasHeader('HX-Request')) {
                 $this->logger->log('garage_list_viewed', (int)$userId, ['mode' => 'htmx', 'country' => $currentCountry]);
             }

            return $this->view->render($response, 'garage/index.twig', array_merge(
                [
                    'user' => $user, 
                    'current_country' => $currentCountry, 
                    'page_title' => 'Garázs', 
                    'is_ajax' => $request->hasHeader('HX-Request'),
                    'current_sort' => $sortBy,
                    'current_dir' => $sortDir
                ],
                $overviewData
            ));
        } catch (\Throwable $e) {
            $this->logger->log('garage_list_error', (int)$userId, ['error' => $e->getMessage()]);
            // For now rethrow or handle gracefully. Let's just return 500 or redirect.
             return $response->withStatus(500); 
        }
    }
}
