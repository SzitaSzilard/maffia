<?php
declare(strict_types=1);

namespace Netmafia\Modules\ECrime\Domain;

use Doctrine\DBAL\Connection;
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Modules\Health\Domain\HealthService;
use Netmafia\Modules\Xp\Domain\XpService;
use Netmafia\Modules\Item\Domain\BuffService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Modules\Home\Domain\SleepService;
use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Modules\Credits\Domain\CreditService;

class ECrimeService
{
    private Connection $db;
    private MoneyService $moneyService;
    private HealthService $healthService;
    private XpService $xpService;
    private BuffService $buffService;
    private SleepService $sleepService;
    private CreditService $creditService;

    public function __construct(
        Connection $db,
        MoneyService $moneyService,
        HealthService $healthService,
        XpService $xpService,
        BuffService $buffService,
        SleepService $sleepService,
        CreditService $creditService
    ) {
        $this->db = $db;
        $this->moneyService = $moneyService;
        $this->healthService = $healthService;
        $this->xpService = $xpService;
        $this->buffService = $buffService;
        $this->sleepService = $sleepService;
        $this->creditService = $creditService;
    }

    public function getUserCooldown(int $userId): ?string
    {
        $cooldownDate = $this->db->fetchOne("SELECT scam_cooldown_until FROM users WHERE id = ?", [$userId]);
        return is_string($cooldownDate) ? $cooldownDate : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function executeScam(int $userId, int $scamId): array
    {
        if ($this->sleepService->isUserSleeping(UserId::of($userId))) {
            throw new GameException('Épp alszol, ezért nem tudsz tevékenykedni!');
        }

        if (!isset(ECrimeConfig::SCAM_TYPES[$scamId])) {
            throw new \InvalidArgumentException("Érvénytelen átverés típus!");
        }

        $config = ECrimeConfig::SCAM_TYPES[$scamId];

        try {
            $this->db->beginTransaction();

            // 1. FOR UPDATE Lock a user adatokra a tranzakción belül!
            // Ezzel megakadályozzuk a Race Conditiont (amikor 20 kérés egyszerre érkezik)
            $user = $this->db->fetchAssociative(
                "SELECT has_laptop, energy, scam_cooldown_until, scam_attempts, webserver_expire_at FROM users WHERE id = ? FOR UPDATE", 
                [$userId]
            );

            if (!$user) {
                throw new \RuntimeException("Felhasználó nem található.");
            }

            if (!$user['has_laptop']) {
                throw new \DomainException("Nincs laptopod! Vásárolj egyet a Számítógép boltban a tevékenység használatához.");
            }

            // Webserver check for Scam ID 4
            if ($scamId === 4) {
                if (empty($user['webserver_expire_at'])) {
                    throw new \DomainException("Ehhez a tevékenységhez érvényes webszerver bérlés szükséges!");
                }
                $expireDt = new \DateTimeImmutable($user['webserver_expire_at'], new \DateTimeZone('UTC'));
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                if ($now > $expireDt) {
                    throw new \DomainException("A webszerver bérleted lejárt! Kérlek hosszabbítsd meg a Számítógép boltban.");
                }
            }

            // COOLDOWN VIZSGÁLATA (Már lockolva)
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if (isset($user['scam_cooldown_until']) && is_string($user['scam_cooldown_until'])) {
                $cdTime = new \DateTimeImmutable($user['scam_cooldown_until'], new \DateTimeZone('UTC'));
                if ($cdTime > $now) {
                    throw new \DomainException("Még le kell töltened a várakozási idődet!");
                }
            }

            // ENERGIA VIZSGÁLATA (Már lockolva)
            $energyCost = random_int($config['energy_min'], $config['energy_max']);
            if ($user['energy'] < $energyCost) {
                throw new \DomainException("Nincs elég energiád az akcióhoz! (Min. {$energyCost} energia szükséges)");
            }

            // ENERGIA LEVONÁSA
            // A HealthService önmagában tranzakció-biztos, de mivel mi már egy nyitott tranzakcióban vagyunk
            // és lockoltuk a usert, biztonságosan levonhatjuk az energiát.
            $this->healthService->useEnergy(UserId::of($userId), $energyCost, 'scam_attempt');

            // Következő cooldown számítása (cooldown_reduction buff csökkenthet rajta)
            $baseCooldownSeconds = ECrimeConfig::SCAM_COOLDOWN_MINUTES * 60;
            $reductionPercent = $this->buffService->getActiveBonus($userId, 'cooldown_reduction', 'ecrime');
            $actualCooldownSeconds = ($reductionPercent > 0)
                ? (int)($baseCooldownSeconds * (1 - $reductionPercent / 100))
                : $baseCooldownSeconds;
            $nextCooldown = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $nextCooldown = $nextCooldown->modify("+{$actualCooldownSeconds} seconds")->format('Y-m-d H:i:s');
            $this->db->executeStatement("UPDATE users SET scam_cooldown_until = ?, scam_attempts = scam_attempts + 1 WHERE id = ?", [$nextCooldown, $userId]);

            // Siker Roll (1-100) dinamikus esély alapján
            $attempts = is_numeric($user['scam_attempts']) ? (int)$user['scam_attempts'] : 0;
            $baseChance = (int)$config['base_success_chance'];
            $maxChance = (int)$config['max_success_chance'];
            $kFactor = (float)$config['k_factor'];
            
            $currentChance = (int)min($maxChance, round($baseChance + $kFactor * log(1 + $attempts)));

            $roll = random_int(1, 100);
            $isSuccess = ($roll <= $currentChance);

            $resultData = [
                'success' => $isSuccess,
                'cooldown_until' => $nextCooldown
            ];

            if ($isSuccess) {
                $moneyReward = random_int($config['money_min'], $config['money_max']);
                $xpReward = random_int($config['xp_min'], $config['xp_max']);

                $description = 'E-Bűnözés: ' . $config['name'];
                $this->moneyService->addMoney(UserId::of($userId), $moneyReward, 'robbery', $description);
                $this->xpService->addXp(UserId::of($userId), $xpReward, 'ecrime_scam');

                $messages = $config['success_messages'];
                $resultData['message'] = $messages[array_keys($messages)[random_int(0, max(0, count($messages) - 1))]];
                $resultData['money_gained'] = $moneyReward;
                $resultData['xp_gained'] = $xpReward;
            } else {
                $messages = $config['fail_messages'];
                $resultData['message'] = $messages[array_keys($messages)[random_int(0, max(0, count($messages) - 1))]];
                
                $failXpReward = random_int(7, 12);
                $this->xpService->addXp(UserId::of($userId), $failXpReward, 'ecrime_fail');
                $resultData['xp_gained'] = $failXpReward;
            }

            $this->db->commit();
            return $resultData;

        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            error_log("ECrimeService Error: " . $e->getMessage());
            throw new \RuntimeException("Hiba történt az átverés végrehajtása közben.");
        }
    }

    public function buyLaptop(int $userId): void
    {
        $user = $this->db->fetchAssociative("SELECT has_laptop FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            throw new \RuntimeException("Felhasználó nem található.");
        }
        
        if ($user['has_laptop']) {
            throw new \DomainException("Már rendelkezel laptoppal!");
        }

        try {
            $this->db->beginTransaction();

            $this->moneyService->spendMoney(
                UserId::of($userId), 
                30000, 
                'purchase', 
                'Számítógép bolt: Laptop vásárlás'
            );

            try {
                $this->db->executeStatement("SET @audit_source = 'ECrimeService::buyLaptop'");
                $this->db->executeStatement("UPDATE users SET has_laptop = 1 WHERE id = ?", [$userId]);
            } finally {
                $this->db->executeStatement("SET @audit_source = NULL");
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $this->db->executeStatement("SET @audit_source = NULL");
            error_log("ECrimeService buyLaptop Error: " . $e->getMessage());
            throw $e;
        }
    }

    public function rentWebserver(int $userId): void
    {
        try {
            $this->db->beginTransaction();
            
            $user = $this->db->fetchAssociative("SELECT webserver_expire_at FROM users WHERE id = ? FOR UPDATE", [$userId]);
            if (!$user) {
                throw new \RuntimeException("Felhasználó nem található.");
            }

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if (!empty($user['webserver_expire_at'])) {
                $currentExpiry = new \DateTimeImmutable($user['webserver_expire_at'], new \DateTimeZone('UTC'));
                if ($currentExpiry > $now) {
                    throw new \DomainException("Még rendelkezel aktív webszerver bérlettel!");
                }
            }

            $this->moneyService->spendMoney(
                UserId::of($userId), 
                20000, 
                'purchase', 
                'Számítógép bolt: Webszerver bérlés (1 hónap)'
            );

            // Extend for 30 days
            $newExpiry = $now->modify('+30 days')->format('Y-m-d H:i:s');

            try {
                $this->db->executeStatement("SET @audit_source = 'ECrimeService::rentWebserver'");
                $this->db->executeStatement(
                    "UPDATE users SET webserver_expire_at = ?, webserver_notified = 0 WHERE id = ?", 
                    [$newExpiry, $userId]
                );
            } finally {
                $this->db->executeStatement("SET @audit_source = NULL");
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            error_log("ECrimeService rentWebserver Error: " . $e->getMessage());
            throw $e;
        }
    }

    public function buyPeripherals(int $userId): void
    {
        try {
            $this->db->beginTransaction();

            $user = $this->db->fetchAssociative("SELECT has_laptop, has_peripherals FROM users WHERE id = ? FOR UPDATE", [$userId]);
            if (!$user) {
                throw new \RuntimeException("Felhasználó nem található.");
            }

            if (empty($user['has_laptop'])) {
                throw new \DomainException("Előbb szükséged van egy laptopra, amihez a perifériákat csatlakoztathatod!");
            }

            if (!empty($user['has_peripherals'])) {
                throw new \DomainException("Már rendelkezel profi perifériákkal!");
            }

            $this->creditService->spendCredits(UserId::of($userId), \Netmafia\Shared\Domain\ValueObjects\Credits::of(4), 'ECrimeShop - Perifériák vásárlása');

            try {
                $this->db->executeStatement("SET @audit_source = 'ECrimeService::buyPeripherals'");
                $this->db->executeStatement("UPDATE users SET has_peripherals = 1 WHERE id = ?", [$userId]);
            } finally {
                $this->db->executeStatement("SET @audit_source = NULL");
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $this->db->executeStatement("SET @audit_source = NULL");
            error_log("ECrimeService buyPeripherals Error: " . $e->getMessage());
            throw $e;
        }
    }
}
