USE nexus_traveltech;
ALTER TABLE properties ADD COLUMN product_details JSON NULL AFTER country_code;
