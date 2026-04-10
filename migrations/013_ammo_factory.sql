CREATE TABLE IF NOT EXISTS ammo_factory_production (
    building_id INT NOT NULL,
    ammo_stock BIGINT NOT NULL DEFAULT 0,
    ammo_price INT NOT NULL DEFAULT 5, -- Default price from screenshot
    is_producing TINYINT(1) NOT NULL DEFAULT 0,
    production_start_time DATETIME DEFAULT NULL,
    production_target_qty INT NOT NULL DEFAULT 0,
    production_completed_qty INT NOT NULL DEFAULT 0,
    last_production_update DATETIME DEFAULT NULL,
    daily_production_count INT NOT NULL DEFAULT 0,
    last_daily_reset DATE DEFAULT NULL,
    PRIMARY KEY (building_id),
    CONSTRAINT fk_ammo_building FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
