CREATE DATABASE IF NOT EXISTS nexus_traveltech
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE nexus_traveltech;

CREATE TABLE IF NOT EXISTS early_access_leads (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  role VARCHAR(80) NULL,
  language VARCHAR(10) NULL,
  currency VARCHAR(10) NULL,
  status ENUM('new', 'contacted', 'pilot_candidate', 'rejected') NOT NULL DEFAULT 'new',
  ip_address VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_created_at (created_at),
  KEY idx_status (status),
  KEY idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
