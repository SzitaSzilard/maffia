<?php
declare(strict_types=1);

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/../config/container.php');
$container = $containerBuilder->build();

// Use the correct session name from middleware config
session_name('netmafia_sess');
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax'
]);

$db = $container->get(\Doctrine\DBAL\Connection::class);
$session = $container->get(\Netmafia\Infrastructure\SessionService::class);

$userId = $session->get('user_id');
if (!$userId) {
    die("Kérlek lépj be a játékba először!");
}

$organizer = $db->fetchAssociative("SELECT username, country_code, is_admin FROM users WHERE id = ?", [$userId]);
if (!$organizer) {
    die("Szervező nem található!");
}
if (!(bool)$organizer['is_admin']) {
    die("Kritikus Biztonsági Hiba: A seed_oc.php futtatása kizárólag Adminisztrátorok számára engedélyezett! (Éles rendszeren ez a fájl egyébként is törlendő!)");
}

echo "<h1>NetMafia - Szervezett Bűnözés Tesztelő</h1>";
echo "<p>Szervező: {$organizer['username']} (Ország: {$organizer['country_code']})</p>";

// Clear the organizer's cooldown IMMEDIATELY so they can start a crime if they want to
$db->update('users', ['oc_cooldown_until' => null], ['id' => $userId]);
echo "<p style='color:green;'>Szervezői időkorlát (cooldown) azonnal törölve! Rögvest újraszervezheted a bűnözést!</p>";

$activeCrime = $db->fetchAssociative("SELECT id FROM organized_crimes WHERE leader_id = ? AND status = 'gathering'", [$userId]);
if ($activeCrime) {
    // Check if the user is actually in the squad list for this crime. If not, it's corrupted from old tests.
    $hasSelf = $db->fetchOne("SELECT id FROM organized_crime_members WHERE crime_id = ? AND user_id = ?", [$activeCrime['id'], $userId]);
    if (!$hasSelf) {
        $db->delete('organized_crimes', ['id' => $activeCrime['id']]);
        $activeCrime = false;
        echo "<p style='color:orange;'>Egy korábban beragadt (hibás) szervezés törölve lett. Kérlek, kattints a Kaszinó kirablás gombra a játékban egy új indításához!</p>";
    }
}

if (!$activeCrime) {
    echo "<p style='color:red;'>Előbb indíts egy szervezést a Szervezett bűnözés menüpontban (Kaszinó kirablás)! (Nyugodtan hívd meg a botokat a teszteléshez.)</p>";
    exit;
}

$crimeId = (int)$activeCrime['id'];
echo "<p>Aktív bűnözés azonosítója: {$crimeId}. Botok generálása és meghívása folyamatban...</p>";

// Forcefully remove ALL bots from any OTHER gathering/in_progress crimes
// so they never get stuck "already participating in a different crime"
$db->executeStatement("
    DELETE m FROM organized_crime_members m
    JOIN organized_crimes c ON c.id = m.crime_id
    WHERE m.user_id IN (SELECT id FROM users WHERE username LIKE 'Teszt %')
      AND c.id != ? 
      AND c.status IN ('gathering', 'in_progress')
", [$crimeId]);
echo "<p style='color:orange;'>Előző, beragadt tesztcsatlakozások kitörölve a botokról.</p>";

// Clear cooldowns for ALL test bots upfront (works even if they were just created)
$db->executeStatement("UPDATE users SET oc_cooldown_until = NULL WHERE username LIKE 'Teszt %'");
echo "<p style='color:green;'>Összes bot cooldown törölve!</p>";

$bots = [
    'gang_leader' => 'Teszt Bandafőnök',
    'union_member' => 'Teszt Szakszervezeti',
    'gunman_1' => 'Teszt Fegyveres 1',
    'gunman_2' => 'Teszt Fegyveres 2',
    'gunman_3' => 'Teszt Fegyveres 3',
    'hacker' => 'Teszt Hacker',
    'driver_1' => 'Teszt Sofőr 1',
    'driver_2' => 'Teszt Sofőr 2',
    'pilot' => 'Teszt Pilóta'
];

foreach ($bots as $role => $botName) {
    // Check if bot exists
    $botId = $db->fetchOne("SELECT id FROM users WHERE username = ?", [$botName]);
    
    if (!$botId) {
        // Create bot
        $db->insert('users', [
            'username' => $botName,
            'password' => password_hash('teszt123', PASSWORD_DEFAULT),
            'email' => strtolower(str_replace(' ', '', $botName)) . '@netmafia_test.hu',
            'country_code' => $organizer['country_code'],
            'xp' => 3000, // Katona rang
            'energy' => 100,
            'health' => 100,
            'money' => 50000,
            'is_union_member' => ($role === 'union_member' ? 1 : 0),
            'oc_cooldown_until' => null,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        $botId = $db->lastInsertId();
        echo "Létrehozva: {$botName} (ID: {$botId})<br>";
    } else {
        // Update stats just in case
        $isUnion = ($role === 'union_member' ? 1 : 0);
        $db->update('users', [
            'xp' => 3000, 
            'energy' => 100, 
            'health' => 100, 
            'country_code' => $organizer['country_code'], 
            'is_union_member' => $isUnion,
            'oc_cooldown_until' => null
        ], ['id' => $botId]);
    }
    
    // Add specific requirements (always verify them even if bot existed)
    if ($role === 'gang_leader') {
        $hasGang = $db->fetchOne("SELECT 1 FROM gang_members WHERE user_id = ?", [$botId]);
        if (!$hasGang) {
            $db->insert('gangs', ['name' => 'Teszt Banda']);
            $gangId = $db->lastInsertId();
            $db->insert('gang_members', ['gang_id' => $gangId, 'user_id' => $botId, 'is_leader' => 1]);
        }
    }
    
    $userVehicleId = null;
    $vehicleNameStr = null;
    if (in_array($role, ['driver_1', 'driver_2', 'pilot'], true)) {
        $uv = $db->fetchAssociative("SELECT uv.id, v.name FROM user_vehicles uv JOIN vehicles v ON uv.vehicle_id = v.id WHERE uv.user_id = ? LIMIT 1", [$botId]);
        if (!$uv) {
            $db->insert('user_vehicles', [
                'user_id' => $botId,
                'vehicle_id' => 1, // Assume ID 1 exists
                'damage_percent' => 0,
                'fuel_amount' => 100,
                'current_fuel' => 100,
                'country' => $organizer['country_code'],
                'is_default' => 1
            ]);
            $userVehicleId = (int)$db->lastInsertId();
            $vehicleData = $db->fetchAssociative("SELECT name FROM vehicles WHERE id = 1");
            $vehicleNameStr = $vehicleData ? $vehicleData['name'] : 'Teszt Autó';
        } else {
            $userVehicleId = (int)$uv['id'];
            $vehicleNameStr = $uv['name'];
        }
    }
    
    // Add to organized crime as accepted!
    $existingMember = $db->fetchOne("SELECT id FROM organized_crime_members WHERE crime_id = ? AND role = ?", [$crimeId, $role]);
    
    $updateData = ['user_id' => $botId, 'status' => 'accepted'];
    $insertData = [
        'crime_id' => $crimeId,
        'user_id' => $botId,
        'role' => $role,
        'status' => 'accepted'
    ];
    
    if ($userVehicleId) {
        $updateData['vehicle_id'] = $userVehicleId;
        $updateData['vehicle_name'] = $vehicleNameStr;
        $insertData['vehicle_id'] = $userVehicleId;
        $insertData['vehicle_name'] = $vehicleNameStr;
    }
    
    if ($existingMember) {
        $db->update('organized_crime_members', $updateData, ['id' => $existingMember]);
        echo "{$botName} frissítve a bűnözésben.<br>";
    } else {
        $db->insert('organized_crime_members', $insertData);
        echo "{$botName} sikeresen csatlakozott a bűnözéshez!<br>";
    }
}

echo "<h2 style='color:green;'>KÉSZ! Menj vissza a játékba, az összes teszt bot elfogadta a meghívót!</h2>";
echo "<a href='/szervezett-bunozes' style='font-size: 20px;'>Vissza a szervezett bűnözéshez</a>";
