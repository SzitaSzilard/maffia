<?php
declare(strict_types=1);

namespace Netmafia\Infrastructure;

/**
 * SessionService - Session wrapper az ARCHITECTURE.md ajánlásai szerint.
 * 
 * A $_SESSION közvetlen használata helyett ezt a szolgáltatást használjuk,
 * így könnyebb a tesztelés és a session logika központosított.
 */
class SessionService
{
    private bool $started = false;

    // Szabályzat 3.1 (A $_SERVER a DI containerből jön)
    public function __construct(private bool $cookieSecure = false)
    {
    }

    // OWASP ajánlás (Session Hijacking védelem végett)
    // SESSION_MAX_LIFETIME: 24 órás abszolút session lejárat (ensureSessionLimits kezeli)
    // Inaktivitás-alapú kiléptetés (15 perc) a SessionTimeoutMiddleware felelőssége — itt NEM kezeljük!
    private const SESSION_MAX_LIFETIME = 86400; // 24 óra abszolút lejárat

    /**
     * Session indítása, ha még nem fut.
     */
    public function start(): void
    {
        if ($this->started) {
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_name('netmafia_sess');
            session_start([
                'cookie_httponly' => true,
                'cookie_secure' => $this->cookieSecure,
                'cookie_samesite' => 'Lax',
                'use_strict_mode' => true,
            ]);
        }

        $this->started = true;
    }

    /**
     * Bejelentkezett user ID lekérdezése.
     */
    public function getUserId(): ?int
    {
        $this->ensureStarted();
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    /**
     * Bejelentkezett user nevének lekérdezése.
     */
    public function getUsername(): ?string
    {
        $this->ensureStarted();
        return $_SESSION['username'] ?? null;
    }

    /**
     * User bejelentkeztetése (session adatok beállítása).
     */
    public function login(int $userId, string $username): void
    {
        $this->ensureStarted();
        
        // Session fixation elleni védelem
        session_regenerate_id(true);
        
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
    }

    /**
     * Kijelentkezés - session teljes törlése.
     */
    public function logout(): void
    {
        $this->ensureStarted();

        // Session változók törlése
        $_SESSION = [];

        // Session cookie törlése
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        // Session megsemmisítése
        session_destroy();
        $this->started = false;
    }

    /**
     * Tetszőleges session érték lekérdezése.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Tetszőleges session érték beállítása.
     */
    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();
        $_SESSION[$key] = $value;
    }

    /**
     * Session érték törlése.
     */
    public function remove(string $key): void
    {
        $this->ensureStarted();
        unset($_SESSION[$key]);
    }

    /**
     * Flash üzenet beállítása (egyszer megjelenik, aztán törlődik).
     */
    public function flash(string $key, string $message): void
    {
        $this->ensureStarted();
        $_SESSION['_flash'][$key] = $message;
    }

    /**
     * Flash üzenet lekérdezése és törlése.
     */
    public function getFlash(string $key): ?string
    {
        $this->ensureStarted();
        $message = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $message;
    }

    /**
     * Összes flash üzenet lekérdezése és törlése.
     */
    public function getAllFlashes(): array
    {
        $this->ensureStarted();
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flashes;
    }

    /**
     * Be van-e jelentkezve a user.
     */
    public function isLoggedIn(): bool
    {
        return $this->getUserId() !== null;
    }

    /**
     * Session indításának biztosítása és időkorlátok ellenőrzése.
     */
    private function ensureStarted(): void
    {
        if (!$this->started && session_status() === PHP_SESSION_NONE) {
            $this->start();
        }
        $this->ensureSessionLimits();
    }

    /**
     * OWASP Time-based Session Validation
     */
    private function ensureSessionLimits(): void
    {
        if (isset($_SESSION['user_id'])) {
            $now = time();
            $lastActivity = $_SESSION['last_activity'] ?? $now;
            $loginTime = $_SESSION['login_time'] ?? $now;

            // Csak a 2 ÓRÁS abszolút lejáratot ellenőrizzük itt.
            // A 15 perces inaktivitás kezelése a SessionTimeoutMiddleware feladata.
            // FONTOS: last_activity-t itt NEM frissítjük, mert az SessionTimeoutMiddleware
            // felelőssége — ha itt felülírnánk, a 15 perces timeout sosem triggerelne.
            if ($now - $loginTime > self::SESSION_MAX_LIFETIME) {
                $this->logout();
            }
        }
    }
}
