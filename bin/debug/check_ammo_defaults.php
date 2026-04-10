<?php
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;

require 'vendor/autoload.php';

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions('config/container.php');
$container = $containerBuilder->build();

$db = $container->get(Connection::class);
$sm = $db->createSchemaManager();
$cols = $sm->listTableColumns('ammo_factory_production');

foreach ($cols as $col) {
    echo $col->getName() . " | Default: " . ($col->getDefault() ?? 'NULL') . " | NotNull: " . ($col->getNotnull() ? 'YES' : 'NO') . "\n";
}
