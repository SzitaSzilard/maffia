-- =============================================================================
-- Trigger és Logging Cleanup Migration
-- Netmafia | 2026-02-28
-- =============================================================================
-- CÉL: Egyértelmű szerep minden log táblának:
--
--   money_change_log   → CSAK bypass detektálás (ha nem MoneyService írta)
--   credit_change_log  → CSAK bypass detektálás (ha nem CreditService írta)
--   bullet_change_log  → CSAK bypass detektálás (ha nem BulletService írta) [ÚJ]
--   health_change_log  → Minden health változás (HealthService @audit_source-szal)
--   energy_change_log  → Minden energy változás (HealthService @audit_source-szal)
--   xp_change_log      → Minden XP változás (@audit_source olvasással javítva)
--
-- PHP transactions táblák = Source of truth + integritás ellenőrzés:
--   money_transactions, credit_transactions, bullet_transactions
-- =============================================================================


-- ─────────────────────────────────────────────────────────────────────────────
-- 1. bullet_change_log tábla — bypass detektálás töltényre
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS bullet_change_log (
    id            INT          AUTO_INCREMENT PRIMARY KEY,
    user_id       INT          NOT NULL,
    old_bullets   INT          NOT NULL,
    new_bullets   INT          NOT NULL,
    change_amount INT          NOT NULL,
    change_source VARCHAR(50)  NULL DEFAULT 'direct_db_bypass',
    changed_at    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_user   (user_id),
    INDEX idx_date   (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────────────────
-- 2. log_money_changes — CSAK akkor logol ha @audit_source IS NULL
--    (= bypass, nem MoneyService csinálta)
--    Eddig: minden money változást logolt → duplikált money_transactions-szal
-- ─────────────────────────────────────────────────────────────────────────────
DROP TRIGGER IF EXISTS log_money_changes;

DELIMITER $$
CREATE TRIGGER log_money_changes
AFTER UPDATE ON users
FOR EACH ROW
BEGIN
    -- Csak akkor logolunk ha @audit_source NEM volt beállítva
    -- = nem a PHP MoneyService tette (az mindig beállítja) → bypass/fraud
    IF OLD.money != NEW.money AND @audit_source IS NULL THEN
        INSERT INTO money_change_log (user_id, old_money, new_money, change_amount, change_source)
        VALUES (NEW.id, OLD.money, NEW.money,
                CAST(NEW.money AS SIGNED) - CAST(OLD.money AS SIGNED),
                'direct_db_bypass');
    END IF;
END$$
DELIMITER ;


-- ─────────────────────────────────────────────────────────────────────────────
-- 3. log_credit_changes — @audit_source támogatás hozzáadva + bypass-only
--    Eddig: mindig 'direct_db'-t írt, @audit_source ignorálta
-- ─────────────────────────────────────────────────────────────────────────────
DROP TRIGGER IF EXISTS log_credit_changes;

DELIMITER $$
CREATE TRIGGER log_credit_changes
AFTER UPDATE ON users
FOR EACH ROW
BEGIN
    -- Csak bypass esetén logol (PHP CreditService @audit_source-t állít)
    IF OLD.credits != NEW.credits AND @audit_source IS NULL THEN
        INSERT INTO credit_change_log (user_id, old_credits, new_credits, change_amount, change_source)
        VALUES (NEW.id, OLD.credits, NEW.credits,
                CAST(NEW.credits AS SIGNED) - CAST(OLD.credits AS SIGNED),
                'direct_db_bypass');
    END IF;
END$$
DELIMITER ;


-- ─────────────────────────────────────────────────────────────────────────────
-- 4. log_bullet_changes — ÚJ: bypass detektálás töltényre
--    Ha nem BulletService csinálta → fraud alert
-- ─────────────────────────────────────────────────────────────────────────────
DROP TRIGGER IF EXISTS log_bullet_changes;

DELIMITER $$
CREATE TRIGGER log_bullet_changes
AFTER UPDATE ON users
FOR EACH ROW
BEGIN
    -- BulletService mindig @audit_source-t állít be
    -- Ha nincs beállítva → valaki directben módosította → bypass → logolunk
    IF OLD.bullets != NEW.bullets AND @audit_source IS NULL THEN
        INSERT INTO bullet_change_log (user_id, old_bullets, new_bullets, change_amount, change_source)
        VALUES (NEW.id, OLD.bullets, NEW.bullets,
                CAST(NEW.bullets AS SIGNED) - CAST(OLD.bullets AS SIGNED),
                'direct_db_bypass');
    END IF;
END$$
DELIMITER ;


-- ─────────────────────────────────────────────────────────────────────────────
-- 5. log_xp_changes — @audit_source olvasás hozzáadva
--    Eddig: mindig 'direct_db'-t írt, most forrást ismer
--    XP-nek nincs PHP transactions táblája → minden változást logolunk (nem csak bypass!)
-- ─────────────────────────────────────────────────────────────────────────────
DROP TRIGGER IF EXISTS log_xp_changes;

DELIMITER $$
CREATE TRIGGER log_xp_changes
AFTER UPDATE ON users
FOR EACH ROW
BEGIN
    DECLARE log_source VARCHAR(50);
    SET log_source = IFNULL(@audit_source, 'direct_db');

    IF OLD.xp != NEW.xp THEN
        INSERT INTO xp_change_log (user_id, old_xp, new_xp, change_amount, change_source)
        VALUES (NEW.id, OLD.xp, NEW.xp,
                CAST(NEW.xp AS SIGNED) - CAST(OLD.xp AS SIGNED),
                log_source);
    END IF;
END$$
DELIMITER ;


-- ─────────────────────────────────────────────────────────────────────────────
-- 6. health + energy triggerek — VÁLTOZATLANUL maradnak, már helyesek
-- ─────────────────────────────────────────────────────────────────────────────
-- log_health_changes és log_energy_changes: @audit_source-t olvasnak, minden változást logolnak
-- Nincs PHP megfelelőjük → ezek a source of truth → nem kell módosítani


-- ─────────────────────────────────────────────────────────────────────────────
-- 7. BulletService @audit_source beállítás trigger-hez
-- ─────────────────────────────────────────────────────────────────────────────
-- A BulletService.php executeTransaction() metódusában az UPDATE előtt:
-- SET @audit_source = 'BulletService::' . type
-- A trigger ezért NEM fog beleírni a bullet_change_log-ba (= nem bypass)
-- Ha valaki direkt SQL-lel módosítja users.bullets → @audit_source NULL → LOGOLVA


-- ─────────────────────────────────────────────────────────────────────────────
-- ÖSSZEFOGLALÓ ELLENŐRZÉS (migration után futtasd)
-- ─────────────────────────────────────────────────────────────────────────────
/*
SHOW TRIGGERS FROM netmafia;

-- Bypass detektorok (ezeknek csak 'direct_db_bypass' forrásuk lesz ezentúl):
SELECT 'money bypass' as tipus, COUNT(*) FROM money_change_log WHERE change_source = 'direct_db_bypass'
UNION ALL
SELECT 'credit bypass', COUNT(*) FROM credit_change_log WHERE change_source = 'direct_db_bypass'
UNION ALL
SELECT 'bullet bypass', COUNT(*) FROM bullet_change_log WHERE change_source = 'direct_db_bypass';
*/
