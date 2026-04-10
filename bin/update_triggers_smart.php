<?php
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;

require dirname(__DIR__) . '/vendor/autoload.php';

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(dirname(__DIR__) . '/config/container.php');
$container = $containerBuilder->build();

$db = $container->get(Connection::class);

echo "Updating Triggers for Smart Audit Logging...\n";

$sql = file_get_contents(__DIR__ . '/migrations/fix_triggers_smart.sql');

// Split by delimiter logic is tricky in PHP raw execution for complex triggers, 
// so we'll execute the DROP/CREATE commands manually without the DELIMITER syntax clutter.

try {
    $db->executeStatement("DROP TRIGGER IF EXISTS log_health_changes");
    $db->executeStatement("
        CREATE TRIGGER log_health_changes 
        AFTER UPDATE ON users 
        FOR EACH ROW 
        BEGIN
            DECLARE log_source VARCHAR(50);
            SET log_source = IFNULL(@audit_source, 'direct_db');

            IF OLD.health != NEW.health THEN
                INSERT INTO health_change_log (user_id, old_health, new_health, change_amount, change_source)
                VALUES (NEW.id, OLD.health, NEW.health, CAST(NEW.health AS SIGNED) - CAST(OLD.health AS SIGNED), log_source);
            END IF;
        END
    ");

    $db->executeStatement("DROP TRIGGER IF EXISTS log_energy_changes");
    $db->executeStatement("
        CREATE TRIGGER log_energy_changes 
        AFTER UPDATE ON users 
        FOR EACH ROW 
        BEGIN
            DECLARE log_source VARCHAR(50);
            SET log_source = IFNULL(@audit_source, 'direct_db');

            IF OLD.energy != NEW.energy THEN
                INSERT INTO energy_change_log (user_id, old_energy, new_energy, change_amount, change_source)
                VALUES (NEW.id, OLD.energy, NEW.energy, CAST(NEW.energy AS SIGNED) - CAST(OLD.energy AS SIGNED), log_source);
            END IF;
        END
    ");

    echo "SUCCESS: Triggers updated!\n";
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
