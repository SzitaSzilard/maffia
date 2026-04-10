<?php
require __DIR__ . '/../vendor/autoload.php';

// Boot App
$containerBuilder = new \DI\ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/../config/container.php');
$container = $containerBuilder->build();

/** @var \Doctrine\DBAL\Connection $db */
$db = $container->get(\Doctrine\DBAL\Connection::class);

echo "--- DATABASE STATUS ---\n";
echo "Host: " . $_ENV['DB_HOST'] . "\n";
echo "Database: " . $_ENV['DB_NAME'] . "\n";

// List Tables
$tables = $db->fetchFirstColumn("SHOW TABLES");
echo "Tables found: " . implode(", ", $tables) . "\n";

// Count Users
if (in_array('users', $tables)) {
    $count = $db->fetchOne("SELECT COUNT(*) FROM users");
    echo "Registered Users: $count\n";
    $lastUser = $db->fetchAssociative("SELECT * FROM users ORDER BY id DESC LIMIT 1");
    echo "Last User: " . $lastUser['username'] . " (ID: " . $lastUser['id'] . ")\n";
}
