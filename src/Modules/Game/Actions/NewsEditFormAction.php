<?php
declare(strict_types=1);

namespace Netmafia\Modules\Game\Actions;

use Netmafia\Modules\Game\Domain\NewsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class NewsEditFormAction
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
        $newsItem = $this->newsService->get($id);

        if (!$newsItem) {
            return $response->withStatus(404);
        }

        // Extract Topic from Title (Format: "Topic - Date")
        // Everything before the last " - " is the topic
        $separator = ' - ';
        $lastPos = strrpos($newsItem['title'], $separator);
        if ($lastPos !== false) {
            $topic = substr($newsItem['title'], 0, $lastPos);
        } else {
            $topic = $newsItem['title'];
        }

        return $this->view->render($response, 'game/news_edit_form.twig', [
            'item' => $newsItem,
            'topic' => $topic
        ]);
    }
}
