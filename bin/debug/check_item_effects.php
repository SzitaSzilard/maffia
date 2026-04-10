<?php
require __DIR__ . '/../../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->safeLoad();
$pdo = new PDO('mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'], $_ENV['DB_USER'], $_ENV['DB_PASS']);

echo "Összes item effect a DB-ben:\n";
try {
    $stmt = $pdo->query('
        SELECT i.name, ie.effect_type, ie.value, ie.duration_minutes, ie.context 
        FROM item_effects ie 
        JOIN items i ON i.id = ie.item_id
    ');
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch(Exception $e) { echo $e->getMessage() . "\n"; }
