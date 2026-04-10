<?php
declare(strict_types=1);

namespace Netmafia\Web\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Views\Twig;
use Doctrine\DBAL\Connection;
use Netmafia\Infrastructure\CacheService;
use Netmafia\Infrastructure\SessionService;

/**
 * UnreadCombatMiddleware - Olvasatlan küzdelmek számát Twig globális változóként adja hozzá
 */
class UnreadCombatMiddleware implements MiddlewareInterface
{
    private Connection $db;
    private Twig $twig;
    private SessionService $session;
    private CacheService $cache;

    public function __construct(Connection $db, Twig $twig, SessionService $session, CacheService $cache)
    {
        $this->db = $db;
        $this->twig = $twig;
        $this->session = $session;
        $this->cache = $cache;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // 1. Check if user is logged in
        $userId = $this->session->get('user_id');

        if ($userId) {
            // 2. Count unread defense logs (Cached)
            $count = $this->cache->remember("unread_combat:{$userId}", 30, function() use ($userId) {
                return (int)$this->db->fetchOne(
                    "SELECT COUNT(*) FROM combat_log WHERE defender_id = ? AND is_read = 0",
                    [$userId]
                );
            });

            // 3. Add to Twig Global
            $this->twig->getEnvironment()->addGlobal('unread_combat_count', $count);
        } else {
             $this->twig->getEnvironment()->addGlobal('unread_combat_count', 0);
        }

        $response = $handler->handle($request);

        // HTMX Trigger for dynamic badge update
        // [REFACTOR] Cache invalidálás + újratöltés (nem nyers DB query minden HTMX kérésnél)
        if ($request->hasHeader('HX-Request') && $userId) {
            $this->cache->forget("unread_combat:{$userId}");
            
            $currentCount = $this->cache->remember("unread_combat:{$userId}", 30, function() use ($userId) {
                return (int)$this->db->fetchOne(
                    "SELECT COUNT(*) FROM combat_log WHERE defender_id = ? AND is_read = 0",
                    [$userId]
                );
            });

            $existingTrigger = $response->getHeaderLine('HX-Trigger');
            $triggers = $existingTrigger ? json_decode($existingTrigger, true) : [];
            $triggers['updateCombatBadge'] = $currentCount;
            
            $response = $response->withHeader('HX-Trigger', json_encode($triggers));
        }

        return $response;
    }
}
