USE nexus_traveltech;

CREATE TABLE IF NOT EXISTS suppliers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_name VARCHAR(190) NOT NULL,
  supplier_type ENUM('hotel','villa','yacht','tour','activity','cruise','car_rental','transfer','ferry','restaurant','cinema','beach','event') NOT NULL DEFAULT 'hotel',
  tax_number VARCHAR(64) NULL,
  status ENUM('pilot','active','suspended') NOT NULL DEFAULT 'pilot',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS supplier_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id BIGINT UNSIGNED NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('owner','manager','operations','finance') NOT NULL DEFAULT 'manager',
  last_login_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_supplier_user_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS properties (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id BIGINT UNSIGNED NOT NULL,
  property_type ENUM('hotel','villa','yacht','tour','activity','cruise','car_rental','transfer','ferry','restaurant','cinema','beach','event') NOT NULL,
  name VARCHAR(190) NOT NULL,
  city VARCHAR(100) NULL,
  country_code CHAR(2) NOT NULL DEFAULT 'TR',
  status ENUM('draft','active','paused') NOT NULL DEFAULT 'draft',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_property_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
  INDEX idx_property_supplier_status (supplier_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS room_types (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  property_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  capacity_adults TINYINT UNSIGNED NOT NULL DEFAULT 2,
  total_units SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  room_details JSON NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  CONSTRAINT fk_room_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rate_plans (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  property_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  board_type VARCHAR(50) NOT NULL DEFAULT 'Bed & Breakfast',
  cancellation_policy TEXT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  CONSTRAINT fk_rate_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inventory_calendar (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_type_id BIGINT UNSIGNED NOT NULL,
  rate_plan_id BIGINT UNSIGNED NOT NULL,
  stay_date DATE NOT NULL,
  allotment SMALLINT NOT NULL DEFAULT 0,
  sold SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  base_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  min_stay TINYINT UNSIGNED NOT NULL DEFAULT 1,
  stop_sale BOOLEAN NOT NULL DEFAULT FALSE,
  UNIQUE KEY unique_inventory_day (room_type_id, rate_plan_id, stay_date),
  CONSTRAINT fk_inventory_room FOREIGN KEY (room_type_id) REFERENCES room_types(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_rate FOREIGN KEY (rate_plan_id) REFERENCES rate_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS supplier_bookings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id BIGINT UNSIGNED NOT NULL,
  booking_code VARCHAR(40) NOT NULL UNIQUE,
  property_id BIGINT UNSIGNED NOT NULL,
  check_in DATE NOT NULL,
  check_out DATE NOT NULL,
  guest_name VARCHAR(190) NOT NULL,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  status ENUM('new','confirmed','cancelled','completed') NOT NULL DEFAULT 'new',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_booking_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
  CONSTRAINT fk_booking_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
  INDEX idx_booking_supplier_dates (supplier_id, check_in, check_out)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_insights (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id BIGINT UNSIGNED NOT NULL,
  insight_type ENUM('demand','pricing','content','anomaly','assistant') NOT NULL,
  priority ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  title VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  status ENUM('new','seen','actioned','dismissed') NOT NULL DEFAULT 'new',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_insight_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
  INDEX idx_insight_supplier_status (supplier_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

CREATE TABLE IF NOT EXISTS supplier_payment_settings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id BIGINT UNSIGNED NOT NULL UNIQUE,
  virtual_pos_enabled BOOLEAN NOT NULL DEFAULT FALSE,
  provider_name VARCHAR(120) NULL,
  merchant_name VARCHAR(190) NULL,
  settlement_iban VARCHAR(34) NULL,
  installment_enabled BOOLEAN NOT NULL DEFAULT FALSE,
  installment_max TINYINT UNSIGNED NULL,
  pos_status ENUM('draft','review','active','disabled') NOT NULL DEFAULT 'draft',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_payment_setting_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS supplier_invoices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id BIGINT UNSIGNED NOT NULL,
  booking_id BIGINT UNSIGNED NULL,
  invoice_number VARCHAR(80) NOT NULL,
  invoice_type ENUM('sales','refund','commission') NOT NULL DEFAULT 'sales',
  customer_name VARCHAR(190) NOT NULL,
  customer_tax_number VARCHAR(64) NULL,
  issue_date DATE NOT NULL,
  due_date DATE NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax_rate DECIMAL(5,2) NOT NULL DEFAULT 20,
  tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'TRY',
  status ENUM('draft','issued','paid','cancelled') NOT NULL DEFAULT 'draft',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_invoice_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
  CONSTRAINT fk_invoice_booking FOREIGN KEY (booking_id) REFERENCES supplier_bookings(id) ON DELETE SET NULL,
  UNIQUE KEY unique_supplier_invoice (supplier_id, invoice_number),
  INDEX idx_invoice_supplier_date (supplier_id, issue_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO suppliers (id, company_name, supplier_type, status) VALUES (1, 'NEXUS Pilot Hospitality', 'hotel', 'pilot')
ON DUPLICATE KEY UPDATE company_name=VALUES(company_name);
INSERT INTO supplier_users (supplier_id, full_name, email, password_hash, role) VALUES (1, 'Pilot Kullanıcısı', 'pilot@nexustraveltech.com', '$2y$10$c8/kWQ4gXtUu/ZvefOOjDOyYBLnzoq8yzRwm1A1KwXXmAMeNWzXMa', 'owner')
ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash);
INSERT INTO properties (id, supplier_id, property_type, name, city, country_code, status) VALUES (1, 1, 'hotel', 'NEXUS Pilot Hotel', 'Fethiye', 'TR', 'active')
ON DUPLICATE KEY UPDATE name=VALUES(name);
INSERT INTO room_types (id, property_id, name, capacity_adults, total_units, status) VALUES (1, 1, 'Deluxe Sea View', 2, 18, 'active'), (2, 1, 'Family Suite', 4, 8, 'active')
ON DUPLICATE KEY UPDATE name=VALUES(name);
INSERT INTO rate_plans (id, property_id, name, currency, board_type, status) VALUES (1, 1, 'Esnek İade Edilebilir', 'EUR', 'Bed & Breakfast', 'active'), (2, 1, 'Erken Rezervasyon', 'EUR', 'Half Board', 'active')
ON DUPLICATE KEY UPDATE name=VALUES(name);
INSERT INTO ai_insights (supplier_id, insight_type, priority, title, body) VALUES
(1, 'demand', 'high', 'Talep fırsatı: 12–18 Temmuz', 'Bu tarih aralığında arama hacmi yükseliyor. Açık kontenjan ve görünür fiyat kontrolü önerilir.'),
(1, 'pricing', 'medium', 'Fiyat kontrolü önerisi', 'Deluxe Sea View oda tipinde hafta sonu fiyatı, son 30 günlük ortalamanın altında görünüyor.'),
(1, 'content', 'low', 'Ürün içeriğini güçlendirin', 'İngilizce oda açıklaması ve görsel etiketleri satış kanalları için tamamlanabilir.')
ON DUPLICATE KEY UPDATE title=VALUES(title), body=VALUES(body);
