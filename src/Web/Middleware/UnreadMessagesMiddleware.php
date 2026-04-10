<?php
declare(strict_types=1);

namespace Netmafia\Web\Middleware;

use Netmafia\Modules\Messages\Domain\MessageService;
use Netmafia\Infrastructure\CacheService;
use Netmafia\Infrastructure\SessionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Views\Twig;

/**
 * UnreadMessagesMiddleware - Olvasatlan üzenetek számát Twig globális változóként adja hozzá
 * 
 * Cache: 30 másodpercig tárolja az unread count-ot
 */
class UnreadMessagesMiddleware implements MiddlewareInterface
{
    private const CACHE_TTL = 30; // másodperc
    
    private MessageService $messageService;
    private CacheService $cache;
    private Twig $twig;
    // [2026-02-28] FIX: SessionService injektálva $_SESSION közvetlen használata helyett
    private SessionService $session;

    public function __construct(MessageService $messageService, CacheService $cache, Twig $twig, SessionService $session)
    {
        $this->messageService = $messageService;
        $this->cache = $cache;
        $this->twig = $twig;
        $this->session = $session;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // [2026-02-28] FIX: SessionService get() használata $_SESSION helyett
        $userId = $this->session->get('user_id');
        $unreadCount = 0;
        
        if ($userId) {
            $cacheKey = "unread_messages:{$userId}";
            
            // Cache-ből vagy DB-ből
            $unreadCount = $this->cache->remember($cacheKey, self::CACHE_TTL, function() use ($userId) {
                return $this->messageService->getUnreadCount((int)$userId);
            });
            
            $this->twig->getEnvironment()->addGlobal('unread_messages', $unreadCount);
        } else {
            $this->twig->getEnvironment()->addGlobal('unread_messages', 0);
        }

        $response = $handler->handle($request);
        
        // HTMX kéréseknél HX-Trigger header küldése a badge frissítéshez
        // [REFACTOR] Cache invalidálás + újratöltés (nem nyers query minden HTMX kérésnél)
        if ($request->hasHeader('HX-Request') && $userId) {
            $this->cache->forget("unread_messages:{$userId}");
            
            $currentUnreadCount = $this->cache->remember("unread_messages:{$userId}", self::CACHE_TTL, function() use ($userId) {
                return $this->messageService->getUnreadCount((int)$userId);
            });
            
            $existingTrigger = $response->getHeaderLine('HX-Trigger');
            $triggers = $existingTrigger ? json_decode($existingTrigger, true) : [];
            $triggers['updateUnreadBadge'] = $currentUnreadCount;
            
            $response = $response->withHeader('HX-Trigger', json_encode($triggers));
        }

        return $response;
    }
}
