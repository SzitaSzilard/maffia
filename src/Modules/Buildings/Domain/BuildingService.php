<?php
declare(strict_types=1);

namespace Netmafia\Modules\Buildings\Domain;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

use Doctrine\DBAL\Connection;
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Modules\Money\Domain\InsufficientBalanceException;
use Netmafia\Shared\Domain\ValueObjects\UserId;

/**
 * BuildingService - Épület műveletek kezelése
 * 
 * MoneyService-t használ minden pénz műveletre (auditálás!)
 */
class BuildingService
{
    private Connection $db;
    private MoneyService $moneyService;

    public function __construct(Connection $db, MoneyService $moneyService)
    {
        $this->db = $db;
        $this->moneyService = $moneyService;
    }

    /**
     * @return Connection
     */
    public function getDb(): Connection
    {
        return $this->db;
    }

    /**
     * Épület lekérdezése ID alapján
     */
    public function getById(int $buildingId): ?array
    {
        $building = $this->db->fetchAssociative(
            "SELECT b.*, c.name_hu as country_name, c.flag_emoji,
                    u.username as owner_name
             FROM buildings b
             JOIN countries c ON c.code = b.country_code
             LEFT JOIN users u ON u.id = b.owner_id
             WHERE b.id = ?",
            [$buildingId]
        );
        return $building ?: null;
    }

    /**
     * Épület lekérdezése ország és típus alapján
     */
    public function getByCountryAndType(string $countryCode, string $type): ?array
    {
        $building = $this->db->fetchAssociative(
            "SELECT b.*, c.name_hu as country_name, c.flag_emoji,
                    u.username as owner_name
             FROM buildings b
             JOIN countries c ON c.code = b.country_code
             LEFT JOIN users u ON u.id = b.owner_id
             WHERE b.country_code = ? AND b.type = ?",
            [$countryCode, $type]
        );
        return $building ?: null;
    }

    /**
     * Összes épület egy országban
     */
    public function getByCountry(string $countryCode): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT b.*, c.name_hu as country_name, c.flag_emoji,
                    u.username as owner_name
             FROM buildings b
             JOIN countries c ON c.code = b.country_code
             LEFT JOIN users u ON u.id = b.owner_id
             WHERE b.country_code = ?
             ORDER BY b.type",
            [$countryCode]
        );
    }

    /**
     * Egy játékos összes épülete
     */
    public function getByOwner(int $ownerId): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT b.*, c.name_hu as country_name, c.flag_emoji
             FROM buildings b
             JOIN countries c ON c.code = b.country_code
             WHERE b.owner_id = ?
             ORDER BY b.country_code, b.type",
            [$ownerId]
        );
    }

    /**
     * Adott típusú épületek lekérdezése minden országból
     * Hasznos az Országok (Épületek) listázásához
     */
    public function getBuildingsByType(string $type): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT b.*, c.name_hu as country_name, u.username as owner_name, 
                    COALESCE(b.id, 0) as has_building
             FROM countries c
             LEFT JOIN buildings b ON c.code = b.country_code AND b.type = ?
             LEFT JOIN users u ON b.owner_id = u.id
             ORDER BY c.name_hu ASC", 
            [$type]
        );
    }

    /**
     * Épület használata - fizet a user, tulaj kap jutalékot
     * 
     * TRANZAKCIÓ BIZTONSÁG:
     * - Minden művelet egy tranzakcióban fut
     * - Ha bármi hiba, minden visszavonódik (rollback)
     * - "Minden vagy semmi" elv
     * 
     * @return array{success: bool, cost: int, owner_cut: int, message: string}
     */
    public function useBuilding(int $buildingId, UserId $userId): array
    {
        // === 1. VALIDÁCIÓK (tranzakción kívül) ===
        $building = $this->getById($buildingId);
        
        if (!$building) {
            return ['success' => false, 'cost' => 0, 'owner_cut' => 0, 'message' => 'Épület nem található'];
        }

        $usagePrice = (int) $building['usage_price'];
        
        // Ingyenes használat - nem kell tranzakció
        if ($usagePrice <= 0) {
            $this->logUsage($buildingId, $userId->id(), 0, 0, $building['owner_id']);
            return ['success' => true, 'cost' => 0, 'owner_cut' => 0, 'message' => 'Sikeres (ingyenes) használat!'];
        }

        // === 2. TRANZAKCIÓ KEZDÉSE ===
        $this->db->beginTransaction();

        try {
            // === 3. PÉNZ LEVONÁSA A USERTŐL ===
            // Ha nincs elég pénz, exception-t dob és ugrunk a catch-re
            $this->moneyService->spendMoney(
                $userId,
                $usagePrice,
                'building_usage',
                "Épület használat: {$building['name_hu']}",
                'building',
                $buildingId
            );

            // === 4. TULAJ JUTALÉK SZÁMÍTÁSA ===
            $ownerCutPercent = \Netmafia\Modules\Buildings\Domain\BuildingConfig::FIXED_OWNER_CUT_PERCENT;
            $ownerCut = (int) floor($usagePrice * $ownerCutPercent / 100);
            $ownerId = $building['owner_id'];

            // === 5. JUTALÉK KEZELÉSE ===
            if ($ownerId !== null && $ownerCut > 0) {
                $payoutMode = $building['payout_mode'];

                if ($payoutMode === 'instant') {
                    // 5a. Azonnali kifizetés tulajnak
                    $this->moneyService->addMoney(
                        UserId::of($ownerId),
                        $ownerCut,
                        'building_income',
                        "Épület bevétel: {$building['name_hu']}",
                        'building',
                        $buildingId
                    );
                } else {
                    // 5b. Napi gyűjtéshez hozzáadjuk
                    $this->db->executeStatement(
                        "UPDATE buildings SET pending_revenue = pending_revenue + ? WHERE id = ?",
                        [$ownerCut, $buildingId]
                    );
                }
            }

            // === 6. STATISZTIKA FRISSÍTÉSE ===
            $this->db->executeStatement(
                "UPDATE buildings SET total_uses = total_uses + 1, total_revenue = total_revenue + ? WHERE id = ?",
                [$usagePrice, $buildingId]
            );

            // Használat naplózása
            $this->logUsage($buildingId, $userId->id(), $usagePrice, $ownerCut, $ownerId);

            // === 7. VÉGLEGESÍTÉS (COMMIT) ===
            $this->db->commit();

            return [
                'success' => true,
                'cost' => $usagePrice,
                'owner_cut' => $ownerCut,
                'message' => 'Sikeres használat!',
            ];

        } catch (InsufficientBalanceException $e) {
            // === 8. VISSZAVONAS - Nincs eleg penz ===
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            return [
                'success' => false, 
                'cost' => $usagePrice, 
                'owner_cut' => 0, 
                'message' => 'Nincs elég pénzed!'
            ];

        } catch (\Throwable $e) {
            // === 8. VISSZAVONÁS - Bármilyen más hiba ===
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e; // Továbbdobjuk a hibát logoláshoz
        }
    }

    /**
     * Használat naplózása
     */
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

    /**
     * Owner bevétel feldolgozása (központosított logika)
     * 
     * Kiszámítja az owner cut-ot és a payout mode alapján
     * azonnali vagy napi gyűjtésbe teszi.
     * 
     * @param int $buildingId Épület ID
     * @param int $totalAmount Teljes összeg (amit a user fizetett)
     * @param string $description Leírás az owner bevételhez
     * @return int Az owner cut összege (0 ha nincs owner)
     */
    public function processOwnerRevenue(
        int $buildingId,
        int $totalAmount,
        string $description = 'Épület bevétel'
    ): int {
        $building = $this->getById($buildingId);
        $ownerId = $building['owner_id'];
        
        // Nincs tulajdonos -> nincs bevétel
        if (!$ownerId) {
            return 0;
        }
        
        // Owner cut számítása
        $ownerCutPercent = \Netmafia\Modules\Buildings\Domain\BuildingConfig::FIXED_OWNER_CUT_PERCENT;
        $ownerCut = (int) floor($totalAmount * $ownerCutPercent / 100);
        
        if ($ownerCut <= 0) {
            return 0;
        }
        
        // Payout mode szerint kifizetés
        if ($building['payout_mode'] === 'instant') {
            // Azonnali kifizetés
            $this->moneyService->addMoney(
                UserId::of($ownerId),
                $ownerCut,
                'building_income',
                $description,
                'building',
                $buildingId
            );
        } else {
            // Napi/heti gyűjtés (daily/weekly)
            $this->db->executeStatement(
                "UPDATE buildings SET pending_revenue = pending_revenue + ? WHERE id = ?",
                [$ownerCut, $buildingId]
            );
        }
        
        return $ownerCut;
    }

    /**
     * Ár beállítása (csak tulajdonos)
     */
    public function setPrice(int $buildingId, int $ownerId, int $newPrice): bool
    {
        if ($newPrice < 0) {
            return false;
        }

        $result = $this->db->executeStatement(
            "UPDATE buildings SET usage_price = ? WHERE id = ? AND owner_id = ?",
            [$newPrice, $buildingId, $ownerId]
        );

        return $result > 0;
    }



    /**
     * Kifizetési mód beállítása
     */
    public function setPayoutMode(int $buildingId, int $ownerId, string $mode): bool
    {
        if (!in_array($mode, ['instant', 'daily', 'weekly'])) {
            return false;
        }

        $this->db->beginTransaction();

        try {
            // Check current mode and pending revenue
            if ($mode === 'instant') {
                 $pending = (int) $this->db->fetchOne(
                    "SELECT pending_revenue FROM buildings WHERE id = ? AND owner_id = ?",
                    [$buildingId, $ownerId]
                 );

                 if ($pending > 0) {
                     // Pay out immediately
                     $this->moneyService->addMoney(
                        UserId::of($ownerId),
                        $pending,
                        'building_income',
                        "Épület bevétel kifizetése (Azonnalira váltás)",
                        'building',
                        $buildingId
                     );

                     // Reset pending
                     $this->db->executeStatement(
                        "UPDATE buildings SET pending_revenue = 0 WHERE id = ?",
                        [$buildingId]
                     );
                 }
            }

            $result = $this->db->executeStatement(
                "UPDATE buildings SET payout_mode = ? WHERE id = ? AND owner_id = ?",
                [$mode, $buildingId, $ownerId]
            );

            $this->db->commit();
            return true;

        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Napi bevétel felvétele
     * 
     * TRANZAKCIÓ BIZTONSÁG:
     * - Minden művelet egy tranzakcióban fut
     * - Ha bármi hiba, minden visszavonódik (rollback)
     * - "Minden vagy semmi" elv
     */
    public function claimDailyRevenue(int $ownerId): int
    {
        // === 1. TRANZAKCIÓ KEZDÉSE ===
        $this->db->beginTransaction();

        try {
            // [FIX] TOCTOU fix: SUM lekérdezés FOR UPDATE-tal a tranzakción BELÜL
            // Ezzel megelőzzük a dupla kifizetést párhuzamos kéréseknél
            $totalPending = (int) $this->db->fetchOne(
                "SELECT SUM(pending_revenue) FROM buildings WHERE owner_id = ? AND payout_mode = 'daily' FOR UPDATE",
                [$ownerId]
            );

            if ($totalPending <= 0) {
                $this->db->commit();
                return 0;
            }

            // === 2. KIFIZETÉS (MoneyService-szel!) ===
            $this->moneyService->addMoney(
                UserId::of($ownerId),
                $totalPending,
                'building_income',
                "Napi épület bevétel felvétele",
                null,
                null
            );

            // === 3. PENDING NULLÁZÁSA ===
            $this->db->executeStatement(
                "UPDATE buildings SET pending_revenue = 0 WHERE owner_id = ? AND payout_mode = 'daily'",
                [$ownerId]
            );

            // === 4. VÉGLEGESÍTÉS (COMMIT) ===
            $this->db->commit();

            return $totalPending;

        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Épület tulajdonba adása (aukció után)
     */
    public function assignOwner(int $buildingId, int $newOwnerId): bool
    {
        // 0. Rang ellenőrzése (Legenda - 228.000 XP)
        // 0. Rang ellenőrzése (Legenda - 228.000 XP)
        $userObj = $this->db->fetchAssociative("SELECT xp, is_admin FROM users WHERE id = ?", [$newOwnerId]);
        $xp = (int) ($userObj['xp'] ?? 0);
        $isAdmin = (bool) ($userObj['is_admin'] ?? false);

        if ($xp < \Netmafia\Modules\Buildings\BuildingConfig::MIN_XP_FOR_OWNERSHIP && !$isAdmin) {
            throw new GameException("Csak Legenda (" . number_format(\Netmafia\Modules\Buildings\BuildingConfig::MIN_XP_FOR_OWNERSHIP, 0, '.', ' ') . " XP) rangtól lehet épületet birtokolni!");
        }

        $this->db->beginTransaction();

        try {
            $oldBuilding = $this->getById($buildingId);
            $oldOwnerId = $oldBuilding['owner_id'] ?? null;

            $this->db->executeStatement(
                "UPDATE buildings SET 
                    owner_id = ?, 
                    acquired_at = UTC_TIMESTAMP(), 
                    pending_revenue = 0,
                    total_revenue = 0,
                    total_uses = 0
                 WHERE id = ?",
                [$newOwnerId, $buildingId]
            );

            $this->updateUnionMemberStatus($newOwnerId);

            if ($oldOwnerId !== null) {
                $this->updateUnionMemberStatus($oldOwnerId);
            }

            $this->db->commit();
            return true;

        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Szakszervezeti tag státusz frissítése
     */
    public function updateUnionMemberStatus(int $userId): void
    {
        $buildingCount = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM buildings WHERE owner_id = ?",
            [$userId]
        );

        $isUnionMember = $buildingCount > 0 ? 1 : 0;

        try {
            $this->db->executeStatement("SET @audit_source = ?", ['BuildingService::updateUnionMember']);
            $this->db->executeStatement(
                "UPDATE users SET is_union_member = ? WHERE id = ?",
                [$isUnionMember, $userId]
            );
        } finally {
            $this->db->executeStatement("SET @audit_source = NULL");
        }
    }

    /**
     * Összes ország lekérdezése
     */
    public function getAllCountries(): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT code, name_hu, flag_emoji FROM countries ORDER BY name_hu"
        );
    }
}
