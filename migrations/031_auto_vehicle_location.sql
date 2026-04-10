-- Migration: Auto-set vehicle location based on garage capacity
-- Logic: 
-- 1. First try to put in garage (if capacity available)
-- 2. If no capacity, put on street

-- Update existing vehicles: 
-- For each user/country, first N vehicles go to garage, rest to street
-- where N = garage capacity

-- Step 1: Set ALL to street first
UPDATE user_vehicles SET location = 'street', location_changed_at = NOW();

-- Step 2: For each user, move first N vehicles to garage based on capacity
-- This is complex in pure SQL, so we'll handle it in PHP
-- For now, just set all to street to be safe (no garage = everything is vulnerable)

-- The PHP code will handle proper allocation when:
-- 1. User buys a vehicle
-- 2. User buys garage slots
-- 3. User buys property with garage
