<?php
declare(strict_types=1);

namespace Netmafia\Web\Middleware;

use Netmafia\Infrastructure\SessionService;
use Netmafia\Infrastructure\AuditLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * SessionTimeoutMiddleware - Inaktivitás alapú automatikus kijelentkezés
 * 
 * Ha a user 15 percig (vagy konfigurált ideig) inaktív, kilépteti és
 * átirányítja a login oldalra egy flash üzenettel.
 */
class SessionTimeoutMiddleware implements MiddlewareInterface
{
    private SessionService $session;
    private ?AuditLogger $auditLogger;
    
    /**
     * Timeout másodpercekben (alapértelmezett: 15 perc = 900 másodperc)
     */
    private int $timeoutSeconds;

    public function __construct(SessionService $session, int $timeoutMinutes = 15, ?AuditLogger $auditLogger = null)
    {
        $this->session = $session;
        $this->timeoutSeconds = $timeoutMinutes * 60;
        $this->auditLogger = $auditLogger;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->session->start();

        // Ha nincs bejelentkezve, nem kell timeout
        if (!$this->session->isLoggedIn()) {
            return $handler->handle($request);
        }

        // Utolsó aktivitás ellenőrzése
        $lastActivity = $this->session->get('last_activity');
        $currentTime = time();

        if ($lastActivity !== null) {
            $inactiveTime = $currentTime - (int) $lastActivity;
            
            if ($inactiveTime > $this->timeoutSeconds) {
                // AUDIT LOG - Inaktivitás miatti kiléptetés
                $userId = $this->session->getUserId();
                $username = $this->session->getUsername();
                $this->logTimeout($userId, $username, $inactiveTime, $request);
                
                // Timeout - kijelentkeztetés
                return $this->handleTimeout($request);
            }
        }

        $isHtmx = $request->hasHeader('HX-Request');
        $path = $request->getUri()->getPath();

        // 1. Háttérben történő frissítések (polling) nem számítanak be emberi aktivitásnak
        $isPolling = $isHtmx && in_array($path, [
            '/szervezett-bunozes/csapat',
            '/toltenygyar/kezel'
        ]);

        if (!$isPolling) {
            // Utolsó aktivitás frissítése
            $this->session->set('last_activity', $currentTime);
        }

        return $handler->handle($request);
    }

    /**
     * Timeout logolása
     */
    private function logTimeout(?int $userId, ?string $username, int $inactiveSeconds, ServerRequestInterface $request): void
    {
        if ($this->auditLogger === null) {
            return;
        }

        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
        
        $this->auditLogger->log(
            'session_timeout',
            $userId,
            [
                'felhasznalo' => $username,
                'inaktiv_perc' => round($inactiveSeconds / 60, 1),
                'ok' => 'Inaktivitás miatti automatikus kijelentkezés',
            ],
            $ip
        );
    }

    /**
     * Timeout kezelése - kijelentkeztetés és átirányítás
     */
    private function handleTimeout(ServerRequestInterface $request): ResponseInterface
    {
        // Flash üzenet beállítása MIELŐTT kiléptetjük
        $this->session->logout();
        
        // Új session indítása a flash üzenethez
        $this->session->start();
        $this->session->flash('warning', 'Biztonsági okokból inaktivitás miatt kiléptettünk. Kérjük, jelentkezz be újra.');

        $response = new Response();
        
        // HTMX támogatás: 
        // HX-Redirect header-rel a HTMX teljes oldal redirectet csinál
        if ($request->hasHeader('HX-Request')) {
            return $response
                ->withHeader('HX-Redirect', '/login')
                ->withStatus(200);
        }

        // Hagyományos kérés esetén standard redirect
        return $response
            ->withHeader('Location', '/login')
            ->withStatus(302);
    }
}

