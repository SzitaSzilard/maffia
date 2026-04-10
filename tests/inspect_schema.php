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

$columns = $conn->fetchAllAssociative('DESCRIBE users');
foreach ($columns as $col) {
    echo "{$col['Field']} ({$col['Type']}) - Null: {$col['Null']}\n";
}
