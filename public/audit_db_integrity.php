<?php
use DI\ContainerBuilder;

require __DIR__ . '/../vendor/autoload.php';

// Bootstrap
$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/../config/container.php');
$container = $containerBuilder->build();
$db = $container->get(\Doctrine\DBAL\Connection::class);

echo "<h1>NETMAFIA DB INTEGRITY AUDIT - EVIDENCE LOG</h1><pre>";
$timestamp = date('Y-m-d H:i:s');
echo "Audit started at: $timestamp\n\n";

function run_evidence_check($title, $sql, $params = []) {
    global $db;
    echo "--------------------------------------------------------\n";
    echo "CHECK: $title\n";
    echo "QUERY: $sql\n";
    
    try {
        if (stripos($sql, 'SELECT') === 0 || stripos($sql, 'SHOW') === 0 || stripos($sql, 'DESCRIBE') === 0) {
            $results = $db->fetchAllAssociative($sql, $params);
            
            if (empty($results)) {
                echo "RESULT: [EMPTY SET] (0 rows)\n";
                return 0;
            }
            
            // Format Table Output
            echo "RESULT:\n";
            $headers = array_keys($results[0]);
            echo implode(" | ", $headers) . "\n";
            echo str_repeat("-", 50) . "\n";
            
            foreach ($results as $row) {
                echo implode(" | ", $row) . "\n";
            }
            return count($results);
        } else {
            // Non-select queries (shouldn't happen in audit but safe to handle)
            echo "RESULT: Executed.\n";
            return 0;
        }
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        return -1;
    }
}

// 1. Verify Storage Engines
echo "\n[1] TABLE ENGINE VERIFICATION (Must be InnoDB)\n";
run_evidence_check(
    "Check Table Engines", 
    "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"
);

// 2. Foreign Key Definitions
echo "\n[2] FOREIGN KEY DEFINITIONS\n";
run_evidence_check(
    "List All Foreign Keys", 
    "SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME 
     FROM information_schema.KEY_COLUMN_USAGE 
     WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL"
);

// 3. Orphan Data Scans
echo "\n[3] ORPHAN DATA DETECTION\n";

// 3.1 Buildings -> Users
run_evidence_check(
    "Orphan Buildings (Invalid Owner ID)",
    "SELECT id, name_hu, owner_id FROM buildings WHERE owner_id IS NOT NULL AND owner_id NOT IN (SELECT id FROM users)"
);

// 3.2 Audit Logs -> Users
run_evidence_check(
    "Orphan Audit Logs (Invalid User ID)",
    "SELECT id, user_id, action FROM audit_logs WHERE user_id IS NOT NULL AND user_id NOT IN (SELECT id FROM users)"
);

// 3.3 Restaurant Menu -> Buildings
run_evidence_check(
    "Orphan Menu Items (Invalid Building ID)",
    "SELECT id, name, building_id FROM restaurant_menu WHERE building_id NOT IN (SELECT id FROM buildings)"
);

// 4. Logical Integrity
echo "\n[4] LOGICAL DATA INTEGRITY\n";

run_evidence_check(
    "Users with Negative Money",
    "SELECT id, username, money FROM users WHERE money < 0"
);

run_evidence_check(
    "Buildings with Negative Price",
    "SELECT id, name_hu, usage_price FROM buildings WHERE usage_price < 0"
);

run_evidence_check(
    "Buildings with Invalid Pending Revenue (Negative)",
    "SELECT id, name_hu, pending_revenue FROM buildings WHERE pending_revenue < 0"
);

echo "\nAudit finished.\n</pre>";
