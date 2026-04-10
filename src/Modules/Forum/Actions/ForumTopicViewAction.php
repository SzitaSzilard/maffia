<?php
declare(strict_types=1);

namespace Netmafia\Modules\Forum\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Netmafia\Modules\Forum\Domain\ForumService;
use Netmafia\Modules\Forum\ForumConfig;

class ForumTopicViewAction
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

        $topicId = (int)($args['id'] ?? 0);
        $topic = $this->forumService->getTopic($topicId);
        if (!$topic) {
            $response->getBody()->write('<div class="alert-error">A topic nem található!</div>');
            return $response;
        }

        $queryParams = $request->getQueryParams();
        $page = max(1, (int)($queryParams['page'] ?? 1));

        $postCount = $this->forumService->getPostCount($topicId);
        $totalPages = max(1, (int)ceil($postCount / ForumConfig::POSTS_PER_PAGE));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $posts = $this->forumService->getPostsByTopic($topicId, $page);

        // Zárt kategóriában nem-admin nem írhat
        $canPost = true;
        if (!empty($topic['category_is_closed']) && empty($user['is_admin'])) {
            $canPost = false;
        }
        if (!empty($topic['is_locked'])) {
            $canPost = false;
        }

        return $this->view->render($response, 'forum/topic_view.twig', [
            'user' => $user,
            'is_ajax' => $request->hasHeader('HX-Request'),
            'pageTitle' => $topic['title'] . ' - Fórum',
            'topic' => $topic,
            'posts' => $posts,
            'canPost' => $canPost,
            'currentPage' => $page,
            'totalPages' => $totalPages,
        ]);
    }
}
