-- Item Module - Database Tables
-- [2025-12-30] Tárgyrendszer: fegyverek, védelmek, fogyaszthatók, egyéb

-- Tárgy típusok és alap adatok
CREATE TABLE IF NOT EXISTS items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    type ENUM('weapon', 'armor', 'consumable', 'misc') NOT NULL,
    attack INT DEFAULT 0,
    defense INT DEFAULT 0,
    price INT DEFAULT 0,
    stackable BOOLEAN DEFAULT true,
    max_stack INT DEFAULT 99,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tárgy hatások (fogyaszthatókhoz)
-- effect_type: milyen típusú hatás
-- duration_minutes: 0 = instant azonnali, >0 = timed buff
-- context: NULL = mindenhol, 'combat,gang,kocsma' = csak ott
CREATE TABLE IF NOT EXISTS item_effects (
    id INT PRIMARY KEY AUTO_INCREMENT,
    item_id INT NOT NULL,
    effect_type ENUM(
        'health_percent',      -- +X% élet (instant)
        'energy_percent',      -- +X% energia (instant)
        'attack_bonus',        -- +X% támadás (timed)
        'defense_bonus',       -- +X% védelem (timed)
        'xp_bonus',           -- +X% XP szerzés (timed)
        'cooldown_reduction'  -- -X% várakozási idő (timed)
    ) NOT NULL,
    value INT NOT NULL,
    duration_minutes INT DEFAULT 0,
    context VARCHAR(100) DEFAULT NULL,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
    INDEX idx_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User inventory - tárolt és felszerelt tárgyak
CREATE TABLE IF NOT EXISTS user_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    item_id INT NOT NULL,
    quantity INT DEFAULT 1,
    equipped BOOLEAN DEFAULT false,
    acquired_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_item (user_id, item_id),
    INDEX idx_user (user_id),
    INDEX idx_equipped (user_id, equipped)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aktív buff-ok (timed hatások)
-- Max 2 különböző buff lehet aktív egyszerre
-- Ugyanaz a tárgy nem stackelhető
CREATE TABLE IF NOT EXISTS user_buffs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    item_id INT NOT NULL,
    effect_type VARCHAR(50) NOT NULL,
    value INT NOT NULL,
    context VARCHAR(100) DEFAULT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
    INDEX idx_user_active (user_id, expires_at),
    INDEX idx_cleanup (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
