-- Hospital pricing configuration
CREATE TABLE IF NOT EXISTS hospital_prices (
    building_id INT NOT NULL,
    price_per_hp INT NOT NULL DEFAULT 52, -- Default $52 as per screenshot
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (building_id),
    CONSTRAINT fk_hospital_building FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default config for existing hospitals (Assuming building_type='hospital' or specific IDs)
-- We'll insert for all buildings of type 2 (kórház) if we knew the type ID, but typically we seed manually or via code.
-- Let's attempt to seed for building_id likely to be a hospital.
-- Based on previous context, user can own buildings. We will seed via PHP script or just rely on 'INSERT IGNORE' if we can find hospital IDs.
