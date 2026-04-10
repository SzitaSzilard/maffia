<?php
require __DIR__ . '/../../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->safeLoad();

$pdo = new PDO(
    'mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'] . ';charset=utf8mb4',
    $_ENV['DB_USER'],
    $_ENV['DB_PASS']
);

$stmt = $pdo->query("
    SELECT c.code, c.name_hu, b.id as building_id, b.type, COUNT(rm.id) as menu_count 
    FROM countries c 
    LEFT JOIN buildings b ON b.country_code = c.code AND b.type = 'restaurant' 
    LEFT JOIN restaurant_menu rm ON rm.building_id = b.id 
    GROUP BY c.code, c.name_hu, b.id, b.type 
    ORDER BY menu_count ASC
");

echo str_pad('Code', 6) . str_pad('Country', 25) . str_pad('BldgID', 10) . str_pad('Type', 15) . 'MenuCount' . PHP_EOL;
echo str_repeat('-', 70) . PHP_EOL;

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo str_pad($row['code'], 6) 
       . str_pad($row['name_hu'], 25) 
       . str_pad($row['building_id'] ?? 'NULL', 10) 
       . str_pad($row['type'] ?? 'NULL', 15) 
       . $row['menu_count'] . PHP_EOL;
}
