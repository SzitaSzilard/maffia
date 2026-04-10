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

// Building IDs that are missing menu items
$missingBuildingIds = [10007, 10014, 10021];

$menuItems = [
    ['name' => 'Beefsteaks',       'image' => 'beefsteak.jpg',       'energy' => 100, 'price' => 84],
    ['name' => 'Amerikai saláta',  'image' => 'salad.jpg',           'energy' => 30,  'price' => 25],
    ['name' => 'Őszibarack pite',  'image' => 'pie.jpg',             'energy' => 50,  'price' => 45],
    ['name' => 'Lazacos Rántotta', 'image' => 'scrambled_eggs.jpg',  'energy' => 75,  'price' => 65],
];

$stmt = $pdo->prepare("INSERT INTO restaurant_menu (building_id, name, image, energy, price) VALUES (?, ?, ?, ?, ?)");

foreach ($missingBuildingIds as $buildingId) {
    foreach ($menuItems as $item) {
        $stmt->execute([$buildingId, $item['name'], $item['image'], $item['energy'], $item['price']]);
        echo "Inserted '{$item['name']}' for building_id={$buildingId}" . PHP_EOL;
    }
}

echo PHP_EOL . "Done! Inserted " . (count($missingBuildingIds) * count($menuItems)) . " menu items total." . PHP_EOL;
