<?php
declare(strict_types=1);

namespace Netmafia\Modules\OrganizedCrime\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\OrganizedCrime\Domain\OrganizedCrimeService;
use Netmafia\Modules\Auth\Domain\AuthService;
use Slim\Views\Twig;

class OrganizedCrimeMemberAction
{
    private OrganizedCrimeService $crimeService;
    private AuthService $authService;
    private Twig $twig;
    private $notifService;

    public function __construct(OrganizedCrimeService $crimeService, AuthService $authService, Twig $twig, $notifService = null)
    {
        $this->crimeService = $crimeService;
        $this->authService = $authService;
        $this->twig = $twig;
        $this->notifService = $notifService;
    }

    public function accept(Request $request, Response $response): Response
    {
        $userId = (int)$request->getAttribute('user_id');
        $crimeId = (int)($request->getParsedBody()['crime_id'] ?? 0);
        if ($crimeId > 0) {
            $this->crimeService->acceptInvite($userId, $crimeId);
        }
        return $response->withHeader('HX-Location', json_encode(['path' => '/szervezett-bunozes', 'target' => '#game-content']));
    }

    public function decline(Request $request, Response $response): Response
    {
        $userId = (int)$request->getAttribute('user_id');
        $crimeId = (int)($request->getParsedBody()['crime_id'] ?? 0);
        $user = $this->authService->getAuthenticatedUser($userId);
        
        if ($crimeId > 0) {
            $this->crimeService->declineInvite($userId, $crimeId);
            if ($this->notifService) {
                // Notifikáció a szervezőnek
                // ... lekérjük a szervezőt a db-ből, de egyszerűbb ha a service csinálja. 
                // Ehelyett csak bízunk benne vagy itt csinálunk egy direkt lekérdezést.
            }
        }
        return $response->withHeader('HX-Location', json_encode(['path' => '/szervezett-bunozes', 'target' => '#game-content']));
    }

    public function revoke(Request $request, Response $response): Response
    {
        $userId = (int)$request->getAttribute('user_id');
        $memberId = (int)($request->getParsedBody()['member_id'] ?? 0);
        
        $activeCrime = $this->crimeService->getActiveCrimeForUser($userId);
        if ($activeCrime && $activeCrime['leader_id'] === $userId && $memberId > 0) {
            $res = $this->crimeService->revokeInvite($userId, (int)$activeCrime['id'], $memberId);
            
            // Ha sikeres volt a visszavonás és a tag már elfogadta a meghívót, értesítjük!
            if (isset($res['success']) && $res['success'] && $this->notifService && !empty($res['user_id']) && $res['status'] === 'accepted') {
                $this->notifService->send((int)$res['user_id'], 'organized_crime_revoke', 'A szervező kirúgott a szervezésből.', 'organized_crime', '/szervezett-bunozes');
            }
        }
        
        // Újratöltjük a crime-t HTMX-hez
        $activeCrime = $this->crimeService->getActiveCrimeForUser($userId);
        return $this->twig->render($response, 'game/organized_crime/_squad.twig', [
            'crime' => $activeCrime,
            'is_organizer' => true,
            'session_user_id' => $userId,
            'is_ajax' => $request->hasHeader('HX-Request')
        ]);
    }

    public function leave(Request $request, Response $response): Response
    {
        $userId = (int)$request->getAttribute('user_id');
        $activeCrime = $this->crimeService->getActiveCrimeForUser($userId);
        if ($activeCrime && $activeCrime['leader_id'] !== $userId) {
            $this->crimeService->leaveCrime($userId, (int)$activeCrime['id']);
        }
        
        // HTMX válasz (HX-Location megtartja az HX-Triggert, és a game-contentbe tölti az új oldalt)
        return $response->withHeader('HX-Location', json_encode(['path' => '/szervezett-bunozes', 'target' => '#game-content']));
    }

    public function disband(Request $request, Response $response): Response
    {
        $userId = (int)$request->getAttribute('user_id');
        $activeCrime = $this->crimeService->getActiveCrimeForUser($userId);
        if ($activeCrime && $activeCrime['leader_id'] === $userId) {
            $res = $this->crimeService->disbandCrime($userId, (int)$activeCrime['id']);
            if ($res['success'] && $this->notifService) {
                foreach ($res['members'] as $mId) {
                    $this->notifService->send((int)$mId, 'organized_crime_disband', 'A szervező feloszlatta a szervezést.', 'organized_crime', '/szervezett-bunozes');
                }
            }
        }
        return $response->withHeader('HX-Location', json_encode(['path' => '/szervezett-bunozes', 'target' => '#game-content']));
    }
}
