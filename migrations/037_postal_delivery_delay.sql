-- [2026-02-22] Posta modul — 15 perces küldési idő

-- Status bővítés: in_transit (útban) / delivered (kézbesítve)
ALTER TABLE postal_packages MODIFY COLUMN status ENUM('in_transit','delivered') DEFAULT 'in_transit';

-- Kézbesítési idő oszlop
ALTER TABLE postal_packages ADD COLUMN delivery_at DATETIME DEFAULT NULL AFTER status;

-- Index a kézbesítés lekérdezéshez (pending packages check)
ALTER TABLE postal_packages ADD INDEX idx_status_delivery (status, delivery_at);
ALTER TABLE postal_packages ADD INDEX idx_recipient_status (recipient_id, status);
