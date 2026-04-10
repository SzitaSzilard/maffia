<?php
require __DIR__ . '/../../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->safeLoad();
$pdo = new PDO('mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'], $_ENV['DB_USER'], $_ENV['DB_PASS']);
$stmt = $pdo->query('SELECT id, user_id, vehicle_id, location, country FROM user_vehicles LIMIT 10');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
