USE nexus_traveltech;
CREATE TABLE IF NOT EXISTS property_media (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  property_id BIGINT UNSIGNED NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  file_name VARCHAR(190) NOT NULL,
  mime_type VARCHAR(80) NOT NULL,
  is_cover BOOLEAN NOT NULL DEFAULT FALSE,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_property_media_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
  INDEX idx_property_media_sort (property_id, is_cover, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
