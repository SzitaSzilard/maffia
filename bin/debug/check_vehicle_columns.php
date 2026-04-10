<?php
require_once __DIR__ . '/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;

$connectionParams = [
    'dbname' => 'netmafia',
    'user' => 'root',
    'password' => '',
    'host' => 'localhost',
    'driver' => 'pdo_mysql',
];

try {
    $conn = DriverManager::getConnection($connectionParams);
    $schema = $conn->createSchemaManager();
    $columns = $schema->listTableColumns('user_vehicles');

    echo "Columns in user_vehicles:\n";
    foreach ($columns as $column) {
        echo "- " . $column->getName() . " (" . $column->getType()->getName() . ")\n";
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
