<?php
declare(strict_types=1);

namespace Netmafia\Infrastructure;

/**
 * RateLimiter - Brute-force és spam védelem
 * 
 * Használat:
 * - Login próbálkozások limitálása
 * - Üzenetküldés korlátozása
 * - Akciók túl gyors végrehajtásának megakadályozása
 */
class RateLimiter
{
    private CacheService $cache;
    
    public function __construct(CacheService $cache)
    {
        $this->cache = $cache;
    }
    
    /**
     * Ellenőrzi és növeli a számlálót
     * 
     * @param string $key Egyedi azonosító (pl. "login:192.168.1.1")
     * @param int $maxAttempts Maximum próbálkozások száma
     * @param int $decaySeconds Mennyi idő után nullázódik
     * @return array ['allowed' => bool, 'remaining' => int, 'retryAfter' => int]
     */
    public function attempt(string $key, int $maxAttempts, int $decaySeconds): array
    {
        $cacheKey = "rate_limit:{$key}";
        $data = $this->cache->get($cacheKey);
        
        $now = time();
        
        if ($data === null) {
            // Első próbálkozás
            $data = [
                'count' => 1,
                'first_attempt' => $now,
                'expires' => $now + $decaySeconds,
            ];
            $this->cache->set($cacheKey, $data, $decaySeconds);
            
            return [
                'allowed' => true,
                'remaining' => $maxAttempts - 1,
                'retryAfter' => 0,
            ];
        }
        
        // Ellenőrizzük lejárt-e
        if ($now >= $data['expires']) {
            // Lejárt, újraindítjuk
            $data = [
                'count' => 1,
                'first_attempt' => $now,
                'expires' => $now + $decaySeconds,
            ];
            $this->cache->set($cacheKey, $data, $decaySeconds);
            
            return [
                'allowed' => true,
                'remaining' => $maxAttempts - 1,
                'retryAfter' => 0,
            ];
        }
        
        // Növeljük a számlálót
        $data['count']++;
        $this->cache->set($cacheKey, $data, $data['expires'] - $now);
        
        $allowed = $data['count'] <= $maxAttempts;
        $remaining = max(0, $maxAttempts - $data['count']);
        $retryAfter = $allowed ? 0 : ($data['expires'] - $now);
        
        return [
            'allowed' => $allowed,
            'remaining' => $remaining,
            'retryAfter' => $retryAfter,
        ];
    }
    
    /**
     * Számlálő nullázása (pl. sikeres login után)
     */
    public function reset(string $key): void
    {
        $this->cache->forget("rate_limit:{$key}");
    }
    
    /**
     * Hátralévő próbálkozások száma
     */
    public function remaining(string $key, int $maxAttempts): int
    {
        $cacheKey = "rate_limit:{$key}";
        $data = $this->cache->get($cacheKey);
        
        if ($data === null) {
            return $maxAttempts;
        }
        
        if (time() >= $data['expires']) {
            return $maxAttempts;
        }
        
        return max(0, $maxAttempts - $data['count']);
    }
    
    /**
     * Ellenőrzi, hogy blokkolva van-e (limit elérve)
     */
    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return $this->remaining($key, $maxAttempts) <= 0;
    }
}
