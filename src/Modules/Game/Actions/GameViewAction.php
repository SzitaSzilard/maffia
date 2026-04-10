<?php
declare(strict_types=1);

namespace Netmafia\Modules\Game\Actions;

use Netmafia\Modules\Auth\Domain\AuthService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class GameViewAction
{
    private Twig $view;
    private AuthService $authService;
    private \Netmafia\Modules\Game\Domain\NewsService $newsService;

    public function __construct(Twig $view, AuthService $authService, \Netmafia\Modules\Game\Domain\NewsService $newsService)
    {
        $this->view = $view;
        $this->authService = $authService;
        $this->newsService = $newsService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $user = $userId ? $this->authService->getAuthenticatedUser((int)$userId) : null;
        
        $news = $this->newsService->getLatest(20);

        if ($request->hasHeader('HX-Request')) {
             return $this->view->render($response, 'game/dashboard.twig', [
                 'user' => $user,
                 'news' => $news,
                 'page_title' => 'Főoldal',
             ]);
        }

        return $this->view->render($response, 'layouts/base_game.twig', [
            'user' => $user,
            'news' => $news,
            'page_title' => 'Főoldal',
        ]);
    }
}
