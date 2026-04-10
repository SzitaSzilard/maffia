<?php
use DI\ContainerBuilder;
use Netmafia\Infrastructure\CacheService;

require 'vendor/autoload.php';

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions('config/container.php');
$container = $containerBuilder->build();

$cache = $container->get(CacheService::class);
$cache->flush();

echo "Cache cleared successfully.\n";

// Also try to remove directory if files exist
$cacheDir = __DIR__ . '/var/cache';
if (is_dir($cacheDir)) {
    $files = glob($cacheDir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) unlink($file);
    }
    echo "Removed cache files from var/cache.\n";
}
