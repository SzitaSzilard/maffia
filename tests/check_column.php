<?php
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

use Doctrine\DBAL\DriverManager;

$conn = DriverManager::getConnection([
    'dbname' => $_ENV['DB_NAME'],
    'user' => $_ENV['DB_USER'],
    'password' => $_ENV['DB_PASS'],
    'host' => $_ENV['DB_HOST'],
    'driver' => $_ENV['DB_DRIVER'] ?? 'pdo_mysql',
]);

$tables = ['credit_transactions', 'bullet_transactions', 'money_transactions'];
foreach ($tables as $table) {
    echo "--- $table ---\n";
    $columns = $conn->fetchAllAssociative("DESCRIBE $table");
    foreach ($columns as $col) {
        if ($col['Field'] === 'type') {
            echo "Column type: {$col['Type']}\n";
        }
    }
}
