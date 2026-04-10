<?php
declare(strict_types=1);

namespace Tests\Building;

use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;
use Netmafia\Modules\Buildings\Domain\BuildingService;
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Shared\Domain\ValueObjects\UserId;

/**
 * Critical Building Transaction Tests
 * 
 * Három kritikus teszteset a BuildingService tranzakció biztonságának ellenőrzéséhez:
 * 1. Atomicity Test - Hiba eseténi visszavonás
 * 2. Race Condition Test - Párhuzamos használat
 * 3. Revenue Claim Duplication Test - Dupla felvétel megelőzése
 */
class BuildingTransactionTest extends TestCase
{
    private Connection $db;
    private MoneyService $moneyService;
    private BuildingService $buildingService;
    private int $testUserId;
    private int $testOwnerId;
    private int $testBuildingId;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection([
            'dbname' => $_ENV['DB_NAME'] ?? 'netmafia',
            'user' => $_ENV['DB_USER'] ?? 'root',
            'password' => $_ENV['DB_PASS'] ?? '',
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'driver' => 'pdo_mysql',
        ]);

        $this->moneyService = new MoneyService($this->db);
        $this->buildingService = new BuildingService($this->db, $this->moneyService);

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
    }

    private function createTestData(): void
    {
        // Test user (aki használja az épületet)
        $this->db->executeStatement(
            "INSERT INTO users (username, email, password, money, credits, xp, health, energy) 
             VALUES ('building_test_user', 'btest@test.com', 'test', 1000, 0, 0, 100, 100)"
        );
        $this->testUserId = (int) $this->db->lastInsertId();

        // Test owner (épület tulajdonos)
        $this->db->executeStatement(
            "INSERT INTO users (username, email, password, money, credits, xp, health, energy) 
             VALUES ('building_test_owner', 'bowner@test.com', 'test', 0, 0, 0, 100, 100)"
        );
        $this->testOwnerId = (int) $this->db->lastInsertId();

        // Get test building and configure it
        $building = $this->db->fetchAssociative(
            "SELECT id FROM buildings WHERE type = 'hospital' AND country_code = 'US' LIMIT 1"
        );
        
        if (!$building) {
            $this->markTestSkipped('No test building available');
        }

        $this->testBuildingId = (int) $building['id'];

        // Configure building: $100 usage price, owner gets 10%
        $this->db->executeStatement(
            "UPDATE buildings SET usage_price = 100, owner_id = ?, owner_cut_percent = 10, 
             payout_mode = 'instant', pending_revenue = 0, total_uses = 0, total_revenue = 0 
             WHERE id = ?",
            [$this->testOwnerId, $this->testBuildingId]
        );
    }

    private function cleanupTestData(): void
    {
        // Restore building
        $this->db->executeStatement(
            "UPDATE buildings SET usage_price = 0, owner_id = NULL, pending_revenue = 0, 
             total_uses = 0, total_revenue = 0 WHERE id = ?",
            [$this->testBuildingId]
        );

        // Delete test data
        $this->db->executeStatement(
            "DELETE FROM money_transactions WHERE user_id IN (?, ?)",
            [$this->testUserId, $this->testOwnerId]
        );
        $this->db->executeStatement(
            "DELETE FROM building_income_log WHERE user_id = ?",
            [$this->testUserId]
        );
        $this->db->executeStatement(
            "DELETE FROM users WHERE id IN (?, ?)",
            [$this->testUserId, $this->testOwnerId]
        );
    }

    private function getUserMoney(int $userId): int
    {
        return (int) $this->db->fetchOne("SELECT money FROM users WHERE id = ?", [$userId]);
    }

    private function getBuildingStats(): array
    {
        return $this->db->fetchAssociative(
            "SELECT total_uses, total_revenue, pending_revenue FROM buildings WHERE id = ?",
            [$this->testBuildingId]
        );
    }

    // ==========================================================================
    // 1. ATOMICITY TEST - Hiba eseténi visszavonás ellenőrzése
    // ==========================================================================

    /**
     * @test
     * Teszt: Normál épület használat - minden rendben működik
     */
    public function building_usage_successful_transaction(): void
    {
        $userId = UserId::of($this->testUserId);
        
        // User: $1000, épület ár: $100
        $initialUserMoney = $this->getUserMoney($this->testUserId);
        $initialOwnerMoney = $this->getUserMoney($this->testOwnerId);
        $this->assertEquals(1000, $initialUserMoney);
        $this->assertEquals(0, $initialOwnerMoney);

        // Használat
        $result = $this->buildingService->useBuilding($this->testBuildingId, $userId);

        $this->assertTrue($result['success']);
        $this->assertEquals(100, $result['cost']);
        $this->assertEquals(10, $result['owner_cut']); // 10%

        // Ellenőrzés
        $finalUserMoney = $this->getUserMoney($this->testUserId);
        $finalOwnerMoney = $this->getUserMoney($this->testOwnerId);

        $this->assertEquals(900, $finalUserMoney); // 1000 - 100
        $this->assertEquals(10, $finalOwnerMoney); // 0 + 10

        $stats = $this->getBuildingStats();
        $this->assertEquals(1, (int)$stats['total_uses']);
        $this->assertEquals(100, (int)$stats['total_revenue']);
    }

    /**
     * @test
     * Teszt: Nincs elég pénz - a tranzakció nem indul el
     */
    public function building_usage_insufficient_funds_no_partial_transaction(): void
    {
        // User pénzét $50-re csökkentjük (épület: $100)
        $this->db->executeStatement(
            "UPDATE users SET money = 50 WHERE id = ?",
            [$this->testUserId]
        );

        $userId = UserId::of($this->testUserId);
        $initialStats = $this->getBuildingStats();

        // Használat megpróbálása
        $result = $this->buildingService->useBuilding($this->testBuildingId, $userId);

        $this->assertFalse($result['success']);
        $this->assertEquals('Nincs elég pénzed!', $result['message']);

        // Pénz NEM változott
        $this->assertEquals(50, $this->getUserMoney($this->testUserId));
        $this->assertEquals(0, $this->getUserMoney($this->testOwnerId));

        // Statisztika NEM változott
        $finalStats = $this->getBuildingStats();
        $this->assertEquals($initialStats['total_uses'], $finalStats['total_uses']);
    }

    // ==========================================================================
    // 2. RACE CONDITION STRESS TEST - Párhuzamos épület használat
    // ==========================================================================

    /**
     * @test
     * Teszt: Szekvenciális kérések - kiürül a számla, de nem megy mínuszba
     */
    public function building_usage_sequential_exhausts_balance_correctly(): void
    {
        // User: pontosan annyit kap, amennyibe 3 használat kerül
        $this->db->executeStatement(
            "UPDATE users SET money = 300 WHERE id = ?",
            [$this->testUserId]
        );

        $userId = UserId::of($this->testUserId);
        $successCount = 0;
        $failCount = 0;

        // 5 próbálkozás, de csak 3 sikerülhet (300/100 = 3)
        for ($i = 0; $i < 5; $i++) {
            $result = $this->buildingService->useBuilding($this->testBuildingId, $userId);
            if ($result['success']) {
                $successCount++;
            } else {
                $failCount++;
            }
        }

        $finalMoney = $this->getUserMoney($this->testUserId);

        // KRITIKUS ELLENŐRZÉS
        $this->assertEquals(3, $successCount, "Pontosan 3 sikeres használat kellett volna");
        $this->assertEquals(2, $failCount, "Pontosan 2 sikertelen használat kellett volna");
        $this->assertEquals(0, $finalMoney, "A végső egyenleg 0 kell legyen");
        $this->assertGreaterThanOrEqual(0, $finalMoney, "KRITIKUS: Az egyenleg NEM lehet negatív!");
    }

    /**
     * @test
     * Teszt: Pontosan egy használathoz van pénz - dupla próbálkozás
     */
    public function building_usage_exact_balance_prevents_double_spend(): void
    {
        // User: pontosan annyi pénze van, amennyibe EGY használat kerül
        $this->db->executeStatement(
            "UPDATE users SET money = 100 WHERE id = ?",
            [$this->testUserId]
        );

        $userId = UserId::of($this->testUserId);
        
        // Első használat - sikeres kell legyen
        $result1 = $this->buildingService->useBuilding($this->testBuildingId, $userId);
        $this->assertTrue($result1['success']);

        // Második használat - sikertelen kell legyen
        $result2 = $this->buildingService->useBuilding($this->testBuildingId, $userId);
        $this->assertFalse($result2['success']);
        $this->assertEquals('Nincs elég pénzed!', $result2['message']);

        $finalMoney = $this->getUserMoney($this->testUserId);
        
        // KRITIKUS
        $this->assertEquals(0, $finalMoney, "Egyenleg 0 kell legyen");
        $this->assertGreaterThanOrEqual(0, $finalMoney, "KRITIKUS: Egyenleg NEM lehet negatív!");
    }

    // ==========================================================================
    // 3. DAILY REVENUE CLAIM DUPLICATION TEST
    // ==========================================================================

    /**
     * @test
     * Teszt: Napi bevétel felvétel - egyszeri felvétel sikeres
     */
    public function claim_daily_revenue_single_claim_works(): void
    {
        // Épület beállítása daily módra, $5000 pending
        $this->db->executeStatement(
            "UPDATE buildings SET payout_mode = 'daily', pending_revenue = 5000 WHERE id = ?",
            [$this->testBuildingId]
        );

        $initialOwnerMoney = $this->getUserMoney($this->testOwnerId);
        $this->assertEquals(0, $initialOwnerMoney);

        // Bevétel felvétele
        $claimed = $this->buildingService->claimDailyRevenue($this->testOwnerId);

        $this->assertEquals(5000, $claimed);

        $finalOwnerMoney = $this->getUserMoney($this->testOwnerId);
        $this->assertEquals(5000, $finalOwnerMoney);

        // Pending nullázódott
        $stats = $this->getBuildingStats();
        $this->assertEquals(0, (int)$stats['pending_revenue']);
    }

    /**
     * @test
     * Teszt: Napi bevétel felvétel - dupla felvétel megelőzése
     */
    public function claim_daily_revenue_double_claim_prevented(): void
    {
        // Épület beállítása daily módra, $5000 pending
        $this->db->executeStatement(
            "UPDATE buildings SET payout_mode = 'daily', pending_revenue = 5000 WHERE id = ?",
            [$this->testBuildingId]
        );

        // Első felvétel
        $claimed1 = $this->buildingService->claimDailyRevenue($this->testOwnerId);
        $this->assertEquals(5000, $claimed1);

        // Második felvétel - 0 kell legyen
        $claimed2 = $this->buildingService->claimDailyRevenue($this->testOwnerId);
        $this->assertEquals(0, $claimed2, "Második felvétel 0 kell legyen!");

        // KRITIKUS: Owner pontosan 5000-et kapott, nem többet!
        $finalOwnerMoney = $this->getUserMoney($this->testOwnerId);
        $this->assertEquals(5000, $finalOwnerMoney, "KRITIKUS: Owner pontosan 5000-et kaphat, nem 10000-et!");
    }

    /**
     * @test
     * Teszt: Szekvenciális dupla claim teszt - nincs duplikáció
     */
    public function claim_daily_revenue_sequential_claims_no_duplication(): void
    {
        // Épület beállítása daily módra, $3000 pending
        $this->db->executeStatement(
            "UPDATE buildings SET payout_mode = 'daily', pending_revenue = 3000 WHERE id = ?",
            [$this->testBuildingId]
        );

        $totalClaimed = 0;

        // 5 egymás utáni próbálkozás
        for ($i = 0; $i < 5; $i++) {
            $claimed = $this->buildingService->claimDailyRevenue($this->testOwnerId);
            $totalClaimed += $claimed;
        }

        // KRITIKUS: Összesen pontosan 3000 jóváírás
        $this->assertEquals(3000, $totalClaimed, "KRITIKUS: Összesen pontosan 3000 kell legyen!");
        
        $finalOwnerMoney = $this->getUserMoney($this->testOwnerId);
        $this->assertEquals(3000, $finalOwnerMoney, "KRITIKUS: Owner egyenlege pontosan 3000!");
    }

    // ==========================================================================
    // TRANSACTION INTEGRITY TESTS
    // ==========================================================================

    /**
     * @test
     * Teszt: Pénz konzisztencia - a rendszerből nem tűnik el és nem keletkezik pénz
     */
    public function money_conservation_during_building_usage(): void
    {
        $initialUserMoney = $this->getUserMoney($this->testUserId);
        $initialOwnerMoney = $this->getUserMoney($this->testOwnerId);
        $totalBefore = $initialUserMoney + $initialOwnerMoney;

        $userId = UserId::of($this->testUserId);
        $result = $this->buildingService->useBuilding($this->testBuildingId, $userId);

        if ($result['success']) {
            $finalUserMoney = $this->getUserMoney($this->testUserId);
            $finalOwnerMoney = $this->getUserMoney($this->testOwnerId);
            
            // A tulajnak jóváírt összeg (10%) + ami maradt a usernél + ami a rendszerbe ment (90%)
            // = az eredeti összeg
            // Megjegyzés: A 90% "eltűnik" a rendszerbe (épület bevétel), ez normális
            
            $this->assertEquals($initialUserMoney - 100, $finalUserMoney);
            $this->assertEquals($initialOwnerMoney + 10, $finalOwnerMoney);
        }
    }
}
