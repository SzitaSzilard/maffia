<?php
declare(strict_types=1);

namespace Netmafia\Modules\Forum\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\Forum\Domain\ForumService;
use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

class ForumCategoryCreateAction
{
    private ForumService $forumService;

    public function __construct(ForumService $forumService)
    {
        $this->forumService = $forumService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$user || empty($user['is_admin'])) {
            return $response->withStatus(403);
        }

        $data = $request->getParsedBody();
        $name = trim((string)($data['name'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        $isClosed = !empty($data['is_closed']);
        $isSingleTopic = !empty($data['is_single_topic']);

        try {
            $this->forumService->createCategory($name, $description, $isClosed, $isSingleTopic, (int)$user['id']);

            return $response
                ->withHeader('HX-Redirect', '/forum')
                ->withStatus(200);

        } catch (GameException | InvalidInputException $e) {
            $response->getBody()->write('<div class="alert-error">' . htmlspecialchars($e->getMessage()) . '</div>');
            return $response;
        } catch (\Throwable $e) {
            error_log("Forum Category Create Error: " . $e->getMessage());
            $response->getBody()->write('<div class="alert-error">Váratlan hiba történt.</div>');
            return $response;
        }
    }
}
