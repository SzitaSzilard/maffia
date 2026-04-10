<?php
require __DIR__ . '/../../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->safeLoad();
$pdo = new PDO('mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'], $_ENV['DB_USER'], $_ENV['DB_PASS']);
$pdo->exec("ALTER TABLE users ADD COLUMN car_theft_attempts INT NOT NULL DEFAULT 0");
echo "car_theft_attempts oszlop hozzáadva!\n";
