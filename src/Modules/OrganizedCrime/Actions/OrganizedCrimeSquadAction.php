<?php
declare(strict_types=1);

namespace Netmafia\Modules\OrganizedCrime\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\OrganizedCrime\Domain\OrganizedCrimeService;
use Netmafia\Modules\Auth\Domain\AuthService;
use Slim\Views\Twig;

class OrganizedCrimeSquadAction
{
    private Twig $twig;
    private OrganizedCrimeService $crimeService;
    private AuthService $authService;

    public function __construct(Twig $twig, OrganizedCrimeService $crimeService, AuthService $authService)
    {
        $this->twig = $twig;
        $this->crimeService = $crimeService;
        $this->authService = $authService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $sessionUserId = (int)$request->getAttribute('user_id');
        
        $user = $request->getAttribute('user');
        $rankInfo = \Netmafia\Shared\Domain\RankCalculator::getRankInfo((int)($user['xp'] ?? 0));
        
        if (empty($user['is_admin']) && $rankInfo['index'] < 5) {
            $response->getBody()->write('');
            return $response;
        }

        $activeCrime = $this->crimeService->getActiveCrimeForUser($sessionUserId);
        
        $isOrganizer = false;
        if ($activeCrime && $activeCrime['leader_id'] === $sessionUserId) {
            $isOrganizer = true;
        }

        $availableVehicles = [];
        if ($activeCrime) {
            $isDriver = false;
            $hasVehicle = false;
            foreach ($activeCrime['members'] as $m) {
                if ($m['user_id'] === $sessionUserId) {
                    if (in_array($m['role'], ['driver_1', 'driver_2'], true)) {
                        $isDriver = true;
                    }
                    if (!empty($m['vehicle_id'])) {
                        $hasVehicle = true;
                    }
                    break;
                }
            }
            if ($isDriver && !$hasVehicle) {
                $availableVehicles = $this->crimeService->getAvailableVehiclesForCrime($sessionUserId);
            }
        }

        $viewData = [
            'crime' => $activeCrime,
            'is_organizer' => $isOrganizer,
            'session_user_id' => $sessionUserId,
            'available_vehicles' => $availableVehicles
        ];

        return $this->twig->render($response, 'game/organized_crime/_squad.twig', $viewData);
    }
}
