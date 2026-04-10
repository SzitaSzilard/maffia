<?php
declare(strict_types=1);

namespace Netmafia\Modules\CarTheft\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\CarTheft\Domain\CarTheftService;
use Netmafia\Infrastructure\SessionService;
use Netmafia\Modules\Home\Domain\SleepService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Slim\Views\Twig;

class CarTheftStreetAction
{
    private Twig $view;
    private AuthService $auth;
    private CarTheftService $theftService;
    private SessionService $session;
    private SleepService $sleepService;

    public function __construct(Twig $view, AuthService $auth, CarTheftService $theftService, SessionService $session, SleepService $sleepService)
    {
        $this->view = $view;
        $this->auth = $auth;
        $this->theftService = $theftService;
        $this->session = $session;
        $this->sleepService = $sleepService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $user = $this->auth->getAuthenticatedUser($userId);

        // Alvás védelem
        $sleepStatus = $this->sleepService->getSleepStatus(UserId::of($userId));
        if ($sleepStatus !== null) {
            return $this->view->render($response, 'home/_sleeping_guard.twig', [
                'sleep_status' => $sleepStatus,
                'user' => $user,
                'is_ajax' => $request->hasHeader('HX-Request')
            ]);
        }
        
        $countryCode = $user['country_code'];
        $thiefXp = (int)($user['xp'] ?? 0);
        
        $vehiclesOnStreet = $this->theftService->getStreetVehicles($countryCode, $userId);
        
        // Esélyek előre kiszámítása bemutatáshoz (próbálkozásszám-alapú)
        $thiefAttempts = (int)($user['car_theft_attempts'] ?? 0);
        foreach ($vehiclesOnStreet as &$vehicle) {
            $vehicle['theft_chance'] = $this->theftService->calculateTheftChance($thiefAttempts);
        }


        // Cooldown ellenőrzés
        $hasCooldown = !empty($user['has_car_theft_cooldown']);
        $cooldownUntilTs = 0;
        if ($hasCooldown && !empty($user['car_theft_cooldown_until'])) {
            $dt = new \DateTimeImmutable($user['car_theft_cooldown_until'], new \DateTimeZone('UTC'));
            $cooldownUntilTs = $dt->getTimestamp();
        }

        return $this->view->render($response, 'car_theft/street.twig', [
            'user' => $user,
            'page_title' => 'Utcai Autólopás',
            'vehicles' => $vehiclesOnStreet,
            'is_ajax' => $request->hasHeader('HX-Request'),
            'has_cooldown' => $hasCooldown,
            'cooldown_until_ts' => $cooldownUntilTs,
        ]);
    }
}
