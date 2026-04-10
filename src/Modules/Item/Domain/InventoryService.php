<?php
declare(strict_types=1);

namespace Netmafia\Modules\Item\Domain;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

use Doctrine\DBAL\Connection;
use Netmafia\Shared\Domain\ValueObjects\UserId;

/**
 * InventoryService - User inventory kezelése
 * 
 * [2025-12-30] Inventory lekérdezés, tárgy hozzáadás/eltávolítás
 */
class InventoryService
{
    private Connection $db;
    
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }
    
    /**
     * Felszerelt tárgyak (fegyver + védelmek együtt)
     */
    public function getEquippedItems(int $userId): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT ui.*, i.name, i.type, i.attack, i.defense, i.price
             FROM user_items ui
             JOIN items i ON i.id = ui.item_id
             WHERE ui.user_id = ? AND ui.equipped = 1
             ORDER BY i.type, i.name",
            [$userId]
        );
    }
    
    /**
     * Tárolt védelmi eszközök (nem felszerelt)
     */
    public function getStoredArmor(int $userId): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT ui.*, i.name, i.type, i.attack, i.defense, i.price
             FROM user_items ui
             JOIN items i ON i.id = ui.item_id
             WHERE ui.user_id = ? AND ui.equipped = 0 AND i.type = 'armor'
             ORDER BY i.name",
            [$userId]
        );
    }
    
    /**
     * Tárolt fegyverek (nem felszerelt)
     */
    public function getStoredWeapons(int $userId): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT ui.*, i.name, i.type, i.attack, i.defense, i.price
             FROM user_items ui
             JOIN items i ON i.id = ui.item_id
             WHERE ui.user_id = ? AND ui.equipped = 0 AND i.type = 'weapon'
             ORDER BY i.name",
            [$userId]
        );
    }
    
    /**
     * Tárolt fogyaszthatók
     */
    public function getStoredConsumables(int $userId): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT ui.*, i.name, i.type, i.price, i.description,
                    (SELECT GROUP_CONCAT(
                        CONCAT(ie.effect_type, ':', ie.value, ':', ie.duration_minutes, ':', IFNULL(ie.context, ''))
                        SEPARATOR '|'
                    ) FROM item_effects ie WHERE ie.item_id = i.id) as effects_raw
             FROM user_items ui
             JOIN items i ON i.id = ui.item_id
             WHERE ui.user_id = ? AND i.type = 'consumable'
             ORDER BY i.name",
            [$userId]
        );
    }
    
    /**
     * Tárolt egyéb tárgyak
     */
    public function getStoredMisc(int $userId): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT ui.*, i.name, i.type, i.price, i.description
             FROM user_items ui
             JOIN items i ON i.id = ui.item_id
             WHERE ui.user_id = ? AND i.type = 'misc'
             ORDER BY i.name",
            [$userId]
        );
    }
    
    /**
     * Tárgy hozzáadása inventory-hoz
     */
    public function addItem(int $userId, int $itemId, int $quantity = 1): void
    {
        // Check if item exists
        $item = $this->db->fetchAssociative(
            "SELECT id, name, type, attack, defense, price, stackable, max_stack FROM items WHERE id = ?",
            [$itemId]
        );
        
        if (!$item) {
            throw new GameException('A tárgy nem létezik!');
        }
        
        // Check if already has this item (stored, not equipped) - FOR UPDATE lock to prevent race condition
        $existing = $this->db->fetchAssociative(
            "SELECT id, user_id, item_id, quantity, equipped FROM user_items WHERE user_id = ? AND item_id = ? AND equipped = 0 FOR UPDATE",
            [$userId, $itemId]
        );
        
        if ($existing) {
            if (!$item['stackable']) {
                throw new GameException('Ez a tárgy nem halmozható!');
            }
            
            $newQty = $existing['quantity'] + $quantity;
            if ($newQty > $item['max_stack']) {
                throw new GameException("Maximum {$item['max_stack']} db lehet ebből a tárgyból!");
            }
            
            $this->db->executeStatement(
                "UPDATE user_items SET quantity = quantity + ? WHERE id = ?",
                [$quantity, $existing['id']]
            );
        } else {
            $this->db->insert('user_items', [
                'user_id' => $userId,
                'item_id' => $itemId,
                'quantity' => $quantity,
                'equipped' => 0
            ]);
        }
    }
    
    /**
     * Tárgy eltávolítása inventory-ból
     */
    public function removeItem(int $userId, int $itemId, int $quantity = 1): void
    {
        // FOR UPDATE lock to prevent race condition (double sell/use exploit)
        $existing = $this->db->fetchAssociative(
            "SELECT id, user_id, item_id, quantity, equipped FROM user_items WHERE user_id = ? AND item_id = ? AND equipped = 0 FOR UPDATE",
            [$userId, $itemId]
        );
        
        if (!$existing) {
            throw new GameException('Nincs ilyen tárgyad!');
        }
        
        if ($existing['quantity'] < $quantity) {
            throw new GameException('Nincs elég tárgyad!');
        }
        
        if ($existing['quantity'] == $quantity) {
            $this->db->delete('user_items', ['id' => $existing['id']]);
        } else {
            $this->db->executeStatement(
                "UPDATE user_items SET quantity = quantity - ? WHERE id = ?",
                [$quantity, $existing['id']]
            );
        }
    }
    
    /**
     * Van-e a usernek adott tárgyból
     */
    public function hasItem(int $userId, int $itemId, int $minQuantity = 1): bool
    {
        $result = $this->db->fetchOne(
            "SELECT COALESCE(SUM(quantity), 0) FROM user_items WHERE user_id = ? AND item_id = ?",
            [$userId, $itemId]
        );
        
        return $result !== false && (int)$result >= $minQuantity;
    }
    
    /**
     * Tárgy mennyiség lekérdezése
     */
    public function getItemQuantity(int $userId, int $itemId): int
    {
        $result = $this->db->fetchOne(
            "SELECT COALESCE(SUM(quantity), 0) FROM user_items WHERE user_id = ? AND item_id = ?",
            [$userId, $itemId]
        );
        
        return $result !== false ? (int)$result : 0;
    }
}
