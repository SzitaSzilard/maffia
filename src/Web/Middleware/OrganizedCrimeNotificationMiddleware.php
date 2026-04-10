<?php
declare(strict_types=1);

namespace Netmafia\Web\Middleware;

use Doctrine\DBAL\Connection;
use Netmafia\Infrastructure\CacheService;
use Netmafia\Infrastructure\SessionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Views\Twig;

class OrganizedCrimeNotificationMiddleware implements MiddlewareInterface
{
    private const CACHE_TTL = 30; // másodperc
    
    private Connection $db;
    private CacheService $cache;
    private Twig $twig;
    private SessionService $session;

    public function __construct(Connection $db, CacheService $cache, Twig $twig, SessionService $session)
    {
        $this->db = $db;
        $this->cache = $cache;
        $this->twig = $twig;
        $this->session = $session;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $userId = $this->session->get('user_id');
        $pendingInvites = 0;
        
        if ($userId) {
            $cacheKey = "pending_oc_invites:{$userId}";
            
            $pendingInvites = $this->cache->remember($cacheKey, self::CACHE_TTL, function() use ($userId) {
                // Csak azokat számoljuk, amiknél 'invited' a státusz, és a bűnözés folyamatban/készülődik
                $sql = "
                    SELECT COUNT(*) as cnt 
                    FROM organized_crime_members m
                    JOIN organized_crimes c ON m.crime_id = c.id
                    WHERE m.user_id = ? AND m.status = 'invited' AND c.status IN ('gathering', 'in_progress')
                ";
                return (int)$this->db->fetchOne($sql, [$userId]);
            });
            
            $this->twig->getEnvironment()->addGlobal('pending_oc_invites', $pendingInvites);
        } else {
            $this->twig->getEnvironment()->addGlobal('pending_oc_invites', 0);
        }

        $response = $handler->handle($request);
        
        // HTMX kéréseknél HX-Trigger header küldése a badge frissítéshez
        if ($request->hasHeader('HX-Request') && $userId) {
            $sql = "
                SELECT COUNT(*) as cnt 
                FROM organized_crime_members m
                JOIN organized_crimes c ON m.crime_id = c.id
                WHERE m.user_id = ? AND m.status = 'invited' AND c.status IN ('gathering', 'in_progress')
            ";
            $currentPending = (int)$this->db->fetchOne($sql, [$userId]);
            
            $existingTrigger = $response->getHeaderLine('HX-Trigger');
            $triggers = $existingTrigger ? json_decode($existingTrigger, true) : [];
            $triggers['updateOrganizedCrimeBadge'] = $currentPending;
            
            $response = $response->withHeader('HX-Trigger', json_encode($triggers));
        }

        return $response;
    }
}
