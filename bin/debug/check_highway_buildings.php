<?php
$conn = new PDO('mysql:host=localhost;dbname=netmafia', 'root', '');
$stmt = $conn->query("SELECT * FROM buildings WHERE type = 'highway'");
$highways = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($highways)) {
    echo "NO HIGHWAYS FOUND in 'buildings' table.\n";
} else {
    echo count($highways) . " HIGHWAYS FOUND:\n";
    foreach ($highways as $h) {
        echo "- ID: {$h['id']}, Country: {$h['country_code']}, Name: {$h['name_hu']}, Usage Price: {$h['usage_price']}\n";
    }
}
