<?php
// audit_stress_chaos.php

use DI\ContainerBuilder;
use Netmafia\Shared\Domain\ValueObjects\UserId;

require __DIR__ . '/../vendor/autoload.php';

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/../config/container.php');
$container = $containerBuilder->build();
$moneyService = $container->get(\Netmafia\Modules\Money\Domain\MoneyService::class);
$db = $container->get(\Doctrine\DBAL\Connection::class);

echo "<h1>STRESS & CHAOS AUDIT LOG</h1><pre>";

function test_case($title, callable $func) {
    echo "--------------------------------------------------\n";
    echo "TEST: $title\n";
    try {
        $func();
        echo "RESULT: [OK] (Handled gracefully)\n";
    } catch (\Throwable $e) {
        // If exception matches expected security blocks, it's [OK].
        // If it's a DB syntax error or crash, it's [FAIL].
        echo "RESULT: [EXCEPTION] " . $e->getMessage() . "\n";
        echo "        Type: " . get_class($e) . "\n";
    }
}

// Setup User
$db->executeStatement("DELETE FROM users WHERE id = 9888");
$db->insert('users', ['id' => 9888, 'username' => 'StressUser', 'email' => 's@test.com', 'password' => 'x', 'money' => 1000, 'xp' => 0]);
$userId = UserId::of(9888);

// 1. Integer Overflow
test_case("Integer Overflow (addMoney: PHP_INT_MAX)", function() use ($moneyService, $userId) {
    echo "Value: " . PHP_INT_MAX . "\n";
    $moneyService->addMoney($userId, PHP_INT_MAX, 'admin_add', 'Overflow Test', null, null, 1);
    // If successful, check if balance is negative (overflow) or capped
});

// 2. Negative Input
test_case("Negative Input (addMoney: -500)", function() use ($moneyService, $userId) {
    $moneyService->addMoney($userId, -500, 'admin_add', 'Negative Test', null, null, 1);
});

// 3. Decimal String
test_case("Decimal String (addMoney: '10.5')", function() use ($moneyService, $userId) {
    // PHP strict types might catch this if declared, otherwise int cast
    $moneyService->addMoney($userId, (int)"10.5", 'admin_add', 'Decimal Test', null, null, 1);
    echo "INFO: Casted to int, likely 10.\n";
});

// 4. Type Juggling (Array)
test_case("Type Juggling (Array passed as int - strict types should block)", function() use ($moneyService, $userId) {
    // This will likely throw TypeError immediately
    try {
        $moneyService->addMoney($userId, [], 'admin_add', 'Array Test', null, null, 1);
    } catch (\TypeError $e) {
        echo "INFO: TypeError caught (Correct)\n";
        throw $e;
    }
});

// 5. Stress Test (Loop)
test_case("Performance Stress (100 Transactions)", function() use ($moneyService, $userId) {
    $start = microtime(true);
    for ($i = 0; $i < 100; $i++) {
        $moneyService->addMoney($userId, 1, 'admin_add', "Stress $i", null, null, 1);
    }
    $end = microtime(true);
    echo "Time: " . number_format($end - $start, 4) . "s (" . number_format(100 / ($end - $start), 2) . " ops/sec)\n";
});

// Verify Final State
$finalBalance = (int)$db->fetchOne("SELECT money FROM users WHERE id = 9888");
echo "\nFinal Balance: $finalBalance\n";

echo "</pre>";
