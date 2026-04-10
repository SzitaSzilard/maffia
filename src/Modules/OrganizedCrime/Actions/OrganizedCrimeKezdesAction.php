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

class OrganizedCrimeKezdesAction
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
        
        $viewData = [
            'is_organizer' => false,
            'session_user_id' => $sessionUserId,
            'is_ajax' => $request->hasHeader('HX-Request')
        ];
        
        $rankInfo = \Netmafia\Shared\Domain\RankCalculator::getRankInfo((int)($user['xp'] ?? 0));
        if ($rankInfo['index'] < 5) {
            $requiredRankName = array_values(\Netmafia\Shared\RankConfig::RANKS)[5] ?? 'Katona';
            $viewData['global_error'] = "A kaszinó kirablás szervezett bűnözéshez szükséges minimum rang: {$requiredRankName}";
            return $this->twig->render($response, 'game/organized_crime/_squad.twig', $viewData);
        }

        $activeCrime = $this->crimeService->getActiveCrimeForUser($sessionUserId);
        $viewData['crime'] = $activeCrime;

        if ($activeCrime) {
            $viewData['global_error'] = 'Már van aktív bűnözésed!';
            $viewData['is_organizer'] = ($activeCrime['leader_id'] === $sessionUserId);
            return $this->twig->render($response, 'game/organized_crime/_squad.twig', $viewData);
        }

        // Szervező cooldown ellenőrzése
        if (!empty($user['oc_cooldown_until'])) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $cooldownTime = new \DateTimeImmutable($user['oc_cooldown_until'], new \DateTimeZone('UTC'));
            if ($cooldownTime > $now) {
                $diffMinutes = (int)ceil(($cooldownTime->getTimestamp() - $now->getTimestamp()) / 60);
                $viewData['global_error'] = "Még {$diffMinutes} percet várnod kell a következő szervezett bűnözésig!";
                return $this->twig->render($response, 'game/organized_crime/_squad.twig', $viewData);
            }
        }

        $data = $request->getParsedBody();
        $targetUsername = '';
        $role = '';

        foreach ($data as $key => $value) {
            if (strpos($key, 'username_') === 0 && !empty(trim($value))) {
                $role = substr($key, 9); // Remove 'username_'
                $targetUsername = trim($value);
                break;
            }
        }

        // Whitelist validáció (§10.1)
        if (!empty($role) && !in_array($role, \Netmafia\Modules\OrganizedCrime\Domain\OrganizedCrimeConfig::VALID_ROLES, true)) {
            $viewData['global_error'] = 'Érvénytelen szerep!';
            return $this->twig->render($response, 'game/organized_crime/_squad.twig', $viewData);
        }

        if (empty($targetUsername) || empty($role)) {
            $viewData['global_error'] = 'Meg kell hívnod legalább 1 tagot a kezdéshez!';
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

        // Create an active crime
        $this->crimeService->createCrimeForUser($sessionUserId, 'casino');
        $activeCrime = $this->crimeService->getActiveCrimeForUser($sessionUserId);
        
        if (!$activeCrime) {
            $viewData['global_error'] = 'Nem sikerült létrehozni a bűnözést!';
            return $this->twig->render($response, 'game/organized_crime/_squad.twig', $viewData);
        }
        // Update view data
        $viewData['crime'] = $activeCrime;
        $viewData['is_organizer'] = true;

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
