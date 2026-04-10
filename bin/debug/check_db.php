<?php
require 'vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$db = new \PDO('mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'], $_ENV['DB_USER'], $_ENV['DB_PASS']);

$stmt = $db->query('DESCRIBE items');
echo "ITEMS TABLE:\n";
foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
    echo json_encode($row) . "\n";
}

$stmt = $db->query('DESCRIBE item_effects');
echo "\nITEM_EFFECTS TABLE:\n";
foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
    echo json_encode($row) . "\n";
}

$stmt = $db->query('DESCRIBE game_settings');
echo "\nGAME_SETTINGS TABLE:\n";
if ($stmt) {
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        echo json_encode($row) . "\n";
    }
} else {
    echo "No game_settings table found.\n";
}
