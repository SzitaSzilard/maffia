-- Migration: Add location field to user_vehicles
-- Stores whether vehicle is in garage or on street
-- Important for crime system: vehicles on street can be stolen!

ALTER TABLE `user_vehicles` 
ADD COLUMN `location` ENUM('garage', 'street') NOT NULL DEFAULT 'garage' AFTER `country`;

-- Add index for quick lookups of street vehicles (potential theft targets)
ALTER TABLE `user_vehicles` 
ADD INDEX `idx_location_country` (`location`, `country`);

-- Add timestamp for when vehicle was moved to street (for exposure time tracking)
ALTER TABLE `user_vehicles` 
ADD COLUMN `location_changed_at` TIMESTAMP NULL DEFAULT NULL AFTER `location`;
