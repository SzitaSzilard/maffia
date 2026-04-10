<?php
declare(strict_types=1);

namespace Netmafia\Web\Middleware;

use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Infrastructure\SessionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class AuthMiddleware implements MiddlewareInterface
{
    private AuthService $authService;
    private SessionService $session;

    public function __construct(AuthService $authService, SessionService $session)
    {
        $this->authService = $authService;
        $this->session = $session;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Session indítása a service-en keresztül
        $this->session->start();

        $userId = $this->session->getUserId();

        if (!$userId) {
            $response = new Response();
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        // [REFACTOR] AuthService használata a direkt SQL helyett
        // Ez tartalmazza: rank_name, next_rank, has_ecrime_cooldown, has_oc_cooldown
        $user = $this->authService->getAuthenticatedUser($userId);
        
        if (!$user || $user['is_banned']) {
            $this->session->logout();
            $response = new Response();
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        // [REMOVED] last_activity frissítés — LastActivityMiddleware kezeli egyedül

        // User adatok hozzáadása a request attribútumaihoz
        $request = $request->withAttribute('user', $user);
        $request = $request->withAttribute('user_id', $userId);
        $request = $request->withAttribute('username', $user['username']);
        
        return $handler->handle($request);
    }
}

