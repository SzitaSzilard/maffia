<?php
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;

require 'vendor/autoload.php';

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions('config/container.php');
$container = $containerBuilder->build();

/** @var Connection $db */
$db = $container->get(Connection::class);
$pdo = $db->getNativeConnection();

try {
    $pdo->exec("ALTER TABLE users ADD COLUMN last_airplane_travel_time DATETIME DEFAULT NULL AFTER last_travel_time");
    echo "Added last_airplane_travel_time column successfully.\n";
} catch (\PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
