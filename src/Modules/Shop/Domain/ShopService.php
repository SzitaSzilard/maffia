<?php
declare(strict_types=1);

namespace Netmafia\Modules\Shop\Domain;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

use Doctrine\DBAL\Connection;
use Netmafia\Modules\Item\Domain\InventoryService;
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Infrastructure\AuditLogger;
use Netmafia\Shared\Domain\ValueObjects\UserId;

/**
 * ShopService - Bolt rendszer logikája
 */
class ShopService
{
    private Connection $db;
    private InventoryService $inventoryService;
    private MoneyService $moneyService;
    public function __construct(
        Connection $db,
        InventoryService $inventoryService,
        MoneyService $moneyService
    ) {
        $this->db = $db;
        $this->inventoryService = $inventoryService;
        $this->moneyService = $moneyService;
    }

    /**
     * JSON stringből kinyeri és megszűri az érvényes effekteket.
     */
    private function parseAndFilterEffects(?string $effectsJson): array
    {
        if (empty($effectsJson)) {
            return [];
        }
        $decoded = json_decode($effectsJson, true);
        if (!is_array($decoded)) {
            return [];
        }
        $validEffects = array_filter($decoded, function ($effect) {
            return is_array($effect) && !empty($effect['effect_type']);
        });
        return array_values($validEffects);
    }

    /**
     * Bolti tárgyak lekérdezése kategória szerint
     */
    public function getShopItems(string $category): array
    {
        $sql = "SELECT i.id, i.name, i.image_url, i.type, i.attack, i.defense, i.price, i.stock, i.stackable, i.max_stack, i.description, 
                json_arrayagg(
                    json_object(
                         'effect_type', e.effect_type,
                         'value', e.value,
                         'duration', e.duration_minutes
                    )
                ) as effects 
                FROM items i 
                LEFT JOIN item_effects e ON i.id = e.item_id 
                WHERE i.is_shop_item = 1 AND i.type = ? AND i.stock > 0 
                GROUP BY i.id 
                ORDER BY i.price ASC";
        $results = $this->db->fetchAllAssociative($sql, [$category]);

        foreach ($results as &$row) {
            $row['effects'] = $this->parseAndFilterEffects($row['effects']);
        }

        return $results;
    }

    /**
     * Bolt összes elemének lekérdezése kategóriák szerint csoportosítva (N+1 fix)
     */
    public function getAllShopItemsGrouped(): array
    {
        $categories = ['weapon', 'armor', 'consumable', 'jet'];
        $placeholders = implode(',', array_fill(0, count($categories), '?'));
        
        $sql = "SELECT i.id, i.name, i.image_url, i.type, i.attack, i.defense, i.price, i.stock, i.stackable, i.max_stack, i.description, 
                json_arrayagg(
                    json_object(
                         'effect_type', e.effect_type,
                         'value', e.value,
                         'duration', e.duration_minutes
                    )
                ) as effects 
                FROM items i 
                LEFT JOIN item_effects e ON i.id = e.item_id 
                WHERE i.is_shop_item = 1 AND i.type IN ($placeholders) AND i.stock > 0 
                GROUP BY i.id 
                ORDER BY i.price ASC";
        
        $results = $this->db->fetchAllAssociative($sql, $categories);

        $groupedItems = array_fill_keys($categories, []);

        foreach ($results as $row) {
            $row['effects'] = $this->parseAndFilterEffects($row['effects']);
            $groupedItems[$row['type']][] = $row;
        }

        return $groupedItems;
    }

    /**
     * Bolt összes adatának lekérdezése a nézethez
     */
    public function getShopData(): array
    {
        $grouped = $this->getAllShopItemsGrouped();
        return [
            'weapons' => $grouped['weapon'] ?? [],
            'armors' => $grouped['armor'] ?? [],
            'consumables' => $grouped['consumable'] ?? [],
            'jets' => $grouped['jet'] ?? [],
            'next_restock' => $this->getNextRestockDate()
        ];
    }

    /**
     * Tárgy vásárlása a boltból
     */
    public function buyItem(UserId $userId, int $itemId, int $quantity = 1): void
    {
        if ($quantity < 1) {
            throw new GameException("Legalább 1 darabot kell vásárolnod.");
        }
        if ($quantity > 10000) {
            throw new GameException("Egyszerre maximum 10.000 darabot vehetsz!");
        }

        $this->db->beginTransaction();

        try {
            $item = $this->db->fetchAssociative(
                "SELECT id, name, type, attack, defense, price, stock, is_shop_item, stackable FROM items WHERE id = ? AND is_shop_item = 1 FOR UPDATE",
                [$itemId]
            );

            if (!$item) {
                throw new GameException("Ez a tárgy nem található a boltban.");
            }

            if ($item['stock'] < $quantity) {
                throw new GameException("Nincs elég készlet ebből a tárgyból.");
            }

            $totalPrice = (int)$item['price'] * $quantity;

            // Money deduction (throws exception if not enough money)
            $this->moneyService->spendMoney(
                $userId,
                $totalPrice,
                'purchase',
                "Bolti vásárlás: {$quantity}x {$item['name']}",
                'item',
                $itemId
            );

            // Deduct stock
            $this->db->executeStatement(
                "UPDATE items SET stock = stock - ? WHERE id = ?",
                [$quantity, $itemId]
            );

            // Add to inventory
            $this->inventoryService->addItem($userId->id(), $itemId, $quantity);



            $this->db->commit();

        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Következő készletfeltöltés lekérdezése
     */
    public function getNextRestockDate(): ?string
    {
        $date = $this->db->fetchOne(
            "SELECT setting_value FROM game_settings WHERE setting_key = 'next_shop_restock'"
        );
        return $date ?: null;
    }

    /**
     * Következő készletfeltöltés beállítása
     */
    public function setNextRestockDate(string $date): void
    {
        $this->db->executeStatement(
            "INSERT INTO game_settings (setting_key, setting_value) VALUES ('next_shop_restock', ?)
             ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = CURRENT_TIMESTAMP",
            [$date, $date]
        );
    }

    /**
     * Új tárgy feltöltése (Admin)
     */
    public function createShopItem(array $data): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->insert('items', [
                'name' => $data['name'],
                'image_url' => $data['image_url'] ?? null,
                'type' => $data['type'],
                'attack' => $data['attack'] ?? 0,
                'defense' => $data['defense'] ?? 0,
                'price' => $data['price'],
                'stock' => $data['stock'],
                'is_shop_item' => 1,
                'stackable' => $data['stackable'] ?? 1,
                'description' => $data['description'] ?? null
            ]);
            
            $itemId = (int)$this->db->lastInsertId();

            if (!empty($data['effects'])) {
                foreach ($data['effects'] as $effect) {
                    $this->db->insert('item_effects', [
                        'item_id' => $itemId,
                        'effect_type' => $effect['type'],
                        'value' => $effect['value'],
                        'duration_minutes' => $effect['duration'] ?? 0,
                        'context' => $effect['context'] ?? null
                    ]);
                }
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
