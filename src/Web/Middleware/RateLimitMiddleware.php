<?php
declare(strict_types=1);

namespace Netmafia\Web\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Netmafia\Infrastructure\SessionService;
use Slim\Psr7\Response;

/**
 * RateLimitMiddleware — IP-alapú rate limiting
 * 
 * [2026-02-21] Brute force és spam védelem.
 * Memória-alapú cache (APCu) vagy fájl-alapú fallback.
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    /** @var array<string, array{limit: int, window: int}> */
    private array $rules;
    private string $cacheDir;
    // [2026-02-28] FIX: SessionService injektálva $_SESSION közvetlen használata helyett
    private ?SessionService $session;

    public function __construct(?string $cacheDir = null, ?SessionService $session = null)
    {
        $this->cacheDir = $cacheDir ?? sys_get_temp_dir() . '/netmafia_ratelimit';
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
        $this->session = $session;

        // Szabályok: útvonal minta => [limit/ablak]
        $this->rules = [
            'POST:/login'    => ['limit' => 10, 'window' => 900],  // 10 kísérlet / 15 perc
            'POST:/register' => ['limit' => 5,  'window' => 3600], // 5 / óra
            'POST:/kuzdelem' => ['limit' => 20, 'window' => 60],   // 20 / perc
            'POST:*'         => ['limit' => 60, 'window' => 60],   // 60 POST / perc (általános)
        ];
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = $request->getMethod();
        
        // Csak POST kérésekre
        if ($method !== 'POST') {
            return $handler->handle($request);
        }

        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1';
        $path = $request->getUri()->getPath();
        $key = "{$method}:{$path}";

        // Keressük a legszorosabb szabályt
        $rule = $this->findRule($method, $path);
        if (!$rule) {
            return $handler->handle($request);
        }

        $cacheKey = md5("{$ip}:{$key}");
        $attempts = $this->getAttempts($cacheKey, $rule['window']);

        if ($attempts >= $rule['limit']) {
            $response = new Response();
            $isHtmx = $request->hasHeader('HX-Request');

            if ($isHtmx) {
                return $response
                    ->withStatus(200)
                    ->withHeader('HX-Trigger', json_encode([
                        'notification' => [
                            'type' => 'error',
                            'message' => 'Túl sok kérés! Kérlek várj egy kicsit.'
                        ]
                    ]));
            }

            // Non-HTMX: SessionService flash + redirect vissza
            // [2026-02-28] FIX: SessionService flash() használata $_SESSION helyett
            if ($this->session) {
                $this->session->flash('flash_error', 'Túl sok kérés! Kérlek várj egy kicsit.');
            }
            $referer = $request->getHeaderLine('Referer');
            $redirectTo = $referer ?: $path;
            return $response
                ->withStatus(302)
                ->withHeader('Location', $redirectTo);
        }

        $this->recordAttempt($cacheKey);

        return $handler->handle($request);
    }

    private function findRule(string $method, string $path): ?array
    {
        // Specifikus szabály először
        $specifics = ["{$method}:{$path}"];
        foreach ($specifics as $key) {
            if (isset($this->rules[$key])) {
                return $this->rules[$key];
            }
        }

        // Wildcard fallback
        $wildcard = "{$method}:*";
        return $this->rules[$wildcard] ?? null;
    }

    private function getAttempts(string $cacheKey, int $window): int
    {
        $file = $this->cacheDir . '/' . $cacheKey;
        if (!file_exists($file)) {
            return 0;
        }

        $data = json_decode(file_get_contents($file), true);
        if (!$data) {
            return 0;
        }

        $cutoff = time() - $window;
        $count = 0;
        foreach ($data as $timestamp) {
            if ($timestamp > $cutoff) {
                $count++;
            }
        }

        return $count;
    }

    private function recordAttempt(string $cacheKey): void
    {
        $file = $this->cacheDir . '/' . $cacheKey;
        $data = [];
        
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true) ?: [];
        }

        $data[] = time();

        // Régi bejegyzések törlése (max 1 óra)
        $cutoff = time() - 3600;
        $data = array_values(array_filter($data, fn($t) => $t > $cutoff));

        file_put_contents($file, json_encode($data), LOCK_EX);
    }
}
