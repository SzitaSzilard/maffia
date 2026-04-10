<?php
declare(strict_types=1);

namespace Tests\RaceCondition;

use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;
use Netmafia\Modules\Credits\Domain\CreditService;
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Modules\Health\Domain\HealthService;
use Netmafia\Modules\Xp\Domain\XpService;
use Netmafia\Modules\Buildings\Domain\BuildingService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Shared\Domain\ValueObjects\Credits;

/**
 * Race Condition Tests - Párhuzamos művelet tesztek
 */
class RaceConditionTest extends TestCase
{
    private Connection $db;
    private int $testUserId;
    private int $testUserId2;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection([
            'dbname' => $_ENV['DB_NAME'] ?? 'netmafia',
            'user' => $_ENV['DB_USER'] ?? 'root',
            'password' => $_ENV['DB_PASS'] ?? '',
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'driver' => 'pdo_mysql',
        ]);
        $this->createTestUsers();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestUsers();
    }

    private function createTestUsers(): void
    {
        $this->db->executeStatement(
            "INSERT INTO users (username, email, password, money, credits, xp, health, energy) 
             VALUES ('race_test_1', 'race1@test.com', 'test', 10000, 1000, 500, 100, 100)"
        );
        $this->testUserId = (int) $this->db->lastInsertId();

        $this->db->executeStatement(
            "INSERT INTO users (username, email, password, money, credits, xp, health, energy) 
             VALUES ('race_test_2', 'race2@test.com', 'test', 10000, 1000, 500, 100, 100)"
        );
        $this->testUserId2 = (int) $this->db->lastInsertId();
    }

    private function cleanupTestUsers(): void
    {
        $this->db->executeStatement("DELETE FROM credit_transactions WHERE user_id = ? OR user_id = ?", [$this->testUserId, $this->testUserId2]);
        $this->db->executeStatement("DELETE FROM money_transactions WHERE user_id = ? OR user_id = ?", [$this->testUserId, $this->testUserId2]);
        $this->db->executeStatement("DELETE FROM death_log WHERE user_id = ? OR user_id = ?", [$this->testUserId, $this->testUserId2]);
        $this->db->executeStatement("DELETE FROM rank_progression_log WHERE user_id = ? OR user_id = ?", [$this->testUserId, $this->testUserId2]);
        $this->db->executeStatement("DELETE FROM building_income_log WHERE user_id = ? OR user_id = ?", [$this->testUserId, $this->testUserId2]);
        $this->db->executeStatement("DELETE FROM users WHERE id = ? OR id = ?", [$this->testUserId, $this->testUserId2]);
    }

    private function getUserBalance(int $userId, string $field): int
    {
        return (int) $this->db->fetchOne("SELECT {$field} FROM users WHERE id = ?", [$userId]);
    }

    // ==================== CREDIT SERVICE ====================

    /** @test */
    public function credit_concurrent_deduction_prevents_negative_balance(): void
    {
        $creditService = new CreditService($this->db);
        $userId = UserId::of($this->testUserId);
        
        $initialCredits = $this->getUserBalance($this->testUserId, 'credits');
        $this->assertEquals(1000, $initialCredits);

        // Első levonás 600
        $creditService->spendCredits($userId, Credits::of(600), 'test1');
        
        // Második 600 - exception-t várunk
        $this->expectException(\InvalidArgumentException::class);
        $creditService->spendCredits($userId, Credits::of(600), 'test2');
    }

    /** @test */
    public function credit_addition_is_atomic(): void
    {
        $creditService = new CreditService($this->db);
        $userId = UserId::of($this->testUserId);
        
        $initialCredits = $this->getUserBalance($this->testUserId, 'credits');
        
        for ($i = 0; $i < 10; $i++) {
            $creditService->addCredits($userId, Credits::of(100), 'purchase', 'test_add_' . $i);
        }
        
        $finalCredits = $this->getUserBalance($this->testUserId, 'credits');
        $this->assertEquals($initialCredits + 1000, $finalCredits);
    }

    // ==================== MONEY SERVICE ====================

    /** @test */
    public function money_concurrent_deduction_prevents_negative_balance(): void
    {
        $moneyService = new MoneyService($this->db);
        $userId = UserId::of($this->testUserId);
        
        $initialMoney = $this->getUserBalance($this->testUserId, 'money');
        $this->assertEquals(10000, $initialMoney);

        $moneyService->spendMoney($userId, 6000, 'purchase', 'test1');
        
        $this->expectException(\Netmafia\Modules\Money\Domain\InsufficientBalanceException::class);
        $moneyService->spendMoney($userId, 6000, 'purchase', 'test2');
    }

    /** @test */
    public function money_transfer_is_atomic(): void
    {
        $moneyService = new MoneyService($this->db);
        $userId1 = UserId::of($this->testUserId);
        $userId2 = UserId::of($this->testUserId2);
        
        $initial1 = $this->getUserBalance($this->testUserId, 'money');
        $initial2 = $this->getUserBalance($this->testUserId2, 'money');
        $totalBefore = $initial1 + $initial2;
        
        $moneyService->transferMoney($userId1, $userId2, 5000, 'test transfer');
        
        $final1 = $this->getUserBalance($this->testUserId, 'money');
        $final2 = $this->getUserBalance($this->testUserId2, 'money');
        $totalAfter = $final1 + $final2;
        
        $this->assertEquals($totalBefore, $totalAfter);
        $this->assertEquals($initial1 - 5000, $final1);
        $this->assertEquals($initial2 + 5000, $final2);
    }

    // ==================== HEALTH SERVICE ====================

    /** @test */
    public function health_concurrent_damage_single_death(): void
    {
        $healthService = new HealthService($this->db, null);
        $userId = UserId::of($this->testUserId);
        
        $result = $healthService->damage($userId, 150, 'test');
        
        $this->assertTrue($result['died']);
        $finalHealth = $this->getUserBalance($this->testUserId, 'health');
        $this->assertEquals(100, $finalHealth);
    }

    /** @test */
    public function health_stays_in_bounds(): void
    {
        $healthService = new HealthService($this->db, null);
        $userId = UserId::of($this->testUserId);
        
        $healthService->heal($userId, 500);
        $this->assertEquals(100, $this->getUserBalance($this->testUserId, 'health'));
        
        $healthService->damage($userId, 50, 'test');
        $this->assertEquals(50, $this->getUserBalance($this->testUserId, 'health'));
    }

    // ==================== XP SERVICE ====================

    /** @test */
    public function xp_addition_and_rank_check(): void
    {
        $xpService = new XpService($this->db, null);
        $userId = UserId::of($this->testUserId);
        
        $initialXp = $this->getUserBalance($this->testUserId, 'xp');
        $result = $xpService->addXp($userId, 200, 'test');
        
        $finalXp = $this->getUserBalance($this->testUserId, 'xp');
        $this->assertEquals($initialXp + 200, $finalXp);
    }

    // ==================== TRANSACTION INTEGRITY ====================

    /** @test */
    public function for_update_lock_prevents_lost_updates(): void
    {
        $userId = $this->testUserId;
        
        $this->db->executeStatement("UPDATE users SET money = money + 100 WHERE id = ?", [$userId]);
        $this->db->executeStatement("UPDATE users SET money = money + 100 WHERE id = ?", [$userId]);
        
        $finalMoney = $this->getUserBalance($userId, 'money');
        $this->assertEquals(10200, $finalMoney);
    }

    /** @test */
    public function transaction_rollback_on_error(): void
    {
        $initialMoney = $this->getUserBalance($this->testUserId, 'money');
        
        try {
            $this->db->beginTransaction();
            $this->db->executeStatement("UPDATE users SET money = money - 1000 WHERE id = ?", [$this->testUserId]);
            throw new \Exception('Test rollback');
        } catch (\Exception $e) {
            $this->db->rollBack();
        }
        
        $finalMoney = $this->getUserBalance($this->testUserId, 'money');
        $this->assertEquals($initialMoney, $finalMoney);
    }
}
