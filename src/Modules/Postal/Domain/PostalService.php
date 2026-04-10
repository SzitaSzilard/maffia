<?php
declare(strict_types=1);

namespace Netmafia\Modules\Postal\Domain;

use Doctrine\DBAL\Connection;
use Netmafia\Modules\Item\Domain\InventoryService;
use Netmafia\Modules\AmmoFactory\Domain\BulletService;
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Modules\Credits\Domain\CreditService;
use Netmafia\Modules\Buildings\Domain\BuildingService;
use Netmafia\Modules\Buildings\BuildingConfig;
use Netmafia\Infrastructure\CacheService;
use Netmafia\Infrastructure\AuditLogger;
use Netmafia\Modules\Postal\PostalConfig;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Shared\Domain\ValueObjects\Credits;
use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

/**
 * PostalService - Csomag összeállítás és küldés
 * 
 * Meglévő service-eket használ minden átutaláshoz:
 * - MoneyService: pénz küldés + díj
 * - CreditService: kredit küldés
 * - InventoryService: tárgy add/remove
 * - BuildingService: épület ownership
 */
class PostalService
{
    private Connection $db;
    private MoneyService $moneyService;
    private CreditService $creditService;
    private InventoryService $inventoryService;
    private BuildingService $buildingService;
    private CacheService $cache;
    private BulletService $bulletService;

    public function __construct(
        Connection $db,
        MoneyService $moneyService,
        CreditService $creditService,
        InventoryService $inventoryService,
        BuildingService $buildingService,
        CacheService $cache,
        BulletService $bulletService
    ) {
        $this->db = $db;
        $this->moneyService = $moneyService;
        $this->creditService = $creditService;
        $this->inventoryService = $inventoryService;
        $this->buildingService = $buildingService;
        $this->cache = $cache;
        $this->bulletService = $bulletService;
    }

    /**
     * Felhasználó elérhető tárgyai adott kategóriában
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
                return $this->db->fetchAllAssociative(
                    "SELECT uv.id, uv.vehicle_id, v.name, uv.country, uv.is_default
                     FROM user_vehicles uv
                     JOIN vehicles v ON v.id = uv.vehicle_id
                     WHERE uv.user_id = ? AND uv.is_default = 0
                     ORDER BY v.name",
                    [$userId]
                );
            
            case 'money':
                $balance = $this->db->fetchOne(
                    "SELECT money FROM users WHERE id = ?", [$userId]
                );
                return [['type' => 'money', 'name' => 'Pénz', 'available' => (int)$balance]];
            
            case 'credits':
                $balance = $this->db->fetchOne(
                    "SELECT credits FROM users WHERE id = ?", [$userId]
                );
                return [['type' => 'credits', 'name' => 'Kredit', 'available' => (int)$balance]];
            
            case 'bullets':
                $balance = $this->db->fetchOne(
                    "SELECT bullets FROM users WHERE id = ?", [$userId]
                );
                return [['type' => 'bullets', 'name' => 'Töltény', 'available' => (int)$balance]];
            
            case 'building':
                return $this->buildingService->getByOwner($userId);
            
            default:
                return [];
        }
    }

    /**
     * Szerveroldali tétel ellenőrzés — NEM bízunk a form-ból jövő névben/árban!
     * 
     * Visszaadja a DB-ből kapott valós nevet, árat, és max mennyiséget.
     * Csalás elleni védelem: a kliens bármit küldhet, mi felülírjuk.
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
                    "SELECT ui.item_id, ui.quantity, i.name, i.price
                     FROM user_items ui
                     JOIN items i ON i.id = ui.item_id
                     WHERE ui.user_id = ? AND ui.item_id = ?",
                    [$userId, $itemId]
                );
                if (!$item) {
                    throw new GameException('Ez a tárgy nem a tiéd!');
                }
                $maxQty = (int)$item['quantity'];
                if ($requestedQty > $maxQty) {
                    throw new GameException("Csak {$maxQty} db van belőle!");
                }
                return [
                    'name' => $item['name'],
                    'unit_price' => (int)$item['price'],
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
                    throw new GameException('Ez a jármű nem a tiéd, vagy aktív használatban van!');
                }
                return [
                    'name' => $vehicle['name'],
                    'unit_price' => PostalConfig::VEHICLE_FEE,
                    'max_quantity' => 1,
                ];

            case 'building':
                if ($itemId === null || $itemId <= 0) {
                    throw new InvalidInputException('Válassz ki egy épületet!');
                }
                $building = $this->db->fetchAssociative(
                    "SELECT id, name_hu as name, country_code FROM buildings WHERE id = ? AND owner_id = ?",
                    [$itemId, $userId]
                );
                if (!$building) {
                    throw new GameException('Ez az épület nem a tiéd!');
                }
                return [
                    'name' => $building['name'] . ' (' . $building['country_code'] . ')',
                    'unit_price' => PostalConfig::BUILDING_FEE,
                    'max_quantity' => 1,
                ];

            case 'money':
                $__fetchResult = $this->db->fetchOne("SELECT money FROM users WHERE id = ?", [$userId]);
                if ($__fetchResult === false) {
                    throw new \Netmafia\Shared\Exceptions\GameException("A kért adat nem található az adatbázisban!");
                }
                $balance = (int) $__fetchResult;
                if ($requestedQty > $balance) {
                    throw new GameException("Csak \${$balance} pénzed van!");
                }
                if ($requestedQty <= 0) {
                    throw new InvalidInputException('Érvénytelen összeg!');
                }
                return [
                    'name' => 'Pénz',
                    'unit_price' => 0,
                    'max_quantity' => $balance,
                ];

            case 'credits':
                $__fetchResult = $this->db->fetchOne("SELECT credits FROM users WHERE id = ?", [$userId]);
                if ($__fetchResult === false) {
                    throw new \Netmafia\Shared\Exceptions\GameException("A kért adat nem található az adatbázisban!");
                }
                $balance = (int) $__fetchResult;
                if ($requestedQty > $balance) {
                    throw new GameException("Csak {$balance} kredited van!");
                }
                if ($requestedQty <= 0) {
                    throw new InvalidInputException('Érvénytelen összeg!');
                }
                return [
                    'name' => 'Kredit',
                    'unit_price' => 0,
                    'max_quantity' => $balance,
                ];

            case 'bullets':
                $__fetchResult = $this->db->fetchOne("SELECT bullets FROM users WHERE id = ?", [$userId]);
                if ($__fetchResult === false) {
                    throw new \Netmafia\Shared\Exceptions\GameException("A kért adat nem található az adatbázisban!");
                }
                $balance = (int) $__fetchResult;
                if ($requestedQty > $balance) {
                    throw new GameException("Csak {$balance} töltényed van!");
                }
                if ($requestedQty <= 0) {
                    throw new InvalidInputException('Érvénytelen mennyiség!');
                }
                return [
                    'name' => 'Töltény',
                    'unit_price' => 0,
                    'max_quantity' => $balance,
                ];

            default:
                throw new InvalidInputException('Ismeretlen kategória: ' . $category);
        }
    }

    /**
     * Küldési díj kalkuláció
     * 
     * - Tárgyak: bolti ár × darabszám
     * - Pénz/kredit/töltény: összeg × FEE_PERCENT%
     * - Jármű: vételár × VEHICLE_FEE_PERCENT%
     * - Épület: fix BUILDING_FEE
     * - Max: MAX_SHIPPING_COST
     */
    public function calculateShippingCost(array $cartItems): int
    {
        $totalCost = 0;

        foreach ($cartItems as $item) {
            switch ($item['category']) {
                case 'weapon':
                case 'armor':
                case 'consumable':
                    $totalCost += ($item['unit_price'] ?? 0) * ($item['quantity'] ?? 1);
                    break;
                    
                case 'money':
                    $totalCost += (int)(($item['quantity'] ?? 0) * PostalConfig::MONEY_FEE_PERCENT / 100);
                    break;
                    
                case 'credits':
                    $totalCost += (int)(($item['quantity'] ?? 0) * PostalConfig::CREDIT_FEE_PERCENT / 100);
                    break;
                    
                case 'bullets':
                    $totalCost += (int)(($item['quantity'] ?? 0) * PostalConfig::BULLET_FEE_PERCENT / 100);
                    break;
                    
                case 'vehicle':
                    $totalCost += (int)(($item['unit_price'] ?? 0) * PostalConfig::VEHICLE_FEE_PERCENT / 100);
                    break;
                    
                case 'building':
                    $totalCost += PostalConfig::BUILDING_FEE;
                    break;
            }
        }

        // Max cap
        return min($totalCost, PostalConfig::MAX_SHIPPING_COST);
    }

    /**
     * Címzett validálás
     */
    public function validateRecipient(string $username, int $senderId): array
    {
        $recipient = $this->db->fetchAssociative(
            "SELECT id, username, xp, is_admin, is_banned FROM users WHERE username = ?",
            [trim($username)]
        );

        if (!$recipient) {
            throw new InvalidInputException('Nincs ilyen nevű felhasználó!');
        }

        if ((int)$recipient['id'] === $senderId) {
            throw new InvalidInputException('Nem küldhetsz csomagot saját magadnak!');
        }

        if ($recipient['is_banned']) {
            throw new InvalidInputException('Ez a felhasználó ki van tiltva!');
        }

        return $recipient;
    }

    /**
     * Csomag küldés — teljes tranzakcióban
     * 
     * FOR UPDATE lockolással biztosítjuk, hogy dupla kattintás/két tab
     * ne okozzon mínusz egyenleget.
     * 
     * A tételek a feladótól AZONNAL levonódnak, de a címzett
     * csak DELIVERY_MINUTES perc után kapja meg (in_transit).
     */
    public function sendPackage(int $senderId, string $recipientUsername, array $cartItems): void
    {
        if (empty($cartItems)) {
            throw new InvalidInputException('A csomag üres!');
        }

        if (count($cartItems) > PostalConfig::MAX_ITEMS_PER_PACKAGE) {
            throw new InvalidInputException('Maximum ' . PostalConfig::MAX_ITEMS_PER_PACKAGE . ' tétel küldhető!');
        }

        // Címzett validálás
        $recipient = $this->validateRecipient($recipientUsername, $senderId);
        $recipientId = (int)$recipient['id'];
        $recipientXp = (int)$recipient['xp'];
        $isRecipientAdmin = (bool)$recipient['is_admin'];

        // Épület birtoklás rank check (Legenda)
        $hasBuilding = false;
        foreach ($cartItems as $item) {
            if ($item['category'] === 'building') {
                $hasBuilding = true;
                break;
            }
        }

        if ($hasBuilding && !$isRecipientAdmin && $recipientXp < BuildingConfig::MIN_XP_FOR_OWNERSHIP) {
            throw new GameException('A címzett nem érte el a Legenda rangot, ezért nem birtokolhat épületet!');
        }

        // Küldési díj kiszámítása
        $shippingCost = $this->calculateShippingCost($cartItems);

        // Kézbesítési idő
        $deliveryAt = date('Y-m-d H:i:s', time() + PostalConfig::DELIVERY_MINUTES * 60);

        $this->db->beginTransaction();
        try {
            $senderUserId = UserId::of($senderId);

            // 1. Küldési díj levonása
            if ($shippingCost > 0) {
                $this->moneyService->spendMoney(
                    $senderUserId,
                    $shippingCost,
                    'spend',
                    "Postai küldési díj ({$recipientUsername} részére)"
                );
            }

            // 2. Tételek LEVONÁSA feladótól (azonnal!)
            foreach ($cartItems as $item) {
                $this->removeFromSender($senderUserId, $item);
            }

            // 3. Csomag tárolása (in_transit — még nem kézbesítve)
            $this->db->insert('postal_packages', [
                'sender_id' => $senderId,
                'recipient_id' => $recipientId,
                'total_cost' => $shippingCost,
                'status' => 'in_transit',
                'delivery_at' => $deliveryAt,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $packageId = (int)$this->db->lastInsertId();

            // 4. Tételek logolása
            foreach ($cartItems as $item) {
                $this->db->insert('postal_package_items', [
                    'package_id' => $packageId,
                    'item_type' => $item['category'],
                    'item_id' => $item['item_id'] ?? null,
                    'item_name' => $item['name'],
                    'quantity' => $item['quantity'] ?? 1,
                    'unit_value' => $item['unit_price'] ?? 0,
                ]);
            }

            // 5. Értesítés #1: "Csomag úton van"
            $senderName = $this->db->fetchOne(
                "SELECT username FROM users WHERE id = ?", [$senderId]
            );
            $itemCount = count($cartItems);
            $this->db->insert('notifications', [
                'user_id' => $recipientId,
                'type' => 'postal',
                'message' => "Csomag érkezik {$senderName} feladótól ({$itemCount} tétel) — " . PostalConfig::DELIVERY_MINUTES . " perc múlva átveheted a postán!",
                'is_read' => 0,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            
            $this->cache->forget("unread_notifications:{$recipientId}");
            $this->cache->forget("pending_postal_data:{$senderId}");
            $this->cache->forget("pending_postal_data:{$recipientId}");

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Tételt levon a feladótól (küldéskor azonnal)
     */
    private function removeFromSender(UserId $senderId, array $item): void
    {
        $category = $item['category'];
        $quantity = (int)($item['quantity'] ?? 1);

        switch ($category) {
            case 'weapon':
            case 'armor':
            case 'consumable':
                $this->inventoryService->removeItem($senderId->id(), (int)$item['item_id'], $quantity);
                break;

            case 'vehicle':
                $vehicleId = (int)$item['item_id'];
                $vehicle = $this->db->fetchAssociative(
                    "SELECT id, user_id FROM user_vehicles WHERE id = ? AND user_id = ? FOR UPDATE",
                    [$vehicleId, $senderId->id()]
                );
                if (!$vehicle) {
                    throw new GameException('Ez a jármű nem a tiéd!');
                }
                // Jármű "transit" állapotba: user_id nullra (senki nem használhatja)
                $this->db->executeStatement(
                    "UPDATE user_vehicles SET user_id = NULL WHERE id = ?",
                    [$vehicleId]
                );
                break;

            case 'money':
                $this->moneyService->spendMoney(
                    $senderId,
                    $quantity,
                    'spend',
                    'Pénz küldés postán'
                );
                break;

            case 'credits':
                $this->creditService->spendCredits(
                    $senderId,
                    Credits::of($quantity),
                    'Kredit küldés postán'
                );
                break;

            case 'bullets':
                // [LEDGER] BulletService kezeli a levonást (balance_before/after log)
                $this->bulletService->useBullets(
                    $senderId,
                    $quantity,
                    'postal_send',
                    'Töltény küldés postán'
                );
                break;

            case 'building':
                $buildingId = (int)$item['item_id'];
                $building = $this->db->fetchAssociative(
                    "SELECT id, owner_id FROM buildings WHERE id = ? AND owner_id = ? FOR UPDATE",
                    [$buildingId, $senderId->id()]
                );
                if (!$building) {
                    throw new GameException('Ez az épület nem a tiéd!');
                }
                // Transit: owner_id = NULL (senki nem tulajdonolja amíg útban van)
                $this->db->executeStatement(
                    "UPDATE buildings SET owner_id = NULL WHERE id = ?",
                    [$buildingId]
                );
                break;

            default:
                throw new InvalidInputException('Ismeretlen kategória: ' . $category);
        }
    }

    /**
     * Kézbesíthető csomagok átadása — hívd meg amikor a címzett belép a postára
     * 
     * Megvizsgálja: van-e in_transit csomag ahol delivery_at <= NOW() ?
     * Ha igen: átadja a tételeket és értesítést küld.
     */
    public function deliverPendingPackages(int $recipientId): array
    {
        $deliveredPackages = [];

        $packages = $this->db->fetchAllAssociative(
            "SELECT id, sender_id, total_cost, delivery_at 
             FROM postal_packages 
             WHERE recipient_id = ? AND status = 'in_transit' AND delivery_at <= NOW() FOR UPDATE SKIP LOCKED",
            [$recipientId]
        );

        if (empty($packages)) {
            return [];
        }

        $recipientUserId = UserId::of($recipientId);

        foreach ($packages as $package) {
            $this->db->beginTransaction();
            try {
                $items = $this->db->fetchAllAssociative(
                    "SELECT item_type, item_id, item_name, quantity, unit_value
                     FROM postal_package_items WHERE package_id = ?",
                    [$package['id']]
                );

                // Tételek átadása címzettnek
                foreach ($items as $item) {
                    $this->deliverItemToRecipient($recipientUserId, $item);
                }

                // Státusz frissítés ellenőrzéssel (ha időközben már feldolgozták)
                $updatedRows = $this->db->executeStatement(
                    "UPDATE postal_packages SET status = 'delivered' WHERE id = ? AND status = 'in_transit'",
                    [$package['id']]
                );
                
                if ($updatedRows === 0) {
                    throw new GameException('Ezt a csomagot már kézbesítették.');
                }

                // Értesítés #2: "Csomag megérkezett" + tétel lista
                $senderName = $this->db->fetchOne(
                    "SELECT username FROM users WHERE id = ?", [$package['sender_id']]
                );
                $itemList = implode(', ', array_map(function($i) {
                    return $i['quantity'] . ' db ' . $i['item_name'];
                }, $items));

                $this->db->insert('notifications', [
                    'user_id' => $recipientId,
                    'type' => 'postal',
                    'message' => "Csomag megérkezett {$senderName} feladótól! Tartalom: {$itemList}",
                    'is_read' => 0,
                    'created_at' => gmdate('Y-m-d H:i:s'),
                ]);
                
                $this->cache->forget("unread_notifications:{$recipientId}");
                $this->cache->forget("pending_postal_data:{$recipientId}");

                $this->db->commit();
                $deliveredPackages[] = [
                    'sender_name' => $senderName,
                    'items' => $items,
                ];
            } catch (\Throwable $e) {
                if ($this->db->isTransactionActive()) {
                    $this->db->rollBack();
                }
                // Log de ne blokkold a többi csomag kézbesítését
            }
        }

        return $deliveredPackages;
    }

    /**
     * Egyetlen tétel kézbesítése a címzettnek
     */
    private function deliverItemToRecipient(UserId $recipientId, array $item, bool $isCancel = false): void
    {
        $type = $item['item_type'];
        $quantity = (int)$item['quantity'];
        
        $moneyLog = $isCancel ? 'Postai csomag visszavonása (tárgyak visszatérítése)' : 'Postai pénz átvétel';
        $creditLog = $isCancel ? 'Postai csomag visszavonása (tárgyak visszatérítése)' : 'Postai kredit átvétel';

        switch ($type) {
            case 'weapon':
            case 'armor':
            case 'consumable':
                $this->inventoryService->addItem($recipientId->id(), (int)$item['item_id'], $quantity);
                break;

            case 'vehicle':
                $this->db->executeStatement(
                    "UPDATE user_vehicles SET user_id = ? WHERE id = ? AND user_id IS NULL",
                    [$recipientId->id(), (int)$item['item_id']]
                );
                break;

            case 'money':
                $transactionType = $isCancel ? 'refund' : 'transfer_in';
                $this->moneyService->addMoney(
                    $recipientId,
                    $quantity,
                    $transactionType,
                    $moneyLog
                );
                break;

            case 'credits':
                $this->creditService->addCredits(
                    $recipientId,
                    Credits::of($quantity),
                    'transfer_in',
                    $creditLog
                );
                break;

            case 'bullets':
                // [LEDGER] BulletService kezeli a jóváírást (balance_before/after log)
                $bulletType = $isCancel ? 'refund' : 'postal_receive';
                $bulletDesc = $isCancel ? 'Posta visszavonás — töltény visszatérítés' : 'Töltény átvétel postán';
                $this->bulletService->addBullets(
                    $recipientId,
                    $quantity,
                    $bulletType,
                    $bulletDesc
                );
                break;

            case 'building':
                $this->buildingService->assignOwner((int)$item['item_id'], $recipientId->id());
                break;
        }
    }

    /**
     * Várakozó (in_transit) csomagok a felhasználónak — UI-hoz (buborék, countdown)
     */
    public function getPendingPackages(int $userId): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT pp.id, pp.sender_id, pp.delivery_at, pp.created_at,
                    u.username as sender_name,
                    TIMESTAMPDIFF(SECOND, NOW(), pp.delivery_at) as seconds_remaining
             FROM postal_packages pp
             JOIN users u ON u.id = pp.sender_id
             WHERE pp.recipient_id = ? AND pp.status = 'in_transit'
             ORDER BY pp.delivery_at ASC",
            [$userId]
        );
    }

    /**
     * Feladó által elküldött, még folyamatban lévő csomagok
     */
    public function getSentPackages(int $userId): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT pp.id, pp.recipient_id, pp.delivery_at, pp.created_at,
                    u.username as recipient_name,
                    TIMESTAMPDIFF(SECOND, NOW(), pp.delivery_at) as seconds_remaining,
                    TIMESTAMPDIFF(SECOND, pp.created_at, NOW()) as seconds_elapsed
             FROM postal_packages pp
             JOIN users u ON u.id = pp.recipient_id
             WHERE pp.sender_id = ? AND pp.status = 'in_transit'
             ORDER BY pp.created_at DESC",
            [$userId]
        );
    }

    /**
     * Csomag visszavonása feladó által (első 10 percben)
     */
    public function cancelPackage(int $userId, int $packageId): void
    {
        $this->db->beginTransaction();
        try {
            $package = $this->db->fetchAssociative(
                "SELECT id, sender_id, recipient_id, created_at, status, total_cost 
                 FROM postal_packages 
                 WHERE id = ? AND sender_id = ? FOR UPDATE",
                [$packageId, $userId]
            );

            if (!$package) {
                throw new GameException('Csomag nem található, vagy nem a tiéd!');
            }

            if ($package['status'] !== 'in_transit') {
                throw new GameException('Ezt a csomagot már nem lehet visszavonni!');
            }

            // Ellenőrzés: 10 percen belül van-e
            $createdAt = strtotime($package['created_at']);
            $now = time();
            $elapsedMinutes = ($now - $createdAt) / 60;

            if ($elapsedMinutes >= 10) {
                throw new GameException('A csomagot csak a feladástól számított 10 percen belül lehet visszavonni!');
            }

            // Postaköltség visszatérítése
            $senderUserId = UserId::of($userId);
            if ((int)$package['total_cost'] > 0) {
                $this->moneyService->addMoney(
                    $senderUserId,
                    (int)$package['total_cost'],
                    'refund',
                    'Postai csomag visszavonása (postaköltség visszatérítés)'
                );
            }

            // Tételek visszatérítése a feladónak
            $items = $this->db->fetchAllAssociative(
                "SELECT item_type, item_id, item_name, quantity, unit_value
                 FROM postal_package_items WHERE package_id = ?",
                [$packageId]
            );

            foreach ($items as $item) {
                $this->deliverItemToRecipient($senderUserId, $item, true); // true = isCancel
            }

            // Státusz frissítés
            $this->db->executeStatement(
                "UPDATE postal_packages SET status = 'cancelled' WHERE id = ?",
                [$packageId]
            );

            // Értesítés a címzettnek
            $senderName = $this->db->fetchOne("SELECT username FROM users WHERE id = ?", [$userId]);
            $this->db->insert('notifications', [
                'user_id' => $package['recipient_id'],
                'type' => 'postal',
                'message' => "{$senderName} feladó visszavonta a számodra feladott csomagját.",
                'is_read' => 0,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            
            // Értesítés cache törlése a címzettnél
            $this->cache->forget("unread_notifications:{$package['recipient_id']}");
            $this->cache->forget("pending_postal_data:{$userId}");
            $this->cache->forget("pending_postal_data:{$package['recipient_id']}");

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Van-e várakozó csomag a usernek? (sidebar buborék)
     */
    public function getPendingCount(int $userId): int
    {
        return (int)$this->db->fetchOne(
            "SELECT COUNT(*) FROM postal_packages WHERE recipient_id = ? AND status = 'in_transit'",
            [$userId]
        );
    }
}
