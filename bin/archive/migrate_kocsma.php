<?php
declare(strict_types=1);

use DI\Container;
use Slim\Factory\AppFactory;
use Doctrine\DBAL\Connection;

require __DIR__ . '/../vendor/autoload.php';

// Boot App to get Container
$containerBuilder = new \DI\ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/../config/container.php');
$container = $containerBuilder->build();

/** @var Connection $db */
$db = $container->get(Connection::class);

$sql = "
    CREATE TABLE IF NOT EXISTS kocsma_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        username VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        is_drunk TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

try {
    $db->executeStatement($sql);
    echo "Migration Successful: kocsma_messages table created.\n";
} catch (\Exception $e) {
    echo "Migration Failed: " . $e->getMessage() . "\n";
}
