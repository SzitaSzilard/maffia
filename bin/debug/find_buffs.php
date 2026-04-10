<?php
require __DIR__ . '/../../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->safeLoad();
$pdo = new PDO('mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'], $_ENV['DB_USER'], $_ENV['DB_PASS']);

echo "item_effects tábla?\n";
try {
    $stmt = $pdo->query('SELECT DISTINCT effect_type, context FROM user_buffs');
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch(Exception $e) { echo $e->getMessage() . "\n"; }

echo "Keresés az Items táblában JSON vagy mezőkre:\n";
try {
    print_r($pdo->query('DESCRIBE items')->fetchAll(PDO::FETCH_ASSOC));
} catch(Exception $e) { echo $e->getMessage() . "\n"; }

try {
    print_r($pdo->query('SELECT name, type, buff_type, buff_value, description FROM items WHERE has_buff = 1 OR description LIKE "%cooldown%" OR description LIKE "%lopás%"')->fetchAll(PDO::FETCH_ASSOC));
} catch(Exception $e) { echo $e->getMessage() . "\n"; }
