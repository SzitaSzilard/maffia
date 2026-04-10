<?php
declare(strict_types=1);

namespace Netmafia\Modules\CarTheft\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Home\Domain\SleepService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Slim\Views\Twig;

class CarTheftIndexAction
{
    private Twig $view;
    private AuthService $auth;
    private SleepService $sleepService;

    public function __construct(Twig $view, AuthService $auth, SleepService $sleepService)
    {
        $this->view = $view;
        $this->auth = $auth;
        $this->sleepService = $sleepService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $user = $this->auth->getAuthenticatedUser($userId);

        $sleepStatus = $this->sleepService->getSleepStatus(UserId::of($userId));
        if ($sleepStatus !== null) {
            return $this->view->render($response, 'home/_sleeping_guard.twig', [
                'sleep_status' => $sleepStatus,
                'user' => $user,
                'is_ajax' => $request->hasHeader('HX-Request')
            ]);
        }

        return $this->view->render($response, 'car_theft/index.twig', [
            'user' => $user,
            'page_title' => 'Járműlopás',
            'is_ajax' => $request->hasHeader('HX-Request')
        ]);
    }
}
