-- 030: Hata izleme, denetim kaydı, ödeme linkleri ve döviz kuru altyapısı

CREATE TABLE IF NOT EXISTS error_logs (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    level VARCHAR(12) NOT NULL DEFAULT 'error' CHECK(level IN ('debug','info','warning','error','critical')),
    message TEXT NOT NULL,
    context JSONB NOT NULL DEFAULT '{}'::jsonb,
    request_uri VARCHAR(500),
    ip VARCHAR(64),
    user_type VARCHAR(20),
    user_id BIGINT,
    status VARCHAR(12) NOT NULL DEFAULT 'new' CHECK(status IN ('new','reviewed')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_error_logs_status_created ON error_logs(status,created_at);

CREATE TABLE IF NOT EXISTS admin_audit_logs (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    admin_username VARCHAR(190) NOT NULL DEFAULT 'admin',
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(40),
    entity_id BIGINT,
    details JSONB NOT NULL DEFAULT '{}'::jsonb,
    ip VARCHAR(64),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_admin_audit_logs_created ON admin_audit_logs(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_admin_audit_logs_action ON admin_audit_logs(action);

CREATE TABLE IF NOT EXISTS payment_links (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    supplier_id BIGINT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,
    booking_id BIGINT REFERENCES supplier_bookings(id) ON DELETE SET NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    amount NUMERIC(12,2) NOT NULL CHECK(amount>0),
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    status VARCHAR(16) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','paid','expired','cancelled')),
    test_mode BOOLEAN NOT NULL DEFAULT true,
    expires_at TIMESTAMPTZ,
    paid_at TIMESTAMPTZ,
    payment_record_id BIGINT REFERENCES payment_records(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_payment_links_status ON payment_links(status,expires_at);

CREATE TABLE IF NOT EXISTS fx_rates (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    base_currency CHAR(3) NOT NULL,
    quote_currency CHAR(3) NOT NULL,
    rate NUMERIC(14,6) NOT NULL CHECK(rate>0),
    rate_date DATE NOT NULL DEFAULT CURRENT_DATE,
    source VARCHAR(40) NOT NULL DEFAULT 'manual',
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE(base_currency,quote_currency,rate_date)
);
CREATE INDEX IF NOT EXISTS idx_fx_rates_lookup ON fx_rates(base_currency,quote_currency,rate_date DESC);
