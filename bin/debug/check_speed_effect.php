<?php
try {
    $conn = new PDO('mysql:host=localhost;dbname=netmafia', 'root', '');
    $stmt = $conn->query("SELECT * FROM item_effects WHERE item_id = 147");
    $effects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($effects);
} catch (Exception $e) {
    echo $e->getMessage();
}
