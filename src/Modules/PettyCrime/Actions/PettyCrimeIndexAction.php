<?php

declare(strict_types=1);

namespace Netmafia\Modules\PettyCrime\Actions;

use Netmafia\Modules\PettyCrime\Domain\PettyCrimeService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\Home\Domain\SleepService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Slim\Views\Twig;
use Netmafia\Infrastructure\SessionService;

class PettyCrimeIndexAction
{
    private Twig $view;
    private PettyCrimeService $pettyCrimeService;
    private SessionService $session;
    private SleepService $sleepService;

    public function __construct(Twig $view, PettyCrimeService $pettyCrimeService, SessionService $session, SleepService $sleepService)
    {
        $this->view = $view;
        $this->pettyCrimeService = $pettyCrimeService;
        $this->session = $session;
        $this->sleepService = $sleepService;
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

        $tab = $args['tab'] ?? 'lopas';

        $cooldown = $this->pettyCrimeService->getCooldownData($userId);
        $opportunities = $this->pettyCrimeService->getSessionOpportunities($userId);

        $viewData = [
            'user'              => $request->getAttribute('user'),
            'active_tab'        => $tab,
            'opportunities'     => $opportunities,
            'scan_remaining'    => $cooldown['scan_remaining'],
            'scan_target'       => $cooldown['scan_target'],
            'commit_remaining'  => $cooldown['commit_remaining'],
            'commit_target'     => $cooldown['commit_target'],
            'is_ajax'           => $request->hasHeader('HX-Request'),
        ];

        // Flash üzenetek
        $flashPayload = $this->session->getFlash('petty_crime_result');
        if ($flashPayload) {
            $viewData['result'] = json_decode($flashPayload, true);
        }
        $errorMsg = $this->session->getFlash('error');
        if ($errorMsg) {
            $viewData['global_error'] = $errorMsg;
        }

        $isAjax = $request->hasHeader('HX-Request');
        $hxTarget = $request->getHeaderLine('HX-Target');

        if ($isAjax && $hxTarget === 'petty-crime-content') {
            return $this->view->render($response, 'game/petty_crime/_lopas.twig', $viewData);
        }

        return $this->view->render($response, 'game/petty_crime/index.twig', $viewData);
    }
}
