<?php
/**
 * Property & Sleep Integration Test - NetMafia
 * 
 * Tesztek:
 * 1. Ingatlan vásárlás (összes típus)
 * 2. Nincs pénz → nem tud venni
 * 3. Ország korlátozás → rossz országban nem tud venni
 * 4. Eladás → 60% visszakapás
 * 5. Alvás: 1 óra → teljes regeneráció
 * 6. Alvás: 0.5 óra → részarányos regeneráció
 * 
 * Futtatás: php tests/PropertySleepTest.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

use Doctrine\DBAL\DriverManager;
use Netmafia\Modules\Home\Domain\PropertyService;
use Netmafia\Modules\Home\Domain\SleepService;
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Modules\Health\Domain\HealthService;
use Netmafia\Shared\Domain\ValueObjects\UserId;

// Test state
$passed = 0;
$failed = 0;
$logs = [];

function logDB(string $message): void {
    global $logs;
    $timestamp = date('H:i:s');
    $logs[] = "[$timestamp] $message";
    echo "   📝 $message\n";
}

function test(string $name, callable $testFn): void {
    global $passed, $failed;
    echo "\n🧪 TEST: $name\n";
    echo str_repeat('-', 60) . "\n";
    try {
        $testFn();
        echo "✅ PASS: $name\n";
        $passed++;
    } catch (Throwable $e) {
        echo "❌ FAIL: $name\n";
        echo "   Error: " . $e->getMessage() . "\n";
        echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
        $failed++;
    }
}

function assertEquals($expected, $actual, string $message = ''): void {
    if ($expected !== $actual) {
        throw new Exception("Expected: " . var_export($expected, true) . ", Got: " . var_export($actual, true) . " $message");
    }
}

function assertGreaterThan($min, $actual, string $message = ''): void {
    if ($actual <= $min) {
        throw new Exception("Expected > $min, Got: $actual. $message");
    }
}

function assertLessThanOrEqual($max, $actual, string $message = ''): void {
    if ($actual > $max) {
        throw new Exception("Expected <= $max, Got: $actual. $message");
    }
}

function assertTrue($value, string $message = ''): void {
    if ($value !== true) {
        throw new Exception("Expected true, got: " . var_export($value, true) . " $message");
    }
}

function assertFalse($value, string $message = ''): void {
    if ($value !== false) {
        throw new Exception("Expected false, got: " . var_export($value, true) . " $message");
    }
}

// Database connection
$db = DriverManager::getConnection([
    'dbname' => $_ENV['DB_NAME'],
    'user' => $_ENV['DB_USER'],
    'password' => $_ENV['DB_PASS'],
    'host' => $_ENV['DB_HOST'],
    'driver' => $_ENV['DB_DRIVER'] ?? 'pdo_mysql',
    'charset' => 'utf8mb4',
]);

// Create services
$moneyService = new MoneyService($db);
$propertyService = new PropertyService($db, $moneyService);
$healthService = new HealthService($db);
$sleepService = new SleepService($db, $propertyService, $healthService);

// Test user ID - create fresh test user
$testUserId = null;

function setupTestUser($db): int {
    global $testUserId;
    
    // Clean up previous test data
    $db->executeStatement("DELETE FROM users WHERE username = 'property_test_user'");
    
    // Create test user with plenty of money
    $db->insert('users', [
        'username' => 'property_test_user',
        'email' => 'property_test@test.com',
        'password' => password_hash('test123', PASSWORD_DEFAULT),
        'money' => 50000000, // 50 million
        'health' => 50,
        'energy' => 50,
        'xp' => 1000,
        'country_code' => 'US'
    ]);
    
    $testUserId = (int)$db->lastInsertId();
    logDB("Created test user ID: $testUserId with $50,000,000");
    
    return $testUserId;
}

function cleanupTestUser($db, int $userId): void {
    $db->executeStatement("DELETE FROM user_sleep WHERE user_id = ?", [$userId]);
    $db->executeStatement("DELETE FROM user_properties WHERE user_id = ?", [$userId]);
    $db->executeStatement("DELETE FROM users WHERE id = ?", [$userId]);
    logDB("Cleaned up test user ID: $userId");
}

function getUserData($db, int $userId): array {
    return $db->fetchAssociative("SELECT * FROM users WHERE id = ?", [$userId]);
}

function getPropertyData($db, int $userId): ?array {
    return $db->fetchAssociative(
        "SELECT up.*, p.name, p.sleep_health_regen_percent, p.sleep_energy_regen_percent, p.price 
         FROM user_properties up 
         JOIN properties p ON p.id = up.property_id 
         WHERE up.user_id = ?",
        [$userId]
    ) ?: null;
}

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║     PROPERTY & SLEEP MODULE - INTEGRATION TEST SUITE        ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
echo "║  Database: " . $_ENV['DB_NAME'] . str_repeat(' ', 48 - strlen($_ENV['DB_NAME'])) . "║\n";
echo "║  Time: " . date('Y-m-d H:i:s') . str_repeat(' ', 40) . "║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";

// Get all available properties
$properties = $db->fetchAllAssociative("SELECT * FROM properties ORDER BY price ASC");
logDB("Loaded " . count($properties) . " property types from database");

echo "\n📋 AVAILABLE PROPERTIES:\n";
echo str_repeat('=', 90) . "\n";
printf("%-3s %-20s %-8s %-8s %-10s %-10s %-12s\n", 
    "ID", "Name", "Country", "Garage", "Health%", "Energy%", "Price");
echo str_repeat('-', 90) . "\n";
foreach ($properties as $p) {
    printf("%-3d %-20s %-8s %-8d %-10d %-10d $%-11s\n",
        $p['id'],
        substr($p['name'], 0, 20),
        $p['country_restriction'] ?? 'ANY',
        $p['garage_capacity'],
        $p['sleep_health_regen_percent'],
        $p['sleep_energy_regen_percent'],
        number_format($p['price'])
    );
}
echo str_repeat('=', 90) . "\n";

// ============================================================
// TEST 1: PURCHASE ALL PROPERTIES (no country restriction)
// ============================================================
test('Purchase all non-restricted properties', function() use ($db, $propertyService, $moneyService, $properties) {
    foreach ($properties as $prop) {
        // Skip country-restricted properties for this test
        if ($prop['country_restriction'] !== null) {
            logDB("Skipping {$prop['name']} (ID: {$prop['id']}) - country restricted to {$prop['country_restriction']}");
            continue;
        }
        
        // Create fresh user for each property
        $userId = setupTestUser($db);
        $userIdObj = UserId::of($userId);
        
        $beforeMoney = getUserData($db, $userId)['money'];
        logDB("BEFORE: User has \${$beforeMoney}");
        
        // Purchase
        $propertyService->purchaseProperty($userIdObj, (int)$prop['id'], 'US');
        
        $afterMoney = getUserData($db, $userId)['money'];
        $expectedMoney = $beforeMoney - $prop['price'];
        
        logDB("AFTER: User has \${$afterMoney} (expected: \${$expectedMoney})");
        logDB("Property purchased: {$prop['name']} for \${$prop['price']}");
        
        // Verify money deducted
        assertEquals($expectedMoney, (int)$afterMoney, "Money should be deducted correctly");
        
        // Verify property owned
        $owned = getPropertyData($db, $userId);
        assertTrue($owned !== null, "User should own property");
        assertEquals((int)$prop['id'], (int)$owned['property_id'], "Correct property should be owned");
        
        logDB("✓ VERIFIED: {$prop['name']} purchase successful!");
        
        cleanupTestUser($db, $userId);
    }
});

// ============================================================
// TEST 2: CANNOT PURCHASE WITHOUT MONEY
// ============================================================
test('Cannot purchase property without sufficient money', function() use ($db, $propertyService, $properties) {
    $cheapestProperty = $properties[0]; // Should be Panel at 2000
    
    // Create user with insufficient funds
    $db->executeStatement("DELETE FROM users WHERE username = 'poor_test_user'");
    $db->insert('users', [
        'username' => 'poor_test_user',
        'email' => 'poor@test.com',
        'password' => password_hash('test123', PASSWORD_DEFAULT),
        'money' => 100, // Only $100
        'health' => 100,
        'energy' => 100,
        'xp' => 0,
        'country_code' => 'US'
    ]);
    $poorUserId = (int)$db->lastInsertId();
    logDB("Created poor user ID: $poorUserId with only \$100");
    
    $exceptionThrown = false;
    $exceptionMessage = '';
    
    try {
        $propertyService->purchaseProperty(UserId::of($poorUserId), (int)$cheapestProperty['id'], 'US');
    } catch (Exception $e) {
        $exceptionThrown = true;
        $exceptionMessage = $e->getMessage();
        logDB("Exception caught: $exceptionMessage");
    }
    
    assertTrue($exceptionThrown, "Should throw exception when no money");
    
    // Verify no property owned
    $owned = getPropertyData($db, $poorUserId);
    assertTrue($owned === null, "Poor user should NOT own any property");
    logDB("✓ VERIFIED: Cannot buy without money!");
    
    // Cleanup
    $db->executeStatement("DELETE FROM users WHERE id = ?", [$poorUserId]);
});

// ============================================================
// TEST 3: COUNTRY RESTRICTION CHECK
// ============================================================
test('Cannot purchase country-restricted property in wrong country', function() use ($db, $propertyService, $properties) {
    // Find CA-restricted property (Luxus ház)
    $caProperty = null;
    foreach ($properties as $p) {
        if ($p['country_restriction'] === 'CA') {
            $caProperty = $p;
            break;
        }
    }
    
    if (!$caProperty) {
        logDB("No CA-restricted property found, skipping test");
        return;
    }
    
    logDB("Testing CA-restricted property: {$caProperty['name']} (ID: {$caProperty['id']})");
    
    // Create user in US (not CA)
    $userId = setupTestUser($db); // Creates user in US
    
    $exceptionThrown = false;
    $exceptionMessage = '';
    
    try {
        $propertyService->purchaseProperty(UserId::of($userId), (int)$caProperty['id'], 'US');
    } catch (Exception $e) {
        $exceptionThrown = true;
        $exceptionMessage = $e->getMessage();
        logDB("Exception caught: $exceptionMessage");
    }
    
    assertTrue($exceptionThrown, "Should throw exception for wrong country");
    assertTrue(strpos($exceptionMessage, 'CA') !== false, "Error should mention CA");
    
    // Verify no property owned
    $owned = getPropertyData($db, $userId);
    assertTrue($owned === null, "User should NOT own CA property when in US");
    logDB("✓ VERIFIED: Country restriction works!");
    
    cleanupTestUser($db, $userId);
});

// ============================================================
// TEST 4: SELL PROPERTY - 60% REFUND
// ============================================================
test('Sell property returns 60% of purchase price', function() use ($db, $propertyService, $properties) {
    // Use a mid-tier property
    $property = $properties[4]; // Családi ház 200000
    
    $userId = setupTestUser($db);
    $userIdObj = UserId::of($userId);
    
    $initialMoney = getUserData($db, $userId)['money'];
    logDB("Initial money: \${$initialMoney}");
    
    // Buy property
    $propertyService->purchaseProperty($userIdObj, (int)$property['id'], 'US');
    
    $afterBuyMoney = getUserData($db, $userId)['money'];
    logDB("After purchase: \${$afterBuyMoney}");
    
    // Get user property ID
    $userProperty = getPropertyData($db, $userId);
    assertTrue($userProperty !== null, "Should own property after purchase");
    
    $userPropertyId = (int)$userProperty['id'];
    $purchasePrice = (int)$userProperty['purchase_price'];
    $expectedRefund = (int)($purchasePrice * 0.6);
    
    logDB("Purchase price: \${$purchasePrice}, Expected refund (60%): \${$expectedRefund}");
    
    // Sell property
    $propertyService->sellProperty($userIdObj, $userPropertyId);
    
    $afterSellMoney = getUserData($db, $userId)['money'];
    $expectedAfterSell = $afterBuyMoney + $expectedRefund;
    
    logDB("After sell: \${$afterSellMoney} (expected: \${$expectedAfterSell})");
    
    assertEquals($expectedAfterSell, (int)$afterSellMoney, "Should receive 60% refund");
    
    // Verify property removed
    $owned = getPropertyData($db, $userId);
    assertTrue($owned === null, "Property should be removed after sale");
    
    logDB("✓ VERIFIED: 60% refund works correctly!");
    
    cleanupTestUser($db, $userId);
});

// ============================================================
// TEST 5: SLEEP REGENERATION - 1 HOUR FULL REGEN
// ============================================================
test('Sleep regeneration - 1 hour gives expected regen', function() use ($db, $propertyService, $sleepService, $properties) {
    // Test with Panel (2% health, 3% energy per hour)
    $property = $properties[0]; // Panel
    
    $userId = setupTestUser($db);
    $userIdObj = UserId::of($userId);
    
    // Set low health/energy
    $db->executeStatement("UPDATE users SET health = 50, energy = 50 WHERE id = ?", [$userId]);
    
    // Buy property
    $propertyService->purchaseProperty($userIdObj, (int)$property['id'], 'US');
    
    $beforeUser = getUserData($db, $userId);
    logDB("BEFORE SLEEP: Health={$beforeUser['health']}, Energy={$beforeUser['energy']}");
    
    // Start sleep for 1 hour
    $sleepService->startSleep($userIdObj, 1, 'US');
    
    // Check sleep record
    $sleepRecord = $db->fetchAssociative("SELECT * FROM user_sleep WHERE user_id = ?", [$userId]);
    logDB("Sleep record: health_regen_per_hour={$sleepRecord['health_regen_per_hour']}, energy_regen_per_hour={$sleepRecord['energy_regen_per_hour']}");
    
    // Simulate 1 hour passing by manipulating timestamps
    $oneHourAgo = (new DateTime())->modify('-1 hour')->format('Y-m-d H:i:s');
    $db->executeStatement(
        "UPDATE user_sleep SET sleep_started_at = ?, sleep_end_at = NOW() WHERE user_id = ?",
        [$oneHourAgo, $userId]
    );
    
    // Wake up
    $result = $sleepService->wakeUp($userIdObj);
    
    $afterUser = getUserData($db, $userId);
    logDB("AFTER SLEEP: Health={$afterUser['health']}, Energy={$afterUser['energy']}");
    logDB("Gained: Health=+{$result['health_gained']}, Energy=+{$result['energy_gained']}");
    
    // Verify regeneration
    $expectedHealthGain = (int)$property['sleep_health_regen_percent']; // 2% per hour * 1 hour
    $expectedEnergyGain = (int)$property['sleep_energy_regen_percent']; // 3% per hour * 1 hour
    
    assertEquals($expectedHealthGain, (int)$result['health_gained'], "Health gain should match property regen");
    assertEquals($expectedEnergyGain, (int)$result['energy_gained'], "Energy gain should match property regen");
    
    logDB("✓ VERIFIED: 1 hour sleep regeneration correct!");
    
    cleanupTestUser($db, $userId);
});

// ============================================================
// TEST 6: SLEEP REGENERATION - 0.5 HOUR PARTIAL REGEN
// ============================================================
test('Sleep regeneration - 0.5 hour gives proportional regen', function() use ($db, $propertyService, $sleepService, $properties) {
    // Test with Villa (6% health, 12% energy per hour)
    $property = null;
    foreach ($properties as $p) {
        if ($p['sleep_health_regen_percent'] >= 6 && $p['country_restriction'] === null) {
            $property = $p;
            break;
        }
    }
    
    if (!$property) {
        $property = $properties[2]; // Fallback to Villa
    }
    
    $userId = setupTestUser($db);
    $userIdObj = UserId::of($userId);
    
    // Set low health/energy
    $db->executeStatement("UPDATE users SET health = 50, energy = 50 WHERE id = ?", [$userId]);
    
    // Buy property
    $propertyService->purchaseProperty($userIdObj, (int)$property['id'], 'US');
    
    $beforeUser = getUserData($db, $userId);
    logDB("Property: {$property['name']} ({$property['sleep_health_regen_percent']}% HP, {$property['sleep_energy_regen_percent']}% EN per hour)");
    logDB("BEFORE SLEEP: Health={$beforeUser['health']}, Energy={$beforeUser['energy']}");
    
    // Start sleep for 1 hour
    $sleepService->startSleep($userIdObj, 1, 'US');
    
    // Simulate 0.5 hour (30 min) passing using DB-native timestamps to avoid timezone issues
    // Set start to DB NOW() minus 30 minutes, keep end far in future
    $db->executeStatement(
        "UPDATE user_sleep SET 
            sleep_started_at = DATE_SUB(NOW(), INTERVAL 30 MINUTE), 
            sleep_end_at = DATE_ADD(NOW(), INTERVAL 1 HOUR),
            sleep_duration_hours = 2 
         WHERE user_id = ?",
        [$userId]
    );
    
    // Wake up early
    $result = $sleepService->wakeUp($userIdObj);
    
    $afterUser = getUserData($db, $userId);
    $hoursSlept = round($result['hours_slept'], 2);
    logDB("AFTER 0.5hr SLEEP: Health={$afterUser['health']}, Energy={$afterUser['energy']}");
    logDB("Gained: Health=+{$result['health_gained']}, Energy=+{$result['energy_gained']}, Hours slept: {$result['hours_slept']}");
    
    // Verify proportional regeneration (0.5 * rate)
    $expectedHealthGain = (int)(0.5 * $property['sleep_health_regen_percent']);
    $expectedEnergyGain = (int)(0.5 * $property['sleep_energy_regen_percent']);
    
    // Allow small variance due to timing
    assertTrue(
        abs($result['health_gained'] - $expectedHealthGain) <= 1,
        "Health gain should be ~{$expectedHealthGain}, got {$result['health_gained']}"
    );
    assertTrue(
        abs($result['energy_gained'] - $expectedEnergyGain) <= 1,
        "Energy gain should be ~{$expectedEnergyGain}, got {$result['energy_gained']}"
    );
    
    logDB("✓ VERIFIED: 0.5 hour proportional regeneration works!");
    
    cleanupTestUser($db, $userId);
});

// ============================================================
// TEST 7: ALL PROPERTIES - SLEEP REGEN VERIFICATION
// ============================================================
test('All properties - verify sleep regeneration rates', function() use ($db, $propertyService, $sleepService, $properties) {
    echo "\n   📊 Testing all property regeneration rates:\n";
    
    foreach ($properties as $prop) {
        // Skip country-restricted for simplicity
        if ($prop['country_restriction'] !== null) {
            logDB("Skipping {$prop['name']} (country restricted)");
            continue;
        }
        
        $userId = setupTestUser($db);
        $userIdObj = UserId::of($userId);
        
        // Set to exactly 50 health/energy
        $db->executeStatement("UPDATE users SET health = 50, energy = 50 WHERE id = ?", [$userId]);
        
        // Buy property
        $propertyService->purchaseProperty($userIdObj, (int)$prop['id'], 'US');
        
        // Start sleep
        $sleepService->startSleep($userIdObj, 1, 'US');
        
        // Simulate exactly 1 hour
        $oneHourAgo = (new DateTime())->modify('-1 hour')->format('Y-m-d H:i:s');
        $db->executeStatement(
            "UPDATE user_sleep SET sleep_started_at = ?, sleep_end_at = NOW() WHERE user_id = ?",
            [$oneHourAgo, $userId]
        );
        
        // Wake up
        $result = $sleepService->wakeUp($userIdObj);
        
        $afterUser = getUserData($db, $userId);
        
        $expectedHealth = min(100, 50 + $prop['sleep_health_regen_percent']);
        $expectedEnergy = min(100, 50 + $prop['sleep_energy_regen_percent']);
        
        logDB("{$prop['name']}: HP 50→{$afterUser['health']} (expected {$expectedHealth}), EN 50→{$afterUser['energy']} (expected {$expectedEnergy})");
        
        assertEquals((int)$expectedHealth, (int)$afterUser['health'], "{$prop['name']} health regen incorrect");
        assertEquals((int)$expectedEnergy, (int)$afterUser['energy'], "{$prop['name']} energy regen incorrect");
        
        cleanupTestUser($db, $userId);
    }
    
    logDB("✓ VERIFIED: All property regeneration rates correct!");
});

// ============================================================
// RESULTS OUTPUT
// ============================================================
echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║                       TEST RESULTS                          ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
printf("║  ✅ Passed: %-48d ║\n", $passed);
printf("║  ❌ Failed: %-48d ║\n", $failed);
echo "╠══════════════════════════════════════════════════════════════╣\n";

if ($failed > 0) {
    echo "║  ⚠️  SOME TESTS FAILED - REVIEW LOGS ABOVE                  ║\n";
} else {
    echo "║  🎉 ALL TESTS PASSED!                                       ║\n";
}

echo "╚══════════════════════════════════════════════════════════════╝\n";

// OUTPUT ALL LOGS
echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║                    COMPLETE DATABASE LOG                    ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";

foreach ($logs as $log) {
    echo "  $log\n";
}

echo "\n📁 Log entries: " . count($logs) . "\n";
echo "🕐 Finished at: " . date('Y-m-d H:i:s') . "\n\n";

if ($failed > 0) {
    exit(1);
}
