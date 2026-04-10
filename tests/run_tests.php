<?php
/**
 * Simple Test Runner - PHPUnit nélkül
 * Futtatás: php tests/run_tests.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Load .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

use Netmafia\Infrastructure\CacheService;
use Netmafia\Modules\Messages\Domain\MessageService;
use Netmafia\Modules\Notifications\Domain\NotificationService;
use Doctrine\DBAL\DriverManager;

echo "=== NetMafia Test Suite ===\n\n";

$passed = 0;
$failed = 0;

function test(string $name, callable $testFn): void {
    global $passed, $failed;
    try {
        $testFn();
        echo "✅ PASS: $name\n";
        $passed++;
    } catch (Throwable $e) {
        echo "❌ FAIL: $name\n";
        echo "   Error: " . $e->getMessage() . "\n";
        $failed++;
    }
}

function assertEquals($expected, $actual, string $message = ''): void {
    if ($expected !== $actual) {
        throw new Exception("Expected: " . var_export($expected, true) . ", Got: " . var_export($actual, true) . " $message");
    }
}

function assertNotNull($value, string $message = ''): void {
    if ($value === null) {
        throw new Exception("Expected non-null value. $message");
    }
}

function assertTrue($value, string $message = ''): void {
    if ($value !== true) {
        throw new Exception("Expected true, got: " . var_export($value, true) . " $message");
    }
}

// ============================================
// UNIT TESTS: CacheService
// ============================================
echo "--- CacheService Unit Tests ---\n";

test('CacheService: can be instantiated with array driver', function() {
    $cache = new CacheService('array');
    assertNotNull($cache);
});

test('CacheService: set and get value', function() {
    $cache = new CacheService('array');
    $cache->set('test_key', 'test_value', 60);
    assertEquals('test_value', $cache->get('test_key'));
});

test('CacheService: get returns null for non-existent key', function() {
    $cache = new CacheService('array');
    assertEquals(null, $cache->get('non_existent_key'));
});

test('CacheService: forget removes key', function() {
    $cache = new CacheService('array');
    $cache->set('to_delete', 'value', 60);
    $cache->forget('to_delete');
    assertEquals(null, $cache->get('to_delete'));
});

test('CacheService: remember returns cached value', function() {
    $cache = new CacheService('array');
    $callCount = 0;
    
    $result1 = $cache->remember('remember_key', 60, function() use (&$callCount) {
        $callCount++;
        return 'computed_value';
    });
    
    $result2 = $cache->remember('remember_key', 60, function() use (&$callCount) {
        $callCount++;
        return 'should_not_be_called';
    });
    
    assertEquals('computed_value', $result1);
    assertEquals('computed_value', $result2);
    assertEquals(1, $callCount, 'Callback should only be called once');
});

test('CacheService: flush clears all keys', function() {
    $cache = new CacheService('array');
    $cache->set('key1', 'value1', 60);
    $cache->set('key2', 'value2', 60);
    $cache->flush();
    assertEquals(null, $cache->get('key1'));
    assertEquals(null, $cache->get('key2'));
});

test('CacheService: stats returns driver info', function() {
    $cache = new CacheService('array');
    $cache->set('stat_key', 'value', 60);
    $stats = $cache->stats();
    assertEquals('array', $stats['driver']);
    assertTrue($stats['keys'] >= 1);
});

// ============================================
// INTEGRATION TESTS: Database Connection
// ============================================
echo "\n--- Integration Tests ---\n";

test('Database: can connect', function() {
    $db = DriverManager::getConnection([
        'dbname' => $_ENV['DB_NAME'],
        'user' => $_ENV['DB_USER'],
        'password' => $_ENV['DB_PASS'],
        'host' => $_ENV['DB_HOST'],
        'driver' => $_ENV['DB_DRIVER'] ?? 'pdo_mysql',
    ]);
    
    $result = $db->fetchOne('SELECT 1');
    assertEquals('1', $result);
});

test('MessageService: can be instantiated', function() {
    $db = DriverManager::getConnection([
        'dbname' => $_ENV['DB_NAME'],
        'user' => $_ENV['DB_USER'],
        'password' => $_ENV['DB_PASS'],
        'host' => $_ENV['DB_HOST'],
        'driver' => $_ENV['DB_DRIVER'] ?? 'pdo_mysql',
    ]);
    $cache = new CacheService('array');
    
    $service = new MessageService($db, $cache);
    assertNotNull($service);
});

test('MessageService: getUnreadCount returns integer', function() {
    $db = DriverManager::getConnection([
        'dbname' => $_ENV['DB_NAME'],
        'user' => $_ENV['DB_USER'],
        'password' => $_ENV['DB_PASS'],
        'host' => $_ENV['DB_HOST'],
        'driver' => $_ENV['DB_DRIVER'] ?? 'pdo_mysql',
    ]);
    $cache = new CacheService('array');
    
    $service = new MessageService($db, $cache);
    $count = $service->getUnreadCount(1); // User ID 1
    assertTrue(is_int($count));
});

test('NotificationService: can be instantiated', function() {
    $db = DriverManager::getConnection([
        'dbname' => $_ENV['DB_NAME'],
        'user' => $_ENV['DB_USER'],
        'password' => $_ENV['DB_PASS'],
        'host' => $_ENV['DB_HOST'],
        'driver' => $_ENV['DB_DRIVER'] ?? 'pdo_mysql',
    ]);
    $cache = new CacheService('array');
    
    $service = new NotificationService($db, $cache);
    assertNotNull($service);
});

test('NotificationService: getUnreadCount returns integer', function() {
    $db = DriverManager::getConnection([
        'dbname' => $_ENV['DB_NAME'],
        'user' => $_ENV['DB_USER'],
        'password' => $_ENV['DB_PASS'],
        'host' => $_ENV['DB_HOST'],
        'driver' => $_ENV['DB_DRIVER'] ?? 'pdo_mysql',
    ]);
    $cache = new CacheService('array');
    
    $service = new NotificationService($db, $cache);
    $count = $service->getUnreadCount(1); // User ID 1
    assertTrue(is_int($count));
});

// ============================================
// RESULTS
// ============================================
echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

if ($failed > 0) {
    exit(1);
}

echo "\n🎉 All tests passed!\n";
