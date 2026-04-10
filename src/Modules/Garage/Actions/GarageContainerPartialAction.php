<?php
declare(strict_types=1);

namespace Netmafia\Modules\Garage\Actions;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Garage\Domain\GarageService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class GarageContainerPartialAction
{
    private Twig $view;
    private AuthService $authService;
    private GarageService $garageService;

    public function __construct(Twig $view, AuthService $authService, GarageService $garageService)
    {
        $this->view = $view;
        $this->authService = $authService;
        $this->garageService = $garageService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
             return $response->withStatus(401);
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

            return $this->view->render($response, 'garage/_garage_container.twig', array_merge(
                [
                    'user' => $user, 
                    'current_country' => $currentCountry,
                    'current_sort' => $sortBy,
                    'current_dir' => $sortDir
                ],
                $overviewData
            ));
        } catch (\Throwable $e) {
             return $response->withStatus(500); 
        }
    }
}
