<?php
declare(strict_types=1);

use Doctrine\DBAL\Connection;

require __DIR__ . '/../vendor/autoload.php';

// Boot App to get Container
$containerBuilder = new \DI\ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/../config/container.php');
$container = $containerBuilder->build();

/** @var Connection $db */
$db = $container->get(Connection::class);

$sql = "
    CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        email VARCHAR(100) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        money BIGINT UNSIGNED DEFAULT 1000,
        bullets INT UNSIGNED DEFAULT 100,
        health INT DEFAULT 100,
        energy INT DEFAULT 100,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

try {
    $db->executeStatement($sql);
    echo "Migration Successful: users table created.\n";
} catch (\Exception $e) {
    echo "Migration Failed: " . $e->getMessage() . "\n";
}
