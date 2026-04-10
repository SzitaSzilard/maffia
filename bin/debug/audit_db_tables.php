<?php
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;

require 'vendor/autoload.php';

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions('config/container.php');
$container = $containerBuilder->build();

$db = $container->get(Connection::class);

$tables = $db->fetchFirstColumn("SHOW TABLES");

echo json_encode($tables, JSON_PRETTY_PRINT);
