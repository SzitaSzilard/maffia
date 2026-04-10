-- Otthon Modul - Ingatlanok rendszer
-- [2025-12-29 15:45:35] Properties (ingatlanok) vásárlás/eladás/garázs/alvás rendszer

-- 1. Ingatlan típusok tábla
CREATE TABLE IF NOT EXISTS properties (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    country_restriction VARCHAR(10) NULL DEFAULT NULL,  -- NULL = 'any' (bárhol vehető)
    garage_capacity INT DEFAULT 0,
    sleep_health_regen_percent INT DEFAULT 0,
    sleep_energy_regen_percent INT DEFAULT 0,
    price BIGINT NOT NULL,
    image_path VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_country (country_restriction),
    INDEX idx_price (price)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Seed adatok (9 ingatlan típus)
INSERT INTO properties (name, country_restriction, garage_capacity, sleep_health_regen_percent, sleep_energy_regen_percent, price) VALUES
('Luxus villa', NULL, 100, 8, 16, 3000000),
('Villa', NULL, 35, 7, 14, 1800000),
('Villa', NULL, 15, 6, 12, 750000),
('Luxus ház', 'CA', 10, 6, 11, 480000),  -- Kanada exclusive
('Családi ház', NULL, 5, 4, 9, 200000),
('Luxus Panel', 'US', 2, 4, 8, 160000),  -- USA exclusive
('Családi ház', NULL, 1, 3, 7, 50000),
('Családi ház', NULL, 0, 2, 4, 6000),
('Panel', NULL, 0, 2, 3, 2000);

-- 3. User ingatlanok tábla
CREATE TABLE IF NOT EXISTS user_properties (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    property_id INT NOT NULL,
    country_code VARCHAR(2) NOT NULL,
    purchase_price BIGINT NOT NULL,
    purchased_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (property_id) REFERENCES properties(id),
    UNIQUE KEY unique_user_country (user_id, country_code),
    INDEX idx_user (user_id),
    INDEX idx_country (country_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
