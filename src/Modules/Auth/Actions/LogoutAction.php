<?php
declare(strict_types=1);

namespace Netmafia\Modules\Auth\Actions;

use Netmafia\Infrastructure\SessionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * LogoutAction - Kijelentkezés kezelése.
 * 
 * Session megfelelő törlése és átirányítás a login oldalra.
 */
class LogoutAction
{
    private SessionService $session;
    private \Doctrine\DBAL\Connection $db;

    public function __construct(SessionService $session, \Doctrine\DBAL\Connection $db)
    {
        $this->session = $session;
        $this->db = $db;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        // [FIX #10] Csak POST kéréssel lehet kijelentkezni (CSRF védelem)
        // GET /logout egy <img> vagy <a> tagen keresztül kijelentkeztethetné a usert
        if ($request->getMethod() !== 'POST') {
            return $response->withHeader('Location', '/game')->withStatus(302);
        }
        
        // 1. Clear last_activity for the user
        $userId = $this->session->get('user_id');
        if ($userId) {
            try {
                $this->db->executeStatement("SET @audit_source = ?", ['LogoutAction::invoke']);
                $this->db->executeStatement("UPDATE users SET last_activity = NULL WHERE id = ?", [$userId]);
            } finally {
                $this->db->executeStatement("SET @audit_source = NULL");
            }
        }

        // 2. Clear Session
        $this->session->logout();

        // 3. Redirect
        return $response
            ->withHeader('Location', '/login')
            ->withStatus(302);
    }
}
