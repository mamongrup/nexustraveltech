CREATE TABLE IF NOT EXISTS agencies (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,company_name VARCHAR(190) NOT NULL,license_number VARCHAR(80),country_code CHAR(2) NOT NULL DEFAULT 'TR',status VARCHAR(16) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','active','suspended')),created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE TABLE IF NOT EXISTS agency_users (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,agency_id BIGINT NOT NULL REFERENCES agencies(id) ON DELETE CASCADE,full_name VARCHAR(190) NOT NULL,email VARCHAR(190) NOT NULL UNIQUE,password_hash VARCHAR(255) NOT NULL,role VARCHAR(30) NOT NULL DEFAULT 'owner',last_login_at TIMESTAMPTZ,created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE TABLE IF NOT EXISTS agency_api_keys (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,agency_id BIGINT NOT NULL REFERENCES agencies(id) ON DELETE CASCADE,key_prefix VARCHAR(16) NOT NULL,key_hash CHAR(64) NOT NULL UNIQUE,label VARCHAR(120) NOT NULL,scopes JSONB NOT NULL DEFAULT '[]'::jsonb,status VARCHAR(16) NOT NULL DEFAULT 'active' CHECK(status IN ('active','revoked')),last_used_at TIMESTAMPTZ,expires_at TIMESTAMPTZ,created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE TABLE IF NOT EXISTS agency_customers (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,agency_id BIGINT NOT NULL REFERENCES agencies(id) ON DELETE CASCADE,full_name VARCHAR(190) NOT NULL,email VARCHAR(190),phone VARCHAR(40),notes TEXT,created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE TABLE IF NOT EXISTS agency_quotes (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,agency_id BIGINT NOT NULL REFERENCES agencies(id) ON DELETE CASCADE,customer_id BIGINT REFERENCES agency_customers(id) ON DELETE SET NULL,quote_number VARCHAR(80) NOT NULL,valid_until TIMESTAMPTZ,total_amount NUMERIC(12,2) NOT NULL DEFAULT 0,currency CHAR(3) NOT NULL DEFAULT 'EUR',status VARCHAR(20) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','sent','accepted','expired','cancelled')),created_at TIMESTAMPTZ NOT NULL DEFAULT now(),UNIQUE(agency_id,quote_number));
CREATE TABLE IF NOT EXISTS channel_connections (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,supplier_id BIGINT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,channel_code VARCHAR(60) NOT NULL,display_name VARCHAR(120) NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','active','error','disabled')),encrypted_credentials TEXT,last_sync_at TIMESTAMPTZ,last_error TEXT,created_at TIMESTAMPTZ NOT NULL DEFAULT now(),UNIQUE(supplier_id,channel_code));
CREATE TABLE IF NOT EXISTS channel_sync_jobs (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,channel_connection_id BIGINT NOT NULL REFERENCES channel_connections(id) ON DELETE CASCADE,property_id BIGINT REFERENCES properties(id) ON DELETE CASCADE,job_type VARCHAR(30) NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','running','completed','failed')),payload JSONB NOT NULL DEFAULT '{}'::jsonb,error_message TEXT,created_at TIMESTAMPTZ NOT NULL DEFAULT now(),processed_at TIMESTAMPTZ);
CREATE TABLE IF NOT EXISTS supplier_settlements (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,supplier_id BIGINT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,booking_id BIGINT REFERENCES supplier_bookings(id) ON DELETE SET NULL,transaction_type VARCHAR(30) NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','paid','failed','refunded')),gross_amount NUMERIC(12,2) NOT NULL,commission_amount NUMERIC(12,2) NOT NULL DEFAULT 0,net_amount NUMERIC(12,2) NOT NULL,currency CHAR(3) NOT NULL DEFAULT 'EUR',due_date DATE,paid_at TIMESTAMPTZ,created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE INDEX IF NOT EXISTS idx_channel_sync_jobs_status ON channel_sync_jobs(status,created_at);
CREATE INDEX IF NOT EXISTS idx_settlements_supplier_status ON supplier_settlements(supplier_id,status,due_date);

-- ============================================================
-- Sahiplikten bağımsız çalışma (GRANT + ALTER OWNER):
-- Migration ister postgres ister app kullanıcısıyla koşsun, dokunulan tablolar
-- app DB kullanıcısına devredilir. @APP_DB_USER@ yer tutucusu çalıştırıcı
-- tarafından secrets.php deki db_user ile değiştirilir (config/health.php ve
-- scripts/apply-migrations-postgres.sh); elle psql -f ile koşarsanız önce
-- sed "s/@APP_DB_USER@/<kullanici>/g" ile değiştirin.
GRANT ALL PRIVILEGES ON TABLE agencies, agency_users, agency_api_keys, agency_customers, agency_quotes, channel_connections, channel_sync_jobs, supplier_settlements TO @APP_DB_USER@;
ALTER TABLE agencies OWNER TO @APP_DB_USER@;
ALTER TABLE agency_users OWNER TO @APP_DB_USER@;
ALTER TABLE agency_api_keys OWNER TO @APP_DB_USER@;
ALTER TABLE agency_customers OWNER TO @APP_DB_USER@;
ALTER TABLE agency_quotes OWNER TO @APP_DB_USER@;
ALTER TABLE channel_connections OWNER TO @APP_DB_USER@;
ALTER TABLE channel_sync_jobs OWNER TO @APP_DB_USER@;
ALTER TABLE supplier_settlements OWNER TO @APP_DB_USER@;
