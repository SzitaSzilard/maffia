<?php
try {
    $conn = new PDO('mysql:host=localhost;dbname=netmafia', 'root', '');
    $stmt = $conn->query("SELECT * FROM items WHERE name LIKE '%speed%' OR name LIKE '%Speed%'");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        // Try 'shop_items' if 'items' fails or is empty
        $stmt2 = $conn->query("SHOW TABLES LIKE 'shop_items'");
        if ($stmt2->rowCount() > 0) {
             $stmt3 = $conn->query("SELECT * FROM shop_items WHERE name LIKE '%speed%'");
             $items = $stmt3->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    print_r($items);

} catch (Exception $e) {
    echo $e->getMessage();
}
