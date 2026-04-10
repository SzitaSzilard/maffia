<?php
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;

require 'vendor/autoload.php';

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions('config/container.php');
$container = $containerBuilder->build();

$db = $container->get(Connection::class);

$schemaManager = $db->createSchemaManager();
$tables = $schemaManager->listTableNames();

if (in_array('ammo_factory_production', $tables)) {
    echo "Table 'ammo_factory_production' EXISTS.\n";
    $columns = $schemaManager->listTableColumns('ammo_factory_production');
    foreach ($columns as $col) {
        echo "- " . $col->getName() . " (" . $col->getType()->getName() . ")\n";
    }
} else {
    echo "Table 'ammo_factory_production' DOES NOT EXIST.\n";
}
