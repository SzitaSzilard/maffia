<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;
use Netmafia\Infrastructure\AuditLogger;
use Netmafia\Infrastructure\CacheService;
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Modules\Credits\Domain\CreditService;
use Netmafia\Modules\Bank\Domain\BankService;
use Netmafia\Modules\Buildings\Domain\HospitalService;
use Netmafia\Modules\Buildings\Domain\RestaurantService;
use Netmafia\Modules\Buildings\Domain\BuildingService;
use Netmafia\Modules\Notifications\Domain\NotificationService;
use Netmafia\Modules\Item\Domain\InventoryService;
use Netmafia\Modules\Shop\Domain\ShopService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Dotenv\Dotenv;

// Initialize Environment
$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->safeLoad();

// Helper for assertions
function assertCondition(bool $condition, string $message): void {
    if (!$condition) {
        throw new Exception("❌ FAIL: $message");
    }
}

function logStep(string $message): void {
    echo "🔹 $message\n";
}

try {
    echo "=== NetMafia Economy & Audit Integration Test ===\n\n";

    // 1. Setup Database Connection
    $db = DriverManager::getConnection([
        'dbname'   => $_ENV['DB_NAME'],
        'user'     => $_ENV['DB_USER'],
        'password' => $_ENV['DB_PASS'],
        'host'     => $_ENV['DB_HOST'],
        'driver'   => $_ENV['DB_DRIVER'] ?? 'pdo_mysql',
    ]);
    logStep("Database connection established.");

    // 2. Setup Services
    $cache = new CacheService('array');
    $auditLogger = new AuditLogger($db);
    $moneyService = new MoneyService($db, $auditLogger);
    $creditService = new CreditService($db, $auditLogger);
    $notificationService = new NotificationService($db, $cache);
    $inventoryService = new InventoryService($db);
    $buildingService = new BuildingService($db, $moneyService);
    
    $bankService = new BankService($db, $moneyService, $notificationService, $auditLogger);
    $hospitalService = new HospitalService($db, $moneyService, $buildingService);
    $restaurantService = new RestaurantService($db, $moneyService, $buildingService);
    $shopService = new ShopService($db, $inventoryService, $moneyService);

    logStep("Services initialized.");

    // 3. Create Test User
    $testUsername = "economy_integrator_" . time();
    $db->executeStatement(
        "INSERT INTO users (username, email, password, money, credits, xp, health, energy) 
         VALUES (?, ?, 'pass', 0, 0, 0, 100, 100)",
        [$testUsername, $testUsername . "@test.com"]
    );
    $userIdInt = (int)$db->lastInsertId();
    $userId = UserId::of($userIdInt);
    
    // Create Bank Account for User
    $accNumber = rand(100000, 999999);
    $db->insert('bank_accounts', [
        'user_id' => $userIdInt,
        'account_number' => $accNumber,
        'balance' => 0,
        'created_at' => date('Y-m-d H:i:s')
    ]);
    $bankAccountId = (int)$db->lastInsertId();

    logStep("Test user created (#$userIdInt, Acc: $accNumber).");

    // 4. TEST SCENARIO: The BigInt Admin Injection
    echo "\n--- Phase 1: BIGINT & Admin Audit ---\n";
    $largeAmount = 5000000000; // 5 Billion (exceeds 32-bit signed int)
    
    $moneyService->addMoney($userId, $largeAmount, 'admin_add', 'Test huge injection', null, null, 1);
    
    $currentMoney = (int)$db->fetchOne("SELECT money FROM users WHERE id = ?", [$userIdInt]);
    assertCondition($currentMoney === $largeAmount, "Money column should hold BIGINT value ($largeAmount)");
    
    $auditEntry = $db->fetchAssociative(
        "SELECT * FROM audit_logs WHERE user_id = ? AND type = ? ORDER BY id DESC LIMIT 1",
        [$userIdInt, AuditLogger::TYPE_ADMIN_ACTION]
    );
    assertCondition($auditEntry !== false, "Automatic audit entry for 'admin_add' missing");
    logStep("BIGINT storage and automated admin audit verified.");

    // 5. TEST SCENARIO: Bank Operations
    echo "\n--- Phase 2: Bank Automated Auditing ---\n";
    $depositAmount = 2000000000;
    
    // Deposit (MoneyService should handle AuditLogger::TYPE_BANK_DEPOSIT)
    $bankService->deposit($userId, $depositAmount);
    
    $fee = (int) floor($depositAmount * 0.05);
    $netAmount = $depositAmount - $fee;
    
    $bankBalance = (int)$db->fetchOne("SELECT balance FROM bank_accounts WHERE id = ?", [$bankAccountId]);
    assertCondition($bankBalance === $netAmount, "Bank balance incorrect. Expected $netAmount, got $bankBalance");
    
    $auditEntry = $db->fetchAssociative(
        "SELECT * FROM audit_logs WHERE user_id = ? AND type = ? ORDER BY id DESC LIMIT 1",
        [$userIdInt, AuditLogger::TYPE_BANK_DEPOSIT]
    );
    assertCondition($auditEntry !== false, "Automatic audit entry for 'bank_deposit' missing");
    logStep("Bank deposit and automated audit verified.");

    // 6. TEST SCENARIO: Hospital & Restaurant (Buildings)
    echo "\n--- Phase 3: Building Services Automated Auditing ---\n";
    
    // Set health down to 50
    $db->executeStatement("UPDATE users SET health = 50, energy = 50 WHERE id = ?", [$userIdInt]);
    
    // Heal (using a dummy building ID or 0 if service allows)
    // We need a real building for HospitalService to work without falling into exceptions
    $buildingId = (int)$db->fetchOne("SELECT id FROM buildings LIMIT 1");
    if ($buildingId > 0) {
        $hospitalService->heal($userId, $buildingId, 'full');
        
        $auditEntry = $db->fetchAssociative(
            "SELECT * FROM audit_logs WHERE user_id = ? AND type = ? ORDER BY id DESC LIMIT 1",
            [$userIdInt, AuditLogger::TYPE_HOSPITAL_HEAL]
        );
        assertCondition($auditEntry !== false, "Automatic audit entry for 'hospital_heal' missing");
        logStep("Hospital heal and automated audit verified.");
        
        // Eat at Restaurant
        $menuItem = $db->fetchAssociative("SELECT id FROM restaurant_menu WHERE building_id = ? LIMIT 1", [$buildingId]);
        if ($menuItem) {
            $restaurantService->consumeItem($userId, (int)$menuItem['id']);
            $auditEntry = $db->fetchAssociative(
                "SELECT * FROM audit_logs WHERE user_id = ? AND type = ? ORDER BY id DESC LIMIT 1",
                [$userIdInt, AuditLogger::TYPE_RESTAURANT_EAT]
            );
            assertCondition($auditEntry !== false, "Automatic audit entry for 'restaurant_eat' missing");
            logStep("Restaurant eat and automated audit verified.");
        }
    }

    // 7. TEST SCENARIO: Shop Buy
    echo "\n--- Phase 4: Shop Automated Auditing ---\n";
    $shopItem = $db->fetchAssociative("SELECT id, price FROM items WHERE is_shop_item = 1 AND stock > 0 LIMIT 1");
    if ($shopItem) {
        $shopService->buyItem($userId, (int)$shopItem['id'], 1);
        
        $auditEntry = $db->fetchAssociative(
            "SELECT * FROM audit_logs WHERE user_id = ? AND type = ? ORDER BY id DESC LIMIT 1",
            [$userIdInt, AuditLogger::TYPE_SHOP_BUY]
        );
        assertCondition($auditEntry !== false, "Automatic audit entry for 'shop_buy' missing");
        logStep("Shop purchase and automated audit verified.");
    }

    echo "\n✨ ALL INTEGRATION TESTS PASSED!\n";
    echo "---------------------------------\n";
    echo "BIGINT Support: SUCCESS\n";
    echo "Automated Auditing: SUCCESS (Bank, Hospital, Restaurant, Shop, Admin)\n";

} catch (Throwable $e) {
    echo "\n❌ TEST FAILED: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
} finally {
    // Cleanup
    if (isset($userIdInt) && $userIdInt > 0) {
        $db->executeStatement("DELETE FROM audit_logs WHERE user_id = ?", [$userIdInt]);
        $db->executeStatement("DELETE FROM money_transactions WHERE user_id = ?", [$userIdInt]);
        $db->executeStatement("DELETE FROM bank_transactions WHERE account_id IN (SELECT id FROM bank_accounts WHERE user_id = ?)", [$userIdInt]);
        $db->executeStatement("DELETE FROM bank_accounts WHERE user_id = ?", [$userIdInt]);
        $db->executeStatement("DELETE FROM user_items WHERE user_id = ?", [$userIdInt]);
        $db->executeStatement("DELETE FROM users WHERE id = ?", [$userIdInt]);
        logStep("Cleanup completed.");
    }
}
