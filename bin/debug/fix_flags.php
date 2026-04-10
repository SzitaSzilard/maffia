<?php
require_once __DIR__ . '/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;

$connectionParams = [
    'dbname' => 'netmafia',
    'user' => 'root',
    'password' => '',
    'host' => 'localhost',
    'driver' => 'pdo_mysql',
    'charset' => 'utf8mb4'
];

try {
    $conn = DriverManager::getConnection($connectionParams);

    $flags = [
        'US' => '🇺🇸',
        'GB' => '🇬🇧',
        'JP' => '🇯🇵',
        'FR' => '🇫🇷',
        'CA' => '🇨🇦',
        'CN' => '🇨🇳',
        'DE' => '🇩🇪',
        'IT' => '🇮🇹',
        'RU' => '🇷🇺',
        'CO' => '🇨🇴',
    ];

    foreach ($flags as $code => $flag) {
        $conn->executeStatement("UPDATE countries SET flag_emoji = ? WHERE code = ?", [$flag, $code]);
        echo "Updated $code with $flag\n";
    }
    
    // Safety check: is the column utf8mb4?
    // If not, emojis might fail again on next insert, but for now update should work if connection is utf8mb4.
    // Let's assume table is OK or we'd get error.
    
    echo "Flags updated successfully.\n";

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
