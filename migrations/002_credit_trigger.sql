-- Credit change log trigger
-- Minden kredit módosítást loggol, akár közvetlenül DB-ben is

DELIMITER //

DROP TRIGGER IF EXISTS log_credit_changes//

CREATE TRIGGER log_credit_changes 
AFTER UPDATE ON users 
FOR EACH ROW 
BEGIN
    IF OLD.credits != NEW.credits THEN
        INSERT INTO credit_change_log (user_id, old_credits, new_credits, change_amount, change_source)
        VALUES (NEW.id, OLD.credits, NEW.credits, NEW.credits - OLD.credits, 'direct_db');
    END IF;
END//

DELIMITER ;
