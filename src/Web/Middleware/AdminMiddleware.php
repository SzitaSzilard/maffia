<?php
declare(strict_types=1);

namespace Netmafia\Web\Middleware;

use Doctrine\DBAL\Connection;
use Netmafia\Infrastructure\SessionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * AdminMiddleware - Admin jogosultság ellenőrzés
 * 
 * Kritikus admin műveletek (pl. user ban, épület tulajdonos módosítás) 
 * védelmére szolgál. Csak akkor enged tovább, ha a user admin jogokkal rendelkezik.
 * 
 * Használat:
 * $app->group('/admin', function(...) {...})->add(AdminMiddleware::class);
 */
class AdminMiddleware implements MiddlewareInterface
{
    private Connection $db;
    private SessionService $session;

    public function __construct(Connection $db, SessionService $session)
    {
        $this->db = $db;
        $this->session = $session;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Session indítása a service-en keresztül
        $this->session->start();
        $userId = $this->session->getUserId();

        if (!$userId) {
            // Nincs bejelentkezve
            $response = new Response();
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        // Admin jogosultság ellenőrzése
        $isAdmin = $this->db->fetchOne(
            "SELECT is_admin FROM users WHERE id = ?",
            [$userId]
        );

        if (!$isAdmin) {
            // Nem admin - hozzáférés megtagadva
            $this->session->flash('error', 'Nincs jogosultságod ehhez a művelethez!');
            
            // HTMX kérés esetén JSON válasz
            if ($request->getHeader('HX-Request')) {
                $response = new Response();
                $response->getBody()->write(json_encode([
                    'error' => 'Hiányzó admin jogosultság',
                    'redirect' => '/game'
                ]));
                return $response
                    ->withHeader('Content-Type', 'application/json')
                    ->withStatus(403);
            }
            
            // Normál kérés esetén redirect
            $response = new Response();
            return $response->withHeader('Location', '/game')->withStatus(403);
        }

        // Admin jog rendben, user_id és is_admin hozzáadása a requesthez
        $request = $request->withAttribute('user_id', $userId);
        $request = $request->withAttribute('is_admin', true);

        return $handler->handle($request);
    }
}
