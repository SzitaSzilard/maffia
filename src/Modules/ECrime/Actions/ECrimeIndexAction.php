<?php
declare(strict_types=1);

namespace Netmafia\Modules\ECrime\Actions;

use Netmafia\Modules\ECrime\Domain\ECrimeConfig;
use Netmafia\Modules\ECrime\Domain\ECrimeService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Netmafia\Infrastructure\SessionService;
use Netmafia\Modules\Home\Domain\SleepService;
use Netmafia\Shared\Domain\ValueObjects\UserId;

class ECrimeIndexAction
{
    private Twig $view;
    private ECrimeService $eCrimeService;
    private SessionService $session;
    private SleepService $sleepService;
    private \Netmafia\Modules\ECrime\Domain\HackService $hackService;

    public function __construct(Twig $view, ECrimeService $eCrimeService, SessionService $session, SleepService $sleepService, \Netmafia\Modules\ECrime\Domain\HackService $hackService)
    {
        $this->view = $view;
        $this->eCrimeService = $eCrimeService;
        $this->session = $session;
        $this->sleepService = $sleepService;
        $this->hackService = $hackService;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $userId = (int)$request->getAttribute('user_id');
        $user = $request->getAttribute('user');

        // Alvás védelem
        $sleepStatus = $this->sleepService->getSleepStatus(UserId::of($userId));
        if ($sleepStatus !== null) {
            return $this->view->render($response, 'home/_sleeping_guard.twig', [
                'sleep_status' => $sleepStatus,
                'user' => $user,
                'is_ajax' => $request->hasHeader('HX-Request')
            ]);
        }

        $tab = $args['tab'] ?? 'atveresek';

        // Lazy decay: minden E-Crime oldal betöltésekor ellenőrzés
        $decayApplied = $this->hackService->applyLazyDecay(UserId::of($userId));


        $cooldownUntil = $this->eCrimeService->getUserCooldown($userId);
        
        $cooldownRemainingSeconds = 0;
        $cooldownTarget = 0;
        if ($cooldownUntil !== null) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $cdTime = new \DateTimeImmutable($cooldownUntil, new \DateTimeZone('UTC'));
            if ($cdTime > $now) {
                $cooldownRemainingSeconds = $cdTime->getTimestamp() - $now->getTimestamp();
                $cooldownTarget = $cdTime->getTimestamp();
            }
        }

        $isAjax = $request->hasHeader('HX-Request');
        $hxTarget = $request->getHeaderLine('HX-Target');

        $webserverActive = false;
        $webserverDaysLeft = 0;
        $webserverExpireTimestamp = 0;
        if (!empty($user['webserver_expire_at'])) {
            $wsExpire = new \DateTimeImmutable($user['webserver_expire_at'], new \DateTimeZone('UTC'));
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if ($wsExpire > $now) {
                $webserverActive = true;
                $diff = $now->diff($wsExpire);
                $webserverDaysLeft = $diff->days;
                $webserverExpireTimestamp = $wsExpire->getTimestamp();
            }
        }

        $scamAttempts = (int)($user['scam_attempts'] ?? 0);
        $scamsData = [];
        foreach (ECrimeConfig::SCAM_TYPES as $id => $config) {
            $base = $config['base_success_chance'];
            $max = $config['max_success_chance'];
            $kFactor = $config['k_factor'];
            
            $currentChance = (int)min($max, round($base + $kFactor * log(1 + $scamAttempts)));
            $config['success_chance'] = $currentChance;
            $scamsData[$id] = $config;
        }

        $viewData = [
            'user' => $user,
            'active_tab' => $tab,
            'scams' => $scamsData,
            'cooldown_remaining' => $cooldownRemainingSeconds,
            'cooldown_target' => $cooldownTarget,
            'webserver_active' => $webserverActive,
            'webserver_days_left' => $webserverDaysLeft,
            'webserver_expire_timestamp' => $webserverExpireTimestamp,
            'is_ajax' => $isAjax,
            'decay_applied' => $decayApplied,
        ];

        // Retrieve any flash messages (e.g., from PRG pattern)
        $successMsg = $this->session->getFlash('success');
        $errorMsg = $this->session->getFlash('error');
        
        // Custom Flash Payload from ECrimeExecuteScamAction
        $flashPayload = $this->session->getFlash('ecrime_result');
        if ($flashPayload) {
            $viewData['result'] = json_decode($flashPayload, true);
        }

        // Custom Flash Payload from HackDevelopAction
        $hackFlashPayload = $this->session->getFlash('hack_result');
        if ($hackFlashPayload) {
            $viewData['hack_result'] = json_decode($hackFlashPayload, true);
        }

        if ($successMsg) {
            $viewData['global_success'] = $successMsg;
        }
        if ($errorMsg) {
            $viewData['global_error'] = $errorMsg;
        }

        // Hacking specifikus adatok
        $hackDevCooldownRemaining = 0;
        $hackDevCooldownTarget = 0;
        if (!empty($user['virus_dev_cooldown_until'])) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $cdTime = new \DateTimeImmutable($user['virus_dev_cooldown_until'], new \DateTimeZone('UTC'));
            if ($cdTime > $now) {
                $hackDevCooldownRemaining = $cdTime->getTimestamp() - $now->getTimestamp();
                $hackDevCooldownTarget = $cdTime->getTimestamp();
            }
        }
        $viewData['virus_progress'] = (float)($user['virus_progress'] ?? 0);
        $viewData['zombie_count'] = (int)($user['zombie_count'] ?? 0);
        $viewData['has_peripherals'] = !empty($user['has_peripherals']);
        $viewData['hack_dev_cooldown_remaining'] = $hackDevCooldownRemaining;
        $viewData['hack_dev_cooldown_target'] = $hackDevCooldownTarget;

        $distChances = [];
        if ($tab === 'hackeles') {
            $distAttempts = (int)($user['virus_dist_attempts'] ?? 0);
            $distChances = [
                1 => $this->hackService->calculateDistributionChance(1, $viewData['virus_progress'], $distAttempts),
                2 => $this->hackService->calculateDistributionChance(2, $viewData['virus_progress'], $distAttempts),
                3 => $this->hackService->calculateDistributionChance(3, $viewData['virus_progress'], $distAttempts)
            ];
        }
        $viewData['dist_chances'] = $distChances;

        // Ha kifejezetten a belső tabot akarjuk frissíteni (HX-Target = ecrime-content)
        if ($isAjax && $hxTarget === 'ecrime-content') {
            if ($tab === 'bolt') {
                return $this->view->render($response, 'game/ecrime/_pcbolt.twig', $viewData);
            } elseif ($tab === 'hackeles') {
                return $this->view->render($response, 'game/ecrime/_hacking.twig', $viewData);
            }
            return $this->view->render($response, 'game/ecrime/_scams.twig', $viewData);
        }

        // Ha a bal menüből jöttünk (HX-Target = main-content) VAGY sima F5 frissítés,
        // akkor a teljes index.twig-et adjuk vissza.
        return $this->view->render($response, 'game/ecrime/index.twig', $viewData);
    }
}
