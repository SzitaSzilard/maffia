<?php
declare(strict_types=1);

namespace Netmafia\Web\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Doctrine\DBAL\Connection;
use Netmafia\Infrastructure\SessionService;

class LastActivityMiddleware implements MiddlewareInterface
{
    private Connection $db;
    private SessionService $sessionService;

    public function __construct(Connection $db, SessionService $sessionService)
    {
        $this->db = $db;
        $this->sessionService = $sessionService;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        // 1. Check if user is logged in
        $userId = $this->sessionService->get('user_id');

        if ($userId) {
            // 2. Throttled Update (Optimization)
            // Only update DB if more than 60 seconds have passed since last update
            // This prevents row locking on every request (e.g. HTMX polling)
            $lastUpdate = $this->sessionService->get('last_activity_update_ts');
            $now = time();

            if (!$lastUpdate || ($now - $lastUpdate) > 60) {
                try {
                    try {
                        $this->db->executeStatement("SET @audit_source = ?", ['LastActivityMiddleware::process']);
                        $this->db->executeStatement(
                            "UPDATE users SET last_activity = NOW() WHERE id = ?",
                            [$userId]
                        );
                    } finally {
                        $this->db->executeStatement("SET @audit_source = NULL");
                    }
                    // Update session timestamp
                    $this->sessionService->set('last_activity_update_ts', $now);
                } catch (\Throwable $e) {
                    // Ignore DB errors here
                }
            }
        }

        // 3. Continue chain
        return $handler->handle($request);
    }
}
