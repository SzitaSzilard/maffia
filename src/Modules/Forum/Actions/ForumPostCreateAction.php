<?php
declare(strict_types=1);

namespace Netmafia\Modules\Forum\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\Forum\Domain\ForumService;
use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

class ForumPostCreateAction
{
    private ForumService $forumService;

    public function __construct(ForumService $forumService)
    {
        $this->forumService = $forumService;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $response->withStatus(401);
        }

        $topicId = (int)($args['id'] ?? 0);
        $data = $request->getParsedBody();
        $content = trim((string)($data['content'] ?? ''));

        try {
            $topic = $this->forumService->getTopic($topicId);
            if (!$topic) {
                throw new GameException('A topic nem létezik!');
            }

            // Zárt kategóriában csak admin írhat
            if (!empty($topic['category_is_closed']) && empty($user['is_admin'])) {
                throw new GameException('Ez a kategória zárt, csak adminisztrátor írhat bele!');
            }

            $this->forumService->createPost($topicId, (int)$user['id'], $content);

            // Redirect az utolsó oldalra
            $postCount = $this->forumService->getPostCount($topicId);
            $lastPage = max(1, (int)ceil($postCount / \Netmafia\Modules\Forum\ForumConfig::POSTS_PER_PAGE));

            return $response
                ->withHeader('HX-Redirect', '/forum/topic/' . $topicId . '?page=' . $lastPage)
                ->withStatus(200);

        } catch (GameException | InvalidInputException $e) {
            $response->getBody()->write('<div class="alert-error">' . htmlspecialchars($e->getMessage()) . '</div>');
            return $response;
        } catch (\Throwable $e) {
            error_log("Forum Post Error: " . $e->getMessage());
            $response->getBody()->write('<div class="alert-error">Váratlan hiba történt.</div>');
            return $response;
        }
    }
}
