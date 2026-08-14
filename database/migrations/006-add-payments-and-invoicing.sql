USE nexus_traveltech;

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
