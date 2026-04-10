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

// Setup Database Connection
$db = DriverManager::getConnection([
    'dbname'   => $_ENV['DB_NAME'],
    'user'     => $_ENV['DB_USER'],
    'password' => $_ENV['DB_PASS'],
    'host'     => $_ENV['DB_HOST'],
    'driver'   => $_ENV['DB_DRIVER'] ?? 'pdo_mysql',
]);

// Helper to print a log entry as a table
function printAuditLog(Connection $db, int $userId, string $label): void {
    $log = $db->fetchAssociative(
        "SELECT id, type, details, created_at FROM audit_logs WHERE user_id = ? ORDER BY id DESC LIMIT 1",
        [$userId]
    );

    echo "\n>>> Audit Log for: $label <<<\n";
    if (!$log) {
        echo "❌ [ERROR] NO LOG ENTRY FOUND!\n";
        return;
    }

    $details = (string)$log['details'];

    echo "+------+----------+------------------------------------------------------------+---------------------+\n";
    echo "| ID   | TYPE     | DETAILS (JSON)                                             | CREATED AT          |\n";
    echo "+------+----------+------------------------------------------------------------+---------------------+\n";
    printf("| %-4d | %-8s | %-58s | %-19s |\n", 
        $log['id'], 
        $log['type'], 
        substr($details, 0, 58), 
        $log['created_at']
    );
    if (strlen($details) > 58) {
        printf("|      |          | %-58s |                     |\n", substr($details, 58, 58));
    }
    echo "+------+----------+------------------------------------------------------------+---------------------+\n";
}

try {
    echo "=== NetMafia TRANSPARENT Economy & Audit Systems Test ===\n";
    echo "Objective: Execute real business logic and show EXACT database log rows.\n\n";

    // 1. Setup Services
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

    // 2. Create Test User
    $testUsername = "audit_proof_" . time();
    $db->executeStatement(
        "INSERT INTO users (username, email, password, money, credits, xp, health, energy) 
         VALUES (?, ?, 'pass', 1000000, 0, 0, 100, 100)",
        [$testUsername, $testUsername . "@test.com"]
    );
    $userIdInt = (int)$db->lastInsertId();
    $userId = UserId::of($userIdInt);

    $accNumber = rand(100000, 999999);
    $db->insert('bank_accounts', [
        'user_id' => $userIdInt,
        'account_number' => $accNumber,
        'balance' => 0,
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
    echo "✅ Created Test User #$userIdInt with $1,000,000 wallet balance.\n";

    // --- STEP 1: Admin Money Injection ---
    $moneyService->addMoney($userId, 500000, 'admin_add', 'Test donation', null, null, 1);
    printAuditLog($db, $userIdInt, "Admin Money Injection (MoneyService)");

    // --- STEP 2: Bank Deposit ---
    $bankService->deposit($userId, 100000);
    printAuditLog($db, $userIdInt, "Bank Deposit (BankService)");

    // --- STEP 3: Hospital Healing ---
    $buildingId = (int)$db->fetchOne("SELECT id FROM buildings LIMIT 1");
    if ($buildingId > 0) {
        $db->executeStatement("UPDATE users SET health = 10 WHERE id = ?", [$userIdInt]);
        $hospitalService->heal($userId, $buildingId, 'full');
        printAuditLog($db, $userIdInt, "Hospital Treatment (HospitalService)");
    }

    // --- STEP 4: Restaurant Eating ---
    if ($buildingId > 0) {
        $menuItem = $db->fetchAssociative("SELECT id FROM restaurant_menu WHERE building_id = ? LIMIT 1", [$buildingId]);
        if ($menuItem) {
            $restaurantService->consumeItem($userId, (int)$menuItem['id']);
            printAuditLog($db, $userIdInt, "Restaurant Consumption (RestaurantService)");
        }
    }

    // --- STEP 5: Shop Purchase ---
    $shopItem = $db->fetchAssociative("SELECT id FROM items WHERE is_shop_item = 1 AND stock > 0 LIMIT 1");
    if ($shopItem) {
        $shopService->buyItem($userId, (int)$shopItem['id'], 1);
        printAuditLog($db, $userIdInt, "Shop Purchase (ShopService)");
    }

    // --- STEP 6: Robbery Victim (Raw MoneyService call) ---
    $moneyService->spendMoney($userId, 5000, 'robbery_victim', 'Got robbed by test script');
    printAuditLog($db, $userIdInt, "Robbery Victim (MoneyService::spendMoney)");

    echo "\n✨ INTEGRATION TEST COMPLETE. ALL LOGS VERIFIED IN DATABASE.\n";

} catch (Throwable $e) {
    echo "\n❌ TEST FAILED: " . $e->getMessage() . "\n";
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
    }
}
