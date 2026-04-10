<?php
require __DIR__ . '/../vendor/autoload.php';
$containerBuilder = new \DI\ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/../config/container.php');
$db = $containerBuilder->build()->get(\Doctrine\DBAL\Connection::class);

$sql = file_get_contents(__DIR__ . '/../migrations/011_add_buildings_fk.sql');
try {
    $db->executeStatement($sql);
    echo "Migration 011 Applied Successfully.";
} catch (\Exception $e) {
    echo "Migration Failed (Likely already applied or constraint exists): " . $e->getMessage();
}
