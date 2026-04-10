<?php
$conn = new PDO('mysql:host=localhost;dbname=netmafia', 'root', '');
$stmt = $conn->query("SHOW COLUMNS FROM user_vehicles");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

$hasCurrentFuel = false;
foreach ($columns as $col) {
    echo $col['Field'] . "\n";
    if ($col['Field'] === 'current_fuel') {
        $hasCurrentFuel = true;
    }
}

if (!$hasCurrentFuel) {
    echo "\ncurrent_fuel column is MISSING. Adding it...\n";
    $conn->exec("ALTER TABLE user_vehicles ADD COLUMN current_fuel INT DEFAULT 100");
    echo "current_fuel added with default 100.\n";
} else {
    echo "\ncurrent_fuel column EXISTS.\n";
}
