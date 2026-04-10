<?php

declare(strict_types=1);

namespace Netmafia\Modules\PettyCrime\Domain;

use Doctrine\DBAL\Connection;
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Modules\Health\Domain\HealthService;
use Netmafia\Modules\Xp\Domain\XpService;
use Netmafia\Infrastructure\SessionService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Modules\PettyCrime\PettyCrimeConfig;
use Netmafia\Modules\Home\Domain\SleepService;

class PettyCrimeService
{
    private Connection $db;
    private MoneyService $moneyService;
    private HealthService $healthService;
    private XpService $xpService;
    private SessionService $session;
    private SleepService $sleepService;

    public function __construct(
        Connection $db,
        MoneyService $moneyService,
        HealthService $healthService,
        XpService $xpService,
        SessionService $session,
        SleepService $sleepService
    ) {
        $this->db = $db;
        $this->moneyService = $moneyService;
        $this->healthService = $healthService;
        $this->xpService = $xpService;
        $this->session = $session;
        $this->sleepService = $sleepService;
    }

    /**
     * Esélyszámítás: min(99, base_chance + K * log(1 + attempts))
     */
    public function calculateChance(int $baseChance, string $level, int $attempts): float
    {
        $k = PettyCrimeConfig::K_VALUES[$level] ?? 5;
        return min(99.0, $baseChance + $k * log(1 + $attempts));
    }

    /**
     * Feltérképezés — 1-5 random bűncselekmény generálása.
     * Ellenőrzi a scan cooldown-t, beállítja az új cooldown-t,
     * majd session-ba menti a generált lehetőségeket (anti-spoofing).
     */
    public function scan(UserId $userId): array
    {
        if ($this->sleepService->isUserSleeping($userId)) {
            throw new GameException('Míg alszol, nem tudsz vizsgélódni Kisstílű Bűnözéshez!');
        }

        $this->db->beginTransaction();
        try {
            $user = $this->db->fetchAssociative(
                "SELECT id, energy, petty_crime_scan_cooldown_until, petty_crime_attempts 
                 FROM users WHERE id = ? FOR UPDATE",
                [$userId->id()]
            );

            if (!$user) {
                throw new GameException('Felhasználó nem található!');
            }

            // Scan cooldown ellenőrzés
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if (!empty($user['petty_crime_scan_cooldown_until'])) {
                $cdTime = new \DateTimeImmutable($user['petty_crime_scan_cooldown_until'], new \DateTimeZone('UTC'));
                if ($cdTime > $now) {
                    $secs = $cdTime->getTimestamp() - $now->getTimestamp();
                    $mins = (int)ceil($secs / 60);
                    throw new GameException("Még {$mins} percig nem térképezheted fel a környéket!");
                }
            }

            // 1-5 db random lehetőség generálása
            $count = random_int(PettyCrimeConfig::SCAN_MIN_RESULTS, PettyCrimeConfig::SCAN_MAX_RESULTS);
            $allIds = array_keys(PettyCrimeConfig::CRIMES);
            shuffle($allIds); // Ha PHP-s shuffle nem CSPRNG, lásd alább
            $selectedIds = array_slice($allIds, 0, $count);

            $attempts = (int)($user['petty_crime_attempts'] ?? 0);
            $opportunities = [];

            foreach ($selectedIds as $crimeId) {
                $crime = PettyCrimeConfig::CRIMES[$crimeId];
                $chance = $this->calculateChance($crime['base_chance'], $crime['level'], $attempts);
                $energyCost = $this->getEnergyCost($crime['level']);

                $opportunities[] = [
                    'crime_id'    => $crime['id'],
                    'name'        => $crime['name'],
                    'level'       => $crime['level'],
                    'level_name'  => PettyCrimeConfig::LEVEL_NAMES[$crime['level']],
                    'chance'      => round($chance, 1),
                    'energy_cost' => $energyCost,
                ];
            }

            // Scan cooldown beállítása
            $cooldownUntil = $now->modify('+' . PettyCrimeConfig::SCAN_COOLDOWN_MINUTES . ' minutes')->format('Y-m-d H:i:s');

            try {
                $this->db->executeStatement("SET @audit_source = 'PettyCrimeService::scan'");
                $this->db->executeStatement(
                    "UPDATE users SET petty_crime_scan_cooldown_until = ? WHERE id = ?",
                    [$cooldownUntil, $userId->id()]
                );
            } finally {
                $this->db->executeStatement("SET @audit_source = NULL");
            }

            $this->db->commit();

            // Session-ba mentjük a generált crime ID-kat (anti-spoofing a commit-nál)
            $validIds = array_column($opportunities, 'crime_id');
            $this->session->set('petty_crime_valid_ids', json_encode($validIds));

            return [
                'opportunities'   => $opportunities,
                'cooldown_until'  => $cooldownUntil,
            ];

        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Bűncselekmény elkövetése.
     * Anti-spoofing: csak session-ban érvényes crime_id-t fogad el.
     */
    public function commit(UserId $userId, int $crimeId): array
    {
        if ($this->sleepService->isUserSleeping($userId)) {
            throw new GameException('Míg alszol, nem tudsz kisstílű bűnözést elkövetni!');
        }

        // Anti-spoofing: session-ból olvassuk az érvényes crime ID-kat
        $validIdsJson = $this->session->get('petty_crime_valid_ids');
        $validIds = $validIdsJson ? json_decode($validIdsJson, true) : [];

        if (!in_array($crimeId, $validIds, true)) {
            throw new GameException('Érvénytelen akció! Először térképezd fel a környéket.');
        }

        if (!isset(PettyCrimeConfig::CRIMES[$crimeId])) {
            throw new GameException('Ismeretlen bűncselekmény!');
        }

        $crime = PettyCrimeConfig::CRIMES[$crimeId];

        $this->db->beginTransaction();
        try {
            $user = $this->db->fetchAssociative(
                "SELECT id, energy, petty_crime_commit_cooldown_until, petty_crime_attempts 
                 FROM users WHERE id = ? FOR UPDATE",
                [$userId->id()]
            );

            if (!$user) {
                throw new GameException('Felhasználó nem található!');
            }

            // Commit cooldown ellenőrzés
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if (!empty($user['petty_crime_commit_cooldown_until'])) {
                $cdTime = new \DateTimeImmutable($user['petty_crime_commit_cooldown_until'], new \DateTimeZone('UTC'));
                if ($cdTime > $now) {
                    $secs = $cdTime->getTimestamp() - $now->getTimestamp();
                    $mins = (int)ceil($secs / 60);
                    throw new GameException("Még {$mins} percig nem követhetsz el bűncselekményt!");
                }
            }

            // Energia ellenőrzés + levonás
            $energyCost = $this->getEnergyCost($crime['level']);
            if ((int)$user['energy'] < $energyCost) {
                throw new GameException("Nincs elég energiád! ({$energyCost} energia szükséges)");
            }

            $this->healthService->useEnergy($userId, $energyCost, 'petty_crime');

            // Esélyszámítás + dobás
            $attempts = (int)($user['petty_crime_attempts'] ?? 0);
            $chance = $this->calculateChance($crime['base_chance'], $crime['level'], $attempts);
            $roll = random_int(1, 100);
            $isSuccess = $roll <= (int)$chance;

            // Cooldown beállítása
            $cooldownUntil = $now->modify('+' . PettyCrimeConfig::COMMIT_COOLDOWN_MINUTES . ' minutes')->format('Y-m-d H:i:s');

            try {
                $this->db->executeStatement("SET @audit_source = 'PettyCrimeService::commit'");
                $this->db->executeStatement(
                    "UPDATE users SET petty_crime_commit_cooldown_until = ?, petty_crime_attempts = petty_crime_attempts + 1 WHERE id = ?",
                    [$cooldownUntil, $userId->id()]
                );
            } finally {
                $this->db->executeStatement("SET @audit_source = NULL");
            }

            $level = $crime['level'];

            if ($isSuccess) {
                $xpRange = PettyCrimeConfig::XP_WIN[$level];
                $xpReward = random_int($xpRange['min'], $xpRange['max']);
                $moneyRange = PettyCrimeConfig::MONEY_WIN[$level];
                $moneyReward = random_int($moneyRange['min'], $moneyRange['max']);

                $this->xpService->addXp($userId, $xpReward, 'petty_crime');
                $this->moneyService->addMoney($userId, $moneyReward, 'robbery', 'Kisstílű bűnözés: ' . $crime['name']);

                // Session-ból töröljük az érvényes ID-kat (fel kell térképezni újra)
                $this->session->set('petty_crime_valid_ids', null);

                $this->db->commit();

                return [
                    'success'      => true,
                    'crime_name'   => $crime['name'],
                    'xp_gained'    => $xpReward,
                    'money_gained' => $moneyReward,
                    'energy_cost'  => $energyCost,
                    'chance'       => round($chance, 1),
                    'cooldown_until' => $cooldownUntil,
                ];
            } else {
                $xpReward = random_int(PettyCrimeConfig::XP_FAIL_MIN, PettyCrimeConfig::XP_FAIL_MAX);
                $this->xpService->addXp($userId, $xpReward, 'petty_crime_fail');

                // Bukásnál is töröljük — újra kell térképezni
                $this->session->set('petty_crime_valid_ids', null);

                $this->db->commit();

                return [
                    'success'      => false,
                    'crime_name'   => $crime['name'],
                    'xp_gained'    => $xpReward,
                    'money_gained' => 0,
                    'energy_cost'  => $energyCost,
                    'chance'       => round($chance, 1),
                    'cooldown_until' => $cooldownUntil,
                ];
            }

        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Energia igény szintenként (profi esetén random 12-22)
     */
    public function getEnergyCost(string $level): int
    {
        if ($level === 'profi') {
            return random_int(PettyCrimeConfig::ENERGY_COST['profi_min'], PettyCrimeConfig::ENERGY_COST['profi_max']);
        }
        return PettyCrimeConfig::ENERGY_COST[$level] ?? 4;
    }

    /**
     * Cooldown adatok lekérése a UI-hoz
     */
    public function getCooldownData(int $userId): array
    {
        $row = $this->db->fetchAssociative(
            "SELECT petty_crime_scan_cooldown_until, petty_crime_commit_cooldown_until FROM users WHERE id = ?",
            [$userId]
        );

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $result = [
            'scan_remaining'  => 0,
            'scan_target'     => 0,
            'commit_remaining' => 0,
            'commit_target'   => 0,
        ];

        if ($row && !empty($row['petty_crime_scan_cooldown_until'])) {
            $dt = new \DateTimeImmutable($row['petty_crime_scan_cooldown_until'], new \DateTimeZone('UTC'));
            if ($dt > $now) {
                $result['scan_remaining'] = $dt->getTimestamp() - $now->getTimestamp();
                $result['scan_target']    = $dt->getTimestamp();
            }
        }

        if ($row && !empty($row['petty_crime_commit_cooldown_until'])) {
            $dt = new \DateTimeImmutable($row['petty_crime_commit_cooldown_until'], new \DateTimeZone('UTC'));
            if ($dt > $now) {
                $result['commit_remaining'] = $dt->getTimestamp() - $now->getTimestamp();
                $result['commit_target']    = $dt->getTimestamp();
            }
        }

        return $result;
    }

    /**
     * Session-ban tárolt scan lehetőségek lekérése (UI-hoz)
     * @return array<int, array<string, mixed>>
     */
    public function getSessionOpportunities(int $userId): array
    {
        $validIdsJson = $this->session->get('petty_crime_valid_ids');
        if (!$validIdsJson) {
            return [];
        }

        $validIds = json_decode($validIdsJson, true);
        if (!is_array($validIds) || empty($validIds)) {
            return [];
        }

        $userAttempts = (int)($this->db->fetchOne(
            "SELECT petty_crime_attempts FROM users WHERE id = ?",
            [$userId]
        ) ?? 0);

        $opportunities = [];
        foreach ($validIds as $crimeId) {
            $crimeId = (int)$crimeId;
            if (!isset(PettyCrimeConfig::CRIMES[$crimeId])) {
                continue;
            }
            $crime = PettyCrimeConfig::CRIMES[$crimeId];
            $chance = $this->calculateChance($crime['base_chance'], $crime['level'], $userAttempts);
            $energyCost = $this->getEnergyCost($crime['level']);

            $opportunities[] = [
                'crime_id'    => $crime['id'],
                'name'        => $crime['name'],
                'level'       => $crime['level'],
                'level_name'  => PettyCrimeConfig::LEVEL_NAMES[$crime['level']],
                'chance'      => round($chance, 1),
                'energy_cost' => $energyCost,
            ];
        }

        return $opportunities;
    }
}
