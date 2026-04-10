<?php
declare(strict_types=1);

namespace Netmafia\Modules\Game\Actions;

use Netmafia\Modules\Game\Domain\NewsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class NewsCreateAction
{
    private Twig $view;
    private NewsService $newsService;

    public function __construct(Twig $view, NewsService $newsService)
    {
        $this->view = $view;
        $this->newsService = $newsService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$user || !$user['is_admin']) {
            return $response->withStatus(403);
        }

        $data = $request->getParsedBody();
        $content = $data['content'] ?? '';
        $topic = $data['topic'] ?? 'Változások';
        $userId = (int) $request->getAttribute('user_id');

        if (!empty($content)) {
            $this->newsService->add($userId, $content, $topic);
        }

        // Return the updated LIST (swap back to list view) with OOB flash
        $news = $this->newsService->getLatest(20);
        
        $html = $this->view->fetch('game/news_list.twig', [
            'news' => $news,
            'user' => $user
        ]);
        
        $flash = '<div id="flash-container" hx-swap-oob="beforeend"><div class="alert-success">Új bejegyzés sikeresen létrehozva!</div></div>';
        $response->getBody()->write($html . $flash);
        return $response;
    }
}
