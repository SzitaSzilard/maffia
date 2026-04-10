-- System Cleanup Logs
-- [2025-12-30] Naplózza, hogy az automata karbantartó mit törölt

CREATE TABLE IF NOT EXISTS system_cleanup_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    table_name VARCHAR(50) NOT NULL COMMENT 'Melyik tablabol toroltunk',
    deleted_rows INT NOT NULL COMMENT 'Hany sort toroltunk',
    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Mikor futott le'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
