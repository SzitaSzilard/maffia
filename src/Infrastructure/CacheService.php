<?php
declare(strict_types=1);

namespace Netmafia\Infrastructure;

use Predis\Client as RedisClient;

/**
 * CacheService - Központi cache kezelő
 * 
 * Támogatott driver-ek:
 * - redis: Predis kliens (production)
 * - file: Fájl alapú cache (development - perzisztens)
 * - array: Memória cache (teszt)
 */
class CacheService
{
    private ?RedisClient $redis = null;
    private array $arrayCache = [];
    private string $driver;
    private string $prefix;
    private string $cachePath;
    
    public function __construct(string $driver = 'file', array $config = [])
    {
        $this->driver = $driver;
        $this->prefix = $config['prefix'] ?? 'netmafia:';
        $this->cachePath = $config['path'] ?? __DIR__ . '/../../var/cache';
        
        // Létrehozzuk a cache könyvtárat ha nem létezik
        if ($driver === 'file' && !is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }
        
        if ($driver === 'redis') {
            $this->redis = new RedisClient([
                'scheme' => 'tcp',
                'host'   => $config['host'] ?? '127.0.0.1',
                'port'   => $config['port'] ?? 6379,
            ]);
        }
    }
    
    /**
     * Érték lekérése cache-ből
     */
    public function get(string $key): mixed
    {
        $fullKey = $this->prefix . $key;
        
        if ($this->driver === 'redis' && $this->redis) {
            $value = $this->redis->get($fullKey);
            return $value !== null ? unserialize($value) : null;
        }
        
        // File cache
        if ($this->driver === 'file') {
            $filePath = $this->getFilePath($fullKey);
            if (file_exists($filePath)) {
                $data = unserialize(file_get_contents($filePath));
                if ($data['expires'] === 0 || $data['expires'] > time()) {
                    return $data['value'];
                }
                // Lejárt, töröljük
                unlink($filePath);
            }
            return null;
        }
        
        // Array cache
        if (isset($this->arrayCache[$fullKey])) {
            $item = $this->arrayCache[$fullKey];
            if ($item['expires'] === 0 || $item['expires'] > time()) {
                return $item['value'];
            }
            unset($this->arrayCache[$fullKey]);
        }
        
        return null;
    }
    
    /**
     * Érték mentése cache-be
     * 
     * @param string $key Cache kulcs
     * @param mixed $value Tárolandó érték
     * @param int $ttl Lejárati idő másodpercben (0 = örök)
     */
    public function set(string $key, mixed $value, int $ttl = 60): void
    {
        $fullKey = $this->prefix . $key;
        
        if ($this->driver === 'redis' && $this->redis) {
            if ($ttl > 0) {
                $this->redis->setex($fullKey, $ttl, serialize($value));
            } else {
                $this->redis->set($fullKey, serialize($value));
            }
            return;
        }
        
        // File cache
        if ($this->driver === 'file') {
            $filePath = $this->getFilePath($fullKey);
            $data = [
                'key' => $fullKey,
                'value' => $value,
                'expires' => $ttl > 0 ? time() + $ttl : 0,
            ];
            file_put_contents($filePath, serialize($data), LOCK_EX);
            return;
        }
        
        // Array cache
        $this->arrayCache[$fullKey] = [
            'value' => $value,
            'expires' => $ttl > 0 ? time() + $ttl : 0,
        ];
    }
    
    /**
     * Fájl elérési út generálása kulcsból
     */
    private function getFilePath(string $key): string
    {
        // Biztonságos fájlnév - md5 hash
        $safeKey = md5($key);
        return $this->cachePath . '/' . $safeKey . '.cache';
    }
    
    /**
     * "Remember" pattern - cache-ből veszi, vagy kiszámolja és elmenti
     * 
     * @param string $key Cache kulcs
     * @param int $ttl TTL másodpercben
     * @param callable $callback Érték kiszámítása ha nincs cache-ben
     */
    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $value = $this->get($key);
        
        if ($value !== null) {
            return $value;
        }
        
        $value = $callback();
        $this->set($key, $value, $ttl);
        
        return $value;
    }
    
    /**
     * Egy kulcs törlése
     */
    public function forget(string $key): void
    {
        $fullKey = $this->prefix . $key;
        
        if ($this->driver === 'redis' && $this->redis) {
            $this->redis->del($fullKey);
            return;
        }
        
        // File cache
        if ($this->driver === 'file') {
            $filePath = $this->getFilePath($fullKey);
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            return;
        }
        
        unset($this->arrayCache[$fullKey]);
    }
    
    /**
     * Több kulcs törlése pattern alapján
     * 
     * @param string $pattern Pl: "user:*" - minden user: kezdetű kulcs
     */
    public function forgetPattern(string $pattern): void
    {
        $fullPattern = $this->prefix . $pattern;
        
        if ($this->driver === 'redis' && $this->redis) {
            $keys = $this->redis->keys($fullPattern);
            if (!empty($keys)) {
                $this->redis->del($keys);
            }
            return;
        }
        
        // File cache — glob minden fájlt, deserialize + key match
        if ($this->driver === 'file') {
            $regex = $this->globToRegex($fullPattern);
            $files = glob($this->cachePath . '/*.cache');
            if ($files === false) {
                return;
            }
            foreach ($files as $file) {
                $data = @unserialize((string)file_get_contents($file));
                if ($data && isset($data['key']) && preg_match($regex, $data['key'])) {
                    unlink($file);
                }
            }
            return;
        }
        
        // Array cache - egyszerű pattern matching (glob -> regex)
        $regex = $this->globToRegex($fullPattern);
        foreach (array_keys($this->arrayCache) as $key) {
            if (preg_match($regex, $key)) {
                unset($this->arrayCache[$key]);
            }
        }
    }
    
    /**
     * Glob pattern konvertálása regex-re
     */
    private function globToRegex(string $globPattern): string
    {
        $regexPattern = '';
        $len = strlen($globPattern);
        for ($i = 0; $i < $len; $i++) {
            $char = $globPattern[$i];
            switch ($char) {
                case '*':
                    $regexPattern .= '.*';
                    break;
                case '?':
                    $regexPattern .= '.';
                    break;
                default:
                    if (in_array($char, ['\\', '^', '$', '.', '[', ']', '|', '(', ')', '+', '{', '}'], true)) {
                        $regexPattern .= '\\' . $char;
                    } else {
                        $regexPattern .= $char;
                    }
            }
        }
        return '/^' . $regexPattern . '$/';
    }
    
    /**
     * Teljes cache ürítése (prefix alatt)
     */
    public function flush(): void
    {
        if ($this->driver === 'redis' && $this->redis) {
            $this->forgetPattern('*');
            return;
        }
        
        // File cache — összes .cache fájl törlése
        if ($this->driver === 'file') {
            $files = glob($this->cachePath . '/*.cache');
            if ($files !== false) {
                array_map('unlink', $files);
            }
            return;
        }
        
        $this->arrayCache = [];
    }
    
    /**
     * Cache statisztikák (debug)
     */
    public function stats(): array
    {
        if ($this->driver === 'redis' && $this->redis) {
            $info = $this->redis->info();
            return [
                'driver' => 'redis',
                'keys' => count($this->redis->keys($this->prefix . '*')),
                'memory' => $info['used_memory_human'] ?? 'N/A',
            ];
        }
        
        // File cache
        if ($this->driver === 'file') {
            $files = glob($this->cachePath . '/*.cache');
            return [
                'driver' => 'file',
                'keys' => $files !== false ? count($files) : 0,
                'memory' => 'disk',
            ];
        }
        
        return [
            'driver' => 'array',
            'keys' => count($this->arrayCache),
            'memory' => 'in-process',
        ];
    }
    
    /**
     * Ellenőrzi, hogy a Redis kapcsolat él-e
     */
    public function isConnected(): bool
    {
        if ($this->driver !== 'redis' || !$this->redis) {
            return false;
        }
        
        try {
            $this->redis->ping();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Getter a Redis klienshez (WebSocket pub/sub-hoz)
     */
    public function getRedisClient(): ?RedisClient
    {
        return $this->redis;
    }
}
