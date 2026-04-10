<?php
declare(strict_types=1);

namespace Netmafia\Modules\Auth\Domain;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

use Doctrine\DBAL\Connection;
use Netmafia\Shared\Domain\RankCalculator;

class AuthService
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function getStartingCountries(): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT code, name_hu as name FROM countries WHERE code IN ('US', 'IT', 'DE') ORDER BY name_hu ASC"
        );
    }

    /**
     * Felhasználó regisztrációja
     * 
     * [2026-02-15] FIX: Tranzakcióba csomagolva.
     * [2026-02-22] FIX: IP paraméterként, username sanitizáció, email normalizáció
     */
    public function register(string $username, string $email, string $password, string $countryCode, ?string $ip = null): bool
    {
        $this->db->beginTransaction();
        
        try {
            // [FIX #9] Email normalizáció
            $email = mb_strtolower(trim($email));
            
            // [FIX #8] Username sanitizáció
            $username = trim($username);
            if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $username)) {
                $this->db->rollBack();
                throw new InvalidInputException('A felhasználónév csak betűket, számokat, aláhúzást, kötőjelet és pontot tartalmazhat!');
            }
            
            // 1. Ország ellenőrzése
            $countryExists = $this->db->fetchOne("SELECT count(*) FROM countries WHERE code = ?", [$countryCode]);
            if (!$countryExists) {
                $this->db->rollBack();
                throw new InvalidInputException('Érvénytelen ország kód!');
            }

            // Egyediség ellenőrzése
            $exists = $this->db->fetchOne(
                "SELECT count(*) FROM users WHERE username = ? OR email = ?",
                [$username, $email]
            );
            if ($exists > 0) {
                $this->db->rollBack();
                throw new InvalidInputException('Ez a felhasználónév vagy email cím már foglalt!');
            }

            $hash = password_hash($password, PASSWORD_BCRYPT);

            $this->db->insert('users', [
                'username' => $username,
                'country_code' => $countryCode,
                'email' => $email,
                'password' => $hash,
                'created_at' => gmdate('Y-m-d H:i:s'),
                'ip_address' => $ip,
                'money' => 120000,
                'bullets' => 0,
                'health' => 100,
                'energy' => 100,
                'credits' => 0,
                'xp' => 0
            ]);
            
            $this->db->commit();
            return true;
            
        } catch (InvalidInputException $e) {
            // [FIX #4] Helyes exception típus — rollback már megtörtént fent
            throw $e;
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Bejelentkezési kísérlet
     * 
     * [2026-02-15] FIX: is_banned ellenőrzés hozzáadva + SELECT * helyett
     * explicit mezőlista (jelszó hash nem szivárog ki a session-be).
     */
    public function attemptLogin(string $username, string $password): ?array
    {
        // [2026-02-15] FIX: Explicit mezőlista – jelszó hash-t csak ellenőrzésre kérjük le
        $user = $this->db->fetchAssociative(
            "SELECT id, username, email, password, country_code, money, credits, 
                    health, energy, xp, bullets, is_admin, is_banned,
                    created_at, last_activity
             FROM users WHERE username = ?",
            [$username]
        );

        if (!$user) {
            return null;
        }

        // [2026-02-15] FIX: Tiltott felhasználó nem léphet be
        if (!empty($user['is_banned'])) {
            throw new GameException('Ez a fiók le van tiltva!');
        }

        if (password_verify($password, $user['password'])) {
            // Jelszó hash eltávolítása a visszaadott adatokból (biztonsági okokból)
            unset($user['password']);
            return $user;
        }

        return null;
    }

    public function getUserById(int $id): ?array
    {
        // [FIX #2] Explicit mezőlista — jelszó hash NEM szivárog ki
        $user = $this->db->fetchAssociative("
            SELECT u.id, u.username, u.email, u.country_code, u.money, u.credits,
                   u.health, u.energy, u.xp, u.bullets, u.is_admin, u.is_banned,
                   u.is_union_member, u.wins, u.losses, u.kills,
                   u.created_at, u.last_activity,
                   u.last_travel_time, u.last_airplane_travel_time,
                   u.travel_cooldown_until, u.airplane_cooldown_until,
                   u.daily_highway_usage, u.highway_sticker_level, u.highway_sticker_expiry,
                   u.oc_cooldown_until, u.oc_success_count, u.oc_fail_count,
                   u.scam_attempts, u.has_laptop, u.scam_cooldown_until, u.webserver_expire_at,
                   u.car_theft_cooldown_until,
                   u.petty_crime_scan_cooldown_until, u.petty_crime_commit_cooldown_until,
                   u.petty_crime_attempts,
                   u.last_weed_plant_at, u.last_weed_harvest_at,
                   u.virus_progress, u.zombie_count, u.virus_dev_cooldown_until, u.last_virus_development_at, u.virus_dist_attempts,
                   u.has_peripherals,
                   c.name_hu as country_name,
                   (SELECT sleep_end_at FROM user_sleep us WHERE us.user_id = u.id AND us.sleep_end_at > NOW() ORDER BY id DESC LIMIT 1) as sleep_end_at
            FROM users u 
            LEFT JOIN countries c ON u.country_code = c.code 
            WHERE u.id = ?
        ", [$id]);

        if ($user) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            
            $user['has_ecrime_cooldown'] = false;
            if (!empty($user['scam_cooldown_until'])) {
                $dt = new \DateTimeImmutable($user['scam_cooldown_until'], new \DateTimeZone('UTC'));
                if ($dt > $now) $user['has_ecrime_cooldown'] = true;
            }

            $user['has_hack_cooldown'] = false;
            if (!empty($user['virus_dev_cooldown_until'])) {
                $dt = new \DateTimeImmutable($user['virus_dev_cooldown_until'], new \DateTimeZone('UTC'));
                if ($dt > $now) $user['has_hack_cooldown'] = true;
            }

            $user['has_oc_cooldown'] = false;
            if (!empty($user['oc_cooldown_until'])) {
                $dt = new \DateTimeImmutable($user['oc_cooldown_until'], new \DateTimeZone('UTC'));
                if ($dt > $now) $user['has_oc_cooldown'] = true;
            }

            $user['has_car_theft_cooldown'] = false;
            if (!empty($user['car_theft_cooldown_until'])) {
                $dt = new \DateTimeImmutable($user['car_theft_cooldown_until'], new \DateTimeZone('UTC'));
                if ($dt > $now) $user['has_car_theft_cooldown'] = true;
            }

            $user['has_petty_crime_scan_cooldown'] = false;
            if (!empty($user['petty_crime_scan_cooldown_until'])) {
                $dt = new \DateTimeImmutable($user['petty_crime_scan_cooldown_until'], new \DateTimeZone('UTC'));
                if ($dt > $now) $user['has_petty_crime_scan_cooldown'] = true;
            }

            $user['has_petty_crime_commit_cooldown'] = false;
            if (!empty($user['petty_crime_commit_cooldown_until'])) {
                $dt = new \DateTimeImmutable($user['petty_crime_commit_cooldown_until'], new \DateTimeZone('UTC'));
                if ($dt > $now) $user['has_petty_crime_commit_cooldown'] = true;
            }

            // Weed cooldowns (DB stores UTC via UTC_TIMESTAMP(), PHP timezone = UTC → strtotime() is correct)
            $user['has_weed_plant_cooldown'] = false;
            $user['weed_plant_cooldown_until_ts'] = 0;
            if (!empty($user['last_weed_plant_at'])) {
                $plantUntilTs = strtotime($user['last_weed_plant_at']) + (12 * 3600);
                if ($plantUntilTs > time()) {
                    $user['has_weed_plant_cooldown'] = true;
                    $user['weed_plant_cooldown_until_ts'] = $plantUntilTs;
                }
            }

            $user['has_weed_harvest_cooldown'] = false;
            $user['weed_harvest_cooldown_until_ts'] = 0;
            if (!empty($user['last_weed_harvest_at'])) {
                $harvestUntilTs = strtotime($user['last_weed_harvest_at']) + (24 * 3600);
                if ($harvestUntilTs > time()) {
                    $user['has_weed_harvest_cooldown'] = true;
                    $user['weed_harvest_cooldown_until_ts'] = $harvestUntilTs;
                }
            }

            $user['has_highway_cooldown'] = false;
            $user['has_airplane_cooldown'] = false;
            $user['travel_cooldown_until_ts'] = 0;
            $user['airplane_cooldown_until_ts'] = 0;

            if (!empty($user['travel_cooldown_until'])) {
                $dt = new \DateTimeImmutable($user['travel_cooldown_until'], new \DateTimeZone('UTC'));
                if ($dt > $now) {
                    $user['has_highway_cooldown'] = true;
                    $user['travel_cooldown_until_ts'] = $dt->getTimestamp();
                }
            }
            if (!empty($user['airplane_cooldown_until'])) {
                $dt = new \DateTimeImmutable($user['airplane_cooldown_until'], new \DateTimeZone('UTC'));
                if ($dt > $now) {
                    $user['has_airplane_cooldown'] = true;
                    $user['airplane_cooldown_until_ts'] = $dt->getTimestamp();
                }
            }
        }

        return $user ?: null;
    }

    /**
     * Autentikált user lekérése rank_name-mel együtt
     * Használd ezt az Action-ökben a session user_id-ből
     */
    public function getAuthenticatedUser(int $userId): ?array
    {
        $user = $this->getUserById($userId);
        
        if ($user) {
            $xp = (int)($user['xp'] ?? 0);
            $user['rank_name'] = RankCalculator::getRank($xp);
            $user['next_rank'] = RankCalculator::getNextRankProgress($xp);
        }
        
        return $user;
    }

    /**
     * Check if user is admin
     */
    public function isAdmin(int $userId): bool
    {
        $isAdmin = $this->db->fetchOne("SELECT is_admin FROM users WHERE id = ?", [$userId]);
        return (bool)$isAdmin;
    }
}

