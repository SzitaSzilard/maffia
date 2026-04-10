<?php
declare(strict_types=1);

namespace Netmafia\Web\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * SecurityHeadersMiddleware - Biztonsági HTTP fejlécek hozzáadása
 * 
 * Védelmet nyújt:
 * - Clickjacking ellen (X-Frame-Options)
 * - XSS ellen (X-XSS-Protection, Content-Security-Policy)
 * - MIME sniffing ellen (X-Content-Type-Options)
 */
class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        
        // Clickjacking védelem - tiltja iframe-be ágyazást
        $response = $response->withHeader('X-Frame-Options', 'DENY');
        
        // MIME-type sniffing védelem
        $response = $response->withHeader('X-Content-Type-Options', 'nosniff');
        
        // XSS filter bekapcsolása (legacy böngészőkhöz)
        $response = $response->withHeader('X-XSS-Protection', '1; mode=block');
        
        // Referrer policy - ne szivárogjon ki teljes URL
        $response = $response->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        
        // Content Security Policy - csak saját forrásból tölthet be
        // Megjegyzés: websocket és htmx miatt engedélyezzük az inline scripteket
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",  // HTMX hx-on miatt kell az unsafe-eval
            "style-src 'self' 'unsafe-inline'",   // Inline style-ok miatt
            "img-src 'self' data:",               // data: URL képekhez
            "font-src 'self'",
            "connect-src 'self' ws: wss:",        // WebSocket-hez
            "frame-ancestors 'none'",             // Iframe tiltás
        ]);
        $response = $response->withHeader('Content-Security-Policy', $csp);
        
        // Permissions Policy - funkciók korlátozása
        $permissions = implode(', ', [
            'geolocation=()',
            'microphone=()',
            'camera=()',
        ]);
        $response = $response->withHeader('Permissions-Policy', $permissions);
        
        return $response;
    }
}
