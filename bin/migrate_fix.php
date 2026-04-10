<?php
require __DIR__ . '/../vendor/autoload.php';

$container = require __DIR__ . '/../config/container.php';
$db = $container->get(\Doctrine\DBAL\Connection::class);

try {
    echo "Adding 'is_banned' column to users table...\n";
    $db->executeStatement("ALTER TABLE users ADD COLUMN is_banned TINYINT(1) DEFAULT 0");
    echo "Success! Column added.\n";
} catch (\Exception $e) {
    if (strpos($e->getMessage(), "Duplicate column name") !== false) {
        echo "Column already exists. Skipping.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
