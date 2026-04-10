<?php
require __DIR__ . '/../../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->safeLoad();
$pdo = new PDO('mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'] . ';charset=utf8mb4', $_ENV['DB_USER'], $_ENV['DB_PASS']);
echo "TABLES:\n";
print_r($pdo->query('SHOW TABLES LIKE "%vehic%"')->fetchAll(PDO::FETCH_COLUMN));
echo "\nuser_vehicles:\n";
print_r($pdo->query('DESCRIBE user_vehicles')->fetchAll(PDO::FETCH_ASSOC));
echo "\nvehicles:\n";
print_r($pdo->query('DESCRIBE vehicles')->fetchAll(PDO::FETCH_ASSOC));
