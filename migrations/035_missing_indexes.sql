-- [2026-02-22] Hiányzó indexek hozzáadása
-- combat_log: legkritikusabb — cooldown check és history 300 usernél lassú index nélkül

-- combat_log: attacker_id (cooldown check, history)
ALTER TABLE combat_log ADD INDEX idx_attacker (attacker_id);

-- combat_log: defender_id (védekező history)
ALTER TABLE combat_log ADD INDEX idx_defender (defender_id);

-- combat_log: created_at (cooldown time check, "utolsó 15 perc" lekérdezések)
ALTER TABLE combat_log ADD INDEX idx_created_at (created_at);

-- combat_log: composed index a leggyakoribb query-hez:
-- "SELECT created_at FROM combat_log WHERE attacker_id = ? AND created_at > ? ORDER BY created_at DESC LIMIT 1"
ALTER TABLE combat_log ADD INDEX idx_attacker_created (attacker_id, created_at);

-- combat_log: "WHERE defender_id = ? AND attacker_id = ? AND created_at > ?"
ALTER TABLE combat_log ADD INDEX idx_defender_attacker_created (defender_id, attacker_id, created_at);

-- kocsma_messages: created_at (ORDER BY lekérdezés)
ALTER TABLE kocsma_messages ADD INDEX idx_created_at (created_at);
