<?php
declare(strict_types=1);

namespace Netmafia\Modules\Forum\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Netmafia\Modules\Forum\Domain\ForumService;
use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

class ForumTopicCreateAction
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
            return $response->withStatus(401);
        }

        $categoryId = (int)($args['id'] ?? 0);
        $data = $request->getParsedBody();
        $title = trim((string)($data['title'] ?? ''));
        $content = trim((string)($data['content'] ?? ''));

        try {
            $category = $this->forumService->getCategory($categoryId);
            if (!$category) {
                throw new GameException('A kategória nem létezik!');
            }

            // Zárt kategóriában csak admin nyithat topicot
            if (!empty($category['is_closed']) && empty($user['is_admin'])) {
                throw new GameException('Ez a kategória zárt, csak adminisztrátor nyithat benne topicot!');
            }

            $topicId = $this->forumService->createTopic($categoryId, (int)$user['id'], $title, $content);

            return $response
                ->withHeader('HX-Redirect', '/forum/topic/' . $topicId)
                ->withStatus(200);

        } catch (GameException | InvalidInputException $e) {
            $response->getBody()->write('<div class="alert-error">' . htmlspecialchars($e->getMessage()) . '</div>');
            return $response;
        } catch (\Throwable $e) {
            error_log("Forum Topic Create Error: " . $e->getMessage());
            $response->getBody()->write('<div class="alert-error">Váratlan hiba történt.</div>');
            return $response;
        }
    }
}
