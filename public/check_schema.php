<?php
require __DIR__ . '/../vendor/autoload.php';
$containerBuilder = new \DI\ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/../config/container.php');
$db = $containerBuilder->build()->get(\Doctrine\DBAL\Connection::class);

$columns = $db->fetchFirstColumn("DESCRIBE users");
echo implode("\n", $columns);
