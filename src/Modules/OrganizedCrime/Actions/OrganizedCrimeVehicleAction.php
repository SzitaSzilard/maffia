<?php
declare(strict_types=1);

namespace Netmafia\Modules\OrganizedCrime\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\OrganizedCrime\Domain\OrganizedCrimeService;
use Netmafia\Modules\Auth\Domain\AuthService;
use Slim\Views\Twig;

class OrganizedCrimeVehicleAction
{
    private OrganizedCrimeService $crimeService;
    private AuthService $authService;
    private Twig $twig;

    public function __construct(
        OrganizedCrimeService $crimeService, 
        AuthService $authService, 
        Twig $twig
    ) {
        $this->crimeService = $crimeService;
        $this->authService = $authService;
        $this->twig = $twig;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $sessionUserId = (int)$request->getAttribute('user_id');
        $user = $this->authService->getAuthenticatedUser($sessionUserId);
        
        $data = $request->getParsedBody();
        $vehicleId = (int)($data['vehicle_id'] ?? 0);
        
        $activeCrime = $this->crimeService->getActiveCrimeForUser($sessionUserId);
        
        $viewData = [
            'crime' => $activeCrime,
            'is_organizer' => ($activeCrime && $activeCrime['leader_id'] === $sessionUserId),
            'session_user_id' => $sessionUserId,
            'is_ajax' => $request->hasHeader('HX-Request')
        ];

        if ($vehicleId <= 0) {
            $viewData['global_error'] = 'Kérlek válassz egy autót!';
            return $this->twig->render($response, 'game/organized_crime/_squad.twig', $viewData);
        }
        
        $res = $this->crimeService->selectVehicle($sessionUserId, $vehicleId);
        
        if (!$res['success']) {
            $viewData['global_error'] = $res['error'];
        } else {
            $viewData['global_success'] = 'Sikeresen kiválasztottad a járművet az akcióhoz!';
            // Refresh crime state
            $activeCrime = $this->crimeService->getActiveCrimeForUser($sessionUserId);
            $viewData['crime'] = $activeCrime;
        }

        return $this->twig->render($response, 'game/organized_crime/_squad.twig', $viewData);
    }
}
