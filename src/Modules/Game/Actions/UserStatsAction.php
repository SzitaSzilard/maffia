<?php
declare(strict_types=1);

namespace Netmafia\Modules\Game\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Netmafia\Modules\Auth\Domain\AuthService;

class UserStatsAction
{
    private Twig $view;
    private AuthService $authService;

    public function __construct(Twig $view, AuthService $authService)
    {
        $this->view = $view;
        $this->authService = $authService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return $response->withStatus(401);
        }

        $user = $this->authService->getAuthenticatedUser((int)$userId);

        return $this->view->render($response, '_partials/user_stats.twig', [
            'user' => $user
        ]);
    }
}
