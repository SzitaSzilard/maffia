<?php
require __DIR__ . '/../../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->safeLoad();
$pdo = new PDO('mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'] . ';charset=utf8mb4', $_ENV['DB_USER'], $_ENV['DB_PASS']);
print_r($pdo->query('DESCRIBE users')->fetchAll(PDO::FETCH_COLUMN));
