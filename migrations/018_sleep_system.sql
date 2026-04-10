-- Alvás rendszer (Sleep System)
-- [2025-12-29 19:13:00] User sleep tracking with property-based regeneration

-- 1. User sleep tábla
CREATE TABLE IF NOT EXISTS user_sleep (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    sleep_started_at DATETIME NOT NULL,
    sleep_duration_hours INT NOT NULL,  -- 1-9 óra
    sleep_end_at DATETIME NOT NULL,
    country_code VARCHAR(2) NOT NULL,  -- Melyik országban alszik
    is_on_street TINYINT(1) DEFAULT 0,  -- 1 = utcán (támadható), 0 = ingatlanban
    health_regen_per_hour INT DEFAULT 0,  -- % regeneráció/óra
    energy_regen_per_hour INT DEFAULT 0,  -- % regeneráció/óra
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_sleep_end (sleep_end_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
