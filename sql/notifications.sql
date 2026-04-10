-- Notifications table
-- Központi értesítések az összes modulból

CREATE TABLE IF NOT EXISTS notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL,           -- 'bank_transfer', 'kocsma_invite', stb.
    source_module VARCHAR(50),            -- 'Bank', 'Kocsma', 'Banda', stb.
    message TEXT NOT NULL,                -- Az értesítés szövege
    link VARCHAR(255),                    -- Opcionális link (pl. '/bank')
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_created (user_id, created_at DESC),
    INDEX idx_user_unread (user_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
