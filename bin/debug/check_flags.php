<?php
require_once __DIR__ . '/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;

$connectionParams = [
    'dbname' => 'netmafia',
    'user' => 'root',
    'password' => '',
    'host' => 'localhost',
    'driver' => 'pdo_mysql',
    'charset' => 'utf8mb4'
];

try {
    $conn = DriverManager::getConnection($connectionParams);
    $countries = $conn->fetchAllAssociative("SELECT code, name_hu, flag_emoji FROM countries");

    echo "Countries:\n";
    foreach ($countries as $c) {
        echo "[{$c['code']}] {$c['name_hu']} - Flag: " . ($c['flag_emoji'] ?? 'NULL') . " (Hex: " . bin2hex($c['flag_emoji'] ?? '') . ")\n";
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
