-- =====================================================
-- CREDITS OSZLOP HOZZÁADÁSA A USERS TÁBLÁHOZ
-- (Ha még nincs benne)
-- =====================================================

-- MySQL 8.0 kompatibilis verzió:
-- Először próbáljuk hozzáadni, ha már létezik, hibát kapunk de nem gond
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'users' 
     AND COLUMN_NAME = 'credits') = 0,
    'ALTER TABLE users ADD COLUMN credits INT UNSIGNED NOT NULL DEFAULT 0',
    'SELECT 1'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================
-- CREDIT TRANSACTIONS TABLE
-- Kredit tranzakciók nyomkövetése integritás ellenőrzéssel
-- =====================================================

CREATE TABLE IF NOT EXISTS credit_transactions (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    
    -- Ki
    user_id INT UNSIGNED NOT NULL,
    
    -- Mikor
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    -- Mennyit (+ = jóváírás, - = költés)
    amount INT NOT NULL,
    
    -- Egyenleg ELŐTTE
    balance_before INT UNSIGNED NOT NULL,
    
    -- Egyenleg UTÁNA
    balance_after INT UNSIGNED NOT NULL,
    
    -- ⚠️ INTEGRITÁS ELLENŐRZÉS ⚠️
    -- TRUE = balance_before + amount = balance_after (OK)
    -- FALSE = valami nem stimmel! (RIASZTÁS)
    is_valid BOOLEAN GENERATED ALWAYS AS (
        balance_before + amount = balance_after
    ) STORED,
    
    -- Tranzakció típusa
    type ENUM(
        'purchase',      -- Vásárlás (PayPal, Stripe, stb.)
        'admin_add',     -- Admin jóváírás
        'admin_remove',  -- Admin levonás
        'referral',      -- Meghívó bónusz
        'spend',         -- Költés (VIP, boost, stb.)
        'refund',        -- Visszatérítés
        'transfer_in',   -- Kapott (másik usertől)
        'transfer_out',  -- Küldött (másik usernek)
        'expired',       -- Lejárt kredit
        'correction'     -- Korrekció
    ) NOT NULL,
    
    -- Részletek
    description VARCHAR(255) NULL,
    
    -- Kapcsolódó azonosító (pl. order_id, target_user_id)
    reference_type VARCHAR(50) NULL,
    reference_id INT UNSIGNED NULL,
    
    -- Audit mezők
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    admin_user_id INT UNSIGNED NULL,  -- Ha admin csinálta
    
    -- Indexek
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at),
    INDEX idx_type (type),
    INDEX idx_is_valid (is_valid)
    
    -- Foreign key (ha van users tábla)
    -- FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Kredit tranzakciók teljes auditálása integritás ellenőrzéssel';

-- =====================================================
-- RIASZTÁS LEKÉRDEZÉS (Admin panel-hez)
-- =====================================================

-- Hibás tranzakciók keresése:
-- SELECT * FROM credit_transactions WHERE is_valid = FALSE;

-- =====================================================
-- PÉLDA ADATOK
-- =====================================================

-- INSERT INTO credit_transactions 
--     (user_id, amount, balance_before, balance_after, type, description)
-- VALUES
--     (1, 100, 0, 100, 'purchase', 'PayPal vásárlás #12345'),     -- OK: 0 + 100 = 100 ✓
--     (1, -25, 100, 75, 'spend', 'VIP aktiválás 1 hónap'),        -- OK: 100 + (-25) = 75 ✓
--     (1, 100, 100, 190, 'purchase', 'HIBÁS TESZT');              -- HIBA: 100 + 100 ≠ 190 ✗
