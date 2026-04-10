<?php
declare(strict_types=1);

namespace Netmafia\Modules\Forum\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Netmafia\Modules\Forum\Domain\ForumService;

class ForumIndexAction
{
    private Twig $view;
    private ForumService $forumService;

    public function __construct(Twig $view, ForumService $forumService)
    {
        $this->view = $view;
        $this->forumService = $forumService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $categories = $this->forumService->getCategories();
        $stats = $this->forumService->getTotalStats();

        return $this->view->render($response, 'forum/index.twig', [
            'user' => $user,
            'is_ajax' => $request->hasHeader('HX-Request'),
            'pageTitle' => 'Fórum',
            'categories' => $categories,
            'stats' => $stats,
        ]);
    }
}
