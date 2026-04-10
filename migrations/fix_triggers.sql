-- Fix all triggers with UNSIGNED value handling

-- Credit trigger
DROP TRIGGER IF EXISTS log_credit_changes;
DELIMITER //
CREATE TRIGGER log_credit_changes AFTER UPDATE ON users
FOR EACH ROW
BEGIN
    IF OLD.credits != NEW.credits THEN
        INSERT INTO credit_change_log (user_id, old_credits, new_credits, change_amount, change_source)
        VALUES (NEW.id, OLD.credits, NEW.credits, CAST(NEW.credits AS SIGNED) - CAST(OLD.credits AS SIGNED), 'direct_db');
    END IF;
END//
DELIMITER ;

-- Money trigger
DROP TRIGGER IF EXISTS log_money_changes;
DELIMITER //
CREATE TRIGGER log_money_changes AFTER UPDATE ON users
FOR EACH ROW
BEGIN
    IF OLD.money != NEW.money THEN
        INSERT INTO money_change_log (user_id, old_money, new_money, change_amount, change_source)
        VALUES (NEW.id, OLD.money, NEW.money, CAST(NEW.money AS SIGNED) - CAST(OLD.money AS SIGNED), 'direct_db');
    END IF;
END//
DELIMITER ;

-- XP trigger
DROP TRIGGER IF EXISTS log_xp_changes;
DELIMITER //
CREATE TRIGGER log_xp_changes AFTER UPDATE ON users
FOR EACH ROW
BEGIN
    IF OLD.xp != NEW.xp THEN
        INSERT INTO xp_change_log (user_id, old_xp, new_xp, change_amount, change_source)
        VALUES (NEW.id, OLD.xp, NEW.xp, CAST(NEW.xp AS SIGNED) - CAST(OLD.xp AS SIGNED), 'direct_db');
    END IF;
END//
DELIMITER ;
