-- Add country_code to users table
ALTER TABLE users 
ADD COLUMN country_code VARCHAR(3) NOT NULL DEFAULT 'HUN' AFTER username;

-- Add index for location lookups
CREATE INDEX idx_users_country ON users(country_code);
