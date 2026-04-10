-- XP change log trigger
-- Minden XP módosítást loggol, akár közvetlenül DB-ben is

DELIMITER //

DROP TRIGGER IF EXISTS log_xp_changes//

CREATE TRIGGER log_xp_changes 
AFTER UPDATE ON users 
FOR EACH ROW 
BEGIN
    IF OLD.xp != NEW.xp THEN
        INSERT INTO xp_change_log (user_id, old_xp, new_xp, change_amount, change_source)
        VALUES (NEW.id, OLD.xp, NEW.xp, CAST(NEW.xp AS SIGNED) - CAST(OLD.xp AS SIGNED), 'direct_db');
    END IF;
END//

DELIMITER ;

