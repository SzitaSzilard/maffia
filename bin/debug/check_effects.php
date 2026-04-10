<?php
try {
    $conn = new PDO('mysql:host=localhost;dbname=netmafia', 'root', '');
    $stmt = $conn->query("SHOW TABLES LIKE '%effect%'");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($tables)) {
        echo "No effect tables found.\n";
        // Check for 'user_modifiers' or similar
        $stmt2 = $conn->query("SHOW TABLES LIKE '%modi%'");
        $tables2 = $stmt2->fetchAll(PDO::FETCH_COLUMN);
        print_r($tables2);
    } else {
        print_r($tables);
    }
    
    // Check columns in users table for 'speed'
    $stmt3 = $conn->query("SHOW COLUMNS FROM users LIKE '%speed%'");
    $cols = $stmt3->fetchAll(PDO::FETCH_ASSOC);
    print_r($cols);

} catch (Exception $e) {
    echo $e->getMessage();
}
