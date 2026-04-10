<?php
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;

require 'vendor/autoload.php';

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions('config/container.php');
$container = $containerBuilder->build();

$db = $container->get(Connection::class);

$columns = $db->fetchAllAssociative("SHOW COLUMNS FROM users LIKE 'highway_%'");

if (empty($columns)) {
    echo "No highway_* columns found.\n";
} else {
    foreach ($columns as $col) {
        echo $col['Field'] . " (" . $col['Type'] . ")\n";
    }
}
