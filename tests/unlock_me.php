<?php
require __DIR__ . '/../vendor/autoload.php';
$container = (new \DI\ContainerBuilder())->addDefinitions(__DIR__ . '/../config/container.php')->build();
/** @var \Netmafia\Infrastructure\CacheService $cache */
$cache = $container->get(\Netmafia\Infrastructure\CacheService::class);

echo "Ürítés: Összes bejelentkezési kísérlet és cache törlése...\n";
$cache->flush();
echo "Kész! Most már újra próbálkozhatsz a belépéssel.\n";
