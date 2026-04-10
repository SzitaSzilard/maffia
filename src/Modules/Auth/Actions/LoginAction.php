<?php
declare(strict_types=1);

namespace Netmafia\Modules\Auth\Actions;

use Netmafia\Infrastructure\SessionService;
use Netmafia\Infrastructure\RateLimiter;
use Netmafia\Infrastructure\AuditLogger;
use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Shared\Exceptions\GameException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class LoginAction
{
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;
    
    private AuthService $authService;
    private SessionService $session;
    private RateLimiter $rateLimiter;
    private AuditLogger $auditLogger;
    private Twig $view;

    public function __construct(
        AuthService $authService, 
        SessionService $session,
        RateLimiter $rateLimiter,
        AuditLogger $auditLogger,
        Twig $view
    ) {
        $this->authService = $authService;
        $this->session = $session;
        $this->rateLimiter = $rateLimiter;
        $this->auditLogger = $auditLogger;
        $this->view = $view;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        // Ha már be van jelentkezve, irányítsuk a game oldalra
        if ($this->session->isLoggedIn()) {
            return $response->withHeader('Location', '/game')->withStatus(302);
        }

        if ($request->getMethod() === 'POST') {
            $data = $request->getParsedBody();
            $username = $data['username'] ?? '';
            $password = $data['password'] ?? '';
            
            // Rate limiting - IP alapján
            $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
            $rateLimitKey = "login:{$ip}";
            
            // Ellenőrizzük, hogy nem-e blokkolva van
            $rateLimit = $this->rateLimiter->attempt(
                $rateLimitKey,
                self::MAX_LOGIN_ATTEMPTS,
                self::LOCKOUT_MINUTES * 60
            );
            
            if (!$rateLimit['allowed']) {
                // AUDIT LOG - Rate limit blokkolás
                $this->auditLogger->log(
                    AuditLogger::TYPE_LOGIN_BLOCKED,
                    null,
                    [
                        'felhasznalo' => $username,
                        'ok' => 'Túl sok sikertelen bejelentkezési kísérlet',
                        'tiltasi_ido_perc' => self::LOCKOUT_MINUTES,
                    ],
                    $ip
                );

                // Brute force blokk — sárga figyelmeztetés (izolált, FlashMiddleware nem látja)
                $this->session->set('_login_msg', [
                    'type'    => 'warning',
                    'message' => sprintf(
                        'Túl sok sikertelen bejelentkezési kísérlet. Próbáld újra %d perc múlva.',
                        (int) ceil($rateLimit['retryAfter'] / 60)
                    ),
                ]);
                return $response->withHeader('Location', '/login')->withStatus(303);
            }

            try {
                $user = $this->authService->attemptLogin($username, $password);
            } catch (GameException $e) {
                // Bannolt felhasználó — sárga figyelmeztetés (izolált, FlashMiddleware nem látja)
                $this->session->set('_login_msg', [
                    'type'    => 'warning',
                    'message' => $e->getMessage(),
                ]);
                $this->session->set('_login_last_username', $username);
                return $response->withHeader('Location', '/login')->withStatus(303);
            }

            if ($user) {
                // Sikeres login - rate limit reset
                $this->rateLimiter->reset($rateLimitKey);
                
                // Session beállítása
                $this->session->login((int) $user['id'], $user['username']);
                return $response->withHeader('Location', '/game')->withStatus(302);
            } else {
                // AUDIT LOG - Sikertelen bejelentkezés
                $this->auditLogger->log(
                    AuditLogger::TYPE_LOGIN_FAILED,
                    null,
                    [
                        'felhasznalo' => $username,
                        'ok' => 'Hibás jelszó vagy felhasználónév',
                    ],
                    $ip
                );

                $remaining = $rateLimit['remaining'];
                $errorMsg = 'Hibás felhasználónév vagy jelszó!';
                if ($remaining <= 2) {
                    $errorMsg .= sprintf(' (Még %d próbálkozásod van.)', $remaining);
                }

                // Hibás jelszó — piros hiba (izolált, FlashMiddleware nem látja)
                $this->session->set('_login_msg', [
                    'type'    => 'error',
                    'message' => $errorMsg,
                ]);
                $this->session->set('_login_last_username', $username);
                return $response->withHeader('Location', '/login')->withStatus(303);
            }
        }

        // LOGIN-SPECIFIKUS üzenet olvasása — izolált _login_msg kulcsból.
        // A FlashMiddleware soha nem látja ezt (nem $_SESSION['_flash']-ban van),
        // ezért teljesen független a globális flash rendszertől.
        $loginMsg     = $this->session->get('_login_msg');
        $lastUsername = $this->session->get('_login_last_username');
        $this->session->remove('_login_msg');
        $this->session->remove('_login_last_username');

        return $this->view->render($response, 'auth/login.twig', [
            'login_msg'     => $loginMsg,
            'last_username' => $lastUsername,
        ]);
    }
}

