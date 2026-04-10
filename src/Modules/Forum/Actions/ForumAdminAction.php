<?php
declare(strict_types=1);

namespace Netmafia\Modules\Forum\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\Forum\Domain\ForumService;
use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

/**
 * Admin CRUD műveletek: kategória/topic/poszt szerkesztés és törlés
 */
class ForumAdminAction
{
    private ForumService $forumService;

    public function __construct(ForumService $forumService)
    {
        $this->forumService = $forumService;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$user || empty($user['is_admin'])) {
            return $response->withStatus(403);
        }
        $adminUserId = (int)$user['id'];

        $action = $args['action'] ?? '';
        $data = $request->getParsedBody();

        // §10.1 Whitelist validation
        $allowedActions = [
            'kategoria-szerkesztes', 'kategoria-torles',
            'topic-szerkesztes', 'topic-torles',
            'poszt-szerkesztes', 'poszt-torles',
        ];
        if (!in_array($action, $allowedActions, true)) {
            $response->getBody()->write('<div class="alert-error">Ismeretlen művelet!</div>');
            return $response;
        }

        try {
            switch ($action) {
                case 'kategoria-szerkesztes':
                    $id = (int)($data['id'] ?? 0);
                    $this->forumService->updateCategory(
                        $id,
                        (string)($data['name'] ?? ''),
                        (string)($data['description'] ?? ''),
                        !empty($data['is_closed']),
                        $adminUserId
                    );
                    return $response->withHeader('HX-Redirect', '/forum')->withStatus(200);

                case 'kategoria-torles':
                    $id = (int)($data['id'] ?? 0);
                    $this->forumService->deleteCategory($id, $adminUserId);
                    return $response->withHeader('HX-Redirect', '/forum')->withStatus(200);

                case 'topic-szerkesztes':
                    $id = (int)($data['id'] ?? 0);
                    $this->forumService->updateTopic(
                        $id,
                        (string)($data['title'] ?? ''),
                        !empty($data['is_locked']),
                        !empty($data['is_pinned']),
                        $adminUserId
                    );
                    return $response->withHeader('HX-Redirect', '/forum/topic/' . $id)->withStatus(200);

                case 'topic-torles':
                    $id = (int)($data['id'] ?? 0);
                    $categoryId = $this->forumService->deleteTopic($id, $adminUserId);
                    return $response->withHeader('HX-Redirect', '/forum/kategoria/' . $categoryId)->withStatus(200);

                case 'poszt-szerkesztes':
                    $id = (int)($data['id'] ?? 0);
                    $topicId = (int)($data['topic_id'] ?? 0);
                    $this->forumService->updatePost($id, (string)($data['content'] ?? ''), $adminUserId);
                    return $response->withHeader('HX-Redirect', '/forum/topic/' . $topicId)->withStatus(200);

                case 'poszt-torles':
                    $id = (int)($data['id'] ?? 0);
                    $topicId = (int)($data['topic_id'] ?? 0);
                    $this->forumService->deletePost($id, $adminUserId);
                    return $response->withHeader('HX-Redirect', '/forum/topic/' . $topicId)->withStatus(200);

                default:
                    $response->getBody()->write('<div class="alert-error">Ismeretlen művelet!</div>');
                    return $response;
            }
        } catch (GameException | InvalidInputException $e) {
            $response->getBody()->write('<div class="alert-error">' . htmlspecialchars($e->getMessage()) . '</div>');
            return $response;
        } catch (\Throwable $e) {
            error_log("Forum Admin Error: " . $e->getMessage());
            $response->getBody()->write('<div class="alert-error">Váratlan hiba történt.</div>');
            return $response;
        }
    }
}
