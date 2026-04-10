-- Item Module - Seed Data
-- [2025-12-30] Fegyverek, védelmek, fogyaszthatók, egyéb tárgyak

-- ============================================================
-- FEGYVEREK (type = 'weapon')
-- ============================================================
INSERT INTO items (name, type, attack, defense, price, stackable, max_stack) VALUES
('2x Mini Uzi', 'weapon', 12, 10, 15000, false, 1),
('AK-101', 'weapon', 15, 10, 18000, false, 1),
('AKS74UN', 'weapon', 12, 18, 20000, false, 1),
('Anzio Ironworks', 'weapon', 135, 40, 500000, false, 1),
('AR-15e', 'weapon', 75, 50, 180000, false, 1),
('AR-15s', 'weapon', 80, 40, 200000, false, 1),
('AR-500', 'weapon', 85, 45, 220000, false, 1),
('Bicska', 'weapon', 3, 2, 500, false, 1),
('FG-42', 'weapon', 55, 45, 120000, false, 1),
('Gepard M3', 'weapon', 30, 20, 50000, false, 1),
('HKG36c', 'weapon', 47, 50, 100000, false, 1),
('HW 100S', 'weapon', 60, 20, 130000, false, 1),
('Japanese Tanto', 'weapon', 5, 5, 2000, false, 1),
('Lupara', 'weapon', 35, 35, 60000, false, 1),
('M1337 Gatling Gun by Groot', 'weapon', 80, 80, 350000, false, 1),
('M16s', 'weapon', 40, 38, 80000, false, 1),
('M249', 'weapon', 55, 40, 110000, false, 1),
('M79', 'weapon', 55, 20, 100000, false, 1),
('MAC10 Silencer', 'weapon', 17, 15, 25000, false, 1),
('Machete', 'weapon', 6, 10, 3000, false, 1),
('MC6', 'weapon', 68, 47, 150000, false, 1),
('Mercury M2 Revolver', 'weapon', 8, 6, 8000, false, 1),
('Microgun', 'weapon', 90, 90, 400000, false, 1),
('Minigun', 'weapon', 100, 60, 450000, false, 1),
('Minigun Extra Limited', 'weapon', 150, 80, 800000, false, 1),
('Minigun RSG-2', 'weapon', 130, 90, 650000, false, 1),
('Minigun Special', 'weapon', 110, 100, 550000, false, 1),
('MK19', 'weapon', 70, 25, 160000, false, 1),
('MP44', 'weapon', 38, 30, 70000, false, 1),
('P229 9mm', 'weapon', 5, 4, 5000, false, 1),
('P308', 'weapon', 60, 100, 200000, false, 1),
('PKT', 'weapon', 55, 30, 105000, false, 1),
('Remington 870', 'weapon', 30, 30, 55000, false, 1),
('RPG7', 'weapon', 70, 15, 170000, false, 1),
('Silenced Saiga 12', 'weapon', 30, 30, 55000, false, 1),
('Smith & Wesson 500', 'weapon', 11, 8, 12000, false, 1),
('SR 25', 'weapon', 70, 70, 250000, false, 1),
('Szamuráj kard', 'weapon', 8, 5, 7000, false, 1),
('Thompson', 'weapon', 22, 18, 35000, false, 1),
('Tokyo Marui Mac10', 'weapon', 15, 15, 22000, false, 1),
('XM307 ACSW', 'weapon', 90, 50, 380000, false, 1);

-- ============================================================
-- VÉDELMEK (type = 'armor')
-- ============================================================
INSERT INTO items (name, type, attack, defense, price, stackable, max_stack) VALUES
('Doberman kutya', 'armor', 1, 2, 5000, false, 1),
('Dragon Golyóálló Mellény', 'armor', 5, 12, 25000, false, 1),
('Golyóálló maszk', 'armor', 3, 9, 15000, false, 1),
('Golyóálló mellény', 'armor', 3, 6, 10000, false, 1),
('Golyóálló ruha', 'armor', 15, 50, 100000, false, 1),
('Kevlár Kesztyű', 'armor', 3, 4, 8000, false, 1),
('Pajzs', 'armor', 4, 15, 20000, false, 1),
('Pitbull kutya', 'armor', 7, 8, 15000, false, 1),
('Rottweiler kutya', 'armor', 4, 6, 10000, false, 1),
('Sisak', 'armor', 1, 3, 5000, false, 1),
('Taktikai pajzs', 'armor', 12, 30, 50000, false, 1),
('US Army Sisak', 'armor', 1, 5, 8000, false, 1);

-- ============================================================
-- FOGYASZTHATÓK (type = 'consumable')
-- ============================================================
INSERT INTO items (name, type, attack, defense, price, stackable, max_stack, description) VALUES
('Bvlgari Csokoládé', 'consumable', 0, 0, 5000, true, 99, '+30% élet, +30% energia'),
('Cohiba Kubai szivar', 'consumable', 0, 0, 15000, true, 99, '+100% élet, +100% energia'),
('Csipsz', 'consumable', 0, 0, 100, true, 99, '+5% energia'),
('Ecstasy', 'consumable', 0, 0, 3000, true, 99, '5 óráig 50%-al több XP'),
('Füves cigi Átlag minőség', 'consumable', 0, 0, 500, true, 99, '+15% élet'),
('Füves cigi Extra minőség', 'consumable', 0, 0, 2000, true, 99, '+50% élet'),
('Füves cigi Jó minőség', 'consumable', 0, 0, 1000, true, 99, '+30% élet'),
('Füves cigi Kezdő minőség', 'consumable', 0, 0, 300, true, 99, '+10% élet'),
('Füves cigi Pocsék minőség', 'consumable', 0, 0, 100, true, 99, '+3% élet'),
('Heroin', 'consumable', 0, 0, 5000, true, 99, '+50% élet, 1 óráig +25% védelem a Küzdelmekben'),
('Hubertus', 'consumable', 0, 0, 800, true, 99, '+7% élet, +12% energia'),
('Johnie Walker', 'consumable', 0, 0, 1500, true, 99, '+12% élet, +20% energia'),
('Kokain', 'consumable', 0, 0, 4000, true, 99, '+30% élet, 40 percig +25% támadás'),
('Malibu Bon-Bon', 'consumable', 0, 0, 600, true, 99, '+20% energia'),
('Noka Vintages Chocolate', 'consumable', 0, 0, 20000, true, 99, '+100% élet, +100% energia'),
('Romeo Y Julieta Cigar', 'consumable', 0, 0, 12000, true, 99, '+70% élet, +100% energia'),
('Speed', 'consumable', 0, 0, 2500, true, 99, '30 percig 25%-al csökkennek a várakozási idők'),
('Van Gogh Vodka', 'consumable', 0, 0, 8000, true, 99, '+100% élet, +50% energia'),
('Vadkender mag', 'consumable', 0, 0, 50, true, 99, 'Termeszthető');

-- ============================================================
-- EGYÉB TÁRGYAK (type = 'misc')
-- ============================================================
INSERT INTO items (name, type, attack, defense, price, stackable, max_stack, description) VALUES
('Aranylapka 5 gramm', 'misc', 0, 0, 60, true, 999, '5 grammos befektetési arany'),
('Fémtömb 5 kg', 'misc', 0, 0, 1, true, 9999, 'Megmunkált fém'),
('Flex', 'misc', 0, 0, 500, true, 99, 'Autóbontásra, h. műkincs készítésére'),
('Hamis műkincs - Fém harang', 'misc', 0, 0, 500, true, 99, 'Kínában eladható hamisítvány'),
('Hamis pénzköteg', 'misc', 0, 0, 200, true, 99, '1 köteg $1,000 h. pénzt tartalmaz');

-- ============================================================
-- ITEM EFFECTS (fogyasztható hatások)
-- ============================================================

-- Bvlgari Csokoládé: +30% HP, +30% EN (instant)
INSERT INTO item_effects (item_id, effect_type, value, duration_minutes, context)
SELECT id, 'health_percent', 30, 0, NULL FROM items WHERE name = 'Bvlgari Csokoládé';
INSERT INTO item_effects (item_id, effect_type, value, duration_minutes, context)
SELECT id, 'energy_percent', 30, 0, NULL FROM items WHERE name = 'Bvlgari Csokoládé';

-- Cohiba Kubai szivar: +100% HP, +100% EN (instant)
INSERT INTO item_effects (item_id, effect_type, value, duration_minutes, context)
SELECT id, 'health_percent', 100, 0, NULL FROM items WHERE name = 'Cohiba Kubai szivar';
INSERT INTO item_effects (item_id, effect_type, value, duration_minutes, context)
SELECT id, 'energy_percent', 100, 0, NULL FROM items WHERE name = 'Cohiba Kubai szivar';

-- Csipsz: +5% EN (instant)
INSERT INTO item_effects (item_id, effect_type, value, duration_minutes, context)
SELECT id, 'energy_percent', 5, 0, NULL FROM items WHERE name = 'Csipsz';

-- Ecstasy: 5 óráig +50% XP (timed)
INSERT INTO item_effects (item_id, effect_type, value, duration_minutes, context)
SELECT id, 'xp_bonus', 50, 300, NULL FROM items WHERE name = 'Ecstasy';

-- Füves cigik (instant HP)
INSERT INTO item_effects (item_id, effect_type, value, duration_minutes, context)
SELECT id, 'health_percent', 15, 0, NULL FROM items WHERE name = 'Füves cigi Átlag minőség';
INSERT INTO item_effects (item_id, effect_type, value, duration_minutes, context)
SELECT id, 'health_percent', 50, 0, NULL FROM items WHERE name = 'Füves cigi Extra minőség';
INSERT INTO item_effects (item_id, effect_type, value, duration_minutes, context)
SELECT id, 'health_percent', 30, 0, NULL FROM items WHERE name = 'Füves cigi Jó minőség';
INSERT INTO item_effects (item_id, effect_type, value, duration_minutes, context)
SELECT id, 'health_percent', 10, 0, NULL FROM items WHERE name = 'Füves cigi Kezdő minőség';
INSERT INTO item_effects (item_id, effect_type, value, duration_minutes, context)
SELECT id, 'health_percent', 3, 0, NULL FROM items WHERE name = 'Füves cigi Pocsék minőség';

-- Heroin: +50% HP (instant) + 1 óráig +25% védelem (timed, context)
INSERT INTO item_effects (item_id, effect_type, value, duration_minutes, context)
SELECT id, 'health_percent', 50, 0, NULL FROM items WHERE name = 'Heroin';
INSERT INTO item_effects (item_id, effect_type, value, duration_minutes, context)
SELECT id, 'defense_bonus', 25, 60, 'combat,gang,kocsma' FROM items WHERE name = 'Heroin';

-- Hubertus: +7% HP, +12% EN (instant)
INSERT INTO item_effects (item_id, effect_type, value, duration_minutes, context)
SELECT id, 'health_percent', 7, 0, NULL FROM items WHERE name = 'Hubertus';
INSERT INTO item_effects (item_id, effect_type, value, duration_minutes, context)
SELECT id, 'energy_percent', 12, 0, NULL FROM items WHERE name = 'Hubertus';

-- Johnie Walker: +12% HP, +20% EN (instant)
INSERT INTO item_effects (item_id, effect_type, value, duration_minutes, context)
SELECT id, 'health_percent', 12, 0, NULL FROM items WHERE name = 'Johnie Walker';
INSERT INTO item_effects (item_id, effect_type, value, duration_minutes, context)
SELECT id, 'energy_percent', 20, 0, NULL FROM items WHERE name = 'Johnie Walker';

-- Kokain: +30% HP (instant) + 40 percig +25% támadás (timed, context)
INSERT INTO item_effects (item_id, effect_type, value, duration_minutes, context)
SELECT id, 'health_percent', 30, 0, NULL FROM items WHERE name = 'Kokain';
INSERT INTO item_effects (item_id, effect_type, value, duration_minutes, context)
SELECT id, 'attack_bonus', 25, 40, 'combat,gang,kocsma' FROM items WHERE name = 'Kokain';

-- Malibu Bon-Bon: +20% EN (instant)
INSERT INTO item_effects (item_id, effect_type, value, duration_minutes, context)
SELECT id, 'energy_percent', 20, 0, NULL FROM items WHERE name = 'Malibu Bon-Bon';

-- Noka Vintages Chocolate: +100% HP, +100% EN (instant)
INSERT INTO item_effects (item_id, effect_type, value, duration_minutes, context)
SELECT id, 'health_percent', 100, 0, NULL FROM items WHERE name = 'Noka Vintages Chocolate';
INSERT INTO item_effects (item_id, effect_type, value, duration_minutes, context)
SELECT id, 'energy_percent', 100, 0, NULL FROM items WHERE name = 'Noka Vintages Chocolate';

-- Romeo Y Julieta Cigar: +70% HP, +100% EN (instant)
INSERT INTO item_effects (item_id, effect_type, value, duration_minutes, context)
SELECT id, 'health_percent', 70, 0, NULL FROM items WHERE name = 'Romeo Y Julieta Cigar';
INSERT INTO item_effects (item_id, effect_type, value, duration_minutes, context)
SELECT id, 'energy_percent', 100, 0, NULL FROM items WHERE name = 'Romeo Y Julieta Cigar';

-- Speed: 30 percig -25% várakozási idő (timed)
INSERT INTO item_effects (item_id, effect_type, value, duration_minutes, context)
SELECT id, 'cooldown_reduction', 25, 30, NULL FROM items WHERE name = 'Speed';

-- Van Gogh Vodka: +100% HP, +50% EN (instant)
INSERT INTO item_effects (item_id, effect_type, value, duration_minutes, context)
SELECT id, 'health_percent', 100, 0, NULL FROM items WHERE name = 'Van Gogh Vodka';
INSERT INTO item_effects (item_id, effect_type, value, duration_minutes, context)
SELECT id, 'energy_percent', 50, 0, NULL FROM items WHERE name = 'Van Gogh Vodka';
