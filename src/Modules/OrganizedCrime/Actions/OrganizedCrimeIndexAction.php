<?php
declare(strict_types=1);

namespace Netmafia\Modules\OrganizedCrime\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\OrganizedCrime\Domain\OrganizedCrimeService;
use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Home\Domain\SleepService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Slim\Views\Twig;

class OrganizedCrimeIndexAction
{
    private Twig $twig;
    private OrganizedCrimeService $crimeService;
    private AuthService $authService;
    private SleepService $sleepService;

    public function __construct(Twig $twig, OrganizedCrimeService $crimeService, AuthService $authService, SleepService $sleepService)
    {
        $this->twig = $twig;
        $this->crimeService = $crimeService;
        $this->authService = $authService;
        $this->sleepService = $sleepService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $sessionUserId = (int)$request->getAttribute('user_id');
        $user = $request->getAttribute('user');

        // Alvás védelem
        $sleepStatus = $this->sleepService->getSleepStatus(UserId::of($sessionUserId));
        if ($sleepStatus !== null) {
            return $this->twig->render($response, 'home/_sleeping_guard.twig', [
                'sleep_status' => $sleepStatus,
                'user' => $user,
                'is_ajax' => $request->hasHeader('HX-Request')
            ]);
        }
        
        // Jelenlegi aktív rablás lekérése, ha van
        $activeCrime = $this->crimeService->getActiveCrimeForUser($sessionUserId);
        
        // rank_name már benne van a user-ben az AuthMiddleware-ből
        $rankInfo = \Netmafia\Shared\Domain\RankCalculator::getRankInfo((int)($user['xp'] ?? 0));
        
        $isOrganizer = false;
        if ($activeCrime && $activeCrime['leader_id'] === $sessionUserId) {
            $isOrganizer = true;
        }
        
        // Cooldown számítás a szinkron timerhez
        $cooldownRemaining = 0;
        $cooldownTarget = 0;
        if (!empty($user['oc_cooldown_until'])) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $cooldownTime = new \DateTimeImmutable($user['oc_cooldown_until'], new \DateTimeZone('UTC'));
            if ($cooldownTime > $now) {
                $cooldownRemaining = $cooldownTime->getTimestamp() - $now->getTimestamp();
                $cooldownTarget = $cooldownTime->getTimestamp();
            }
        }
        
        $requiredRankName = array_values(\Netmafia\Shared\RankConfig::RANKS)[5] ?? 'Katona';
        
        $viewData = [
            'user' => $user,
            'user_rank_index' => $rankInfo['index'],
            'is_admin' => !empty($user['is_admin']),
            'is_ajax' => $request->hasHeader('HX-Request'),
            'active_crime' => $activeCrime,
            'tab' => 'casino',
            'crime' => $activeCrime,
            'is_organizer' => $isOrganizer,
            'session_user_id' => $sessionUserId,
            'cooldown_remaining' => $cooldownRemaining,
            'cooldown_target' => $cooldownTarget,
            'available_vehicles' => [],
            'required_rank_name' => $requiredRankName
        ];

        // Retrieve vehicle list if driver
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
                $viewData['available_vehicles'] = $this->crimeService->getAvailableVehiclesForCrime($sessionUserId);
            }
        }

        return $this->twig->render($response, 'game/organized_crime/index.twig', $viewData);
    }
}
