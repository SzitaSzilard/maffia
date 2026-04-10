<?php

require_once __DIR__ . '/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;

$connectionParams = [
    'dbname' => 'netmafia',
    'user' => 'root',
    'password' => '',
    'host' => 'localhost',
    'driver' => 'pdo_mysql',
];

$conn = DriverManager::getConnection($connectionParams);

$sql = "
    ALTER TABLE users 
    ADD COLUMN last_travel_time DATETIME DEFAULT NULL,
    ADD COLUMN daily_highway_usage INT DEFAULT 0
";

try {
    $conn->executeStatement($sql);
    echo "Migration successful: Added last_travel_time and daily_highway_usage to users table.\n";
} catch (\Exception $e) {
    if (strpos($e->getMessage(), "Duplicate column") !== false) {
        echo "Columns already exist.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
