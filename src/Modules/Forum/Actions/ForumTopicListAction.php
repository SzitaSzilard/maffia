<?php
declare(strict_types=1);

namespace Netmafia\Modules\Forum\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Netmafia\Modules\Forum\Domain\ForumService;

class ForumTopicListAction
{
    private Twig $view;
    private ForumService $forumService;

    public function __construct(Twig $view, ForumService $forumService)
    {
        $this->view = $view;
        $this->forumService = $forumService;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $categoryId = (int)($args['id'] ?? 0);
        $category = $this->forumService->getCategory($categoryId);
        if (!$category) {
            $response->getBody()->write('<div class="alert-error">A kategória nem található!</div>');
            return $response;
        }

        // Single-topic: egyből a topic nézetbe
        if (!empty($category['is_single_topic'])) {
            $topicId = $this->forumService->getSingleTopicId($categoryId);
            if ($topicId) {
                if ($request->hasHeader('HX-Request')) {
                    return $response->withHeader('HX-Redirect', '/forum/topic/' . $topicId)->withStatus(200);
                }
                return $response->withHeader('Location', '/forum/topic/' . $topicId)->withStatus(302);
            }
        }

        $topics = $this->forumService->getTopicsByCategory($categoryId);
        $categories = $this->forumService->getCategories();
        $stats = $this->forumService->getTotalStats();

        return $this->view->render($response, 'forum/topic_list.twig', [
            'user' => $user,
            'is_ajax' => $request->hasHeader('HX-Request'),
            'pageTitle' => $category['name'] . ' - Fórum',
            'category' => $category,
            'categories' => $categories,
            'topics' => $topics,
            'stats' => $stats,
        ]);
    }
}
