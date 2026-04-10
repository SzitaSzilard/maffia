-- Migration: Add origin_country to vehicles table
-- This stores the manufacturing country of each vehicle

ALTER TABLE `vehicles` ADD COLUMN `origin_country` VARCHAR(2) DEFAULT NULL AFTER `name`;

-- Update origin countries for all vehicles
-- IT = Italy, JP = Japan, DE = Germany, US = USA, UK = United Kingdom

UPDATE `vehicles` SET `origin_country` = 'IT' WHERE `name` = 'Maserati GranTurismo';
UPDATE `vehicles` SET `origin_country` = 'JP' WHERE `name` = 'Subaru BRZ';
UPDATE `vehicles` SET `origin_country` = 'DE' WHERE `name` = 'Mercedes G63 AMG';
UPDATE `vehicles` SET `origin_country` = 'UK' WHERE `name` = 'Jaguar F Type Coupe';
UPDATE `vehicles` SET `origin_country` = 'DE' WHERE `name` = 'Audi RS6 C7';
UPDATE `vehicles` SET `origin_country` = 'DE' WHERE `name` = 'BMW M5 F90';
UPDATE `vehicles` SET `origin_country` = 'JP' WHERE `name` = 'Nissan GT-R R35';
UPDATE `vehicles` SET `origin_country` = 'IT' WHERE `name` = 'Lamborghini Huracán';
UPDATE `vehicles` SET `origin_country` = 'IT' WHERE `name` = 'Ferrari 488 Pista';
UPDATE `vehicles` SET `origin_country` = 'US' WHERE `name` = 'Dodge Challenger SRT';
UPDATE `vehicles` SET `origin_country` = 'DE' WHERE `name` = 'Porsche 911 GT3 RS';
UPDATE `vehicles` SET `origin_country` = 'UK' WHERE `name` = 'McLaren 720S';
UPDATE `vehicles` SET `origin_country` = 'JP' WHERE `name` = 'Toyota Supra MK5';
UPDATE `vehicles` SET `origin_country` = 'US' WHERE `name` = 'Ford Mustang GT';
UPDATE `vehicles` SET `origin_country` = 'US' WHERE `name` = 'Chevrolet Camaro ZL1';
UPDATE `vehicles` SET `origin_country` = 'DE' WHERE `name` = 'BMW M4 Competition';
UPDATE `vehicles` SET `origin_country` = 'DE' WHERE `name` = 'Mercedes-AMG GT 63 S';
UPDATE `vehicles` SET `origin_country` = 'DE' WHERE `name` = 'Audi R8 V10';
UPDATE `vehicles` SET `origin_country` = 'FR' WHERE `name` = 'Bugatti Chiron';
UPDATE `vehicles` SET `origin_country` = 'UK' WHERE `name` = 'Rolls-Royce Phantom';
UPDATE `vehicles` SET `origin_country` = 'UK' WHERE `name` = 'Bentley Continental GT';
UPDATE `vehicles` SET `origin_country` = 'US' WHERE `name` = 'Tesla Model S Plaid';
UPDATE `vehicles` SET `origin_country` = 'UK' WHERE `name` = 'Range Rover Sport SVR';
UPDATE `vehicles` SET `origin_country` = 'US' WHERE `name` = 'Jeep Grand Cherokee';
UPDATE `vehicles` SET `origin_country` = 'JP' WHERE `name` = 'Mazda RX-7 FD';
UPDATE `vehicles` SET `origin_country` = 'JP' WHERE `name` = 'Honda NSX (New)';
UPDATE `vehicles` SET `origin_country` = 'JP' WHERE `name` = 'Lexus LFA';
UPDATE `vehicles` SET `origin_country` = 'UK' WHERE `name` = 'Aston Martin DB11';
UPDATE `vehicles` SET `origin_country` = 'SE' WHERE `name` = 'Koenigsegg Agera';
UPDATE `vehicles` SET `origin_country` = 'IT' WHERE `name` = 'Pagani Huayra';
