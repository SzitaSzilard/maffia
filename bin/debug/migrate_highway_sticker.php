<?php
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;

require 'vendor/autoload.php';

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions('config/container.php');
$container = $containerBuilder->build();

$db = $container->get(Connection::class);

try {
    $db->executeStatement("
        ALTER TABLE users 
        ADD COLUMN highway_sticker_level TINYINT DEFAULT 0 COMMENT '0=None, 1=7-Limit, 2=10-Limit, 3=Unlimited',
        ADD COLUMN highway_sticker_expiry DATETIME DEFAULT NULL
    ");
    echo "Migration successful: Added columns to users table.\n";
} catch (\Exception $e) {
    if (strpos($e->getMessage(), "Duplicate column name") !== false) {
        echo "Migration skipped: Columns already exist.\n";
    } else {
        echo "Migration failed: " . $e->getMessage() . "\n";
    }
}
