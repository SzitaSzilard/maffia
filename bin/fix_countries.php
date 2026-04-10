<?php
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;

require dirname(__DIR__) . '/vendor/autoload.php';

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(dirname(__DIR__) . '/config/container.php');
$container = $containerBuilder->build();

$db = $container->get(Connection::class);

echo "Fixing Country Codes...\n";

try {
    // 1. Update existing HUN users to US
    $affected = $db->executeStatement("UPDATE users SET country_code = 'US' WHERE country_code = 'HUN'");
    echo "Updated {$affected} users from HUN to US.\n";

    // 2. Change column default to US (Using direct SQL as DBAL schema manager is complex for this specific change in raw SQL)
    $db->executeStatement("ALTER TABLE users ALTER COLUMN country_code SET DEFAULT 'US'");
    echo "Updated default value for country_code to 'US'.\n";

    echo "SUCCESS: Database fixed!\n";
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
