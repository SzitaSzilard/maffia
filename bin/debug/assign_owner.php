<?php
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Netmafia\Modules\Buildings\Domain\BuildingService;

require __DIR__ . '/vendor/autoload.php';

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/config/container.php');
$container = $containerBuilder->build();

$buildingService = $container->get(BuildingService::class);

echo "Assigning Restaurant (ID 36 - US) to User 1...\n";

try {
    // ID 36 is the US Restaurant based on previous checks
    // User 1 is the admin/main user
    $success = $buildingService->assignOwner(36, 1);
    
    if ($success) {
        echo "SUCCESS: Restaurant assigned to User 1!\n";
    } else {
        echo "FAILED: Unknown error.\n";
    }
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
