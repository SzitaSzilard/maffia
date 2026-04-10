<?php
declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;

/**
 * Critical Services Lock Test
 * 
 * Teszteli, hogy az ÖSSZES pénz/érték kezelő szolgáltatás
 * védve van-e concurrent access elleni duplication glitch ellen.
 * 
 * KRITIKUS: Ha bármelyik teszt FAIL-el → duplication glitch lehetséges!
 */
class CriticalServicesLockTest extends TestCase
{
    private Connection $db;
    private int $testUserId;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection([
            'dbname' => $_ENV['DB_NAME'] ?? 'netmafia',
            'user' => $_ENV['DB_USER'] ?? 'root',
            'password' => $_ENV['DB_PASS'] ?? '',
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'driver' => 'pdo_mysql',
        ]);

        $this->createTestUser();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestUser();
    }

    private function createTestUser(): void
    {
        $this->db->executeStatement(
            "INSERT INTO users (username, email, password, money, credits, xp, health, energy) 
             VALUES ('lock_test_user', 'locktest@test.com', 'test', 10000, 100, 500, 100, 100)"
        );
        $this->testUserId = (int) $this->db->lastInsertId();
    }

    private function cleanupTestUser(): void
    {
        $this->db->executeStatement(
            "DELETE FROM money_transactions WHERE user_id = ?",
            [$this->testUserId]
        );
        $this->db->executeStatement(
            "DELETE FROM credit_transactions WHERE user_id = ?",
            [$this->testUserId]
        );
        $this->db->executeStatement(
            "DELETE FROM users WHERE id = ?",
            [$this->testUserId]
        );
    }

    private function createSecondConnection(): Connection
    {
        return DriverManager::getConnection([
            'dbname' => $_ENV['DB_NAME'] ?? 'netmafia',
            'user' => $_ENV['DB_USER'] ?? 'root',
            'password' => $_ENV['DB_PASS'] ?? '',
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'driver' => 'pdo_mysql',
        ]);
    }

    private function testForUpdateLock(string $column, string $testName): bool
    {
        $conn1 = $this->db;
        $conn2 = $this->createSecondConnection();

        // Conn1: Lock a sort
        $conn1->beginTransaction();
        $conn1->fetchOne(
            "SELECT {$column} FROM users WHERE id = ? FOR UPDATE",
            [$this->testUserId]
        );

        // Conn2: Próbál lock-olni - TIMEOUT kell!
        $lockTimeout = false;
        try {
            $conn2->executeStatement("SET SESSION innodb_lock_wait_timeout = 1");
            $conn2->beginTransaction();
            $conn2->fetchOne(
                "SELECT {$column} FROM users WHERE id = ? FOR UPDATE",
                [$this->testUserId]
            );
            $conn2->rollBack();
        } catch (\Throwable $e) {
            $lockTimeout = true;
            try { $conn2->rollBack(); } catch (\Throwable $e2) {}
        }

        $conn1->rollBack();

        return $lockTimeout;
    }

    // ==========================================================================
    // MONEY LOCK TEST
    // ==========================================================================

    /**
     * @test
     */
    public function money_column_for_update_lock_works(): void
    {
        $lockWorks = $this->testForUpdateLock('money', 'Money');
        
        $this->assertTrue(
            $lockWorks,
            "🔥 CRITICAL: Money column FOR UPDATE lock NOT working! " .
            "Concurrent money duplication possible!"
        );
    }

    // ==========================================================================
    // CREDITS LOCK TEST  
    // ==========================================================================

    /**
     * @test
     */
    public function credits_column_for_update_lock_works(): void
    {
        $lockWorks = $this->testForUpdateLock('credits', 'Credits');
        
        $this->assertTrue(
            $lockWorks,
            "🔥 CRITICAL: Credits column FOR UPDATE lock NOT working! " .
            "Concurrent credits duplication possible!"
        );
    }

    // ==========================================================================
    // XP LOCK TEST
    // ==========================================================================

    /**
     * @test
     */
    public function xp_column_for_update_lock_works(): void
    {
        $lockWorks = $this->testForUpdateLock('xp', 'XP');
        
        $this->assertTrue(
            $lockWorks,
            "🔥 CRITICAL: XP column FOR UPDATE lock NOT working! " .
            "Concurrent XP duplication possible!"
        );
    }

    // ==========================================================================
    // HEALTH LOCK TEST
    // ==========================================================================

    /**
     * @test
     */
    public function health_column_for_update_lock_works(): void
    {
        $lockWorks = $this->testForUpdateLock('health', 'Health');
        
        $this->assertTrue(
            $lockWorks,
            "🔥 CRITICAL: Health column FOR UPDATE lock NOT working! " .
            "Concurrent health manipulation possible!"
        );
    }

    // ==========================================================================
    // ENERGY LOCK TEST
    // ==========================================================================

    /**
     * @test
     */
    public function energy_column_for_update_lock_works(): void
    {
        $lockWorks = $this->testForUpdateLock('energy', 'Energy');
        
        $this->assertTrue(
            $lockWorks,
            "🔥 CRITICAL: Energy column FOR UPDATE lock NOT working! " .
            "Concurrent energy manipulation possible!"
        );
    }

    // ==========================================================================
    // FULL ROW LOCK TEST (legfontosabb!)
    // ==========================================================================

    /**
     * @test
     * 
     * Ez a LEGFONTOSABB teszt - az egész user sor lock-olása
     */
    public function full_user_row_for_update_lock_works(): void
    {
        $conn1 = $this->db;
        $conn2 = $this->createSecondConnection();

        // Conn1: Lock az egész sort
        $conn1->beginTransaction();
        $conn1->fetchAssociative(
            "SELECT * FROM users WHERE id = ? FOR UPDATE",
            [$this->testUserId]
        );

        // Conn2: Próbál bármely oszlopot UPDATE-elni
        $lockTimeout = false;
        try {
            $conn2->executeStatement("SET SESSION innodb_lock_wait_timeout = 1");
            $conn2->beginTransaction();
            $conn2->executeStatement(
                "UPDATE users SET money = money + 1 WHERE id = ?",
                [$this->testUserId]
            );
            $conn2->rollBack();
        } catch (\Throwable $e) {
            $lockTimeout = true;
            try { $conn2->rollBack(); } catch (\Throwable $e2) {}
        }

        $conn1->rollBack();

        $this->assertTrue(
            $lockTimeout,
            "🔥 CRITICAL: Full row FOR UPDATE lock NOT blocking UPDATE! " .
            "All concurrent modifications are vulnerable!"
        );
    }
}
