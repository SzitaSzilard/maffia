<?php
require __DIR__ . '/../vendor/autoload.php';

$container = require __DIR__ . '/../config/container.php';
$db = $container->get(\Doctrine\DBAL\Connection::class);

try {
    echo "Running Ammo Factory migration...\n";
    $sql = file_get_contents(__DIR__ . '/../migrations/013_ammo_factory.sql');
    $db->executeStatement($sql);
    echo "Success! Table created.\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
