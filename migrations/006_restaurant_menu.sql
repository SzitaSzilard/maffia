CREATE TABLE IF NOT EXISTS `restaurant_menu` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `building_id` INT NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `image` VARCHAR(255) NOT NULL,
    `energy` INT NOT NULL DEFAULT 10,
    `price` INT NOT NULL DEFAULT 100,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`building_id`) REFERENCES `buildings`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default items for ALL existing restaurants
INSERT INTO `restaurant_menu` (`building_id`, `name`, `image`, `energy`, `price`)
SELECT `id`, 'Beefsteaks', 'beefsteak.jpg', 100, 84
FROM `buildings` WHERE `type` LIKE '%restaurant%' OR `type` LIKE '%etterem%';

INSERT INTO `restaurant_menu` (`building_id`, `name`, `image`, `energy`, `price`)
SELECT `id`, 'Amerikai saláta', 'salad.jpg', 30, 25
FROM `buildings` WHERE `type` LIKE '%restaurant%' OR `type` LIKE '%etterem%';

INSERT INTO `restaurant_menu` (`building_id`, `name`, `image`, `energy`, `price`)
SELECT `id`, 'Őszibarack pite', 'pie.jpg', 50, 45
FROM `buildings` WHERE `type` LIKE '%restaurant%' OR `type` LIKE '%etterem%';

INSERT INTO `restaurant_menu` (`building_id`, `name`, `image`, `energy`, `price`)
SELECT `id`, 'Lazacos Rántotta', 'scrambled_eggs.jpg', 75, 65
FROM `buildings` WHERE `type` LIKE '%restaurant%' OR `type` LIKE '%etterem%';
