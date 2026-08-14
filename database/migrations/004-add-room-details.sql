USE nexus_traveltech;
ALTER TABLE room_types ADD COLUMN room_details JSON NULL AFTER total_units;
