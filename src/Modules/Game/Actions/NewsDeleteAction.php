<?php
declare(strict_types=1);

namespace Netmafia\Modules\Game\Actions;

use Netmafia\Modules\Game\Domain\NewsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class NewsDeleteAction
{
    private Twig $view;
    private NewsService $newsService;

    public function __construct(Twig $view, NewsService $newsService)
    {
        $this->view = $view;
        $this->newsService = $newsService;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$user || !$user['is_admin']) {
            return $response->withStatus(403);
        }

        $id = (int)$args['id'];
        $this->newsService->delete($id);

        // Return updated list with OOB flash
        $news = $this->newsService->getLatest(20);
        $html = $this->view->fetch('game/news_list.twig', [
            'news' => $news,
            'user' => $user
        ]);
        $flash = '<div id="flash-container" hx-swap-oob="beforeend"><div class="alert-success">Hír sikeresen törölve!</div></div>';
        $response->getBody()->write($html . $flash);
        return $response;
    }
}
