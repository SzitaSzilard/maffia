<?php
declare(strict_types=1);

use Doctrine\DBAL\Connection;

require __DIR__ . '/../vendor/autoload.php';

// Boot App
$containerBuilder = new \DI\ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/../config/container.php');
$container = $containerBuilder->build();

/** @var Connection $db */
$db = $container->get(Connection::class);

echo "Updating Users Table Schema...\n";

$queries = [
    // Add new columns if they don't exist
    "ALTER TABLE users ADD COLUMN is_admin TINYINT(1) DEFAULT 0",
    "ALTER TABLE users ADD COLUMN ip_address VARCHAR(45) DEFAULT NULL", // IPv6 support length
    "ALTER TABLE users ADD COLUMN last_activity DATETIME DEFAULT NULL",
    "ALTER TABLE users ADD COLUMN credits INT UNSIGNED DEFAULT 0",
    
    // Modify existing defaults
    "ALTER TABLE users MODIFY COLUMN money BIGINT UNSIGNED DEFAULT 120000",
    
    // Ensure data integrity constraints (Logic handled in app, but def helps)
    // MySQL 8.0.16+ supports CHECK constraints. We try, catch if fails (older versions)
];

foreach ($queries as $sql) {
    try {
        $db->executeStatement($sql);
        echo "Executed: " . substr($sql, 0, 50) . "...\n";
    } catch (\Exception $e) {
        echo "Error/Skipped: " . $e->getMessage() . "\n";
    }
}

// Update existing users to have 120k if they have default 120000
// Or update all new users? Let's just update default for future.
// Optional: Reset test user.
try {
    $db->executeStatement("UPDATE users SET money = 120000 WHERE money = 1000"); // Update old default
    echo "Updated legacy users money to 120,000\n";
} catch (\Exception $e) {}

echo "Migration Complete.\n";
