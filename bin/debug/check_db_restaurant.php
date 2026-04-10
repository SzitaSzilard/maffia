<?php

use Netmafia\Shared\Infrastructure\Database\ConnectionFactory;

require __DIR__ . '/public/index.php'; // Bootstrap to get container (this might run app, bad idea)
// Better to just create a simple script using container.php

require __DIR__ . '/vendor/autoload.php';

$containerBuilder = new \DI\ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/config/container.php');
$container = $containerBuilder->build();

$db = $container->get(\Doctrine\DBAL\Connection::class);

echo "Checking 'buildings' table for 'etierem' or 'restaurant'...\n";
$buildings = $db->fetchAllAssociative("SELECT * FROM buildings WHERE type LIKE '%etterem%' OR type LIKE '%restaurant%' OR name_hu LIKE '%etterem%'");
print_r($buildings);

echo "\nChecking 'users' table columns...\n";
$columns = $db->fetchAllAssociative("SHOW COLUMNS FROM users");
print_r($columns);
