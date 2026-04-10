<?php
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;

require 'vendor/autoload.php';

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions('config/container.php');
$container = $containerBuilder->build();

$db = $container->get(Connection::class);

echo "Checking and inserting missing buildings...\n";

// 1. Get all countries and building types
$countries = $db->fetchAllAssociative("SELECT code, name_hu FROM countries");
// Define standard building types that should exist everywhere
$buildingTypes = [
    'gas_station', 
    'ammo_factory', 
    'lottery', 
    'airport', 
    'hospital', 
    'restaurant', 
    'highway'
];

$count = 0;

foreach ($countries as $country) {
    foreach ($buildingTypes as $type) {
        // Check if exists
        $exists = $db->fetchOne(
            "SELECT COUNT(*) FROM buildings WHERE country_code = ? AND type = ?",
            [$country['code'], $type]
        );

        if ($exists == 0) {
            echo "Inserting $type for {$country['name_hu']} ({$country['code']})...\n";
            
            // Default values
            $usagePrice = 0;
            $ownerCutPercent = 0;
            
            // Type specific defaults (optional, can be refined)
            if ($type === 'highway') {
                 // Highway usage is usually free unless sticker, or maybe small toll? 
                 // Stickers are bought separately so usage is 0.
                 $usagePrice = 0;
            } elseif ($type === 'hospital') {
                 $usagePrice = 100; // Base heal price
            } elseif ($type === 'gas_station') {
                 $usagePrice = 2; // Price per liter
            } elseif ($type === 'restaurant') {
                 $usagePrice = 10; // Price per energy
            }

            $db->insert('buildings', [
                'country_code' => $country['code'],
                'type' => $type,
                'name_hu' => ucfirst(str_replace('_', ' ', $type)), // visible name
                'owner_id' => null, // System owned
                'usage_price' => $usagePrice,
                'owner_cut_percent' => 50, // Default cut if bought
                'payout_mode' => 'daily',
                'created_at' => date('Y-m-d H:i:s')
            ]);
            $count++;
        }
    }
}

echo "Done! Inserted $count missing buildings.\n";
