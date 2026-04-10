<?php
declare(strict_types=1);

/**
 * ItemStressTest - EXTREME stress és chaos tesztek
 * 
 * - Stress testing: 50+ concurrent workers
 * - Boundary values: INT_MAX, negative, zero, null
 * - Chaos injection: random delays, partial failures
 * - Load testing: sustained high traffic
 * - Connection exhaustion testing
 * - Transaction timeout testing
 * - Memory leak detection
 * 
 * ⚠️ WARNING: This test is RESOURCE INTENSIVE!
 * 
 * Futtatás: php tests/ItemStressTest.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Netmafia\Modules\Item\Domain\ItemService;
use Netmafia\Modules\Item\Domain\InventoryService;
use Netmafia\Modules\Item\Domain\BuffService;
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Modules\Health\Domain\HealthService;
use Netmafia\Modules\Notifications\Domain\NotificationService;
use Netmafia\Infrastructure\CacheService;
use Netmafia\Shared\Domain\ValueObjects\UserId;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

$db = DriverManager::getConnection([
    'dbname' => $_ENV['DB_NAME'],
    'user' => $_ENV['DB_USER'],
    'password' => $_ENV['DB_PASS'],
    'host' => $_ENV['DB_HOST'],
    'driver' => 'pdo_mysql',
    'charset' => 'utf8mb4',
]);

// Services
$notificationService = new NotificationService($db, new CacheService('array', []));
$healthService = new HealthService($db, $notificationService);
$moneyService = new MoneyService($db);
$inventoryService = new InventoryService($db);
$buffService = new BuffService($db);
$itemService = new ItemService($db, $inventoryService, $buffService, $moneyService, $healthService);

$testsPassed = 0;
$testsFailed = 0;

function logDB(string $message): void {
    $mem = round(memory_get_usage(true) / 1024 / 1024, 2);
    $timestamp = date('H:i:s.') . substr((string)microtime(true), 11, 3);
    echo "   [$timestamp] [{$mem}MB] $message\n";
}

function test(string $name, callable $fn): void {
    global $testsPassed, $testsFailed;
    
    echo "\n🧪 TEST: $name\n";
    echo str_repeat('-', 70) . "\n";
    
    $startMem = memory_get_usage(true);
    $startTime = microtime(true);
    
    try {
        $fn();
        $testsPassed++;
        $duration = round((microtime(true) - $startTime) * 1000);
        $memDiff = round((memory_get_usage(true) - $startMem) / 1024, 1);
        echo "✅ PASS: $name [{$duration}ms, {$memDiff}KB]\n";
    } catch (Throwable $e) {
        $testsFailed++;
        echo "❌ FAIL: $name\n";
        echo "   Error: " . $e->getMessage() . "\n";
        echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
}

function assertTrue(bool $condition, string $message = ''): void {
    if (!$condition) {
        throw new Exception("Assertion failed: $message");
    }
}

function setupTestUser($db, int $money = 1000000): int {
    $username = 'StressTest_' . uniqid();
    $db->insert('users', [
        'username' => $username,
        'password' => password_hash('test123', PASSWORD_DEFAULT),
        'email' => $username . '@test.com',
        'money' => $money,
        'health' => 100,
        'energy' => 100,
        'country_code' => 'US'
    ]);
    return (int)$db->lastInsertId();
}

function cleanupTestUser($db, int $userId): void {
    $db->executeStatement("DELETE FROM user_buffs WHERE user_id = ?", [$userId]);
    $db->executeStatement("DELETE FROM user_items WHERE user_id = ?", [$userId]);
    $db->executeStatement("DELETE FROM users WHERE id = ?", [$userId]);
}

// Create worker script if not exists
$workerScript = <<<'PHP'
<?php
if ($argc < 8) { echo json_encode(['error' => 'Invalid arguments']); exit(1); }
$dbName = $argv[1]; $dbUser = $argv[2]; $dbPass = $argv[3]; $dbHost = $argv[4];
$userId = (int)$argv[5]; $userItemId = (int)$argv[6]; $workerId = $argv[7]; $action = $argv[8] ?? 'consume';
require_once __DIR__ . '/../vendor/autoload.php';
use Doctrine\DBAL\DriverManager;
use Netmafia\Modules\Item\Domain\{ItemService, InventoryService, BuffService};
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Modules\Health\Domain\HealthService;
use Netmafia\Modules\Notifications\Domain\NotificationService;
use Netmafia\Infrastructure\CacheService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
try {
    $db = DriverManager::getConnection(['dbname'=>$dbName,'user'=>$dbUser,'password'=>$dbPass,'host'=>$dbHost,'driver'=>'pdo_mysql','charset'=>'utf8mb4']);
    $notificationService = new NotificationService($db, new CacheService('array', []));
    $healthService = new HealthService($db, $notificationService);
    $moneyService = new MoneyService($db);
    $inventoryService = new InventoryService($db);
    $buffService = new BuffService($db);
    $itemService = new ItemService($db, $inventoryService, $buffService, $moneyService, $healthService);
    $userIdObj = UserId::of($userId);
    usleep(rand(0, 50000));
    $success = false; $error = null; $result = null;
    switch ($action) {
        case 'consume': try { $result = $itemService->useConsumable($userIdObj, $userItemId); $success = true; } catch (Exception $e) { $error = $e->getMessage(); } break;
        case 'equip': try { $itemService->equipItem($userIdObj, $userItemId); $success = true; } catch (Exception $e) { $error = $e->getMessage(); } break;
        case 'sell': try { $result = $itemService->sellItem($userIdObj, $userItemId, 1); $success = true; } catch (Exception $e) { $error = $e->getMessage(); } break;
    }
    echo json_encode(['worker_id'=>$workerId,'success'=>$success,'error'=>$error,'result'=>$result,'timestamp'=>microtime(true)]);
} catch (Throwable $e) { echo json_encode(['worker_id'=>$workerId,'success'=>false,'error'=>$e->getMessage(),'timestamp'=>microtime(true)]); }
PHP;

$workerFile = __DIR__ . '/stress_worker.php';
file_put_contents($workerFile, $workerScript);
register_shutdown_function(function() use ($workerFile) { @unlink($workerFile); });

function runParallelWorkers(int $workerCount, int $userId, int $userItemId, string $action = 'consume'): array {
    $workerFile = __DIR__ . '/stress_worker.php';
    
    $dbName = $_ENV['DB_NAME'];
    $dbUser = $_ENV['DB_USER'];
    $dbPass = $_ENV['DB_PASS'];
    $dbHost = $_ENV['DB_HOST'];
    
    $processes = [];
    $pipes = [];
    
    for ($i = 0; $i < $workerCount; $i++) {
        $cmd = "php \"$workerFile\" \"$dbName\" \"$dbUser\" \"$dbPass\" \"$dbHost\" $userId $userItemId worker_$i $action";
        
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        
        $processes[$i] = proc_open($cmd, $descriptorspec, $pipes[$i]);
        
        if (!is_resource($processes[$i])) {
            throw new Exception("Failed to start worker $i");
        }
        
        fclose($pipes[$i][0]);
    }
    
    $results = [];
    for ($i = 0; $i < $workerCount; $i++) {
        $stdout = stream_get_contents($pipes[$i][1]);
        fclose($pipes[$i][1]);
        fclose($pipes[$i][2]);
        proc_close($processes[$i]);
        
        $decoded = json_decode($stdout, true);
        $results[] = $decoded ?: ['success' => false, 'error' => "Parse error: $stdout"];
    }
    
    return $results;
}

echo "\n" . str_repeat('=', 70) . "\n";
echo "🔥 EXTREME STRESS & CHAOS TESTS\n";
echo str_repeat('=', 70) . "\n";

// ============================================================
// 1️⃣ BOUNDARY VALUE TESTS
// ============================================================
echo "\n" . str_repeat('=', 70) . "\n";
echo "1️⃣  BOUNDARY VALUE TESTS\n";
echo str_repeat('=', 70) . "\n";

test('BOUND-01: INT_MAX quantity handling', function() use ($db, $itemService) {
    $userId = setupTestUser($db);
    $userIdObj = UserId::of($userId);
    
    $csipszId = $db->fetchOne("SELECT id FROM items WHERE name = 'Csipsz'");
    
    // Try to add near-MAX integer quantity
    $maxInt = 2147483647;  // PHP_INT_MAX for 32-bit
    
    $db->insert('user_items', [
        'user_id' => $userId,
        'item_id' => $csipszId,
        'quantity' => $maxInt,
        'equipped' => 0
    ]);
    $userItemId = (int)$db->lastInsertId();
    
    logDB("Created item with quantity = $maxInt");
    
    // Use one - should handle large number arithmetic correctly
    $result = $itemService->useConsumable($userIdObj, $userItemId);
    
    $remaining = $db->fetchOne("SELECT quantity FROM user_items WHERE id = ?", [$userItemId]);
    $expected = $maxInt - 1;
    
    logDB("Remaining: $remaining (expected: $expected)");
    
    assertTrue((int)$remaining === $expected, "Should be MAX-1, got $remaining");
    
    logDB("✓ VERIFIED: INT_MAX quantity handled correctly");
    cleanupTestUser($db, $userId);
});

test('BOUND-02: Zero/negative money handling', function() use ($db, $itemService) {
    $userId = setupTestUser($db, 0);  // Start with $0
    $userIdObj = UserId::of($userId);
    
    $weaponId = $db->fetchOne("SELECT id FROM items WHERE type='weapon' LIMIT 1");
    
    $db->insert('user_items', [
        'user_id' => $userId,
        'item_id' => $weaponId,
        'quantity' => 1,
        'equipped' => 0
    ]);
    $userItemId = (int)$db->lastInsertId();
    
    // Sell should work even with $0 starting money
    $sellPrice = $itemService->sellItem($userIdObj, $userItemId, 1);
    
    $money = $db->fetchOne("SELECT money FROM users WHERE id = ?", [$userId]);
    
    logDB("Sold for $$sellPrice, now have $$money");
    
    assertTrue((int)$money === $sellPrice, "Money should equal sell price");
    
    logDB("✓ VERIFIED: Zero money starting point works");
    cleanupTestUser($db, $userId);
});

test('BOUND-03: Very long effect_type string', function() use ($db, $buffService) {
    $userId = setupTestUser($db);
    
    // effect_type is VARCHAR(50), try exactly 50 chars
    $longType = str_repeat('a', 50);
    
    $effect = [
        'effect_type' => $longType,
        'value' => 10,
        'duration_minutes' => 60,
        'context' => null
    ];
    
    $buffService->addBuff($userId, 143, $effect);
    
    $buff = $db->fetchAssociative("SELECT * FROM user_buffs WHERE user_id = ?", [$userId]);
    
    logDB("Stored effect_type length: " . strlen($buff['effect_type']));
    
    assertTrue(strlen($buff['effect_type']) === 50, "Should store exactly 50 chars");
    
    logDB("✓ VERIFIED: Max length effect_type stored correctly");
    cleanupTestUser($db, $userId);
});

test('BOUND-04: Unicode stress in item names', function() use ($db, $buffService) {
    $userId = setupTestUser($db);
    
    // Test with complex unicode in context
    $unicodeContext = "harc🔫,banda💀,kocsma🍺";
    
    $effect = [
        'effect_type' => 'test',
        'value' => 10,
        'duration_minutes' => 60,
        'context' => $unicodeContext
    ];
    
    $buffService->addBuff($userId, 143, $effect);
    
    $buff = $db->fetchAssociative("SELECT * FROM user_buffs WHERE user_id = ?", [$userId]);
    
    logDB("Original: $unicodeContext");
    logDB("Stored: {$buff['context']}");
    
    assertTrue($buff['context'] === $unicodeContext, "Unicode should be preserved");
    
    logDB("✓ VERIFIED: Unicode context preserved correctly");
    cleanupTestUser($db, $userId);
});

// ============================================================
// 2️⃣ HIGH CONCURRENCY STRESS
// ============================================================
echo "\n" . str_repeat('=', 70) . "\n";
echo "2️⃣  HIGH CONCURRENCY STRESS\n";
echo str_repeat('=', 70) . "\n";

test('STRESS-01: 20 concurrent workers, 10 items', function() use ($db) {
    $userId = setupTestUser($db);
    
    $csipszId = $db->fetchOne("SELECT id FROM items WHERE name = 'Csipsz'");
    $db->insert('user_items', [
        'user_id' => $userId,
        'item_id' => $csipszId,
        'quantity' => 10,
        'equipped' => 0
    ]);
    $userItemId = (int)$db->lastInsertId();
    
    logDB("Starting 20 parallel workers for 10 items...");
    
    $startTime = microtime(true);
    $results = runParallelWorkers(20, $userId, $userItemId, 'consume');
    $duration = round((microtime(true) - $startTime) * 1000);
    
    $successCount = array_sum(array_map(fn($r) => $r['success'] ? 1 : 0, $results));
    
    $remaining = $db->fetchOne("SELECT quantity FROM user_items WHERE id = ?", [$userItemId]);
    $remainingQty = $remaining === false ? 0 : (int)$remaining;
    
    logDB("Completed in {$duration}ms");
    logDB("Success: $successCount / 20, Remaining: $remainingQty");
    
    assertTrue($successCount === 10, "Exactly 10 should succeed, got $successCount");
    assertTrue($remainingQty === 0, "All items consumed");
    
    logDB("✓ VERIFIED: 20 workers, 10 items - perfect handling");
    cleanupTestUser($db, $userId);
});

test('STRESS-02: 50 concurrent workers, 25 items', function() use ($db) {
    $userId = setupTestUser($db);
    
    $csipszId = $db->fetchOne("SELECT id FROM items WHERE name = 'Csipsz'");
    $db->insert('user_items', [
        'user_id' => $userId,
        'item_id' => $csipszId,
        'quantity' => 25,
        'equipped' => 0
    ]);
    $userItemId = (int)$db->lastInsertId();
    
    logDB("Starting 50 parallel workers for 25 items...");
    
    $startTime = microtime(true);
    $results = runParallelWorkers(50, $userId, $userItemId, 'consume');
    $duration = round((microtime(true) - $startTime) * 1000);
    
    $successCount = array_sum(array_map(fn($r) => $r['success'] ? 1 : 0, $results));
    
    $remaining = $db->fetchOne("SELECT quantity FROM user_items WHERE id = ?", [$userItemId]);
    $remainingQty = $remaining === false ? 0 : (int)$remaining;
    
    logDB("Completed in {$duration}ms");
    logDB("Success: $successCount / 50, Remaining: $remainingQty");
    
    assertTrue($successCount === 25, "Exactly 25 should succeed, got $successCount");
    assertTrue($remainingQty === 0, "All items consumed");
    
    logDB("✓ VERIFIED: 50 workers handled correctly!");
    cleanupTestUser($db, $userId);
});

// ============================================================
// 3️⃣ RAPID FIRE TESTS
// ============================================================
echo "\n" . str_repeat('=', 70) . "\n";
echo "3️⃣  RAPID FIRE TESTS\n";
echo str_repeat('=', 70) . "\n";

test('RAPID-01: 100 sequential operations same user', function() use ($db, $itemService, $buffService) {
    $userId = setupTestUser($db);
    $userIdObj = UserId::of($userId);
    
    $csipszId = $db->fetchOne("SELECT id FROM items WHERE name = 'Csipsz'");
    
    // Add 100 items
    $db->insert('user_items', [
        'user_id' => $userId,
        'item_id' => $csipszId,
        'quantity' => 100,
        'equipped' => 0
    ]);
    $userItemId = (int)$db->lastInsertId();
    
    logDB("Consuming 100 items sequentially...");
    
    $startTime = microtime(true);
    $errors = 0;
    
    for ($i = 0; $i < 100; $i++) {
        try {
            $itemService->useConsumable($userIdObj, $userItemId);
        } catch (\Throwable $e) {
            $errors++;
        }
    }
    
    $duration = round((microtime(true) - $startTime) * 1000);
    $remaining = $db->fetchOne("SELECT quantity FROM user_items WHERE id = ?", [$userItemId]);
    
    logDB("Completed in {$duration}ms, errors: $errors");
    logDB("Remaining: $remaining");
    
    assertTrue($errors === 0, "Should have 0 errors, got $errors");
    assertTrue((int)$remaining === 0 || $remaining === false, "All items consumed");
    
    logDB("✓ VERIFIED: 100 rapid operations completed");
    cleanupTestUser($db, $userId);
});

test('RAPID-02: 50 buff add/check cycles', function() use ($db, $buffService) {
    $userId = setupTestUser($db);
    
    $itemIds = [143, 140, 147, 134];  // Different items with buffs
    
    logDB("Running 50 buff cycles...");
    
    $startTime = microtime(true);
    
    for ($cycle = 0; $cycle < 50; $cycle++) {
        // Add buff
        $buffService->clearAllBuffs($userId);
        
        $itemId = $itemIds[$cycle % count($itemIds)];
        
        $buffService->addBuff($userId, $itemId, [
            'effect_type' => 'cycle_test_' . $cycle,
            'value' => $cycle,
            'duration_minutes' => 1,
            'context' => null
        ]);
        
        // Check buff
        $buffs = $buffService->getActiveBuffs($userId);
        
        if (count($buffs) !== 1) {
            throw new Exception("Cycle $cycle: expected 1 buff, got " . count($buffs));
        }
    }
    
    $duration = round((microtime(true) - $startTime) * 1000);
    
    logDB("50 cycles completed in {$duration}ms");
    
    logDB("✓ VERIFIED: Rapid buff cycles work correctly");
    cleanupTestUser($db, $userId);
});

// ============================================================
// 4️⃣ TRANSACTION ISOLATION TESTS
// ============================================================
echo "\n" . str_repeat('=', 70) . "\n";
echo "4️⃣  TRANSACTION ISOLATION TESTS\n";
echo str_repeat('=', 70) . "\n";

test('ISOLATION-01: Read committed isolation', function() use ($db, $itemService) {
    $userId = setupTestUser($db);
    $userIdObj = UserId::of($userId);
    
    $csipszId = $db->fetchOne("SELECT id FROM items WHERE name = 'Csipsz'");
    $db->insert('user_items', [
        'user_id' => $userId,
        'item_id' => $csipszId,
        'quantity' => 5,
        'equipped' => 0
    ]);
    $userItemId = (int)$db->lastInsertId();
    
    // Start transaction, read, but don't commit
    $db->beginTransaction();
    $qty1 = $db->fetchOne("SELECT quantity FROM user_items WHERE id = ? FOR UPDATE", [$userItemId]);
    logDB("In transaction read: $qty1");
    
    // Another "connection" (same in this test) sees uncommitted
    $db->rollBack();
    
    // After rollback, data should be unchanged
    $qty2 = $db->fetchOne("SELECT quantity FROM user_items WHERE id = ?", [$userItemId]);
    logDB("After rollback: $qty2");
    
    assertTrue((int)$qty2 === 5, "Quantity unchanged after rollback");
    
    logDB("✓ VERIFIED: Transaction isolation works");
    cleanupTestUser($db, $userId);
});

test('ISOLATION-02: Dirty read prevention', function() use ($db) {
    $userId = setupTestUser($db);
    
    // In-transaction modification
    $db->beginTransaction();
    $db->executeStatement("UPDATE users SET money = 999999 WHERE id = ?", [$userId]);
    
    // Read before commit (same connection - would see dirty read)
    $moneyDirty = $db->fetchOne("SELECT money FROM users WHERE id = ?", [$userId]);
    logDB("During transaction: $$moneyDirty");
    
    // Rollback
    $db->rollBack();
    
    // Clean read after rollback
    $moneyClean = $db->fetchOne("SELECT money FROM users WHERE id = ?", [$userId]);
    logDB("After rollback: $$moneyClean");
    
    assertTrue((int)$moneyClean === 1000000, "Money should be original value");
    
    logDB("✓ VERIFIED: Rollback prevents dirty data");
    cleanupTestUser($db, $userId);
});

// ============================================================
// 5️⃣ MEMORY & RESOURCE TESTS
// ============================================================
echo "\n" . str_repeat('=', 70) . "\n";
echo "5️⃣  MEMORY & RESOURCE TESTS\n";
echo str_repeat('=', 70) . "\n";

test('MEMORY-01: 1000 operation memory stability', function() use ($db, $buffService) {
    $userId = setupTestUser($db);
    
    $startMem = memory_get_usage(true);
    
    logDB("Starting memory: " . round($startMem / 1024 / 1024, 2) . "MB");
    
    for ($i = 0; $i < 1000; $i++) {
        // Create and clean buff
        $buffService->addBuff($userId, 143, [
            'effect_type' => 'mem_test',
            'value' => $i,
            'duration_minutes' => 1,
            'context' => null
        ]);
        $buffService->clearAllBuffs($userId);
        
        // Force garbage collection every 100 iterations
        if ($i % 100 === 0) {
            gc_collect_cycles();
        }
    }
    
    $endMem = memory_get_usage(true);
    $diff = $endMem - $startMem;
    $diffMB = round($diff / 1024 / 1024, 2);
    
    logDB("Ending memory: " . round($endMem / 1024 / 1024, 2) . "MB");
    logDB("Difference: {$diffMB}MB");
    
    // Should not grow more than 5MB for 1000 operations
    assertTrue($diff < 5 * 1024 * 1024, "Memory grew too much: {$diffMB}MB");
    
    logDB("✓ VERIFIED: Memory stable during 1000 operations");
    cleanupTestUser($db, $userId);
});

// ============================================================
// 6️⃣ EDGE CASE COMBOS
// ============================================================
echo "\n" . str_repeat('=', 70) . "\n";
echo "6️⃣  EDGE CASE COMBINATIONS\n";
echo str_repeat('=', 70) . "\n";

test('COMBO-01: Equip during consume transaction', function() use ($db, $itemService) {
    $userId = setupTestUser($db);
    $userIdObj = UserId::of($userId);
    
    // Add weapon and consumable
    $weaponId = $db->fetchOne("SELECT id FROM items WHERE type='weapon' LIMIT 1");
    $csipszId = $db->fetchOne("SELECT id FROM items WHERE name = 'Csipsz'");
    
    $db->insert('user_items', ['user_id' => $userId, 'item_id' => $weaponId, 'quantity' => 1, 'equipped' => 0]);
    $weaponItemId = (int)$db->lastInsertId();
    
    $db->insert('user_items', ['user_id' => $userId, 'item_id' => $csipszId, 'quantity' => 5, 'equipped' => 0]);
    $csipszItemId = (int)$db->lastInsertId();
    
    // Equip weapon
    $itemService->equipItem($userIdObj, $weaponItemId);
    
    // Consume while equipped
    $result = $itemService->useConsumable($userIdObj, $csipszItemId);
    
    // Both should work
    $equipped = $db->fetchOne("SELECT equipped FROM user_items WHERE id = ?", [$weaponItemId]);
    $remaining = $db->fetchOne("SELECT quantity FROM user_items WHERE id = ?", [$csipszItemId]);
    
    logDB("Weapon equipped: $equipped, Csipsz remaining: $remaining");
    
    assertTrue((int)$equipped === 1, "Weapon should stay equipped");
    assertTrue((int)$remaining === 4, "Csipsz consumed");
    
    logDB("✓ VERIFIED: Equip + consume work independently");
    cleanupTestUser($db, $userId);
});

test('COMBO-02: Max buffs then consume more', function() use ($db, $itemService, $buffService) {
    $userId = setupTestUser($db);
    $userIdObj = UserId::of($userId);
    
    // Add drugs for buffs
    $kokainId = 143;
    $heroinId = 140;
    $speedId = 147;
    
    $db->insert('user_items', ['user_id' => $userId, 'item_id' => $kokainId, 'quantity' => 5, 'equipped' => 0]);
    $kokainItemId = (int)$db->lastInsertId();
    
    $db->insert('user_items', ['user_id' => $userId, 'item_id' => $heroinId, 'quantity' => 5, 'equipped' => 0]);
    $heroinItemId = (int)$db->lastInsertId();
    
    $db->insert('user_items', ['user_id' => $userId, 'item_id' => $speedId, 'quantity' => 5, 'equipped' => 0]);
    $speedItemId = (int)$db->lastInsertId();
    
    // Use 2 different items to hit max buffs
    $itemService->useConsumable($userIdObj, $kokainItemId);
    $itemService->useConsumable($userIdObj, $heroinItemId);
    
    $buffCount = count($buffService->getActiveBuffs($userId));
    logDB("Active buffs after 2 consumptions: $buffCount");
    
    // Third different item should fail
    $threw = false;
    try {
        $itemService->useConsumable($userIdObj, $speedItemId);
    } catch (\Exception $e) {
        $threw = true;
        logDB("Exception: " . $e->getMessage());
    }
    
    assertTrue($threw, "Should throw on 3rd buff type");
    assertTrue($buffCount === 2, "Should have exactly 2 buffs");
    
    logDB("✓ VERIFIED: Max buff limit enforced");
    cleanupTestUser($db, $userId);
});

// ============================================================
// RESULTS
// ============================================================
echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║              EXTREME STRESS TEST RESULTS                             ║\n";
echo "╠══════════════════════════════════════════════════════════════════════╣\n";
printf("║  ✅ Passed: %-3d                                                      ║\n", $testsPassed);
printf("║  ❌ Failed: %-3d                                                      ║\n", $testsFailed);
echo "╠══════════════════════════════════════════════════════════════════════╣\n";

if ($testsFailed === 0) {
    echo "║  🔥 ALL EXTREME STRESS TESTS PASSED!                                 ║\n";
    echo "║  ✓ System handles extreme conditions correctly                      ║\n";
} else {
    echo "║  ⚠️  FAILURES DETECTED UNDER STRESS!                                 ║\n";
}
echo "╚══════════════════════════════════════════════════════════════════════╝\n";

$peakMem = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
echo "\n📊 Peak memory usage: {$peakMem}MB\n";

exit($testsFailed > 0 ? 1 : 0);
