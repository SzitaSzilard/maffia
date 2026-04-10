<?php
declare(strict_types=1);

namespace Netmafia\Modules\CarTheft\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\CarTheft\Domain\CarTheftService;
use Netmafia\Infrastructure\SessionService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Modules\Home\Domain\SleepService;
use Slim\Views\Twig;

class CarTheftDealershipAction
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

    public function show(Request $request, Response $response): Response
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
        $attempts = (int)($user['car_theft_attempts'] ?? 0);

        $vehicles = $this->theftService->getDealershipVehicles($countryCode);

        // Esélyek kiszámítása 5-8%-os eltérésekkel
        $offsets = [random_int(5, 8), 0, -random_int(5, 8)];
        foreach ($vehicles as $i => &$vehicle) {
            $offset = $offsets[$i] ?? 0;
            $vehicle['theft_chance'] = $this->theftService->calculateDealershipChance($attempts, $offset);
        }

        // Cooldown
        $hasCooldown = !empty($user['has_car_theft_cooldown']);
        $cooldownUntilTs = 0;
        if ($hasCooldown && !empty($user['car_theft_cooldown_until'])) {
            $dt = new \DateTimeImmutable($user['car_theft_cooldown_until'], new \DateTimeZone('UTC'));
            $cooldownUntilTs = $dt->getTimestamp();
        }

        return $this->view->render($response, 'car_theft/dealership.twig', [
            'user' => $user,
            'page_title' => 'Szalonlopás',
            'vehicles' => $vehicles,
            'is_ajax' => $request->hasHeader('HX-Request'),
            'has_cooldown' => $hasCooldown,
            'cooldown_until_ts' => $cooldownUntilTs,
            'attempts' => $attempts,
        ]);
    }

    public function attempt(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $data = $request->getParsedBody();
        $vehicleId = (int) ($data['vehicle_id'] ?? 0);

        if ($vehicleId <= 0) {
            $this->session->flash('error', 'Érvénytelen jármű azonosító!');
            return $response->withHeader('Location', '/autolopas/szalon')->withStatus(303);
        }

        try {
            $result = $this->theftService->attemptDealershipTheft(UserId::of($userId), $vehicleId);

            if ($result['success']) {
                $msg = $result['message'];
                $this->session->flash('success', $msg);
            } else {
                $msg = $result['message'];
                $this->session->flash('error', $msg);
            }

        } catch (GameException $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return $response->withHeader('Location', '/autolopas/szalon')->withStatus(303);
    }
}
