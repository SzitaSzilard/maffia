<?php
declare(strict_types=1);

namespace Netmafia\Modules\Online\Domain;

use Doctrine\DBAL\Connection;
use Netmafia\Infrastructure\CacheService;

class OnlineService
{
    private Connection $db;
    private CacheService $cache;

    public function __construct(Connection $db, CacheService $cache)
    {
        $this->db = $db;
        $this->cache = $cache;
    }

    /**
     * getOnlineUsers
     * 
     * Returns list of users active in the last 15 minutes.
     * Ordered by username ASC.
     * 
     * [2025-12-29 15:03:20] Optimalizálás: Cache hozzáadása (1 perc TTL)
     * Online lista gyorsan változik, de 1 perc cache jelentősen csökkenti a DB terhelést.
     * 
     * @return array List of users (id, username, is_admin, country_code etc.)
     */
    public function getOnlineUsers(): array
    {
        $cacheKey = 'online_users';
        
        // [2025-12-29 15:03:20] Cache-ből próbálkozás
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
        
        // DB lekérdezés
        $users = $this->db->fetchAllAssociative(
            "SELECT u.id, u.username, u.is_admin, u.country_code, u.xp, c.name_hu as country_name 
             FROM users u
             LEFT JOIN countries c ON u.country_code = c.code 
             WHERE u.last_activity >= NOW() - INTERVAL 15 MINUTE 
             ORDER BY u.username ASC"
        );

        // Enrich with rank and gang
        foreach ($users as &$user) {
            $user['rank_name'] = \Netmafia\Shared\Domain\RankCalculator::getRank((int)($user['xp'] ?? 0));
            $user['gang_name'] = 'Fejlesztés alatt';
        }
        
        // [2025-12-29 15:03:20] Cache-be mentés (60 sec TTL)
        $this->cache->set($cacheKey, $users, 60);

        return $users;
    }

    /**
     * getOnlineCount
     * 
     * Returns simply the count of online users.
     * 
     * [2025-12-29 15:03:20] Optimalizálás: Cache hozzáadása (1 perc TTL)
     */
    public function getOnlineCount(): int
    {
        $cacheKey = 'online_count';
        
        // [2025-12-29 15:03:20] Cache-ből próbálkozás
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return (int) $cached;
        }
        
        // DB lekérdezés
        $count = (int) $this->db->fetchOne(
            "SELECT COUNT(*) 
             FROM users 
             WHERE last_activity >= NOW() - INTERVAL 15 MINUTE"
        );
        
        // [2025-12-29 15:03:20] Cache-be mentés (60 sec TTL)
        $this->cache->set($cacheKey, $count, 60);
        
        return $count;
    }
}
