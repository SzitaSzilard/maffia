<?php
try {
    $conn = new PDO('mysql:host=localhost;dbname=netmafia', 'root', '');
    
    // Check tables related to user effects
    $stmt = $conn->query("SHOW TABLES LIKE 'user_active_effects'");
    if ($stmt->rowCount() > 0) {
        $stmt2 = $conn->query("DESCRIBE user_active_effects");
        print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));
    } else {
        echo "No 'user_active_effects' table found.\n";
        // Check users table columns again for any json field or similar
        $stmt3 = $conn->query("SHOW COLUMNS FROM users");
        $cols = $stmt3->fetchAll(PDO::FETCH_COLUMN);
        foreach ($cols as $c) {
            if (strpos($c, 'effect') !== false || strpos($c, 'buff') !== false) {
                echo "Found potential column: $c\n";
            }
        }
    }

} catch (Exception $e) {
    echo $e->getMessage();
}
