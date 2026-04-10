<?php
declare(strict_types=1);

namespace Netmafia\Modules\Item\Domain;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

use Doctrine\DBAL\Connection;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Modules\Health\Domain\HealthService;

/**
 * ItemService - Tárgy műveletek (felszerel, levesz, használ, elad)
 * 
 * [2025-12-30] Fegyver/védelem/fogyasztható kezelés
 */
class ItemService
{
    private Connection $db;
    private InventoryService $inventoryService;
    private BuffService $buffService;
    private MoneyService $moneyService;
    private HealthService $healthService;
    
    public function __construct(
        Connection $db,
        InventoryService $inventoryService,
        BuffService $buffService,
        MoneyService $moneyService,
        HealthService $healthService
    ) {
        $this->db = $db;
        $this->inventoryService = $inventoryService;
        $this->buffService = $buffService;
        $this->moneyService = $moneyService;
        $this->healthService = $healthService;
    }
    
    /**
     * Fegyver vagy védelem felszerelése
     */
    public function equipItem(UserId $userId, int $userItemId): void
    {
        $this->db->beginTransaction();
        
        try {
            // Get the user_item record
            $userItem = $this->db->fetchAssociative(
                "SELECT ui.*, i.type, i.name 
                 FROM user_items ui 
                 JOIN items i ON i.id = ui.item_id
                 WHERE ui.id = ? AND ui.user_id = ? FOR UPDATE",
                [$userItemId, $userId->id()]
            );
            
            if (!$userItem) {
                throw new GameException('Nincs ilyen tárgyad!');
            }
            
            if ($userItem['equipped']) {
                throw new GameException('Ez a tárgy már fel van szerelve!');
            }
            
            // Check type-specific rules
            if ($userItem['type'] === 'weapon') {
                // Max 1 weapon - check if already has one equipped
                // FOR UPDATE lock prevents race condition where two processes both see "no weapon equipped"
                $equippedWeapon = $this->db->fetchAssociative(
                    "SELECT ui.id FROM user_items ui
                     JOIN items i ON i.id = ui.item_id
                     WHERE ui.user_id = ? AND ui.equipped = 1 AND i.type = 'weapon'
                     FOR UPDATE",
                    [$userId->id()]
                );
                
                if ($equippedWeapon) {
                    throw new GameException('Már van felszerelt fegyvered! Előbb tedd le.');
                }
            } elseif ($userItem['type'] === 'armor') {
                // Check if this specific armor type is already equipped
                // FOR UPDATE lock for race condition protection
                $equippedSame = $this->db->fetchAssociative(
                    "SELECT ui.id FROM user_items ui
                     WHERE ui.user_id = ? AND ui.item_id = ? AND ui.equipped = 1
                     FOR UPDATE",
                    [$userId->id(), $userItem['item_id']]
                );
                
                if ($equippedSame) {
                    throw new GameException('Már viseled ezt a védelmet!');
                }
            } else {
                throw new GameException('Ezt a tárgyat nem lehet felszerelni!');
            }
            
            // Equip the item: split stack if quantity > 1
            if ($userItem['quantity'] > 1) {
                // Decrease quantity on the stored stack
                $this->db->executeStatement(
                    "UPDATE user_items SET quantity = quantity - 1 WHERE id = ?",
                    [$userItemId]
                );
                // Create a new record for the equipped item (quantity = 1)
                $this->db->insert('user_items', [
                    'user_id' => $userId->id(),
                    'item_id' => $userItem['item_id'],
                    'quantity' => 1,
                    'equipped' => 1
                ]);
            } else {
                // Only 1 item — just mark it as equipped
                $this->db->executeStatement(
                    "UPDATE user_items SET equipped = 1 WHERE id = ?",
                    [$userItemId]
                );
            }
            
            $this->db->commit();
            
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Fegyver vagy védelem levétele
     */
    public function unequipItem(UserId $userId, int $userItemId): void
    {
        $this->db->beginTransaction();
        
        try {
            $userItem = $this->db->fetchAssociative(
                "SELECT id, user_id, item_id, quantity, equipped FROM user_items WHERE id = ? AND user_id = ? FOR UPDATE",
                [$userItemId, $userId->id()]
            );
            
            if (!$userItem) {
                throw new GameException('Nincs ilyen tárgyad!');
            }
            
            if (!$userItem['equipped']) {
                throw new GameException('Ez a tárgy nincs felszerelve!');
            }
            
            // Check if there's already a stored (unequipped) stack of the same item
            $storedStack = $this->db->fetchAssociative(
                "SELECT id, user_id, item_id, quantity, equipped FROM user_items WHERE user_id = ? AND item_id = ? AND equipped = 0 FOR UPDATE",
                [$userId->id(), $userItem['item_id']]
            );
            
            if ($storedStack) {
                // Merge back into existing stack: increase stored quantity, delete equipped record
                $this->db->executeStatement(
                    "UPDATE user_items SET quantity = quantity + ? WHERE id = ?",
                    [$userItem['quantity'], $storedStack['id']]
                );
                $this->db->delete('user_items', ['id' => $userItemId]);
            } else {
                // No stored stack — just mark as unequipped
                $this->db->executeStatement(
                    "UPDATE user_items SET equipped = 0 WHERE id = ?",
                    [$userItemId]
                );
            }
            
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Fogyasztható tárgy használata
     */
    public function useConsumable(UserId $userId, int $userItemId): array
    {
        $this->db->beginTransaction();
        
        try {
            // Get item with effects
            $userItem = $this->db->fetchAssociative(
                "SELECT ui.*, i.name, i.type
                 FROM user_items ui
                 JOIN items i ON i.id = ui.item_id
                 WHERE ui.id = ? AND ui.user_id = ? FOR UPDATE",
                [$userItemId, $userId->id()]
            );
            
            if (!$userItem) {
                throw new GameException('Nincs ilyen tárgyad!');
            }
            
            if ($userItem['type'] !== 'consumable') {
                throw new GameException('Ez nem fogyasztható tárgy!');
            }
            
            if ($userItem['quantity'] < 1) {
                throw new GameException('Nincs elég tárgyad!');
            }
            
            // Get effects
            $effects = $this->db->fetchAllAssociative(
                "SELECT id, item_id, effect_type, value, duration_minutes, context FROM item_effects WHERE item_id = ?",
                [$userItem['item_id']]
            );
            
            // Check for timed effects - buff limit
            $timedEffects = array_filter($effects, fn($e) => $e['duration_minutes'] > 0);
            
            if (!empty($timedEffects)) {
                // Check if can add buff (max 2, not same item)
                if (!$this->buffService->canUseBuff($userId->id(), $userItem['item_id'])) {
                    throw new GameException('Már aktív ez a hatás, vagy már 2 buff aktív!');
                }
            }
            
            $results = [
                'item_name' => $userItem['name'],
                'instant_effects' => [],
                'timed_effects' => []
            ];
            
            // Apply effects
            foreach ($effects as $effect) {
                if ($effect['duration_minutes'] == 0) {
                    // Instant effect
                    $this->applyInstantEffect($userId, $effect);
                    $results['instant_effects'][] = $effect;
                } else {
                    // Timed buff
                    $this->buffService->addBuff($userId->id(), $userItem['item_id'], $effect);
                    $results['timed_effects'][] = $effect;
                }
            }
            
            // Remove one item from inventory
            $this->inventoryService->removeItem($userId->id(), $userItem['item_id'], 1);
            
            $this->db->commit();
            
            return $results;
            
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Instant effect alkalmazása
     */
    private function applyInstantEffect(UserId $userId, array $effect): void
    {
        switch ($effect['effect_type']) {
            case 'health_percent':
                $this->healthService->heal($userId, $effect['value']);
                break;
                
            case 'energy_percent':
                $this->healthService->restoreEnergy($userId, $effect['value']);
                break;
                
            default:
                // Unknown instant effect - ignore
                break;
        }
    }
    
    /**
     * Tárgy eladása
     */
    public function sellItem(UserId $userId, int $userItemId, int $quantity = 1): int
    {
        $this->db->beginTransaction();
        
        try {
            $userItem = $this->db->fetchAssociative(
                "SELECT ui.*, i.price, i.name
                 FROM user_items ui
                 JOIN items i ON i.id = ui.item_id
                 WHERE ui.id = ? AND ui.user_id = ? FOR UPDATE",
                [$userItemId, $userId->id()]
            );
            
            if (!$userItem) {
                throw new GameException('Nincs ilyen tárgyad!');
            }
            
            if ($userItem['equipped']) {
                throw new GameException('Felszerelt tárgyat nem adhatsz el! Előbb tedd le.');
            }
            
            if ($userItem['quantity'] < $quantity) {
                throw new GameException('Nincs elég tárgyad!');
            }
            
            // [2026-02-15] FIX: Eladási ár 50%-ra csökkentve (végtelen pénz exploit javítás)
            // Korábban sellPrice = price * quantity volt (100%), ami lehetővé tette
            // a végtelen pénz generálást vétel-eladás ciklussal.
            $sellPrice = (int)(($userItem['price'] * \Netmafia\Modules\Item\ItemConfig::ITEM_SELL_RATIO) * $quantity);
            
            // Add money
            $this->moneyService->addMoney(
                $userId,
                $sellPrice,
                'sell',
                "Tárgy eladás: {$quantity}x {$userItem['name']}",
                'item',
                $userItem['item_id']
            );
            
            // Remove from inventory
            $this->inventoryService->removeItem($userId->id(), $userItem['item_id'], $quantity);
            
            $this->db->commit();
            
            return $sellPrice;
            
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * User összesített támadás/védelem
     */
    public function calculateUserStats(int $userId): array
    {
        $equipped = $this->inventoryService->getEquippedItems($userId);
        
        $baseAttack = 0;
        $baseDefense = 0;
        
        foreach ($equipped as $item) {
            $baseAttack += (int)$item['attack'];
            $baseDefense += (int)$item['defense'];
        }

        // Apply Buffs
        $attackBonusPercent = $this->buffService->getActiveBonus($userId, 'attack_bonus', 'combat');
        $defenseBonusPercent = $this->buffService->getActiveBonus($userId, 'defense_bonus', 'combat');
        
        $attackBonusVal = (int)($baseAttack * ($attackBonusPercent / 100));
        $defenseBonusVal = (int)($baseDefense * ($defenseBonusPercent / 100));
        
        $totalAttack = $baseAttack + $attackBonusVal;
        $totalDefense = $baseDefense + $defenseBonusVal;

        // Get detailed buffs for UI
        $activeBuffs = $this->buffService->getActiveBuffs($userId);
        
        return [
            'attack' => $totalAttack,
            'defense' => $totalDefense,
            'base_attack' => $baseAttack,
            'base_defense' => $baseDefense,
            'attack_bonus_val' => $attackBonusVal,
            'defense_bonus_val' => $defenseBonusVal,
            'active_buffs' => $activeBuffs,
            'equipped_count' => count($equipped)
        ];
    }
    
    /**
     * Tárgy információ lekérdezése ID alapján
     */
    public function getItemById(int $itemId): ?array
    {
        $result = $this->db->fetchAssociative(
            "SELECT id, name, type, attack, defense, price, stackable FROM items WHERE id = ?",
            [$itemId]
        );
        
        return $result ?: null;
    }
}
