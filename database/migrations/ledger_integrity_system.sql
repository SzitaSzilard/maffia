-- =============================================================================
-- Ledger Integrity System — DB Migration
-- Netmafia | 2026-02-28
-- =============================================================================
-- Futtatás előtt: Mentsd az adatbázist!
-- MySQL 8.0.16+ szükséges a CHECK CONSTRAINT-ekhez.
-- =============================================================================


-- ─────────────────────────────────────────────────────────────────────────────
-- 1. bullet_transactions — Töltény főkönyv
-- ─────────────────────────────────────────────────────────────────────────────
-- Engedélyezett type értékek:
--   add:  ammo_factory | postal_receive | market_buy | admin_add | refund
--   use:  combat_use   | postal_send    | market_sell | admin_remove
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS bullet_transactions (
    id              BIGINT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED        NOT NULL,
    amount          INT                 NOT NULL COMMENT 'Pozitív = kapott, negatív = felhasznált',
    balance_before  INT UNSIGNED        NOT NULL,
    balance_after   INT UNSIGNED        NOT NULL,
    type            VARCHAR(50)         NOT NULL,
    description     VARCHAR(255)        NOT NULL,
    reference_type  VARCHAR(50)         NULL     COMMENT 'combat_log | package | market_item | building | NULL',
    reference_id    BIGINT UNSIGNED     NULL,
    created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_user_date    (user_id, created_at),
    INDEX idx_user_last    (user_id, id DESC),
    INDEX idx_ref          (reference_type, reference_id),

    -- DB SZINTŰ GARANCIA: egyenleg nem mehet negatívba
    CONSTRAINT chk_bullets_positive CHECK (balance_after >= 0),
    -- DB SZINTŰ GARANCIA: matematika: before + amount = after
    CONSTRAINT chk_bullets_math     CHECK (balance_before + amount = balance_after)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────────────────
-- 2. bullet_integrity_violations — Integritási hibák naplója
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS bullet_integrity_violations (
    id               BIGINT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED     NOT NULL,
    expected_balance INT              NOT NULL,
    actual_balance   INT              NOT NULL,
    difference       INT              NOT NULL COMMENT 'actual - expected (pozitív = "extra" töltény jelent meg)',
    detected_at      DATETIME         NOT NULL,
    resolved         TINYINT(1)       NOT NULL DEFAULT 0,
    notes            TEXT             NULL,

    INDEX idx_user       (user_id),
    INDEX idx_unresolved (resolved, detected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────────────────
-- 3. CHECK CONSTRAINT a meglévő money_transactions táblára
-- ─────────────────────────────────────────────────────────────────────────────
-- FIGYELEM: Ha már vannak rossz adatok a táblában, az ALTER megtagadódik!
-- Ellenőrző lekérdezés futtatása előtte:
--   SELECT COUNT(*) FROM money_transactions WHERE balance_before + amount != balance_after;
-- Ha 0, biztonságos az ALTER.
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE money_transactions
    ADD CONSTRAINT IF NOT EXISTS chk_money_math
        CHECK (balance_before + amount = balance_after);


-- ─────────────────────────────────────────────────────────────────────────────
-- 4. CHECK CONSTRAINT a meglévő credit_transactions táblára
-- ─────────────────────────────────────────────────────────────────────────────
-- Ugyanúgy ellenőrizd előtte:
--   SELECT COUNT(*) FROM credit_transactions WHERE balance_before + amount != balance_after;
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE credit_transactions
    ADD CONSTRAINT IF NOT EXISTS chk_credit_math
        CHECK (balance_before + amount = balance_after);


-- ─────────────────────────────────────────────────────────────────────────────
-- 5. Pre-flight ellenőrző lekérdezések (futtatsd ezeket ELŐTTE)
-- ─────────────────────────────────────────────────────────────────────────────
/*
-- Pénz integrity check (0-t kell mutatnia):
SELECT COUNT(*) AS invalid_money_rows
FROM money_transactions
WHERE balance_before + amount != balance_after;

-- Kredit integrity check (0-t kell mutatnia):
SELECT COUNT(*) AS invalid_credit_rows
FROM credit_transactions
WHERE balance_before + amount != balance_after;

-- Töltény egyenleg vs transactions mismatch (migrálás UTÁN):
SELECT u.id, u.username, u.bullets AS actual,
       bt.last_balance AS ledger_says
FROM users u
LEFT JOIN (
    SELECT user_id, balance_after AS last_balance
    FROM bullet_transactions
    WHERE id IN (
        SELECT MAX(id) FROM bullet_transactions GROUP BY user_id
    )
) bt ON bt.user_id = u.id
WHERE u.bullets != COALESCE(bt.last_balance, u.bullets);

-- Teljes CSA lekérdezés (minden currency egyszerre):
SELECT 'MONEY' AS currency,
       u.id, u.username,
       u.money AS actual,
       (SELECT mt.balance_after FROM money_transactions mt
        WHERE mt.user_id = u.id ORDER BY mt.id DESC LIMIT 1) AS expected
FROM users u
HAVING actual != expected OR expected IS NULL

UNION ALL

SELECT 'CREDITS',
       u.id, u.username,
       u.credits,
       (SELECT ct.balance_after FROM credit_transactions ct
        WHERE ct.user_id = u.id ORDER BY ct.id DESC LIMIT 1)
FROM users u
HAVING actual != expected OR expected IS NULL

UNION ALL

SELECT 'BULLETS',
       u.id, u.username,
       u.bullets,
       (SELECT bt.balance_after FROM bullet_transactions bt
        WHERE bt.user_id = u.id ORDER BY bt.id DESC LIMIT 1)
FROM users u
HAVING actual != expected OR expected IS NULL;
*/
