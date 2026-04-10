<?php
declare(strict_types=1);

/**
 * ItemRaceConditionTest - VALÓDI párhuzamos race condition tesztek
 * 
 * Több PHP process-t futtat egyszerre, hogy valódi versenyhelyzetet szimuláljon.
 * Windows-on proc_open-t használ, Unix-on pcntl_fork-ot.
 * 
 * Futtatás: php tests/ItemRaceConditionTest.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Doctrine\DBAL\DriverManager;

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

$testsPassed = 0;
$testsFailed = 0;

function logDB(string $message): void {
    $timestamp = date('H:i:s.') . substr((string)microtime(true), 11, 3);
    echo "   [$timestamp] $message\n";
}

function test(string $name, callable $fn): void {
    global $testsPassed, $testsFailed;
    
    echo "\n🧪 TEST: $name\n";
    echo str_repeat('-', 70) . "\n";
    
    try {
        $fn();
        $testsPassed++;
        echo "✅ PASS: $name\n";
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
    $username = 'RaceTest_' . uniqid();
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

// ============================================================
// Worker script for subprocess execution
// ============================================================
$workerScript = <<<'PHP'
<?php
// Race condition worker script
// Args: db_name db_user db_pass db_host user_id user_item_id worker_id action

if ($argc < 8) {
    echo json_encode(['error' => 'Invalid arguments']);
    exit(1);
}

$dbName = $argv[1];
$dbUser = $argv[2];
$dbPass = $argv[3];
$dbHost = $argv[4];
$userId = (int)$argv[5];
$userItemId = (int)$argv[6];
$workerId = $argv[7];
$action = $argv[8] ?? 'consume';

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

try {
    $db = DriverManager::getConnection([
        'dbname' => $dbName,
        'user' => $dbUser,
        'password' => $dbPass,
        'host' => $dbHost,
        'driver' => 'pdo_mysql',
        'charset' => 'utf8mb4',
    ]);

    $notificationService = new NotificationService($db, new CacheService('array', []));
    $healthService = new HealthService($db, $notificationService);
    $moneyService = new MoneyService($db);
    $inventoryService = new InventoryService($db);
    $buffService = new BuffService($db);
    
    $itemService = new ItemService(
        $db,
        $inventoryService,
        $buffService,
        $moneyService,
        $healthService
    );

    $userIdObj = UserId::of($userId);
    
    $result = null;
    $success = false;
    $error = null;
    
    // Add small random delay to increase chance of collision
    usleep(rand(0, 50000)); // 0-50ms random delay
    
    switch ($action) {
        case 'consume':
            try {
                $result = $itemService->useConsumable($userIdObj, $userItemId);
                $success = true;
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
            break;
            
        case 'equip':
            try {
                $itemService->equipItem($userIdObj, $userItemId);
                $success = true;
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
            break;
            
        case 'sell':
            try {
                $result = $itemService->sellItem($userIdObj, $userItemId, 1);
                $success = true;
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
            break;
    }
    
    echo json_encode([
        'worker_id' => $workerId,
        'success' => $success,
        'error' => $error,
        'result' => $result,
        'timestamp' => microtime(true)
    ]);
    
} catch (Throwable $e) {
    echo json_encode([
        'worker_id' => $workerId,
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => microtime(true)
    ]);
}
PHP;

// Write worker script
$workerFile = __DIR__ . '/race_worker.php';
file_put_contents($workerFile, $workerScript);

/**
 * Run multiple workers in parallel and collect results
 */
function runParallelWorkers(
    int $workerCount, 
    int $userId, 
    int $userItemId, 
    string $action = 'consume'
): array {
    global $workerFile;
    
    $dbName = $_ENV['DB_NAME'];
    $dbUser = $_ENV['DB_USER'];
    $dbPass = $_ENV['DB_PASS'];
    $dbHost = $_ENV['DB_HOST'];
    
    $processes = [];
    $pipes = [];
    
    // Start all workers simultaneously
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
        
        // Close stdin immediately
        fclose($pipes[$i][0]);
    }
    
    // Collect results
    $results = [];
    for ($i = 0; $i < $workerCount; $i++) {
        $stdout = stream_get_contents($pipes[$i][1]);
        $stderr = stream_get_contents($pipes[$i][2]);
        
        fclose($pipes[$i][1]);
        fclose($pipes[$i][2]);
        
        $exitCode = proc_close($processes[$i]);
        
        $decoded = json_decode($stdout, true);
        if ($decoded) {
            $results[] = $decoded;
        } else {
            $results[] = [
                'worker_id' => "worker_$i",
                'success' => false,
                'error' => "Failed to decode output: $stdout / $stderr",
                'exit_code' => $exitCode
            ];
        }
    }
    
    return $results;
}

echo "\n" . str_repeat('=', 70) . "\n";
echo "🏎️  REAL RACE CONDITION TESTS - PARALLEL PROCESSES\n";
echo str_repeat('=', 70) . "\n";

// ============================================================
// TEST 1: 5 processes fight for 1 item
// ============================================================
test('REAL-RACE-01: 5 process küzd 1 db tárgyért', function() use ($db) {
    $userId = setupTestUser($db);
    
    // Add only 1 Csipsz
    $csipszId = $db->fetchOne("SELECT id FROM items WHERE name = 'Csipsz'");
    $db->insert('user_items', [
        'user_id' => $userId,
        'item_id' => $csipszId,
        'quantity' => 1,
        'equipped' => 0
    ]);
    $userItemId = (int)$db->lastInsertId();
    
    logDB("Created user $userId with 1x Csipsz (user_item_id: $userItemId)");
    logDB("Starting 5 parallel workers...");
    
    $results = runParallelWorkers(5, $userId, $userItemId, 'consume');
    
    // Analyze results
    $successCount = 0;
    $failCount = 0;
    
    foreach ($results as $r) {
        if ($r['success']) {
            $successCount++;
            logDB("Worker {$r['worker_id']}: SUCCESS at {$r['timestamp']}");
        } else {
            $failCount++;
            logDB("Worker {$r['worker_id']}: FAIL - {$r['error']}");
        }
    }
    
    logDB("Results: $successCount success, $failCount fail");
    
    // Check database state - item should be consumed (qty=0 or deleted)
    $remaining = $db->fetchOne("SELECT quantity FROM user_items WHERE id = ?", [$userItemId]);
    $remainingQty = $remaining === false ? 0 : (int)$remaining;
    
    logDB("Remaining quantity in DB: $remainingQty");
    
    // EXACTLY 1 should succeed!
    assertTrue($successCount === 1, "Exactly 1 worker should succeed, got $successCount");
    assertTrue($failCount === 4, "Exactly 4 workers should fail, got $failCount");
    assertTrue($remainingQty === 0, "Item should be consumed, remaining: $remainingQty");
    
    logDB("✓ VERIFIED: Only 1 out of 5 workers consumed the item - race condition handled!");
    cleanupTestUser($db, $userId);
});

// ============================================================
// TEST 2: 3 processes fight for last 2 items (scarce resource)
// ============================================================
test('REAL-RACE-02: 3 process küzd 2 db tárgyért', function() use ($db) {
    $userId = setupTestUser($db);
    
    // Add 2 Csipsz
    $csipszId = $db->fetchOne("SELECT id FROM items WHERE name = 'Csipsz'");
    $db->insert('user_items', [
        'user_id' => $userId,
        'item_id' => $csipszId,
        'quantity' => 2,
        'equipped' => 0
    ]);
    $userItemId = (int)$db->lastInsertId();
    
    logDB("Created user $userId with 2x Csipsz");
    logDB("Starting 3 parallel workers...");
    
    $results = runParallelWorkers(3, $userId, $userItemId, 'consume');
    
    $successCount = array_sum(array_map(fn($r) => $r['success'] ? 1 : 0, $results));
    $failCount = 3 - $successCount;
    
    $remaining = $db->fetchOne("SELECT quantity FROM user_items WHERE id = ?", [$userItemId]);
    $remainingQty = $remaining === false ? 0 : (int)$remaining;
    
    logDB("Success: $successCount, Fail: $failCount, Remaining: $remainingQty");
    
    // EXACTLY 2 should succeed!
    assertTrue($successCount === 2, "Exactly 2 workers should succeed, got $successCount");
    assertTrue($remainingQty === 0, "Items should be consumed, remaining: $remainingQty");
    
    logDB("✓ VERIFIED: Exactly 2 out of 3 workers consumed items!");
    cleanupTestUser($db, $userId);
});

// ============================================================
// TEST 3: Double weapon equip race
// ============================================================
test('REAL-RACE-03: 2 process próbál fegyvert felszerelni egyszerre', function() use ($db) {
    $userId = setupTestUser($db);
    
    // Add 2 different weapons
    $weapon1Id = $db->fetchOne("SELECT id FROM items WHERE type='weapon' ORDER BY id LIMIT 1");
    $weapon2Id = $db->fetchOne("SELECT id FROM items WHERE type='weapon' ORDER BY id LIMIT 1 OFFSET 1");
    
    $db->insert('user_items', ['user_id' => $userId, 'item_id' => $weapon1Id, 'quantity' => 1, 'equipped' => 0]);
    $userItemId1 = (int)$db->lastInsertId();
    
    $db->insert('user_items', ['user_id' => $userId, 'item_id' => $weapon2Id, 'quantity' => 1, 'equipped' => 0]);
    $userItemId2 = (int)$db->lastInsertId();
    
    logDB("Created user with 2 weapons (IDs: $userItemId1, $userItemId2)");
    
    // Start both equip operations in parallel - only one should succeed!
    $processes = [];
    $pipes = [];
    
    global $workerFile;
    $dbName = $_ENV['DB_NAME'];
    $dbUser = $_ENV['DB_USER'];
    $dbPass = $_ENV['DB_PASS'];
    $dbHost = $_ENV['DB_HOST'];
    
    // Worker 1 equips weapon 1
    $cmd1 = "php \"$workerFile\" \"$dbName\" \"$dbUser\" \"$dbPass\" \"$dbHost\" $userId $userItemId1 w1 equip";
    $cmd2 = "php \"$workerFile\" \"$dbName\" \"$dbUser\" \"$dbPass\" \"$dbHost\" $userId $userItemId2 w2 equip";
    
    $descriptorspec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    
    $p1 = proc_open($cmd1, $descriptorspec, $pipes[0]);
    $p2 = proc_open($cmd2, $descriptorspec, $pipes[1]);
    
    fclose($pipes[0][0]);
    fclose($pipes[1][0]);
    
    $out1 = stream_get_contents($pipes[0][1]);
    $out2 = stream_get_contents($pipes[1][1]);
    
    fclose($pipes[0][1]);
    fclose($pipes[0][2]);
    fclose($pipes[1][1]);
    fclose($pipes[1][2]);
    
    proc_close($p1);
    proc_close($p2);
    
    $r1 = json_decode($out1, true) ?? ['success' => false, 'error' => $out1];
    $r2 = json_decode($out2, true) ?? ['success' => false, 'error' => $out2];
    
    logDB("Worker 1: " . ($r1['success'] ? 'SUCCESS' : "FAIL - " . ($r1['error'] ?? 'unknown')));
    logDB("Worker 2: " . ($r2['success'] ? 'SUCCESS' : "FAIL - " . ($r2['error'] ?? 'unknown')));
    
    $successCount = ($r1['success'] ? 1 : 0) + ($r2['success'] ? 1 : 0);
    
    // Check equipped count
    $equippedCount = $db->fetchOne(
        "SELECT COUNT(*) FROM user_items ui JOIN items i ON i.id = ui.item_id 
         WHERE ui.user_id = ? AND ui.equipped = 1 AND i.type = 'weapon'",
        [$userId]
    );
    
    logDB("Equipped weapons: $equippedCount");
    
    assertTrue((int)$equippedCount === 1, "Should have exactly 1 weapon equipped, got $equippedCount");
    assertTrue($successCount === 1, "Exactly 1 equip should succeed, got $successCount");
    
    logDB("✓ VERIFIED: Only 1 weapon equipped despite parallel attempts!");
    cleanupTestUser($db, $userId);
});

// ============================================================
// TEST 4: Sell same item race
// ============================================================
test('REAL-RACE-04: 5 process próbálja eladni ugyanazt a tárgyat', function() use ($db) {
    $userId = setupTestUser($db, 0);  // Start with $0
    
    // Add 1 expensive weapon
    $weaponId = $db->fetchOne("SELECT id FROM items WHERE type='weapon' ORDER BY price DESC LIMIT 1");
    $weaponPrice = $db->fetchOne("SELECT price FROM items WHERE id = ?", [$weaponId]);
    
    $db->insert('user_items', [
        'user_id' => $userId,
        'item_id' => $weaponId,
        'quantity' => 1,
        'equipped' => 0
    ]);
    $userItemId = (int)$db->lastInsertId();
    
    logDB("Created user with 0$ and 1 weapon worth $$weaponPrice");
    logDB("Starting 5 parallel sell attempts...");
    
    $results = runParallelWorkers(5, $userId, $userItemId, 'sell');
    
    $successCount = array_sum(array_map(fn($r) => $r['success'] ? 1 : 0, $results));
    
    foreach ($results as $r) {
        logDB("Worker {$r['worker_id']}: " . ($r['success'] ? "SUCCESS (+\${$r['result']})" : "FAIL - {$r['error']}"));
    }
    
    // Check final money
    $finalMoney = $db->fetchOne("SELECT money FROM users WHERE id = ?", [$userId]);
    logDB("Final money: $$finalMoney");
    
    assertTrue($successCount === 1, "Exactly 1 sell should succeed, got $successCount");
    assertTrue((int)$finalMoney === (int)$weaponPrice, "Should have exactly weapon price, got $finalMoney");
    
    logDB("✓ VERIFIED: Only 1 sale completed, no double-sell exploit!");
    cleanupTestUser($db, $userId);
});

// ============================================================
// TEST 5: High contention - 10 workers, 5 items
// ============================================================
test('REAL-RACE-05: Magas verseny - 10 process, 5 item', function() use ($db) {
    $userId = setupTestUser($db);
    
    $csipszId = $db->fetchOne("SELECT id FROM items WHERE name = 'Csipsz'");
    $db->insert('user_items', [
        'user_id' => $userId,
        'item_id' => $csipszId,
        'quantity' => 5,
        'equipped' => 0
    ]);
    $userItemId = (int)$db->lastInsertId();
    
    logDB("Created user with 5x Csipsz");
    logDB("Starting 10 parallel workers...");
    
    $results = runParallelWorkers(10, $userId, $userItemId, 'consume');
    
    $successCount = array_sum(array_map(fn($r) => $r['success'] ? 1 : 0, $results));
    
    $remaining = $db->fetchOne("SELECT quantity FROM user_items WHERE id = ?", [$userItemId]);
    $remainingQty = $remaining === false ? 0 : (int)$remaining;
    
    logDB("Success: $successCount / 10, Remaining: $remainingQty");
    
    assertTrue($successCount === 5, "Exactly 5 should succeed, got $successCount");
    assertTrue($remainingQty === 0, "All items consumed, remaining: $remainingQty");
    
    logDB("✓ VERIFIED: High contention handled - $successCount/10 succeeded for 5 items!");
    cleanupTestUser($db, $userId);
});

// Cleanup worker file
unlink($workerFile);

// ============================================================
// RESULTS
// ============================================================
echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║            REAL RACE CONDITION TEST RESULTS                          ║\n";
echo "╠══════════════════════════════════════════════════════════════════════╣\n";
printf("║  ✅ Passed: %-3d                                                      ║\n", $testsPassed);
printf("║  ❌ Failed: %-3d                                                      ║\n", $testsFailed);
echo "╠══════════════════════════════════════════════════════════════════════╣\n";

if ($testsFailed === 0) {
    echo "║  🏆 ALL REAL RACE CONDITION TESTS PASSED!                            ║\n";
    echo "║  ✓ FOR UPDATE locks work correctly under concurrent access          ║\n";
} else {
    echo "║  ⚠️  RACE CONDITION VULNERABILITIES DETECTED!                        ║\n";
}
echo "╚══════════════════════════════════════════════════════════════════════╝\n";

exit($testsFailed > 0 ? 1 : 0);
