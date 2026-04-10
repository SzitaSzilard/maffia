<?php
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;

require dirname(__DIR__) . '/vendor/autoload.php';

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(dirname(__DIR__) . '/config/container.php');
$container = $containerBuilder->build();

$db = $container->get(Connection::class);

echo "Migrating Hospital Module...\n";

$sql = file_get_contents(dirname(__DIR__) . '/migrations/008_hospital_module.sql');
$db->executeStatement($sql);

// Seed default price for all Hospital buildings (assuming type_id = 2 or name like '%kórház%')
// Let's find hospitals
$hospitals = $db->fetchAllAssociative("SELECT id FROM buildings WHERE name LIKE '%kórház%' OR name LIKE '%Hospital%'");

foreach ($hospitals as $hospital) {
    echo "Seeding price for Hospital ID: " . $hospital['id'] . "\n";
    $db->executeStatement(
        "INSERT IGNORE INTO hospital_prices (building_id, price_per_hp) VALUES (?, 52)",
        [$hospital['id']]
    );
}

echo "SUCCESS: Hospital module migrated!\n";
