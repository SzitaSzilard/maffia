<?php
declare(strict_types=1);

namespace Netmafia\Modules\AmmoFactory\Domain;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

use Doctrine\DBAL\Connection;
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Modules\AmmoFactory\Domain\BulletService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Modules\AmmoFactory\AmmoFactoryConfig;

class AmmoFactoryService
{
    private Connection $db;
    private MoneyService $moneyService;
    private BulletService $bulletService;

// Config constants moved to AmmoFactoryConfig

    public function __construct(Connection $db, MoneyService $moneyService, BulletService $bulletService)
    {
        $this->db          = $db;
        $this->moneyService  = $moneyService;
        $this->bulletService = $bulletService;
    }

    /**
     * Get factory data and perform lazy update
     */
    public function getFactoryData(int $buildingId): ?array
    {
        // Fetch building & production data
        $data = $this->db->fetchAssociative(
            "SELECT 
                b.*, 
                u.username as owner_name,
                afp.*
             FROM buildings b
             LEFT JOIN users u ON b.owner_id = u.id
             LEFT JOIN ammo_factory_production afp ON b.id = afp.building_id
             WHERE b.id = ?",
            [$buildingId]
        );

        if (!$data) {
            return null;
        }

        // Initialize table entry if missing (first time)
        if ($data['ammo_stock'] === null) {
            $this->db->executeStatement(
                "INSERT INTO ammo_factory_production (building_id) VALUES (?)",
                [$buildingId]
            );
            return $this->getFactoryData($buildingId); // Recurse once
        }

        // Lazy Update Logic
        if ($data['is_producing']) {
            $this->processProduction($data);
            // Re-fetch updated data
            $data = $this->db->fetchAssociative(
                "SELECT b.*, u.username as owner_name, afp.*
                 FROM buildings b
                 LEFT JOIN users u ON b.owner_id = u.id
                 LEFT JOIN ammo_factory_production afp ON b.id = afp.building_id
                 WHERE b.id = ?",
                [$buildingId]
            );
        }

        return $data;
    }

    /**
     * Adatok lekérése az öszzes tölténygyárhoz (Országok nézethez)
     * FIGYELEM: Itt NEM futtatunk lazy update-et mindenre, mert lassú lenne!
     * Csak a tárolt állapotot adjuk vissza.
     */
    public function getAllFactoriesData(): array
    {
        // Kulcs: building_id, Érték: array
        $rows = $this->db->fetchAllAssociative(
            "SELECT building_id, ammo_stock, ammo_price FROM ammo_factory_production"
        );
        
        $result = [];
        foreach ($rows as $row) {
            $result[$row['building_id']] = $row;
        }
        return $result;
    }

    /**
     * Start Production
     */
    public function startProduction(int $buildingId, int $ownerId, int $investment): void
    {
        $this->db->beginTransaction();
        try {
            // [FIX] Lock Factory Row to prevent Race Condition
            $factory = $this->db->fetchAssociative(
                "SELECT afp.*, b.owner_id 
                 FROM buildings b
                 LEFT JOIN ammo_factory_production afp ON b.id = afp.building_id
                 WHERE b.id = ? FOR UPDATE",
                [$buildingId]
            );

            // Lazy Update Trigger via internal logic if needed, OR just trust locked state.
            // If we lock here, we see the state committed by others.
            // If previous lazy update committed, we see true state.
            
            // Validation
            if (($factory['owner_id'] ?? null) !== $ownerId) {
                throw new GameException("Nincs jogosultságod!");
            }

            if ($factory['is_producing']) {
                throw new GameException("A termelés már folyamatban van!");
            }

            // Calculate quantity
            $qty = (int) floor($investment / AmmoFactoryConfig::COST_PER_UNIT);

            if ($qty <= 0) {
                throw new GameException("Túl kevés befektetés! (Min: $" . AmmoFactoryConfig::COST_PER_UNIT . ")");
            }

            if ($qty > AmmoFactoryConfig::MANUAL_LIMIT) {
                 throw new GameException("A maximális manuális limit " . number_format(AmmoFactoryConfig::MANUAL_LIMIT) . " db!");
            }
            
            // Money deduction
            $this->moneyService->spendMoney(
                UserId::of($ownerId),
                $investment,
                'spend',
                'Tölténygyártás indítása (' . $qty . ' db)',
                'building',
                $buildingId
            );

            // Start Logic - Insert if missing (should be handled by getFactoryData but we are manual here)
            // If afp entry missing, insert it.
            if (!isset($factory['building_id'])) {
                 $this->db->insert('ammo_factory_production', ['building_id' => $buildingId]);
            }

            $this->db->update('ammo_factory_production', [
                'is_producing' => 1,
                'production_start_time' => gmdate('Y-m-d H:i:s'),
                'last_production_update' => gmdate('Y-m-d H:i:s'),
                'production_target_qty' => $qty,
                'production_completed_qty' => 0
            ], ['building_id' => $buildingId]);

            $this->db->commit();
            
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Public Buy Ammo
     */
    public function buyAmmo(int $buyerId, int $buildingId, int $qty): void
    {
        // Lazy update first (getFactoryData does this)
        $this->getFactoryData($buildingId);

        // Begin transaction BEFORE checking stock (FOR UPDATE lock)
        $this->db->beginTransaction();
        
        try {
            // CRITICAL: Re-fetch with FOR UPDATE inside transaction for thread safety
            // This prevents race conditions where 2 users buy at the same time
            $factory = $this->db->fetchAssociative(
                "SELECT afp.*, b.owner_id 
                 FROM ammo_factory_production afp
                 JOIN buildings b ON b.id = afp.building_id
                 WHERE afp.building_id = ? FOR UPDATE",
                [$buildingId]
            );
            
            // Validations
            if ($factory['owner_id'] === $buyerId) {
                throw new GameException("Saját gyáradból nem vásárolhatsz!");
            }

            if ($factory['ammo_stock'] <= 0) {
                throw new GameException("Jelenleg a raktár üres, így nem tudsz töltényt vásárolni.");
            }

            if ($factory['ammo_stock'] < AmmoFactoryConfig::MIN_PURCHASE_QTY) {
                throw new GameException("A minimum vásárlás " . AmmoFactoryConfig::MIN_PURCHASE_QTY . "db, sajnáljuk a készlet " . AmmoFactoryConfig::MIN_PURCHASE_QTY . " alatt van.");
            }

            if ($qty < AmmoFactoryConfig::MIN_PURCHASE_QTY) {
                throw new GameException("Minimum " . AmmoFactoryConfig::MIN_PURCHASE_QTY . " darabot kell vásárolni!");
            }

            if ($factory['ammo_stock'] < $qty) {
                throw new GameException("Nincs elég készlet a választott mennyiséghez!");
            }
            
            $totalPrice = $qty * $factory['ammo_price'];

            // Buyer pays
            $this->moneyService->spendMoney(
                UserId::of($buyerId),
                $totalPrice,
                'purchase',
                'Töltény vásárlás (' . $qty . ' db)'
            );

            // Owner receives full amount
            if ($factory['owner_id']) {
                $this->moneyService->addMoney(
                    UserId::of((int)$factory['owner_id']),
                    $totalPrice,
                    'sell',
                    'Töltény eladás bevétel'
                );
            }

            // [LEDGER] BulletService kezeli a jóváírást — market_buy típus
            $this->bulletService->addBullets(
                UserId::of($buyerId),
                $qty,
                'market_buy',
                'Töltény vásárlás (' . $qty . ' db)',
                'building', $buildingId
            );

            // Remove from stock
            $this->db->executeStatement(
                "UPDATE ammo_factory_production SET ammo_stock = ammo_stock - ? WHERE building_id = ?",
                [$qty, $buildingId]
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Update Price
     */
    public function updatePrice(int $buildingId, int $ownerId, int $newPrice): void
    {
        $factory = $this->getFactoryData($buildingId);
        
        if ($factory['owner_id'] !== $ownerId) {
             throw new GameException("Nincs jogosultságod!");
        }

        if ($newPrice < AmmoFactoryConfig::MIN_PRICE) {
            throw new GameException("Az ár nem lehet kevesebb mint $" . AmmoFactoryConfig::MIN_PRICE . "!");
        }

        $this->db->update('ammo_factory_production', [
            'ammo_price' => $newPrice
        ], ['building_id' => $buildingId]);
    }

    /**
     * Lazy Update Logic
     * 
     * [2026-02-15] FIX: Tranzakcióba csomagolva + FOR UPDATE lock hozzáadva.
     * Korábban párhuzamos kérések dupla termelést okozhattak, mert nem volt
     * thread-safe a processProduction(). Most pesszimista lockkal védjük.
     */
    private function processProduction(array $factory): void
    {
        $lastUpdate = new \DateTime($factory['last_production_update']);
        $dbNow = $this->db->fetchOne("SELECT NOW()");
        $now = new \DateTime($dbNow);
        
        // Eltelt percek számítása
        $diffMin = ($now->getTimestamp() - $lastUpdate->getTimestamp()) / 60;
        
        if ($diffMin < 1) {
            return; // Kevesebb mint 1 perc – várakozás
        }

        // [2026-02-15] FIX: Tranzakció + FOR UPDATE lock a race condition ellen
        $this->db->beginTransaction();
        
        try {
            // FOR UPDATE lock – más request nem módosíthatja közben
            $lockedFactory = $this->db->fetchAssociative(
                "SELECT afp.*, b.owner_id
                 FROM ammo_factory_production afp
                 JOIN buildings b ON b.id = afp.building_id
                 WHERE afp.building_id = ? FOR UPDATE",
                [$factory['building_id']]
            );
            
            if (!$lockedFactory || !$lockedFactory['is_producing']) {
                $this->db->commit();
                return;
            }
            
            // Újraszámoljuk a lock-olt adatokból (biztonságos)
            $lockedLastUpdate = new \DateTime($lockedFactory['last_production_update']);
            $lockedDiffMin = ($now->getTimestamp() - $lockedLastUpdate->getTimestamp()) / 60;
            
            if ($lockedDiffMin < 1) {
                $this->db->commit();
                return;
            }

            $mins = (int) floor($lockedDiffMin);
            
            // Termelt mennyiség számítása
            $produced = $mins * AmmoFactoryConfig::PRODUCTION_RATE_PER_MIN;
            $bonus = $mins * AmmoFactoryConfig::OWNER_BONUS_PER_MIN;

            // Cél limitelés
            $remainingTarget = $lockedFactory['production_target_qty'] - $lockedFactory['production_completed_qty'];
            if ($produced > $remainingTarget) {
                $produced = $remainingTarget;
                // Bónusz arányos csökkentése
                $ratio = $produced / ($mins * AmmoFactoryConfig::PRODUCTION_RATE_PER_MIN);
                $bonus = (int)($bonus * $ratio); 
            }

            // Napi limit kezelés
            $today = date('Y-m-d');
            if ($lockedFactory['last_daily_reset'] !== $today) {
                $this->db->executeStatement(
                    "UPDATE ammo_factory_production SET daily_production_count = 0, last_daily_reset = ? WHERE building_id = ?",
                    [$today, $lockedFactory['building_id']]
                );
                $lockedFactory['daily_production_count'] = 0;
            }

            $dailyRemaining = AmmoFactoryConfig::DAILY_LIMIT - $lockedFactory['daily_production_count'];
            if ($produced > $dailyRemaining) {
                $produced = $dailyRemaining;
                $bonus = (int)($bonus * ($produced / max(1, $mins * AmmoFactoryConfig::PRODUCTION_RATE_PER_MIN)));
            }

            if ($produced > 0) {
                // Készlet és haladás frissítése
                $this->db->executeStatement(
                    "UPDATE ammo_factory_production SET 
                        ammo_stock = ammo_stock + ?,
                        production_completed_qty = production_completed_qty + ?,
                        daily_production_count = daily_production_count + ?,
                        last_production_update = ?
                     WHERE building_id = ?",
                    [$produced, $produced, $produced, $now->format('Y-m-d H:i:s'), $lockedFactory['building_id']]
                );
                
                // [LEDGER] Tulajdonos bónusz töltény — ammo_factory típus
                if ($lockedFactory['owner_id'] && $bonus > 0) {
                    $this->bulletService->addBullets(
                        UserId::of((int)$lockedFactory['owner_id']),
                        $bonus,
                        'ammo_factory',
                        'Tölténygyár bónusz termelés',
                        'building', $lockedFactory['building_id']
                    );
                }
            }

            // [2026-02-15] FIX: Memóriában ellenőrizzük a befejezést (felesleges SELECT kiküszöbölve)
            $newCompleted = $lockedFactory['production_completed_qty'] + $produced;
            if ($newCompleted >= $lockedFactory['production_target_qty']) {
                // Termelés leállítása
                $this->db->executeStatement(
                    "UPDATE ammo_factory_production SET is_producing = 0, production_completed_qty = 0, production_target_qty = 0 WHERE building_id = ?",
                    [$lockedFactory['building_id']]
                );
            }
            
            $this->db->commit();
            
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
