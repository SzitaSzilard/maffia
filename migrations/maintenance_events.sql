-- Database Maintenance Events
-- Automatikus tisztogató script
-- Futtatás: mysql -u root netmafia < migrations/maintenance_events.sql

-- 1. Eseményütemező bekapcsolása (Globálisan)
SET GLOBAL event_scheduler = ON;

-- Ha esetleg létezik, dobjuk el
DROP EVENT IF EXISTS daily_log_cleanup;

DELIMITER //

-- 2. Napi tisztogató esemény létrehozása
-- Minden nap hajnali 03:00-kor fut le
CREATE EVENT daily_log_cleanup
ON SCHEDULE EVERY 1 DAY
STARTS (TIMESTAMP(CURRENT_DATE) + INTERVAL 1 DAY + INTERVAL 3 HOUR)
DO
BEGIN
    -- Pénz logok
    DELETE FROM money_change_log WHERE changed_at < NOW() - INTERVAL 30 DAY;
    INSERT INTO system_cleanup_logs (table_name, deleted_rows) VALUES ('money_change_log', ROW_COUNT());
    
    -- XP logok
    DELETE FROM xp_change_log WHERE changed_at < NOW() - INTERVAL 30 DAY;
    INSERT INTO system_cleanup_logs (table_name, deleted_rows) VALUES ('xp_change_log', ROW_COUNT());
    
    -- Audit logok
    DELETE FROM audit_logs WHERE created_at < NOW() - INTERVAL 60 DAY;
    INSERT INTO system_cleanup_logs (table_name, deleted_rows) VALUES ('audit_logs', ROW_COUNT());
    
    -- Prémium kredit logok
    DELETE FROM credit_change_log WHERE changed_at < NOW() - INTERVAL 90 DAY;
    INSERT INTO system_cleanup_logs (table_name, deleted_rows) VALUES ('credit_change_log', ROW_COUNT());
    
    -- Privát üzenetek (olvasatlan beragadt és extrém régi felesleges inaktív adatok)
    DELETE FROM messages WHERE created_at < NOW() - INTERVAL 180 DAY;
    INSERT INTO system_cleanup_logs (table_name, deleted_rows) VALUES ('messages', ROW_COUNT());
    
    -- Értesítések
    DELETE FROM notifications WHERE created_at < NOW() - INTERVAL 14 DAY;
    INSERT INTO system_cleanup_logs (table_name, deleted_rows) VALUES ('notifications', ROW_COUNT());
    
    -- Kocsma chat
    DELETE FROM kocsma_messages WHERE created_at < NOW() - INTERVAL 2 DAY;
    INSERT INTO system_cleanup_logs (table_name, deleted_rows) VALUES ('kocsma_messages', ROW_COUNT());
    
    -- Technikai logok
    DELETE FROM health_change_log WHERE changed_at < NOW() - INTERVAL 7 DAY;
    INSERT INTO system_cleanup_logs (table_name, deleted_rows) VALUES ('health_change_log', ROW_COUNT());
    
    DELETE FROM energy_change_log WHERE changed_at < NOW() - INTERVAL 7 DAY;
    INSERT INTO system_cleanup_logs (table_name, deleted_rows) VALUES ('energy_change_log', ROW_COUNT());

END//

DELIMITER ;
