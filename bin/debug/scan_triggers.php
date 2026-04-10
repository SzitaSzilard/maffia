<?php
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;

require 'vendor/autoload.php';

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions('config/container.php');
$container = $containerBuilder->build();

/** @var Connection $db */
$db = $container->get(Connection::class);
$pdo = $db->getNativeConnection();

$stmt = $pdo->query("SHOW TRIGGERS");
$triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$output = [];
foreach ($triggers as $trigger) {
    $output[] = [
        'Trigger' => $trigger['Trigger'],
        'Event' => $trigger['Event'],
        'Table' => $trigger['Table'],
        'Statement' => $trigger['Statement'],
        'Timing' => $trigger['Timing']
    ];
}

file_put_contents('db_triggers.json', json_encode($output, JSON_PRETTY_PRINT));
echo "Found " . count($triggers) . " triggers. Saved to db_triggers.json\n";
