<?php
declare(strict_types=1);

namespace Netmafia\Modules\Market\Domain;

use Doctrine\DBAL\Connection;
use Netmafia\Modules\Item\Domain\InventoryService;
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Modules\Credits\Domain\CreditService;
use Netmafia\Modules\AmmoFactory\Domain\BulletService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Shared\Domain\ValueObjects\Credits;
use Netmafia\Shared\Domain\RankCalculator;
use Netmafia\Modules\Market\MarketConfig;
use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;
use Netmafia\Infrastructure\CacheService;
use Netmafia\Infrastructure\AuditLogger;
use Netmafia\Modules\Notifications\Domain\NotificationService;
use Netmafia\Modules\Garage\Domain\VehicleRepository;

class MarketService
{
    private Connection $db;
    private InventoryService $inventoryService;
    private MoneyService $moneyService;
    private CreditService $creditService;
    private BulletService $bulletService;
    private CacheService $cache;
    private AuditLogger $auditLogger;
    private NotificationService $notificationService;
    private VehicleRepository $vehicleRepository;

    public function __construct(
        Connection $db,
        InventoryService $inventoryService,
        MoneyService $moneyService,
        CreditService $creditService,
        BulletService $bulletService,
        CacheService $cache,
        AuditLogger $auditLogger,
        NotificationService $notificationService,
        VehicleRepository $vehicleRepository
    ) {
        $this->db = $db;
        $this->inventoryService = $inventoryService;
        $this->moneyService = $moneyService;
        $this->creditService = $creditService;
        $this->bulletService = $bulletService;
        $this->cache = $cache;
        $this->auditLogger = $auditLogger;
        $this->notificationService = $notificationService;
        $this->vehicleRepository = $vehicleRepository;
    }

    /**
     * Eldönti, hogy a játékos elég magas rangú-e az eladáshoz
     */
    public function canUserSell(array $user): bool
    {
        if (!empty($user['is_admin'])) {
            return true;
        }
        $rankInfo = RankCalculator::getRankInfo((int)$user['xp']);
        return $rankInfo['index'] >= MarketConfig::MIN_RANK_INDEX_TO_SELL;
    }

    /**
     * Visszaadja a játékos eladható tárgyait egy adott kategóriában
     */
    public function getAvailableItems(int $userId, string $category): array
    {
        switch ($category) {
            case 'weapon':
                return $this->inventoryService->getStoredWeapons($userId);
            
            case 'armor':
                return $this->inventoryService->getStoredArmor($userId);
            
            case 'consumable':
                return $this->inventoryService->getStoredConsumables($userId);
            
            case 'vehicle':
                // Csak azokat a járműveket adhatja el, amiket nem használ (is_default = 0)
                return $this->db->fetchAllAssociative(
                    "SELECT uv.id, uv.vehicle_id, v.name, uv.country, uv.is_default
                     FROM user_vehicles uv
                     JOIN vehicles v ON v.id = uv.vehicle_id
                     WHERE uv.user_id = ? AND uv.is_default = 0
                     ORDER BY v.name",
                    [$userId]
                );
            case 'misc':
            case 'car_part':
                return []; // Jövőbeli fejlesztés

            case 'bullet':
                $balance = $this->db->fetchOne(
                    "SELECT bullets FROM users WHERE id = ?", [$userId]
                );
                return [['type' => 'bullet', 'name' => 'Töltény', 'available' => (int)$balance]];
            
            case 'credit':
                $balance = $this->db->fetchOne(
                    "SELECT credits FROM users WHERE id = ?", [$userId]
                );
                return [['type' => 'credit', 'name' => 'Kredit', 'available' => (int)$balance]];
            
            default:
                return [];
        }
    }

    /**
     * Szerveroldali biztonsági validáció az eladásra szánt cikkre
     * Visszaadja az item adatait és a maximális eladható mennyiséget
     */
    public function verifyItemFromDb(int $userId, string $category, ?int $itemId, int $requestedQty): array
    {
        switch ($category) {
            case 'weapon':
            case 'armor':
            case 'consumable':
                if ($itemId === null || $itemId <= 0) {
                    throw new InvalidInputException('Válassz ki egy tételt!');
                }

                $item = $this->db->fetchAssociative(
                    "SELECT ui.item_id, ui.quantity, i.name
                     FROM user_items ui
                     JOIN items i ON i.id = ui.item_id
                     WHERE ui.user_id = ? AND ui.item_id = ?",
                    [$userId, $itemId]
                );

                if (!$item) {
                    throw new GameException('Nincs ilyen tárgy a birtokodban!');
                }

                $maxQty = (int)$item['quantity'];
                if ($requestedQty > $maxQty) {
                    throw new GameException("Csak {$maxQty} db van belőle!");
                }
                
                return [
                    'name' => $item['name'],
                    'max_quantity' => $maxQty,
                ];

            case 'vehicle':
                if ($itemId === null || $itemId <= 0) {
                    throw new InvalidInputException('Válassz ki egy járművet!');
                }

                $vehicle = $this->db->fetchAssociative(
                    "SELECT uv.id, v.name
                     FROM user_vehicles uv
                     JOIN vehicles v ON v.id = uv.vehicle_id
                     WHERE uv.id = ? AND uv.user_id = ? AND uv.is_default = 0",
                    [$itemId, $userId]
                );

                if (!$vehicle) {
                    throw new GameException('Ez a jármű nem a tiéd, vagy éppen használod!');
                }

                if ($requestedQty !== 1) {
                    throw new GameException('Járműből egyszerre csak 1 darabot adhatsz el!');
                }

                return [
                    'name' => $vehicle['name'],
                    'max_quantity' => 1,
                ];
                
            case 'bullet':
                $__fetchResult = $this->db->fetchOne("SELECT bullets FROM users WHERE id = ?", [$userId]);
                if ($__fetchResult === false) {
                    throw new \Netmafia\Shared\Exceptions\GameException("A kért adat nem található az adatbázisban!");
                }
                $bullets = (int) $__fetchResult;
                if ($bullets < $requestedQty) {
                    throw new GameException("Nincs ennyi töltényed! Maximum {$bullets} db-ot adhatsz el.");
                }
                return [
                    'name' => 'Töltény',
                    'max_quantity' => $bullets,
                ];

            case 'credit':
                $__fetchResult = $this->db->fetchOne("SELECT credits FROM users WHERE id = ?", [$userId]);
                if ($__fetchResult === false) {
                    throw new \Netmafia\Shared\Exceptions\GameException("A kért adat nem található az adatbázisban!");
                }
                $credits = (int) $__fetchResult;
                if ($credits < $requestedQty) {
                    throw new GameException("Nincs ennyi kredited! Maximum {$credits} db-ot adhatsz el.");
                }
                return [
                    'name' => 'Kredit',
                    'max_quantity' => $credits,
                ];

            default:
                throw new InvalidInputException("Ez a kategória jelenleg nem eladható.");
        }
    }

    /**
     * Tárgy feltevése a piacra (Tranzakciós)
     */
    public function listItemOnMarket(int $userId, string $category, ?int $itemId, int $qty, int $price, string $currency): void
    {
        if ($qty <= 0) {
            throw new InvalidInputException('A mennyiségnek legalább 1-nek kell lennie!');
        }

        if ($price <= 0) {
            throw new InvalidInputException('Az eladási árnak nagyobbnak kell lennie nullánál!');
        }

        if ($price > MarketConfig::MAX_ITEM_PRICE) {
            throw new InvalidInputException('A maximális egységár $' . number_format(MarketConfig::MAX_ITEM_PRICE) . '!');
        }

        if (!in_array($currency, ['money', 'credit'])) {
            throw new InvalidInputException('Érvénytelen fizetőeszköz!');
        }

        $this->db->beginTransaction();

        try {
            // Először kivesszük/lefoglaljuk az eszközt a felhasználótól
            if ($category === 'bullet' || $category === 'credit') {
                // Konzisztencia zárolás FOR UPDATE-tel az egyenlegre
                $balanceColumn = ($category === 'bullet') ? 'bullets' : 'credits';
                $__fetchResult = $this->db->fetchOne("SELECT {$balanceColumn} FROM users WHERE id = ? FOR UPDATE", [$userId]);
                if ($__fetchResult === false) {
                    throw new \Netmafia\Shared\Exceptions\GameException("A kért adat nem található az adatbázisban!");
                }
                $currentBalance = (int) $__fetchResult;
                
                if ($currentBalance < $qty) {
                    throw new GameException("Közben elfogyott a feltenni kívánt mennyiség!");
                }

                // [LEDGER] Levonás service-en keresztül — így credit_transactions / bullet_transactions-ba kerül
                if ($category === 'credit') {
                    $this->creditService->spendCredits(
                        UserId::of($userId), Credits::of($qty),
                        "Kredit feltevése a piacra",
                        'market_list', null, 'market_escrow_out'
                    );
                } else {
                    $this->bulletService->useBullets(
                        UserId::of($userId), $qty, 'market_escrow_out',
                        "Töltény feltevése a piacra",
                        'market_list', null
                    );
                }
            }
            elseif ($category === 'vehicle') {
                // Jármű leválasztó: eltűnik az inventory-ból, de fizikailag megmarad NULL tulajjjal tranzit állapotban.
                $updated = $this->db->executeStatement(
                    "UPDATE user_vehicles SET user_id = NULL WHERE id = ? AND user_id = ? AND is_default = 0",
                    [$itemId, $userId]
                );
                if ($updated === 0) {
                    throw new GameException('Valami hiba történt a jármű piacra helyezésekor.');
                }
            }
            else {
                // Fegyver, Védelem, Elfogyasztható cucc
                $hasItem = $this->db->fetchOne("SELECT quantity FROM user_items WHERE user_id = ? AND item_id = ? FOR UPDATE", [$userId, $itemId]);
                if (!$hasItem || $hasItem < $qty) {
                    throw new GameException('Nincs ennyi a választott tárgyból!');
                }
                $this->inventoryService->removeItem($userId, $itemId, $qty);
            }

            // Rákerül a piacra (Összevonás kredit és töltény esetén, ha a megadott ár és valuta egyezik)
            $inserted = false;
            if (in_array($category, ['bullet', 'credit'])) {
                $existingId = $this->db->fetchOne("SELECT id FROM market_items WHERE seller_id = ? AND category = ? AND price = ? AND currency = ? FOR UPDATE", [$userId, $category, $price, $currency]);
                if ($existingId) {
                    $this->db->executeStatement("UPDATE market_items SET quantity = quantity + ? WHERE id = ?", [$qty, $existingId]);
                    $inserted = true;
                }
            }

            if (!$inserted) {
                $this->db->executeStatement("
                    INSERT INTO market_items (seller_id, category, item_id, quantity, price, currency, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ", [
                    $userId,
                    $category,
                    $itemId, // Nullable ha credit vagy bullet
                    $qty,
                    $price,
                    $currency
                ]);
            }

            // Cache törlés hogy a levont töltény/kredit felső sáv frissüljön
            $this->cache->forget("user:{$userId}");

            // Audit log
            $this->auditLogger->log(AuditLogger::TYPE_MARKET_LIST, $userId, [
                'category' => $category,
                'item_id'  => $itemId,
                'qty'      => $qty,
                'price'    => $price,
                'currency' => $currency,
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Tárgy vásárlása a piacról (Tranzakciós)
     */
    public function buyItem(int $buyerId, int $marketId, int $quantityToBuy): void
    {
        if ($quantityToBuy <= 0) {
            throw new InvalidInputException('Érvénytelen mennyiség!');
        }

        $this->db->beginTransaction();

        try {
            // 1. Lock market item
            $marketItem = $this->db->fetchAssociative("SELECT id, seller_id, category, item_id, quantity, price, currency FROM market_items WHERE id = ? FOR UPDATE", [$marketId]);
            if (!$marketItem) {
                throw new GameException('Ez az ajánlat már nem létezik.');
            }

            if ((int)$marketItem['seller_id'] === $buyerId) {
                throw new GameException('Nem veheted meg a saját tárgyadat!');
            }

            if ($marketItem['quantity'] < $quantityToBuy) {
                throw new GameException('Nincs ennyi a piacon ebből a tételből!');
            }

            $totalCost = $marketItem['price'] * $quantityToBuy;
            $currencyColumn = ($marketItem['currency'] === 'credit') ? 'credits' : 'money';

            // Item neve a log leíráshoz
            $itemName = $marketItem['category'];
            if ($marketItem['item_id']) {
                $fetchedName = $this->db->fetchOne("SELECT name FROM items WHERE id = ?", [$marketItem['item_id']]);
                if ($fetchedName) {
                    $itemName = $fetchedName;
                }
            }

            // 2. Deadlock-biztos lock sorrend: mindig a kisebb user ID-t lockoljuk először
            $sellerId = (int)$marketItem['seller_id'];
            $lockIds = [$buyerId, $sellerId];
            sort($lockIds);
            $balances = [];
            foreach ($lockIds as $lockId) {
                $__fetchResult = $this->db->fetchOne("SELECT {$currencyColumn} FROM users WHERE id = ? FOR UPDATE", [$lockId]);
                if ($__fetchResult === false) {
                    throw new GameException('Felhasználó nem található a pénz zárolásakor!');
                }
                $balances[$lockId] = (int) $__fetchResult;
            }

            $buyerBalance = $balances[$buyerId];
            if ($buyerBalance < $totalCost) {
                $currencyName = ($marketItem['currency'] === 'credit') ? 'kredited' : 'pénzed';
                throw new GameException("Nincs elég {$currencyName} a vásárláshoz!");
            }

            // 3. Levonás a vőtől, jóváírás az eladónak — [LEDGER] MoneyService/CreditService
            if ($marketItem['currency'] === 'credit') {
                $this->creditService->spendCredits(
                    UserId::of($buyerId), Credits::of($totalCost),
                    "Piaci vásárlás: {$quantityToBuy}x {$itemName}",
                    'market_item', $marketId
                );
                $this->creditService->addCredits(
                    UserId::of($sellerId), Credits::of($totalCost), 'transfer_in',
                    "Piaci eladás: {$quantityToBuy}x {$itemName}",
                    'market_item', $marketId
                );
            } else {
                $this->moneyService->spendMoney(
                    UserId::of($buyerId), $totalCost, 'purchase',
                    "Piaci vásárlás: {$quantityToBuy}x {$itemName}",
                    'market_item', $marketId
                );
                $this->moneyService->addMoney(
                    UserId::of($sellerId), $totalCost, 'sell',
                    "Piaci eladás: {$quantityToBuy}x {$itemName}",
                    'market_item', $marketId
                );
            }

            // 5. Tárgy transzfer
            $category = $marketItem['category'];
            if ($category === 'vehicle') {
                if ($quantityToBuy !== 1) {
                    throw new GameException('Járműből egyszerre csak 1 db vehető!');
                }
                
                // Get vehicle country before transfer to recalculate location
                $vehicleCountry = $this->db->fetchOne("SELECT country FROM user_vehicles WHERE id = ?", [$marketItem['item_id']]);
                
                if ($vehicleCountry) {
                    $vehicleCountryStr = (string)$vehicleCountry;
                    $capacity = $this->vehicleRepository->getGarageCapacity($buyerId, $vehicleCountryStr);
                    $currentCount = $this->vehicleRepository->countVehiclesInGarage($buyerId, $vehicleCountryStr);
                    
                    if ($currentCount >= $capacity) {
                        throw new GameException('Nincs elég hely a garázsodban (' . $vehicleCountryStr . ') az új járműnek!');
                    }
                }
                
                $this->db->executeStatement("UPDATE user_vehicles SET user_id = ? WHERE id = ?", [$buyerId, $marketItem['item_id']]);
                
                // [FIX] 2026-02-28: Újraszámoljuk a helyzetet az új tulajdonosnál
                if ($vehicleCountry) {
                    $this->vehicleRepository->recalculateVehicleLocations($buyerId, (string)$vehicleCountry);
                }
            }
            elseif ($category === 'bullet' || $category === 'credit') {
                if ($category === 'bullet') {
                    // [LEDGER] Töltény vásárlás — BulletService
                    $this->bulletService->addBullets(
                        UserId::of($buyerId), $quantityToBuy,
                        'market_escrow_in', "Piaci töltény megkapása (letét feloldva): {$quantityToBuy} db",
                        'market_item', $marketId
                    );
                } else {
                    // [LEDGER] Kredit vásárlás — CreditService
                    $this->creditService->addCredits(
                        UserId::of($buyerId), Credits::of($quantityToBuy), 'market_escrow_in',
                        "Piaci kredit megkapása (letét feloldva): {$quantityToBuy} kredit",
                        'market_item', $marketId
                    );
                }
            }
            else {
                // weapon, armor, consumable, misc, car_part
                $this->inventoryService->addItem($buyerId, $marketItem['item_id'], $quantityToBuy);
            }

            // 6. Market tétel frissítése vagy törlése
            if ($marketItem['quantity'] == $quantityToBuy) {
                $this->db->executeStatement("DELETE FROM market_items WHERE id = ?", [$marketId]);
            } else {
                $this->db->executeStatement("UPDATE market_items SET quantity = quantity - ? WHERE id = ?", [$quantityToBuy, $marketId]);
            }

            // 7. History naplózás
            $itemName = 'Ismeretlen';
            if (in_array($category, ['weapon', 'armor', 'consumable'])) {
                $itemName = $this->db->fetchOne("SELECT name FROM items WHERE id = ?", [$marketItem['item_id']]) ?: 'Tárgy';
            } elseif ($category === 'vehicle') {
                $itemName = $this->db->fetchOne("SELECT v.name FROM vehicles v JOIN user_vehicles uv ON v.id = uv.vehicle_id WHERE uv.id = ?", [$marketItem['item_id']]) ?: 'Jármű';
            } elseif ($category === 'bullet') {
                $itemName = 'Töltény';
            } elseif ($category === 'credit') {
                $itemName = 'Kredit';
            }

            $this->db->executeStatement("
                INSERT INTO market_history (seller_id, buyer_id, category, item_id, item_name, quantity, price, currency, sold_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ", [
                $marketItem['seller_id'],
                $buyerId,
                $category,
                $marketItem['item_id'],
                $itemName,
                $quantityToBuy,
                $marketItem['price'],
                $marketItem['currency']
            ]);

            // 8. Cache törlés
            $this->cache->forget("user:{$buyerId}");
            $this->cache->forget("user:{$marketItem['seller_id']}");

            // Audit log
            $this->auditLogger->log(AuditLogger::TYPE_MARKET_BUY, $buyerId, [
                'market_id' => $marketId,
                'seller_id' => $sellerId,
                'category'  => $category,
                'item'      => $itemName,
                'qty'       => $quantityToBuy,
                'total'     => $totalCost,
                'currency'  => $marketItem['currency'],
            ]);

            // 9. Értesítés küldése az eladónak (2026-02-28)
            $priceText = number_format((float)$totalCost, 0, '.', ' ') . ' ' . ($marketItem['currency'] === 'credit' ? 'kredit' : 'dollár');
            $this->notificationService->send(
                (int)$marketItem['seller_id'],
                'market_sale',
                "Sikeresen eladtál {$quantityToBuy} db {$itemName} terméket {$priceText} értékben.",
                'market',
                '/piac/eladasaim'
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
     * Aggregált piaci lista az "Új áru" tabon való megjelenítéshez (Eladó Fegyverek stb...)
     */
    public function getMarketGroupedListings(string $category): array
    {
        if (in_array($category, ['weapon', 'armor', 'consumable'])) {
            // Tárgyak csoportosítása
            return $this->db->fetchAllAssociative("
                SELECT 
                    m.item_id,
                    i.name as item_name, 
                    i.attack as attack, 
                    i.defense as defense, 
                    i.description as description,
                    SUM(m.quantity) as total_quantity,
                    COUNT(DISTINCT m.seller_id) as seller_count,
                    MIN(m.price) as lowest_price
                FROM market_items m
                JOIN items i ON m.item_id = i.id
                WHERE m.category = ?
                GROUP BY m.item_id
                ORDER BY i.name ASC
            ", [$category]);
        }
        
        if ($category === 'vehicle') {
            return $this->db->fetchAllAssociative("
                SELECT 
                    v.id as item_id,
                    v.name as item_name,
                    v.category as sub_category,
                    v.speed as speed,
                    v.safety as safety,
                    v.origin_country as origin_country,
                    SUM(m.quantity) as total_quantity,
                    COUNT(DISTINCT m.seller_id) as seller_count,
                    MIN(m.price) as lowest_price
                FROM market_items m
                JOIN user_vehicles uv ON m.item_id = uv.id
                JOIN vehicles v ON uv.vehicle_id = v.id
                WHERE m.category = ?
                GROUP BY v.id
                ORDER BY v.name ASC
            ", [$category]);
        }

        if (in_array($category, ['bullet', 'credit'])) {
            return $this->db->fetchAllAssociative("
                SELECT m.id as market_id, m.price, m.currency, m.quantity, u.username as seller_name
                FROM market_items m
                JOIN users u ON m.seller_id = u.id
                WHERE m.category = ?
                ORDER BY m.price ASC
            ", [$category]);
        }

        return [];
    }

    /**
     * Lekéri az eladókat egy adott kategórián és (opcionálisan) tárgyon belül
     */
    public function getMarketSellers(string $category, string $itemId): array
    {
        if (in_array($category, ['weapon', 'armor', 'consumable'])) {
            return $this->db->fetchAllAssociative("
                SELECT m.id as market_id, m.price, m.currency, m.quantity, u.username as seller_name
                FROM market_items m
                JOIN users u ON m.seller_id = u.id
                WHERE m.category = ? AND m.item_id = ?
                ORDER BY m.price ASC
            ", [$category, $itemId]);
        }

        if ($category === 'vehicle') {
            $sellers = $this->db->fetchAllAssociative("
                SELECT m.id as market_id, m.price, m.currency, m.quantity, u.username as seller_name,
                       v.speed as base_speed, v.safety as base_safety,
                       uv.tuning_engine, uv.tuning_tires, uv.tuning_exhaust, uv.tuning_brakes,
                       uv.tuning_nitros, uv.tuning_body, uv.tuning_shocks, uv.tuning_wheels,
                       uv.has_bulletproof_glass, uv.has_steel_body, uv.has_runflat_tires, uv.has_explosion_proof_tank
                FROM market_items m
                JOIN user_vehicles uv ON m.item_id = uv.id
                JOIN vehicles v ON uv.vehicle_id = v.id
                JOIN users u ON m.seller_id = u.id
                WHERE m.category = ? AND uv.vehicle_id = ?
                ORDER BY m.price ASC
            ", [$category, $itemId]);

            foreach ($sellers as &$seller) {
                $baseSpeed = (int)$seller['base_speed'];
                $baseSafety = (int)$seller['base_safety'];
                $speedMult = 1.0 + ((int)$seller['tuning_engine'] * 0.05) + ((int)$seller['tuning_exhaust'] * 0.05) + ((int)$seller['tuning_nitros'] * 0.05);
                $safetyMult = 1.0 + ((int)$seller['tuning_tires'] * 0.05) + ((int)$seller['tuning_brakes'] * 0.05) + ((int)$seller['tuning_body'] * 0.05);
                
                $mixedParts = (int)$seller['tuning_shocks'] + (int)$seller['tuning_wheels'];
                $speedMult += ($mixedParts * 0.02);
                $safetyMult += ($mixedParts * 0.02);
                
                if (!empty($seller['has_bulletproof_glass'])) $safetyMult += 0.02;
                if (!empty($seller['has_steel_body'])) $safetyMult += 0.02;
                if (!empty($seller['has_runflat_tires'])) $safetyMult += 0.02;
                if (!empty($seller['has_explosion_proof_tank'])) $safetyMult += 0.02;
                
                $seller['effective_speed'] = (int)($baseSpeed * $speedMult);
                $seller['effective_safety'] = (int)($baseSafety * $safetyMult);
            }
            unset($seller);

            return $sellers;
        }

        if (in_array($category, ['bullet', 'credit'])) {
            return $this->db->fetchAllAssociative("
                SELECT m.id as market_id, m.price, m.currency, m.quantity, u.username as seller_name
                FROM market_items m
                JOIN users u ON m.seller_id = u.id
                WHERE m.category = ?
                ORDER BY m.price ASC
            ", [$category]);
        }

        return [];
    }

    /**
     * Felhasználó saját aktív eladásainak lekérdezése
     */
    public function getUserActiveListings(int $userId): array
    {
        // N+1 mentes, JOIN-os lekérdezés (SELECT * kijavítva explicit mezőlistára)
        return $this->db->fetchAllAssociative("
            SELECT m.id, m.category, m.item_id, m.quantity, m.price, m.currency,
                   COALESCE(i.name, v2.name,
                       CASE m.category WHEN 'bullet' THEN 'Töltény' WHEN 'credit' THEN 'Kredit' ELSE 'Ismeretlen' END
                   ) as item_name
            FROM market_items m
            LEFT JOIN items i ON m.item_id = i.id AND m.category IN ('weapon','armor','consumable')
            LEFT JOIN user_vehicles uv ON m.item_id = uv.id AND m.category = 'vehicle'
            LEFT JOIN vehicles v2 ON uv.vehicle_id = v2.id
            WHERE m.seller_id = ?
            ORDER BY m.id DESC
        ", [$userId]);
    }

    /**
     * Felhasználó utolsó eladásai
     */
    public function getUserRecentSales(int $userId, int $limit = 30): array
    {
        return $this->db->fetchAllAssociative("
            SELECT mh.id, mh.category, mh.item_name, mh.quantity, mh.price, mh.currency, mh.sold_at,
                   u.username as other_party
            FROM market_history mh 
            JOIN users u ON mh.buyer_id = u.id 
            WHERE mh.seller_id = ? 
            ORDER BY mh.sold_at DESC 
            LIMIT ?
        ", [$userId, $limit], [\PDO::PARAM_INT, \PDO::PARAM_INT]);
    }

    /**
     * Felhasználó utolsó vásárlásai
     */
    public function getUserRecentPurchases(int $userId, int $limit = 30): array
    {
        return $this->db->fetchAllAssociative("
            SELECT mh.id, mh.category, mh.item_name, mh.quantity, mh.price, mh.currency, mh.sold_at,
                   u.username as other_party
            FROM market_history mh 
            JOIN users u ON mh.seller_id = u.id 
            WHERE mh.buyer_id = ? 
            ORDER BY mh.sold_at DESC 
            LIMIT ?
        ", [$userId, $limit], [\PDO::PARAM_INT, \PDO::PARAM_INT]);
    }

    /**
     * Piacon lévő tárgy visszavonása (tulajdonosnak visszakerül)
     */
    public function revokeListing(int $userId, int $marketId): void
    {
        $this->db->beginTransaction();
        try {
            $marketItem = $this->db->fetchAssociative("SELECT id, seller_id, category, item_id, quantity FROM market_items WHERE id = ? FOR UPDATE", [$marketId]);
            
            if (!$marketItem) {
                throw new GameException('Ez az ajánlat nem létezik.');
            }
            if ((int)$marketItem['seller_id'] !== $userId) {
                throw new GameException('Ezt a tárgyat nem te árulod!');
            }

            $category = $marketItem['category'];
            $qty = $marketItem['quantity'];

            if ($category === 'vehicle') {
                $vehicleCountry = $this->db->fetchOne("SELECT country FROM user_vehicles WHERE id = ?", [$marketItem['item_id']]);
                $this->db->executeStatement("UPDATE user_vehicles SET user_id = ? WHERE id = ?", [$userId, $marketItem['item_id']]);
                
                // [FIX] 2026-02-28: Újraszámoljuk a helyzetet visszavonáskor is
                if ($vehicleCountry) {
                    $this->vehicleRepository->recalculateVehicleLocations($userId, (string)$vehicleCountry);
                }
            } elseif ($category === 'bullet' || $category === 'credit') {
                // [LEDGER] Visszavonáskor service-en keresztül adjuk vissza — így benne lesz a ledger táblában
                if ($category === 'credit') {
                    $this->creditService->addCredits(
                        UserId::of($userId), Credits::of($qty), 'market_escrow_in',
                        'Piaci kredit visszavonás (letét feloldva)',
                        'market_cancel', $marketId
                    );
                } else {
                    $this->bulletService->addBullets(
                        UserId::of($userId), $qty, 'market_escrow_in',
                        'Piaci töltény visszavonás (letét feloldva)',
                        'market_cancel', $marketId
                    );
                }
            } else {
                $this->inventoryService->addItem($userId, $marketItem['item_id'], $qty);
            }

            $this->db->executeStatement("DELETE FROM market_items WHERE id = ?", [$marketId]);

            $this->cache->forget("user:{$userId}");

            // Audit log
            $this->auditLogger->log(AuditLogger::TYPE_MARKET_REVOKE, $userId, [
                'market_id' => $marketId,
                'category'  => $category,
                'item_id'   => $marketItem['item_id'],
                'qty'       => $qty,
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
