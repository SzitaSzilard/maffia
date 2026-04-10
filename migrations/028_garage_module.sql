-- Create vehicles table if not exists
CREATE TABLE IF NOT EXISTS `vehicles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `image_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `max_fuel` int NOT NULL,
  `speed` int NOT NULL,
  `safety` int NOT NULL,
  `tuning_potential` int DEFAULT 0,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create user_vehicles table if not exists
CREATE TABLE IF NOT EXISTS `user_vehicles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `vehicle_id` int NOT NULL,
  `country` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `damage_percent` int DEFAULT '0',
  `fuel_amount` int NOT NULL DEFAULT '0',
  `tuning_percent` int DEFAULT '0',
  `is_default` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_vehicle` (`user_id`, `vehicle_id`),
  KEY `idx_country` (`country`),
  CONSTRAINT `fk_user_vehicles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_vehicles_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create user_garage_slots table if not exists
CREATE TABLE IF NOT EXISTS `user_garage_slots` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `country` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slots` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_country_slots` (`user_id`, `country`),
  CONSTRAINT `fk_garage_slots_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed vehicles
INSERT INTO `vehicles` (`name`, `max_fuel`, `speed`, `safety`) VALUES
('Maserati GranTurismo', 114, 2574, 314),
('Subaru BRZ', 140, 2502, 264),
('Mercedes G63 AMG', 5, 2718, 508),
('Jaguar F Type Coupe', 140, 2601, 282),
('Audi RS6 C7', 75, 2680, 350),
('BMW M5 F90', 60, 2710, 360),
('Nissan GT-R R35', 95, 2750, 410),
('Lamborghini Huracán', 40, 2820, 450),
('Ferrari 488 Pista', 88, 2840, 470),
('Dodge Challenger SRT', 110, 2550, 290),
('Porsche 911 GT3 RS', 55, 2790, 430),
('McLaren 720S', 20, 2880, 520),
('Toyota Supra MK5', 120, 2540, 275),
('Ford Mustang GT', 68, 2520, 260),
('Chevrolet Camaro ZL1', 100, 2560, 285),
('BMW M4 Competition', 90, 2620, 310),
('Mercedes-AMG GT 63 S', 130, 2690, 380),
('Audi R8 V10', 45, 2780, 420),
('Bugatti Chiron', 150, 3100, 850),
('Rolls-Royce Phantom', 100, 2400, 600),
('Bentley Continental GT', 115, 2580, 400),
('Tesla Model S Plaid', 90, 2950, 350),
('Range Rover Sport SVR', 85, 2550, 330),
('Jeep Grand Cherokee', 105, 2480, 250),
('Mazda RX-7 FD', 70, 2490, 240),
('Honda NSX (New)', 60, 2700, 390),
('Lexus LFA', 50, 2740, 550),
('Aston Martin DB11', 95, 2610, 340),
('Koenigsegg Agera', 140, 3050, 780),
('Pagani Huayra', 35, 2980, 720);
