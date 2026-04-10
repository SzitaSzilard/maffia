<?php
require __DIR__ . '/../../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->safeLoad();
$pdo = new PDO('mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'], $_ENV['DB_USER'], $_ENV['DB_PASS']);

echo "=== USER_VEHICLES COLUMNS ===\n";
$cols = $pdo->query("DESCRIBE user_vehicles")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $col) {
    echo $col['Field'] . " (" . $col['Type'] . ") " . ($col['Null'] === 'YES' ? 'NULL' : 'NOT NULL') . " Default:" . ($col['Default'] ?? 'none') . "\n";
}

echo "\n=== SAMPLE USER_VEHICLE ===\n";
$sample = $pdo->query("SELECT * FROM user_vehicles LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
print_r($sample);
