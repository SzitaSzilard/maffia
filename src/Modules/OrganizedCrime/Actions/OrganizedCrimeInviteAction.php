<?php
declare(strict_types=1);

namespace Netmafia\Modules\OrganizedCrime\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\OrganizedCrime\Domain\OrganizedCrimeService;
use Netmafia\Modules\OrganizedCrime\Domain\CrimeRequirementsValidator;
use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Notifications\Domain\NotificationService;
use Slim\Views\Twig;

class OrganizedCrimeInviteAction
{
    private OrganizedCrimeService $crimeService;
    private CrimeRequirementsValidator $validator;
    private AuthService $authService;
    private Twig $twig;

    public function __construct(
        OrganizedCrimeService $crimeService, 
        CrimeRequirementsValidator $validator, 
        AuthService $authService, 
        Twig $twig
    ) {
        $this->crimeService = $crimeService;
        $this->validator = $validator;
        $this->authService = $authService;
        $this->twig = $twig;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $sessionUserId = (int)$request->getAttribute('user_id');
        $user = $this->authService->getAuthenticatedUser($sessionUserId);
        
        $activeCrime = $this->crimeService->getActiveCrimeForUser($sessionUserId);
        
        $data = $request->getParsedBody();
        $targetUsername = trim($data['username'] ?? '');
        $role = $data['role'] ?? '';

        // Whitelist validáció (§10.1)
        if (!in_array($role, \Netmafia\Modules\OrganizedCrime\Domain\OrganizedCrimeConfig::VALID_ROLES, true)) {
            $role = '';
        }

        $viewData = [
            'crime' => $activeCrime,
            'is_organizer' => ($activeCrime && $activeCrime['leader_id'] === $sessionUserId),
            'session_user_id' => $sessionUserId,
            'is_ajax' => $request->hasHeader('HX-Request')
        ];

        if (empty($targetUsername) || empty($role)) {
            $viewData['global_error'] = 'Hiányzó adatok!';
            return $this->twig->render($response, 'game/organized_crime/_squad.twig', $viewData);
        }

        if (strtolower($targetUsername) === strtolower($user['username'])) {
            $viewData['global_error'] = 'Magadat nem hívhatod meg!';
            return $this->twig->render($response, 'game/organized_crime/_squad.twig', $viewData);
        }

        $validation = $this->validator->validateInvite($sessionUserId, $targetUsername, $role);
        if (!$validation['success']) {
            $viewData['global_error'] = $validation['error'];
            return $this->twig->render($response, 'game/organized_crime/_squad.twig', $viewData);
        }
        
        $targetId = $validation['target_id'];

        // If user doesn't have an active crime, create one!
        if (!$activeCrime) {
            $this->crimeService->createCrimeForUser($sessionUserId, 'casino');
            $activeCrime = $this->crimeService->getActiveCrimeForUser($sessionUserId);
            
            if (!$activeCrime) {
                $viewData['global_error'] = 'Nem sikerült létrehozni a bűnözést!';
                return $this->twig->render($response, 'game/organized_crime/_squad.twig', $viewData);
            }
            // Update view data
            $viewData['crime'] = $activeCrime;
            $viewData['is_organizer'] = true;
        }

        // Check if the user is the leader of the current active crime
        if ($activeCrime['leader_id'] !== $sessionUserId) {
            $viewData['global_error'] = 'Már meghívtak egy másik szervezett bűnözésbe vendégként, nem indíthatsz újat!';
            return $this->twig->render($response, 'game/organized_crime/_squad.twig', $viewData);
        }

        try {
            $res = $this->crimeService->inviteMember($activeCrime['id'], $sessionUserId, $targetId, $role);
            if (!$res['success']) {
                $viewData['global_error'] = $res['error'];
            } else {
                $viewData['global_success'] = "{$targetUsername} sikeresen meghívva!";
            }
        } catch (\Throwable $e) {
            $viewData['global_error'] = "Hiba történt a meghívás során: " . $e->getMessage();
        }

        // Újratöltjük a crime objektumot, hogy látszódjon a friss listában
        $activeCrime = $this->crimeService->getActiveCrimeForUser($sessionUserId);
        $viewData['crime'] = $activeCrime;

        return $this->twig->render($response, 'game/organized_crime/_squad.twig', $viewData);
    }
}
