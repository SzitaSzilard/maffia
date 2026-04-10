<?php
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;

require dirname(__DIR__) . '/vendor/autoload.php';

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(dirname(__DIR__) . '/config/container.php');
$container = $containerBuilder->build();

$db = $container->get(Connection::class);

echo "Listing Countries...\n";
$countries = $db->fetchAllAssociative("SELECT * FROM countries");
print_r($countries);
