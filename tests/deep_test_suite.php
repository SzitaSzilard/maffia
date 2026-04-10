<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Modules\Bank\Domain\BankService;
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Modules\Market\Domain\MarketService;
use Netmafia\Modules\Garage\Domain\GarageService;
use Netmafia\Modules\Messages\Domain\MessageService;
use Netmafia\Modules\PettyCrime\Domain\PettyCrimeService;
use Doctrine\DBAL\Connection;

// 1. Boot Container
$containerBuilder = new \DI\ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/../config/container.php');
$container = $containerBuilder->build();

/** @var Connection $db */
$db = $container->get(Connection::class);

echo "=== NetMafia Deep Security & Logic Test Suite ===\n";
echo "Currency: $ (Dollár) & Kredit\n\n";

$attackerName = 'test_attacker';
$victimName = 'test_victim';

$attackerIdRaw = $db->fetchOne("SELECT id FROM users WHERE username = ?", [$attackerName]);
$victimIdRaw = $db->fetchOne("SELECT id FROM users WHERE username = ?", [$victimName]);

if (!$attackerIdRaw || !$victimIdRaw) {
    die("Error: Test users not found. Run tests/init_test_users.php first.\n");
}

$attackerId = UserId::of((int)$attackerIdRaw);
$victimId = UserId::of((int)$victimIdRaw);

function assertEquals($expected, $actual, string $message = ''): void {
    if ($expected !== $actual) {
        throw new Exception("FAIL: Expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ". $message");
    }
}

// Helper to catch warnings and report them as findings
set_error_handler(function($errno, $errstr) {
    if (strpos($errstr, 'Data truncated') !== false) {
        throw new Exception("BUG DETECTED (Data Truncation): $errstr");
    }
    return false;
});

// ============================================================================
// BANK TESTS
// ============================================================================
echo "--- Testing BANK Module ---\n";

/** @var BankService $bankService */
$bankService = $container->get(BankService::class);

// Reset bank balances
$db->executeStatement("DELETE FROM bank_accounts WHERE user_id IN (?, ?)", [$attackerId->id(), $victimId->id()]);
$db->executeStatement("UPDATE users SET money = 100000 WHERE id IN (?, ?)", [$attackerId->id(), $victimId->id()]);

try {
    echo "[Test] Bank: Open account... ";
    $bankService->openAccount($attackerId);
    $bankService->openAccount($victimId);
    echo "OK\n";

    echo "[Test] Bank: Deposit 50,000... ";
    $bankService->deposit($attackerId, 50000);
    $acc = $bankService->getAccount($attackerId->id());
    assertEquals(47500, (int)$acc['balance'], "Balance after 50k deposit (5% fee)");
    echo "OK\n";

    echo "[Test] Bank: Negative Withdraw Attempt... ";
    try {
        $bankService->withdraw($attackerId, -20000);
        echo "❌ FAIL (Allowed negative withdraw)\n";
    } catch (\Netmafia\Shared\Exceptions\InvalidInputException $e) {
        echo "OK (Blocked: " . $e->getMessage() . ")\n";
    }

    echo "[Test] Bank: Withdraw Race Condition simulation (10x 10,000 from 47,500)... ";
    $successCount = 0;
    for ($i = 0; $i < 10; $i++) {
        try {
            $bankService->withdraw($attackerId, 10000);
            $successCount++;
        } catch (\Throwable $e) {}
    }
    assertEquals(4, $successCount, "Should only allow 4 withdrawals of 10k from 47.5k");
    $acc = $bankService->getAccount($attackerId->id());
    assertEquals(7500, (int)$acc['balance'], "DB Final Balance must be exactly 7500");
    echo "OK (Caught overflow correctly)\n";

} catch (\Throwable $e) {
    echo "❌ BANK ERROR: " . $e->getMessage() . "\n";
}

// ============================================================================
// MARKET TESTS
// ============================================================================
echo "\n--- Testing MARKET Module ---\n";

/** @var MarketService $marketService */
$marketService = $container->get(MarketService::class);

// Reset items
$db->executeStatement("DELETE FROM market_items WHERE seller_id IN (?, ?)", [$attackerId->id(), $victimId->id()]);
$db->executeStatement("UPDATE users SET credits = 1000 WHERE id = ?", [$victimId->id()]);
$db->executeStatement("UPDATE users SET money = 100000 WHERE id = ?", [$attackerId->id()]);

try {
    echo "[Test] Market: List 500 credits for 10,000$... ";
    try {
        $marketService->listItemOnMarket($victimId->id(), 'credit', null, 500, 10000, 'money');
        echo "OK\n";
    } catch (\Throwable $e) {
        if (strpos($e->getMessage(), 'Data truncated') !== false) {
            echo "⚠️ BUG DETECTED: DB column truncates 'market_escrow_out' type!\n";
        } else {
            throw $e;
        }
    }

    $marketIdRaw = $db->fetchOne("SELECT id FROM market_items WHERE seller_id = ?", [$victimId->id()]);
    if ($marketIdRaw) {
        $marketId = (int)$marketIdRaw;
        echo "[Test] Market: Double Buy Race (Buying 400 then 200 from remaining)... ";
        try {
            $marketService->buyItem($attackerId->id(), $marketId, 400);
            $marketService->buyItem($attackerId->id(), $marketId, 200); 
            echo "❌ FAIL (Allowed over-purchase)\n";
        } catch (\Throwable $e) {
            echo "OK (Blocked second buy: " . $e->getMessage() . ")\n";
        }
    }

} catch (\Throwable $e) {
    echo "❌ MARKET ERROR: " . $e->getMessage() . "\n";
}

// ============================================================================
// GARAGE TESTS
// ============================================================================
echo "\n--- Testing GARAGE Module ---\n";

/** @var GarageService $garageService */
$garageService = $container->get(GarageService::class);

// Clear vehicles and garage data for tests
$db->executeStatement("DELETE FROM user_vehicles WHERE user_id = ?", [$attackerId->id()]);
$db->executeStatement("DELETE FROM user_properties WHERE user_id = ?", [$attackerId->id()]);
$db->executeStatement("DELETE FROM user_garage_purchases WHERE user_id = ?", [$attackerId->id()]);

try {
    $realVehicleId = (int)$db->fetchOne("SELECT id FROM vehicles LIMIT 1");
    if (!$realVehicleId) die("No vehicles in vehicles table!\n");

    echo "[Test] Garage: Capacity test (No property, move to garage)... ";
    $db->insert('user_vehicles', [
        'user_id' => $attackerId->id(),
        'vehicle_id' => $realVehicleId,
        'country' => 'HU',
        'location' => 'street'
    ]);
    $uvId = (int)$db->lastInsertId();

    try {
        $garageService->moveVehicle($attackerId->id(), $uvId, 'garage');
        echo "❌ FAIL (Allowed move without slots)\n";
    } catch (\Throwable $e) {
        echo "OK (Blocked: " . $e->getMessage() . ")\n";
    }

} catch (\Throwable $e) {
    echo "❌ GARAGE ERROR: " . $e->getMessage() . "\n";
}

// ============================================================================
// CAR THEFT TESTS (Added Phase 2)
// ============================================================================
echo "\n--- Testing CAR THEFT Module ---\n";

/** @var \Netmafia\Modules\CarTheft\Domain\CarTheftService $carTheftService */
$carTheftService = $container->get(\Netmafia\Modules\CarTheft\Domain\CarTheftService::class);

try {
    echo "[Test] CarTheft: Cross-country spoofing... ";
    $db->executeStatement("UPDATE users SET energy = 100 WHERE id = ?", [$attackerId->id()]);
    // Attacker is in HU or default country. Let's create a car in 'IT'
    $itVehicleId = (int)$db->fetchOne("SELECT id FROM vehicles LIMIT 1");
    $db->insert('user_vehicles', [
        'user_id' => $victimId->id(),
        'vehicle_id' => $itVehicleId,
        'country' => 'IT',
        'location' => 'street'
    ]);
    $uvIdIt = (int)$db->lastInsertId();

    try {
        $carTheftService->attemptTheft($attackerId, $uvIdIt);
        echo "❌ FAIL (Allowed stealing from another country!)\n";
    } catch (\Throwable $e) {
        echo "OK (Blocked: " . $e->getMessage() . ")\n";
    }

} catch (\Throwable $e) {
    echo "❌ CAR THEFT ERROR: " . $e->getMessage() . "\n";
}

// ============================================================================
// ORGANIZED CRIME TESTS (Added Phase 2)
// ============================================================================
echo "\n--- Testing ORGANIZED CRIME Module ---\n";

/** @var \Netmafia\Modules\OrganizedCrime\Domain\OrganizedCrimeService $ocService */
$ocService = $container->get(\Netmafia\Modules\OrganizedCrime\Domain\OrganizedCrimeService::class);

try {
    echo "[Test] OC: Invite low-rank bypass check... ";
    $db->executeStatement("DELETE FROM organized_crimes WHERE leader_id = ?", [$attackerId->id()]);
    $db->executeStatement("UPDATE users SET xp = 0 WHERE id = ?", [$victimId->id()]); // Victim is too low rank
    
    $ocService->createCrimeForUser($attackerId->id(), 'casino');
    $crime = $ocService->getActiveCrimeForUser($attackerId->id());
    $crimeId = (int)$crime['id'];

    $ocService->inviteMember($crimeId, $victimId->id(), 'hacker');
    $ocService->acceptInvite($victimId->id(), $crimeId);
    
    // Fill remaining 8 slots with unique roles to reach 10 members
    $roles = ['gang_leader', 'union_member', 'gunman_1', 'gunman_2', 'gunman_3', 'driver_1', 'driver_2', 'pilot'];
    foreach ($roles as $i => $role) {
        $dummyName = "oc_dummy_" . ($i + 1);
        $dummyIdRaw = $db->fetchOne("SELECT id FROM users WHERE username = ?", [$dummyName]);
        if (!$dummyIdRaw) {
            $db->insert('users', [
                'username' => $dummyName,
                'email' => "{$dummyName}@test.com",
                'password' => 'nopass',
                'xp' => 10000, // High rank to pass their own checks
                'energy' => 100,
                'health' => 100,
                'country_code' => 'HU'
            ]);
            $dummyId = (int)$db->lastInsertId();
        } else {
            $dummyId = (int)$dummyIdRaw;
        }
        $ocService->inviteMember($crimeId, $dummyId, $role);
        $ocService->acceptInvite($dummyId, $crimeId);
        
        // If role is a driver/pilot, we need a vehicle for them too
        if (in_array($role, ['driver_1', 'driver_2', 'pilot'])) {
            $vId = (int)$db->fetchOne("SELECT id FROM vehicles LIMIT 1");
            $db->insert('user_vehicles', [
                'user_id' => $dummyId,
                'vehicle_id' => $vId,
                'country' => 'HU',
                'location' => 'garage',
                'fuel_amount' => 100
            ]);
            $uvId = (int)$db->lastInsertId();
            $db->insert('user_garage_slots', ['user_id' => $dummyId, 'country' => 'HU', 'slots' => 5]); // Ensure they have slots
            $ocService->selectVehicle($dummyId, $uvId);
        }
    }
    
    // Now try to start it (rank check for Victim should trigger)
    $result = $ocService->startCrime($attackerId->id(), $crimeId);
    if ($result['success']) {
        echo "❌ FAIL (Started crime with low-rank member)\n";
    } else {
        echo "OK (Blocked: " . $result['error'] . ")\n";
    }

} catch (\Throwable $e) {
    echo "❌ OC ERROR: " . $e->getMessage() . "\n";
}

// ============================================================================
// COMBAT & HEALTH TESTS (Added Phase 2)
// ============================================================================
echo "\n--- Testing COMBAT & HEALTH Module ---\n";

/** @var \Netmafia\Modules\Health\Domain\HealthService $healthService */
$healthService = $container->get(\Netmafia\Modules\Health\Domain\HealthService::class);

try {
    echo "[Test] Health: Overkill & Resurrection check... ";
    $db->executeStatement("UPDATE users SET health = 10, xp = 5000 WHERE id = ?", [$victimId->id()]);
    $res = $healthService->damage($victimId, 50, 'test_overkill');
    
    $hp = (int)$db->fetchOne("SELECT health FROM users WHERE id = ?", [$victimId->id()]);
    $xp = (int)$db->fetchOne("SELECT xp FROM users WHERE id = ?", [$victimId->id()]);
    
    if ($res['died'] && $hp === 100 && $xp < 5000) {
        echo "OK (Died and resurrected with penalty)\n";
    } else {
        echo "❌ FAIL (Resurrection logic failed or HP/XP incorrect. HP: $hp, XP: $xp)\n";
    }

} catch (\Throwable $e) {
    echo "❌ HEALTH ERROR: " . $e->getMessage() . "\n";
}

// ============================================================================
// MESSAGES TESTS
// ============================================================================
echo "\n--- Testing MESSAGES Module ---\n";

/** @var MessageService $messageService */
$messageService = $container->get(MessageService::class);

try {
    echo "[Test] Messages: Auth bypass (Read victim's private msg)... ";
    $db->insert('messages', [
        'sender_id' => 1,
        'recipient_id' => $victimId->id(),
        'subject' => 'Secret',
        'body' => 'Private stuff',
        'created_at' => gmdate('Y-m-d H:i:s')
    ]);
    $msgId = (int)$db->lastInsertId();

    $msg = $messageService->getMessage($msgId, $attackerId->id());
    if ($msg) {
        echo "❌ FAIL (Attacker read message!)\n";
    } else {
        echo "OK (Access denied)\n";
    }

} catch (\Throwable $e) {
    echo "❌ MESSAGES ERROR: " . $e->getMessage() . "\n";
}

// ============================================================================
// CRIMES TESTS
// ============================================================================
echo "\n--- Testing CRIMES Module ---\n";

/** @var PettyCrimeService $pettyCrimeService */
$pettyCrimeService = $container->get(PettyCrimeService::class);

try {
    echo "[Test] Crimes: Energy drain check... ";
    $db->executeStatement("UPDATE users SET energy = 5 WHERE id = ?", [$attackerId->id()]);
    
    $session = $container->get(\Netmafia\Infrastructure\SessionService::class);
    $session->set('petty_crime_valid_ids', json_encode([1, 2, 3]));

    try {
        $pettyCrimeService->commit($attackerId, 1);
        $pettyCrimeService->commit($attackerId, 1);
        echo "❌ FAIL (Allowed second crime)\n";
    } catch (\Throwable $e) {
        echo "OK (Blocked: " . $e->getMessage() . ")\n";
    }
    
} catch (\Throwable $e) {
    echo "❌ CRIMES ERROR: " . $e->getMessage() . "\n";
}

// ============================================================================
// ITEM & INVENTORY TESTS (Added Phase 2)
// ============================================================================
echo "\n--- Testing ITEM & INVENTORY Module ---\n";

/** @var \Netmafia\Modules\Item\Domain\ItemService $itemService */
$itemService = $container->get(\Netmafia\Modules\Item\Domain\ItemService::class);
/** @var \Netmafia\Modules\Item\Domain\InventoryService $inventoryService */
$inventoryService = $container->get(\Netmafia\Modules\Item\Domain\InventoryService::class);

try {
    echo "[Test] Inventory: Double weapon equip check... ";
    
    // Need two weapons
    $weaponId = (int)$db->fetchOne("SELECT id FROM items WHERE type = 'weapon' LIMIT 1");
    if (!$weaponId) {
        $db->insert('items', ['name' => 'Test Gun', 'type' => 'weapon', 'attack' => 10, 'defense' => 0, 'price' => 1000]);
        $weaponId = (int)$db->lastInsertId();
    }
    
    // Clear attacker weapon slots first to be safe
    $db->executeStatement("DELETE FROM user_items WHERE user_id = ? AND item_id = ?", [$attackerId->id(), $weaponId]);
    
    $inventoryService->addItem($attackerId->id(), $weaponId, 2);
    
    // Find the user_item IDs
    $userItems = $db->fetchAllAssociative("SELECT id FROM user_items WHERE user_id = ? AND item_id = ?", [$attackerId->id(), $weaponId]);
    $ui1 = (int)$userItems[0]['id'];
    
    $itemService->equipItem($attackerId, $ui1); // First equip
    
    try {
        // Try to equip same item again (should fail with "already equipped")
        $itemService->equipItem($attackerId, $ui1);
        echo "❌ FAIL (Re-equipped same item)\n";
    } catch (\Throwable $e) {
        echo "OK (Blocked: " . $e->getMessage() . ")\n";
    }

} catch (\Throwable $e) {
    echo "❌ ITEM ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== Deep Testing Completed ===\n";
