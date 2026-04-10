<?php
require __DIR__ . '/../../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->safeLoad();
$pdo = new PDO('mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'], $_ENV['DB_USER'], $_ENV['DB_PASS']);

echo "=== COUNTRIES ===\n";
$countries = $pdo->query("SELECT * FROM countries")->fetchAll(PDO::FETCH_ASSOC);
foreach ($countries as $c) {
    echo $c['code'] . " => " . ($c['name_hu'] ?? $c['name'] ?? '?') . "\n";
}

echo "\n=== VEHICLES (first 20) ===\n";
$vehicles = $pdo->query("SELECT * FROM vehicles LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
print_r($vehicles);

echo "\n=== VEHICLE COLUMNS ===\n";
$cols = $pdo->query("DESCRIBE vehicles")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $col) {
    echo $col['Field'] . " (" . $col['Type'] . ")\n";
}
