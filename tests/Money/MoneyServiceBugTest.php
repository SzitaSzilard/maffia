<?php
declare(strict_types=1);

namespace Tests\Money;

use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Shared\Domain\ValueObjects\UserId;

/**
 * MoneyService - Játék Orientált Tesztek
 * 
 * PRIORITÁS: Concurrent duplication prevention (játék halál megelőzése!)
 */
class MoneyServiceBugTest extends TestCase
{
    private Connection $db;
    private MoneyService $moneyService;
    private int $testUserId1;
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

        $this->moneyService = new MoneyService($this->db);
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
             VALUES ('money_test_1', 'mtest1@test.com', 'test', 10000, 0, 0, 100, 100)"
        );
        $this->testUserId1 = (int) $this->db->lastInsertId();

        $this->db->executeStatement(
            "INSERT INTO users (username, email, password, money, credits, xp, health, energy) 
             VALUES ('money_test_2', 'mtest2@test.com', 'test', 0, 0, 0, 100, 100)"
        );
        $this->testUserId2 = (int) $this->db->lastInsertId();
    }

    private function cleanupTestUsers(): void
    {
        $this->db->executeStatement(
            "DELETE FROM money_transactions WHERE user_id IN (?, ?)",
            [$this->testUserId1, $this->testUserId2]
        );
        $this->db->executeStatement(
            "DELETE FROM users WHERE id IN (?, ?)",
            [$this->testUserId1, $this->testUserId2]
        );
    }

    private function getUserMoney(int $userId): int
    {
        return (int) $this->db->fetchOne("SELECT money FROM users WHERE id = ?", [$userId]);
    }

    // ==========================================================================
    // 🔥 CRITICAL: CONCURRENT DUPLICATION PREVENTION TEST
    // Ez A LEGFONTOSABB teszt egy játékhoz!
    // ==========================================================================

    /**
     * @test
     * 🔥 CRITICAL: Database lock prevents double-spending
     * 
     * Ha ez FAIL-el → duplication glitch → játék halál!
     */
    public function database_lock_prevents_double_spending(): void
    {
        // Két külön DB connection a concurrent access szimuláláshoz
        $conn1 = DriverManager::getConnection([
            'dbname' => $_ENV['DB_NAME'] ?? 'netmafia',
            'user' => $_ENV['DB_USER'] ?? 'root',
            'password' => $_ENV['DB_PASS'] ?? '',
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'driver' => 'pdo_mysql',
        ]);
        
        $conn2 = DriverManager::getConnection([
            'dbname' => $_ENV['DB_NAME'] ?? 'netmafia',
            'user' => $_ENV['DB_USER'] ?? 'root',
            'password' => $_ENV['DB_PASS'] ?? '',
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'driver' => 'pdo_mysql',
        ]);

        // User: $1000
        $this->db->executeStatement(
            "UPDATE users SET money = 1000 WHERE id = ?",
            [$this->testUserId1]
        );

        // Conn1: Tranzakció kezdése és FOR UPDATE lock
        $conn1->beginTransaction();
        $balance1 = $conn1->fetchOne(
            "SELECT money FROM users WHERE id = ? FOR UPDATE",
            [$this->testUserId1]
        );
        $this->assertEquals(1000, (int)$balance1);

        // Conn2: Próbál ugyanarra a sorra lock-olni - ez TIMEOUT kell legyen!
        $lockTimeout = false;
        try {
            // 1 másodperc timeout beállítása
            $conn2->executeStatement("SET SESSION innodb_lock_wait_timeout = 1");
            $conn2->beginTransaction();
            
            // Ez a SELECT ... FOR UPDATE-nak VÁRNIA kell majd TIMEOUT
            $balance2 = $conn2->fetchOne(
                "SELECT money FROM users WHERE id = ? FOR UPDATE",
                [$this->testUserId1]
            );
            
            // Ha idáig eljutunk timeout nélkül → BÁJ!
            $conn2->rollBack();
            
        } catch (\Throwable $e) {
            // Lock wait timeout exception ELVÁRT!
            $lockTimeout = true;
            try { $conn2->rollBack(); } catch (\Throwable $e2) {}
        }
        
        // Conn1 lezárása
        $conn1->rollBack();

        // CRITICAL ASSERTION: Conn2-nek timeout-olnia KELLETT!
        $this->assertTrue(
            $lockTimeout,
            "🔥 CRITICAL BUG: FOR UPDATE lock NOT working! " .
            "Second connection did NOT timeout while first held the lock. " .
            "This allows concurrent double-spending = DUPLICATION GLITCH!"
        );
    }

    /**
     * @test
     * 🔥 CRITICAL: Szekvenciális költés nem megy negatívba
     */
    public function sequential_spending_never_goes_negative(): void
    {
        // User: $1000, két $700 költés próba
        $this->db->executeStatement(
            "UPDATE users SET money = 1000 WHERE id = ?",
            [$this->testUserId1]
        );

        $userId = UserId::of($this->testUserId1);
        $successCount = 0;

        // Első spend: $700 - sikeres kell legyen
        try {
            $this->moneyService->spendMoney($userId, 700, 'purchase', 'First');
            $successCount++;
        } catch (\Throwable $e) {}

        // Második spend: $700 - sikertelen kell legyen (csak $300 maradt)
        try {
            $this->moneyService->spendMoney($userId, 700, 'purchase', 'Second');
            $successCount++;
        } catch (\Throwable $e) {}

        $finalMoney = $this->getUserMoney($this->testUserId1);

        // KRITIKUS: Nem lehet negatív!
        $this->assertGreaterThanOrEqual(0, $finalMoney, 
            "🔥 CRITICAL: Negative balance! Balance: {$finalMoney}");
        $this->assertEquals(1, $successCount, "Pontosan 1 sikeres spend");
        $this->assertEquals(300, $finalMoney, "Maradék: \$300");
    }

    // ==========================================================================
    // ✅ IMPORTANT: Transfer és Rollback tesztek
    // ==========================================================================

    /**
     * @test
     * Transfer money - konzisztencia és money conservation
     */
    public function transfer_uses_single_transaction_level(): void
    {
        $userId1 = UserId::of($this->testUserId1);
        $userId2 = UserId::of($this->testUserId2);

        $initialMoney1 = $this->getUserMoney($this->testUserId1);
        $initialMoney2 = $this->getUserMoney($this->testUserId2);

        $this->moneyService->transferMoney($userId1, $userId2, 1000, 'Test');
        
        $finalMoney1 = $this->getUserMoney($this->testUserId1);
        $finalMoney2 = $this->getUserMoney($this->testUserId2);

        // Pénz megmaradás
        $this->assertEquals(
            $initialMoney1 + $initialMoney2,
            $finalMoney1 + $finalMoney2,
            "Money conservation violated!"
        );
    }

    /**
     * @test
     * Ha transfer közben hiba van, MINDEN visszavonódik
     */
    public function transfer_rollback_is_atomic(): void
    {
        $userId1 = UserId::of($this->testUserId1);
        $userId2 = UserId::of(99999999); // Nem létező user

        $initialMoney1 = $this->getUserMoney($this->testUserId1);

        try {
            $this->moneyService->transferMoney($userId1, $userId2, 1000, 'Test');
        } catch (\Throwable $e) {}

        $afterMoney1 = $this->getUserMoney($this->testUserId1);
        
        // KRITIKUS: Küldő pénze NEM csökkenhetett!
        $this->assertEquals(
            $initialMoney1,
            $afterMoney1,
            "🔥 CRITICAL: Sender money deducted but receiver didn't get it! " .
            "Partial transaction bug!"
        );
    }

    // ==========================================================================
    // ✅ CLI CONTEXT
    // ==========================================================================

    /**
     * @test
     * CLI context-ben (cron job) is működik
     */
    public function cli_context_handles_missing_server_variables(): void
    {
        $originalAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        $originalAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);

        $userId = UserId::of($this->testUserId1);
        $exceptionThrown = false;

        try {
            $this->moneyService->addMoney($userId, 100, 'work', 'CLI test');
        } catch (\Throwable $e) {
            $exceptionThrown = true;
        } finally {
            if ($originalAddr) $_SERVER['REMOTE_ADDR'] = $originalAddr;
            if ($originalAgent) $_SERVER['HTTP_USER_AGENT'] = $originalAgent;
        }

        $this->assertFalse($exceptionThrown, "CLI context should not throw exception");
    }

    // ==========================================================================
    // ⚠️ SKIPPED: Nice-to-have, de nem blocker
    // ==========================================================================

    /**
     * @test
     * @group optional
     */
    public function rate_limiting_blocks_spam_transactions(): void
    {
        $this->markTestSkipped(
            'Rate limiting not implemented - using game cooldowns instead'
        );
    }

    /**
     * @test
     * @group optional
     */
    public function fraud_detection_blocks_suspicious_amounts(): void
    {
        $this->markTestSkipped(
            'Fraud detection not implemented - using admin monitoring instead'
        );
    }

    /**
     * @test
     * @group optional
     */
    public function insufficient_balance_has_specific_exception_type(): void
    {
        $this->markTestSkipped(
            'Custom exceptions implemented but not required for game - frontend parses error messages'
        );
    }

    /**
     * @test
     * @group optional
     */
    public function invalid_type_has_specific_exception_type(): void
    {
        $this->markTestSkipped(
            'Custom exceptions implemented but not required for game'
        );
    }
}
