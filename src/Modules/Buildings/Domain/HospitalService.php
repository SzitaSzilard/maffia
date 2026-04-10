<?php
declare(strict_types=1);

namespace Netmafia\Modules\Buildings\Domain;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

use Doctrine\DBAL\Connection;
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Infrastructure\AuditLogger;
use Netmafia\Shared\Domain\ValueObjects\UserId;

class HospitalService
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

    public function getPrice(int $buildingId): int
    {
        $price = $this->db->fetchOne("SELECT price_per_hp FROM hospital_prices WHERE building_id = ?", [$buildingId]);
        return $price !== false ? (int)$price : \Netmafia\Modules\Buildings\BuildingConfig::HOSPITAL_DEFAULT_PRICE_PER_HP; // Default callback
    }

    /**
     * Minden kórház árának lekérése (Országok nézethez)
     */
    public function getAllHospitalsData(): array
    {
        $rows = $this->db->fetchAllAssociative("SELECT building_id, price_per_hp FROM hospital_prices");
        
        $result = [];
        foreach ($rows as $row) {
            $result[$row['building_id']] = ['price_per_hp' => (int)$row['price_per_hp']];
        }
        return $result;
    }

    public function heal(UserId $userId, int $buildingId, string $mode): array
    {
        // 1. Get Building & Price
        $building = $this->buildingService->getById($buildingId);
        $pricePerHp = $this->getPrice($buildingId);
        
        // Transaction
        $this->db->beginTransaction();
        try {
            // [FIX] Lock User Row to prevent Double Healing/Payment
            $user = $this->db->fetchAssociative("SELECT health FROM users WHERE id = ? FOR UPDATE", [$userId->id()]);
            $currentHp = (int)$user['health'];

            if ($currentHp >= \Netmafia\Modules\Buildings\BuildingConfig::MAX_HEALTH) {
                throw new GameException("Már teljesen egészséges vagy!");
            }

            // Recalculate needed amount inside lock
            $needed = \Netmafia\Modules\Buildings\BuildingConfig::MAX_HEALTH - $currentHp;
            $healAmount = $needed;
            $totalCost = $healAmount * $pricePerHp;
            
            // Owner check (optimization: fetch generic owner info earlier is fine, strictly immutable usually)
            $ownerId = $building['owner_id'];
            $isOwner = ($ownerId !== null && $ownerId === $userId->id());
            $finalCost = $isOwner ? 0 : $totalCost;

            // Deduct Money
            if ($finalCost > 0) {
                $this->moneyService->spendMoney(
                    $userId,
                    $finalCost,
                    'hospital_heal',
                    "Kórház: Gyógyítás (+{$healAmount}%)",
                    'building',
                    $buildingId
                );
            }

            // Increase Health
            // [NULL-SAFE] @audit_source try-finally
            try {
                $this->db->executeStatement("SET @audit_source = 'HospitalService::heal'");
                $this->db->executeStatement(
                    "UPDATE users SET health = LEAST(?, health + ?) WHERE id = ?",
                    [\Netmafia\Modules\Buildings\BuildingConfig::MAX_HEALTH, $healAmount, $userId->id()]
                );
            } finally {
                $this->db->executeStatement("SET @audit_source = NULL");
            }

            // [2026-02-15] FIX: DRY – központosított owner revenue logika használata
            // Korábban az owner cut, payout mode és stats kezelés duplikálva volt.
            // Most a BuildingService.processOwnerRevenue() végzi el mindezt.
            $ownerCut = 0;
            if ($finalCost > 0) {
                $ownerCut = $this->buildingService->processOwnerRevenue(
                    $buildingId,
                    $finalCost,
                    "Kórház bevétel"
                );

                // Stats frissítés
                $this->db->executeStatement(
                    "UPDATE buildings SET total_uses = total_uses + 1, total_revenue = total_revenue + ? WHERE id = ?",
                    [$finalCost, $buildingId]
                );
            }

            // Használat logolása
            $this->db->insert('building_income_log', [
                'building_id' => $buildingId,
                'user_id' => $userId->id(),
                'amount' => $finalCost,
                'owner_cut' => $ownerCut,
                'owner_id' => $ownerId,
            ]);

            $this->db->commit();



            return [
                'success' => true,
                'new_health' => min(\Netmafia\Modules\Buildings\BuildingConfig::MAX_HEALTH, $currentHp + $healAmount),
                'cost' => $finalCost
            ];

        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function updatePrice(int $ownerId, int $buildingId, int $newPrice): void
    {
        // Check ownership
        $building = $this->buildingService->getById($buildingId);
        if ($building['owner_id'] !== $ownerId) {
            throw new GameException("Nincs jogosultságod módosítani ezt a kórházat!");
        }

        if ($newPrice < 1) {
             throw new InvalidInputException("Az ár pozitív kell legyen.");
        }

        // Insert or Update
        $this->db->executeStatement(
            "INSERT INTO hospital_prices (building_id, price_per_hp) VALUES (?, ?) 
             ON DUPLICATE KEY UPDATE price_per_hp = ?",
            [$buildingId, $newPrice, $newPrice]
        );
    }
}
