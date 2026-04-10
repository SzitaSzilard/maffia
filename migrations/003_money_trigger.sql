-- Money change log trigger
-- Minden pénz módosítást loggol, akár közvetlenül DB-ben is

DELIMITER //

DROP TRIGGER IF EXISTS log_money_changes//

CREATE TRIGGER log_money_changes 
AFTER UPDATE ON users 
FOR EACH ROW 
BEGIN
    IF OLD.money != NEW.money THEN
        INSERT INTO money_change_log (user_id, old_money, new_money, change_amount, change_source)
        VALUES (NEW.id, OLD.money, NEW.money, NEW.money - OLD.money, 'direct_db');
    END IF;
END//

DELIMITER ;
