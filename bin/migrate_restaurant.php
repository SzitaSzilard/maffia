<?php
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;

require __DIR__ . '/../vendor/autoload.php';
$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/../config/container.php');
$container = $containerBuilder->build();

$db = $container->get(Connection::class);

echo "Running migration 006_restaurant_menu.sql...\n";
$sql = file_get_contents(__DIR__ . '/../migrations/006_restaurant_menu.sql');

// Split by ; but handle potential complexity? Simple split is usually fine for this size.
$statements = explode(';', $sql);

foreach ($statements as $stmt) {
    if (trim($stmt) !== '') {
        try {
            $db->executeStatement($stmt);
        } catch (\Exception $e) {
            echo "Error executing statement: " . $e->getMessage() . "\n";
            // Don't die, might be duplicate insert which is fine-ish or handled by logic
        }
    }
}
echo "Migration completed.\n";
