<?php
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;

require dirname(__DIR__) . '/vendor/autoload.php';

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(dirname(__DIR__) . '/config/container.php');
$container = $containerBuilder->build();

$db = $container->get(Connection::class);

echo "Adding country_code to users table...\n";

$sql = file_get_contents(dirname(__DIR__) . '/migrations/007_add_country_to_users.sql');

try {
    // Execute line by line because DDL
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $stmt) {
        if (!empty($stmt)) {
            $db->executeStatement($stmt);
        }
    }
    echo "SUCCESS: Users table updated with country_code!\n";
} catch (\Exception $e) {
    if (strpos($e->getMessage(), "Duplicate column name") !== false) {
        echo "NOTICE: Column already exists.\n";
    } else {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}
