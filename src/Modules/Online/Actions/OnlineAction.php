<?php
declare(strict_types=1);

namespace Netmafia\Modules\Online\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Netmafia\Modules\Online\Domain\OnlineService;
use Netmafia\Modules\Auth\Domain\AuthService;

class OnlineAction
{
    private Twig $view;
    private OnlineService $onlineService;
    private AuthService $authService;

    public function __construct(Twig $view, OnlineService $onlineService, AuthService $authService)
    {
        $this->view = $view;
        $this->onlineService = $onlineService;
        $this->authService = $authService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        // 0. Get Authenticated User (for Header Stats)
        $userId = $request->getAttribute('user_id');
        $user = $userId ? $this->authService->getAuthenticatedUser((int)$userId) : null;

        // 1. Get Data
        $onlineUsers = $this->onlineService->getOnlineUsers();
        $onlineCount = count($onlineUsers);

        // 2. Render
        return $this->view->render($response, 'online/index.twig', [
            'user' => $user, // Critical for header stats
            'online_users' => $onlineUsers,
            'online_count' => $onlineCount
        ]);
    }
}
