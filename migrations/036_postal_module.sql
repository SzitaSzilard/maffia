-- [2026-02-22] Posta modul — csomag küldés rendszer

CREATE TABLE IF NOT EXISTS postal_packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    recipient_id INT NOT NULL,
    total_cost INT NOT NULL DEFAULT 0,
    status ENUM('delivered') DEFAULT 'delivered',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sender (sender_id),
    INDEX idx_recipient (recipient_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS postal_package_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    package_id INT NOT NULL,
    item_type ENUM('weapon','armor','consumable','vehicle','money','credits','bullets','building') NOT NULL,
    item_id INT DEFAULT NULL,
    item_name VARCHAR(255) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_value INT NOT NULL DEFAULT 0,
    INDEX idx_package (package_id),
    FOREIGN KEY (package_id) REFERENCES postal_packages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
