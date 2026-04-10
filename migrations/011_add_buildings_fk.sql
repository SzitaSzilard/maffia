ALTER TABLE buildings ADD CONSTRAINT fk_buildings_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL;
