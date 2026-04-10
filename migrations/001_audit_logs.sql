-- Audit Logs tábla - biztonsági események naplózása
-- Futtatás: mysql -u root netmafia < migrations/001_audit_logs.sql

CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50) NOT NULL COMMENT 'Esemény típusa (login_blocked, login_success, stb.)',
    user_id INT NULL COMMENT 'Érintett user ID (NULL ha ismeretlen)',
    ip_address VARCHAR(45) NOT NULL COMMENT 'IP cím (IPv6 támogatás)',
    details JSON NULL COMMENT 'Extra információk JSON formátumban',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_type (type),
    INDEX idx_user_id (user_id),
    INDEX idx_ip_address (ip_address),
    INDEX idx_created_at (created_at),
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
