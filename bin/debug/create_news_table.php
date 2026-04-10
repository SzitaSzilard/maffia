<?php
use DI\ContainerBuilder;

require __DIR__ . '/vendor/autoload.php';

$containerBuilder = new ContainerBuilder();
$definitions = require __DIR__ . '/config/container.php';
$containerBuilder->addDefinitions($definitions);
$container = $containerBuilder->build();

$db = $container->get(\Doctrine\DBAL\Connection::class);

$sql = "
CREATE TABLE IF NOT EXISTS `game_news` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `author_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

try {
    $db->executeStatement($sql);
    echo "Table 'game_news' created successfully.\n";
} catch (\Exception $e) {
    echo "Error creating table: " . $e->getMessage() . "\n";
}
