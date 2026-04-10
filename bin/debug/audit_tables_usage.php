<?php
$tables = [
    "ammo_factory_production", "audit_logs", "bank_accounts", "bank_transactions",
    "building_income_log", "buildings", "combat_log", "countries",
    "credit_change_log", "credit_integrity_violations", "credit_transactions",
    "death_log", "energy_change_log", "game_news", "health_change_log",
    "hospital_prices", "item_effects", "items", "kocsma_messages",
    "messages", "money_change_log", "money_integrity_violations",
    "money_transactions", "notifications", "phinxlog", "properties",
    "rank_progression_log", "restaurant_menu", "system_cleanup_logs",
    "user_buffs", "user_combat_settings", "user_garage_purchases",
    "user_garage_slots", "user_items", "user_properties", "user_sleep",
    "user_vehicles", "users", "vehicles", "xp_change_log"
];

$dir = new RecursiveDirectoryIterator('src');
$iterator = new RecursiveIteratorIterator($dir);
$phpFiles = new RegexIterator($iterator, '/^.+\.php$/i', RecursiveRegexIterator::GET_MATCH);

$usage = array_fill_keys($tables, 0);

foreach ($phpFiles as $file) {
    if ($file[0] == 'src\Shared\Infrastructure\Database\Migrations' || strpos($file[0], 'Migration') !== false) {
       continue; // Skip migrations? No, maybe we should include them? Let's skip for now to find usage in LOGIC.
    }
    
    $content = file_get_contents($file[0]);
    foreach ($tables as $table) {
        if (strpos($content, $table) !== false) {
            $usage[$table]++;
        }
    }
}

echo "Unused Tables (in src logic):\n";
foreach ($usage as $table => $count) {
    if ($count === 0) {
        echo "- $table\n";
    }
}

echo "\nUsage Counts:\n";
foreach ($usage as $table => $count) {
    echo "$table: $count\n";
}
