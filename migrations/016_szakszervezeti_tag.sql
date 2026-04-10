-- Szakszervezeti Tag rendszer
-- [2025-12-29 15:10:51] Épület tulajdonosok automatikusan szakszervezeti tagok lesznek
-- Ez prestige és speciális funkciókhoz ad hozzáférést

-- 1. Mező hozzáadása
ALTER TABLE users ADD COLUMN is_szakszervezeti_tag TINYINT(1) DEFAULT 0 AFTER is_admin;

-- 2. Index hozzáadása (gyors lekérdezés)
CREATE INDEX idx_szakszervezeti_tag ON users(is_szakszervezeti_tag);

-- 3. Jelenlegi épület tulajdonosok automatikus frissítése
UPDATE users 
SET is_szakszervezeti_tag = 1 
WHERE id IN (
    SELECT DISTINCT owner_id 
    FROM buildings 
    WHERE owner_id IS NOT NULL
);
