-- Supplier identity, authority and product-category approval workflow.
CREATE TABLE IF NOT EXISTS supplier_verifications (
  supplier_id BIGINT PRIMARY KEY REFERENCES suppliers(id) ON DELETE CASCADE,
  legal_identity_type VARCHAR(20) NOT NULL DEFAULT 'company' CHECK (legal_identity_type IN ('individual', 'company')),
  legal_name VARCHAR(190) NOT NULL DEFAULT '',
  authority_role VARCHAR(40) NOT NULL DEFAULT '',
  verification_reference VARCHAR(120),
  hotel_certificate_number VARCHAR(120),
  request_note TEXT,
  requested_product_types JSONB NOT NULL DEFAULT '[]'::jsonb,
  approved_product_types JSONB NOT NULL DEFAULT '[]'::jsonb,
  review_status VARCHAR(16) NOT NULL DEFAULT 'pending' CHECK (review_status IN ('pending', 'approved', 'rejected')),
  identity_status VARCHAR(16) NOT NULL DEFAULT 'pending' CHECK (identity_status IN ('pending', 'approved', 'rejected')),
  identity_check_status VARCHAR(16) NOT NULL DEFAULT 'pending' CHECK (identity_check_status IN ('pending', 'verified', 'failed', 'service_error')),
  identity_check_message VARCHAR(500),
  identity_checked_at TIMESTAMPTZ,
  authority_status VARCHAR(16) NOT NULL DEFAULT 'pending' CHECK (authority_status IN ('pending', 'approved', 'rejected')),
  review_note TEXT,
  reviewed_by VARCHAR(190),
  submitted_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  reviewed_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_supplier_verification_review ON supplier_verifications(review_status, submitted_at);

CREATE TABLE IF NOT EXISTS admin_alerts (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  alert_type VARCHAR(50) NOT NULL,
  supplier_id BIGINT REFERENCES suppliers(id) ON DELETE CASCADE,
  title VARCHAR(190) NOT NULL,
  body TEXT NOT NULL,
  is_read BOOLEAN NOT NULL DEFAULT false,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_admin_alerts_open ON admin_alerts(is_read, created_at DESC);
