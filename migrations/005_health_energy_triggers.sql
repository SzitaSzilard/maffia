-- Health and Energy change log triggers

DELIMITER //

DROP TRIGGER IF EXISTS log_health_changes//

CREATE TRIGGER log_health_changes 
AFTER UPDATE ON users 
FOR EACH ROW 
BEGIN
    IF OLD.health != NEW.health THEN
        INSERT INTO health_change_log (user_id, old_health, new_health, change_amount, change_source)
        VALUES (NEW.id, OLD.health, NEW.health, CAST(NEW.health AS SIGNED) - CAST(OLD.health AS SIGNED), 'direct_db');
    END IF;
END//

DROP TRIGGER IF EXISTS log_energy_changes//

CREATE TRIGGER log_energy_changes 
AFTER UPDATE ON users 
FOR EACH ROW 
BEGIN
    IF OLD.energy != NEW.energy THEN
        INSERT INTO energy_change_log (user_id, old_energy, new_energy, change_amount, change_source)
        VALUES (NEW.id, OLD.energy, NEW.energy, CAST(NEW.energy AS SIGNED) - CAST(OLD.energy AS SIGNED), 'direct_db');
    END IF;
END//

DELIMITER ;
