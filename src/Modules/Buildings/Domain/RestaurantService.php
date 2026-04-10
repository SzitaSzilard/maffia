<?php
declare(strict_types=1);

namespace Netmafia\Modules\Buildings\Domain;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

use Doctrine\DBAL\Connection;
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Infrastructure\AuditLogger;
use Netmafia\Shared\Domain\ValueObjects\UserId;

class RestaurantService
{
    private Connection $db;
    private MoneyService $moneyService;
    private BuildingService $buildingService;
    public function __construct(Connection $db, MoneyService $moneyService, BuildingService $buildingService)
    {
        $this->db = $db;
        $this->moneyService = $moneyService;
        $this->buildingService = $buildingService;
    }

    public function getMenu(int $buildingId): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT id, building_id, name, image, energy, price FROM restaurant_menu WHERE building_id = ? ORDER BY price ASC",
            [$buildingId]
        );
    }

    public function consumeItem(UserId $userId, int $itemId): array
    {
        // 1. Get Item details
        $item = $this->db->fetchAssociative("SELECT id, building_id, name, image, energy, price FROM restaurant_menu WHERE id = ?", [$itemId]);
        if (!$item) {
            throw new GameException("Az étel nem található!");
        }

        $buildingId = (int)$item['building_id'];
        $building = $this->buildingService->getById($buildingId);
        
        $price = (int)$item['price'];
        $energyGain = (int)$item['energy'];
        $ownerId = $building['owner_id'];

        // Owner eats for free
        $isOwner = ($ownerId !== null && $ownerId === $userId->id());
        $finalPrice = $isOwner ? 0 : $price;
        $desc = "Étterem: {$item['name']}" . ($isOwner ? " (Saját)" : "");

        // 2. Transaction
        $this->db->beginTransaction();
        try {
            // Deduct Money (0 if owner)
            if ($finalPrice > 0) {
                $this->moneyService->spendMoney(
                    $userId, 
                    $finalPrice, 
                    'restaurant_eat', 
                    $desc, 
                    'building', 
                    $buildingId
                );
            }

            // [NULL-SAFE] @audit_source try-finally
            try {
                $__fetchResult = $this->db->fetchOne("SELECT energy FROM users WHERE id = ?", [$userId->id()]);
                if ($__fetchResult === false) {
                    throw new \Netmafia\Shared\Exceptions\GameException("A kért adat nem található az adatbázisban!");
                }
                $userEnergy = (int) $__fetchResult;
                $newEnergy = min(100, $userEnergy + $energyGain);

                $this->db->executeStatement("SET @audit_source = 'RestaurantService::etterem'");
                $this->db->executeStatement("UPDATE users SET energy = ? WHERE id = ?", [$newEnergy, $userId->id()]);
            } finally {
                $this->db->executeStatement("SET @audit_source = NULL");
            }

            // [2026-02-15] FIX: DRY – központosított owner revenue logika használata
            // Korábban az owner cut, payout mode kezelés duplikálva volt.
            $ownerCut = 0;
            if ($finalPrice > 0) {
                $ownerCut = $this->buildingService->processOwnerRevenue(
                    $buildingId,
                    $finalPrice,
                    "Étterem bevétel: {$item['name']}"
                );

                // Stats
                $this->db->executeStatement(
                    "UPDATE buildings SET total_uses = total_uses + 1, total_revenue = total_revenue + ? WHERE id = ?",
                    [$finalPrice, $buildingId]
                );
            }

            $this->logUsage($buildingId, $userId->id(), $finalPrice, $ownerCut, $ownerId ? (int)$ownerId : null);



            $this->db->commit();



            return [
                'success' => true,
                'message' => "Sikeresen elfogyasztottad: {$item['name']} (+{$energyGain} Energia)",
                'new_energy' => $newEnergy
            ];

        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
    
    private function logUsage(int $buildingId, int $userId, int $amount, int $ownerCut, ?int $ownerId): void
    {
        $this->db->insert('building_income_log', [
            'building_id' => $buildingId,
            'user_id' => $userId,
            'amount' => $amount,
            'owner_cut' => $ownerCut,
            'owner_id' => $ownerId,
        ]);
    }

    // Owner Functions
    public function updateItem(int $ownerId, int $itemId, int $newPrice, int $newEnergy): void
    {
        // Verify ownership via JOIN
        $exists = $this->db->fetchOne(
            "SELECT 1 FROM restaurant_menu m 
             JOIN buildings b ON b.id = m.building_id 
             WHERE m.id = ? AND b.owner_id = ?",
             [$itemId, $ownerId]
        );

        if (!$exists) {
            throw new GameException("Nincs jogosultságod módosítani ezt az elemet!");
        }

        if ($newPrice < 1 || $newEnergy < 1) {
             throw new InvalidInputException("Az ár és energia pozitív kell legyen.");
        }

        $this->db->executeStatement(
            "UPDATE restaurant_menu SET price = ?, energy = ? WHERE id = ?",
            [$newPrice, $newEnergy, $itemId]
        );
    }
}
