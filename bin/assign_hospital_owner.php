<?php
use Slim\Factory\AppFactory;
use DI\ContainerBuilder;

require __DIR__ . '/../vendor/autoload.php';

// Instantiate PHP-DI ContainerBuilder
$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/../config/container.php');
$container = $containerBuilder->build();

$db = $container->get(\Doctrine\DBAL\Connection::class);

echo "Assigning USA Hospital to User 1...\n";

// 1. Find USA Hospital
$hospital = $db->fetchAssociative("SELECT * FROM buildings WHERE country_code = 'US' AND (type = 'hospital' OR name LIKE '%kórház%')");

if (!$hospital) {
    echo "ERROR: No hospital found in USA (US).\n";
    exit(1);
}

$hospitalId = $hospital['id'];
echo "Found Hospital ID: $hospitalId (Name: {$hospital['name']}, Type: {$hospital['type']})\n";

// 2. Assign to User 1
$userId = 1;

// Update the owner
$result = $db->executeStatement("UPDATE buildings SET owner_id = ? WHERE id = ?", [$userId, $hospitalId]);

if ($result > 0) {
    echo "SUCCESS: Hospital (ID: $hospitalId) assigned to User $userId.\n";
} else {
    echo "WARNING: No changes made (User $userId might already be the owner).\n";
}

// Verify
$updated = $db->fetchAssociative("SELECT owner_id, u.username FROM buildings b LEFT JOIN users u ON u.id = b.owner_id WHERE b.id = ?", [$hospitalId]);
echo "Current Owner: " . ($updated['username'] ?? 'None') . " (ID: {$updated['owner_id']})\n";
