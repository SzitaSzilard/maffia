<?php
require __DIR__ . '/../../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->safeLoad();

$pdo = new PDO(
    'mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'] . ';charset=utf8mb4',
    $_ENV['DB_USER'],
    $_ENV['DB_PASS']
);

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sql = "ALTER TABLE users ADD COLUMN car_theft_cooldown_until DATETIME DEFAULT NULL AFTER oc_cooldown_until";

try {
    $pdo->exec($sql);
    echo "Sikeresen hozzáadva a car_theft_cooldown_until oszlop a users táblához.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Az oszlop már létezik.\n";
    } else {
        echo "Hiba: " . $e->getMessage() . "\n";
    }
}
