<?php
declare(strict_types=1);

use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;

require_once __DIR__ . '/../vendor/autoload.php';

$containerBuilder = new ContainerBuilder();
$definitions = require __DIR__ . '/../config/container.php';
$containerBuilder->addDefinitions($definitions);
$container = $containerBuilder->build();

$db = $container->get(Connection::class);

echo "Executing Bank Module Migration...\n";

$sql = file_get_contents(__DIR__ . '/../migrations/002_bank_module.sql');

try {
    $db->executeStatement($sql);
    echo "Migration successful!\n";
} catch (\Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
