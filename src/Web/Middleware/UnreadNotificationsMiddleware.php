<?php
declare(strict_types=1);

namespace Netmafia\Web\Middleware;

use Doctrine\DBAL\Connection;
use Netmafia\Modules\Notifications\Domain\NotificationService;
use Netmafia\Infrastructure\CacheService;
use Netmafia\Infrastructure\SessionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Views\Twig;

/**
 * UnreadNotificationsMiddleware - Olvasatlan értesítések számát Twig globális változóként adja hozzá
 * 
 * Cache: 30 másodpercig tárolja az unread count-ot
 */
class UnreadNotificationsMiddleware implements MiddlewareInterface
{
    private const CACHE_TTL = 30; // másodperc
    
    private Connection $db;
    private NotificationService $notificationService;
    private CacheService $cache;
    private Twig $twig;
    // [2026-02-28] FIX: SessionService injektálva $_SESSION közvetlen használata helyett
    private SessionService $session;

    public function __construct(Connection $db, NotificationService $notificationService, CacheService $cache, Twig $twig, SessionService $session)
    {
        $this->db = $db;
        $this->notificationService = $notificationService;
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
            $cacheKey = "unread_notifications:{$userId}";
            
            // Cache-ből vagy DB-ből
            $unreadCount = $this->cache->remember($cacheKey, self::CACHE_TTL, function() use ($userId) {
                return $this->notificationService->getUnreadCount((int)$userId);
            });
            
            $this->twig->getEnvironment()->addGlobal('unread_notifications', $unreadCount);

            // [Webserver Lejárat Ellenőrzése]
            // Csak akkor fut le a módosítás (és küld notification-t), ha pontosan most járt le és még nem kapott róla
            $wsExpired = $this->db->executeStatement(
                "UPDATE users SET webserver_notified = 1 
                 WHERE id = ? AND webserver_notified = 0 
                 AND webserver_expire_at IS NOT NULL 
                 AND webserver_expire_at <= UTC_TIMESTAMP()",
                [(int)$userId]
            );
            if ($wsExpired > 0) {
                $this->notificationService->send(
                    (int)$userId,
                    'system',
                    'Hahó! Lejárt a webszerver bérleti időd. Ha szeretnéd továbbra is használni az Adathalász SMS tevékenységet, béreld újra a Számítógép boltban!',
                    'e-bunozes',
                    '/e-bunozes/bolt'
                );
                // Mivel most ment ki egy értesítés, a cache-t érvénytelenítjük
                $this->cache->forget($cacheKey);
                $unreadCount = $this->notificationService->getUnreadCount((int)$userId);
                $this->twig->getEnvironment()->addGlobal('unread_notifications', $unreadCount);
            }

            // Posta: várakozó csomagok száma és legközelebbi érkezés ideje (sidebar buborék és visszaszámláló)
            $postalCacheKey = "pending_postal_data:{$userId}";
            $postalData = $this->cache->remember($postalCacheKey, self::CACHE_TTL, function() use ($userId) {
                return $this->db->fetchAssociative(
                    "SELECT COUNT(*) as count, 
                            UNIX_TIMESTAMP(MIN(delivery_at)) as next_ts
                     FROM postal_packages 
                     WHERE recipient_id = ? AND status = 'in_transit' AND delivery_at > UTC_TIMESTAMP()",
                    [(int)$userId]
                );
            });
            $this->twig->getEnvironment()->addGlobal('pending_postal', (int)($postalData['count'] ?? 0));
            $this->twig->getEnvironment()->addGlobal('postal_next_delivery_ts', (int)($postalData['next_ts'] ?? 0));
            $this->twig->getEnvironment()->addGlobal('postal_has_incoming', (int)($postalData['count'] ?? 0) > 0);
        } else {
            $this->twig->getEnvironment()->addGlobal('unread_notifications', 0);
            $this->twig->getEnvironment()->addGlobal('pending_postal', 0);
            $this->twig->getEnvironment()->addGlobal('postal_next_delivery_ts', 0);
            $this->twig->getEnvironment()->addGlobal('postal_has_incoming', false);
        }

        $response = $handler->handle($request);
        
        // HTMX kéréseknél HX-Trigger header küldése a badge frissítéshez
        // [REFACTOR] Cache invalidálás + újratöltés (nem nyers DB query minden HTMX kérésnél)
        if ($request->hasHeader('HX-Request') && $userId) {
            // Cache invalidálás, hogy a következő lekérdezés friss legyen
            $this->cache->forget("unread_notifications:{$userId}");
            $this->cache->forget("pending_postal_data:{$userId}");
            
            // Friss adat cache-en keresztül (azonnal újra cache-eli)
            $currentUnreadCount = $this->cache->remember("unread_notifications:{$userId}", self::CACHE_TTL, function() use ($userId) {
                return $this->notificationService->getUnreadCount((int)$userId);
            });
            
            $currentPostalData = $this->cache->remember("pending_postal_data:{$userId}", self::CACHE_TTL, function() use ($userId) {
                return $this->db->fetchAssociative(
                    "SELECT COUNT(*) as count, 
                            UNIX_TIMESTAMP(MIN(delivery_at)) as next_ts
                     FROM postal_packages 
                     WHERE recipient_id = ? AND status = 'in_transit' AND delivery_at > UTC_TIMESTAMP()",
                    [(int)$userId]
                );
            });
            $currentPendingPostal = (int)($currentPostalData['count'] ?? 0);
            
            $existingTrigger = $response->getHeaderLine('HX-Trigger');
            $triggers = $existingTrigger ? json_decode($existingTrigger, true) : [];
            $triggers['updateNotificationBadge'] = $currentUnreadCount;
            $triggers['updatePostalBadge'] = $currentPendingPostal;
            
            $response = $response->withHeader('HX-Trigger', json_encode($triggers));
        }

        return $response;
    }
}
