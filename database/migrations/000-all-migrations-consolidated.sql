-- ═══════════════════════════════════════════════════════════
-- NEXUS TravelTech — Tüm PostgreSQL Migration'ları (009-064)
-- Tek seferde çalıştırılabilir (idempotent: IF NOT EXISTS)
-- Tarih: 2026-08-20
-- ═══════════════════════════════════════════════════════════


-- ═══ 009-property-media-and-translations-postgres.sql ═══
-- PostgreSQL only: run after the initial NEXUS schema on production.
ALTER TABLE property_media ADD COLUMN IF NOT EXISTS media_scope VARCHAR(20) NOT NULL DEFAULT 'property';
ALTER TABLE property_media ADD COLUMN IF NOT EXISTS room_type_id BIGINT NULL REFERENCES room_types(id) ON DELETE SET NULL;
ALTER TABLE property_media ADD COLUMN IF NOT EXISTS title VARCHAR(190) NULL;
ALTER TABLE property_media ADD COLUMN IF NOT EXISTS description TEXT NULL;
ALTER TABLE property_media ADD COLUMN IF NOT EXISTS alt_text VARCHAR(255) NULL;
CREATE INDEX IF NOT EXISTS idx_property_media_scope ON property_media(property_id, media_scope, room_type_id);

CREATE TABLE IF NOT EXISTS property_content_translations (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,
  entity_type VARCHAR(20) NOT NULL,
  entity_id BIGINT NOT NULL DEFAULT 0,
  locale CHAR(2) NOT NULL,
  field_key VARCHAR(60) NOT NULL,
  value TEXT NOT NULL,
  source_locale CHAR(2) NOT NULL DEFAULT 'tr',
  translation_source VARCHAR(20) NOT NULL DEFAULT 'manual',
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE(property_id, entity_type, entity_id, locale, field_key)
);
CREATE INDEX IF NOT EXISTS idx_content_translation_lookup ON property_content_translations(property_id, entity_type, entity_id, locale);

-- ============================================================
-- Sahiplikten bağımsız çalışma (GRANT + ALTER OWNER):
-- Migration ister postgres ister app kullanıcısıyla koşsun, dokunulan tablolar
-- app DB kullanıcısına devredilir. @APP_DB_USER@ yer tutucusu çalıştırıcı
-- tarafından secrets.php deki db_user ile değiştirilir (config/health.php ve
-- scripts/apply-migrations-postgres.sh); elle psql -f ile koşarsanız önce
-- sed "s/@APP_DB_USER@/<kullanici>/g" ile değiştirin.
GRANT ALL PRIVILEGES ON TABLE property_content_translations, property_media TO @APP_DB_USER@;
ALTER TABLE property_content_translations OWNER TO @APP_DB_USER@;
ALTER TABLE property_media OWNER TO @APP_DB_USER@;


-- ═══ 010-ai-provider-settings-postgres.sql ═══
-- Encrypted AI provider settings. The application encryption key stays in config/secrets.php.
CREATE TABLE IF NOT EXISTS ai_provider_settings (
  provider VARCHAR(32) PRIMARY KEY,
  encrypted_api_key TEXT,
  model VARCHAR(80) NOT NULL DEFAULT 'deepseek-chat',
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ============================================================
-- Sahiplikten bağımsız çalışma (GRANT + ALTER OWNER):
-- Migration ister postgres ister app kullanıcısıyla koşsun, dokunulan tablolar
-- app DB kullanıcısına devredilir. @APP_DB_USER@ yer tutucusu çalıştırıcı
-- tarafından secrets.php deki db_user ile değiştirilir (config/health.php ve
-- scripts/apply-migrations-postgres.sh); elle psql -f ile koşarsanız önce
-- sed "s/@APP_DB_USER@/<kullanici>/g" ile değiştirin.
GRANT ALL PRIVILEGES ON TABLE ai_provider_settings TO @APP_DB_USER@;
ALTER TABLE ai_provider_settings OWNER TO @APP_DB_USER@;


-- ═══ 011-supplier-verification-postgres.sql ═══
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

-- ============================================================
-- Sahiplikten bağımsız çalışma (GRANT + ALTER OWNER):
-- Migration ister postgres ister app kullanıcısıyla koşsun, dokunulan tablolar
-- app DB kullanıcısına devredilir. @APP_DB_USER@ yer tutucusu çalıştırıcı
-- tarafından secrets.php deki db_user ile değiştirilir (config/health.php ve
-- scripts/apply-migrations-postgres.sh); elle psql -f ile koşarsanız önce
-- sed "s/@APP_DB_USER@/<kullanici>/g" ile değiştirin.
GRANT ALL PRIVILEGES ON TABLE supplier_verifications, admin_alerts TO @APP_DB_USER@;
ALTER TABLE supplier_verifications OWNER TO @APP_DB_USER@;
ALTER TABLE admin_alerts OWNER TO @APP_DB_USER@;


-- ═══ 012-supplier-verification-documents-postgres.sql ═══
-- Sensitive supplier verification documents: accessible only through authenticated admin routes.
CREATE TABLE IF NOT EXISTS supplier_verification_documents (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  supplier_id BIGINT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,
  document_type VARCHAR(50) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(100) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  file_size INTEGER NOT NULL,
  uploaded_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (supplier_id, document_type)
);
CREATE INDEX IF NOT EXISTS idx_supplier_verification_documents_supplier ON supplier_verification_documents(supplier_id);

-- ============================================================
-- Sahiplikten bağımsız çalışma (GRANT + ALTER OWNER):
-- Migration ister postgres ister app kullanıcısıyla koşsun, dokunulan tablolar
-- app DB kullanıcısına devredilir. @APP_DB_USER@ yer tutucusu çalıştırıcı
-- tarafından secrets.php deki db_user ile değiştirilir (config/health.php ve
-- scripts/apply-migrations-postgres.sh); elle psql -f ile koşarsanız önce
-- sed "s/@APP_DB_USER@/<kullanici>/g" ile değiştirin.
GRANT ALL PRIVILEGES ON TABLE supplier_verification_documents TO @APP_DB_USER@;
ALTER TABLE supplier_verification_documents OWNER TO @APP_DB_USER@;


-- ═══ 013-listing-integrity-and-audit-postgres.sql ═══
-- Exact product identity prevents different suppliers from creating the same listing.
ALTER TABLE properties ADD COLUMN IF NOT EXISTS duplicate_key CHAR(64);
CREATE UNIQUE INDEX IF NOT EXISTS uq_properties_duplicate_key ON properties(duplicate_key) WHERE duplicate_key IS NOT NULL;

ALTER TABLE property_media ADD COLUMN IF NOT EXISTS content_hash CHAR(64);
CREATE INDEX IF NOT EXISTS idx_property_media_content_hash ON property_media(content_hash) WHERE content_hash IS NOT NULL;

CREATE TABLE IF NOT EXISTS duplicate_listing_signals (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,
  matched_property_id BIGINT REFERENCES properties(id) ON DELETE SET NULL,
  signal_type VARCHAR(40) NOT NULL,
  confidence NUMERIC(5,2) NOT NULL DEFAULT 100,
  details JSONB NOT NULL DEFAULT '{}'::jsonb,
  status VARCHAR(16) NOT NULL DEFAULT 'open' CHECK (status IN ('open','confirmed','dismissed')),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_duplicate_listing_signals_status ON duplicate_listing_signals(status,created_at DESC);

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  actor_type VARCHAR(30) NOT NULL,
  actor_id BIGINT,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(50) NOT NULL,
  entity_id BIGINT,
  meta JSONB NOT NULL DEFAULT '{}'::jsonb,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_audit_logs_entity ON audit_logs(entity_type,entity_id,created_at DESC);

-- ============================================================
-- Sahiplikten bağımsız çalışma (GRANT + ALTER OWNER):
-- Migration ister postgres ister app kullanıcısıyla koşsun, dokunulan tablolar
-- app DB kullanıcısına devredilir. @APP_DB_USER@ yer tutucusu çalıştırıcı
-- tarafından secrets.php deki db_user ile değiştirilir (config/health.php ve
-- scripts/apply-migrations-postgres.sh); elle psql -f ile koşarsanız önce
-- sed "s/@APP_DB_USER@/<kullanici>/g" ile değiştirin.
GRANT ALL PRIVILEGES ON TABLE duplicate_listing_signals, audit_logs, properties, property_media TO @APP_DB_USER@;
ALTER TABLE duplicate_listing_signals OWNER TO @APP_DB_USER@;
ALTER TABLE audit_logs OWNER TO @APP_DB_USER@;
ALTER TABLE properties OWNER TO @APP_DB_USER@;
ALTER TABLE property_media OWNER TO @APP_DB_USER@;


-- ═══ 014-platform-settings-postgres.sql ═══
CREATE TABLE IF NOT EXISTS platform_settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  value JSONB NOT NULL,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
INSERT INTO platform_settings(setting_key,value) VALUES
  ('gemini_visual_similarity_threshold','90'::jsonb),
  ('gemini_auto_pause_duplicate','true'::jsonb),
  ('kps_identity_verification_enabled','false'::jsonb),
  ('admin_alert_email','""'::jsonb)
ON CONFLICT(setting_key) DO NOTHING;

-- ============================================================
-- Sahiplikten bağımsız çalışma (GRANT + ALTER OWNER):
-- Migration ister postgres ister app kullanıcısıyla koşsun, dokunulan tablolar
-- app DB kullanıcısına devredilir. @APP_DB_USER@ yer tutucusu çalıştırıcı
-- tarafından secrets.php deki db_user ile değiştirilir (config/health.php ve
-- scripts/apply-migrations-postgres.sh); elle psql -f ile koşarsanız önce
-- sed "s/@APP_DB_USER@/<kullanici>/g" ile değiştirin.
GRANT ALL PRIVILEGES ON TABLE platform_settings TO @APP_DB_USER@;
ALTER TABLE platform_settings OWNER TO @APP_DB_USER@;


-- ═══ 015-product-catalog-postgres.sql ═══
CREATE TABLE IF NOT EXISTS product_type_catalog (
  code VARCHAR(40) PRIMARY KEY,
  label VARCHAR(120) NOT NULL,
  unit VARCHAR(120) NOT NULL,
  steps JSONB NOT NULL DEFAULT '[]'::jsonb,
  fields JSONB NOT NULL DEFAULT '[]'::jsonb,
  room_setup BOOLEAN NOT NULL DEFAULT false,
  hint TEXT NOT NULL DEFAULT '',
  is_active BOOLEAN NOT NULL DEFAULT true,
  sort_order SMALLINT NOT NULL DEFAULT 100,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS product_verification_requirements (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  product_type_code VARCHAR(40) NOT NULL REFERENCES product_type_catalog(code) ON DELETE CASCADE,
  requirement_code VARCHAR(60) NOT NULL,
  label VARCHAR(190) NOT NULL,
  is_required BOOLEAN NOT NULL DEFAULT true,
  sort_order SMALLINT NOT NULL DEFAULT 100,
  UNIQUE(product_type_code,requirement_code)
);

-- ============================================================
-- Sahiplikten bağımsız çalışma (GRANT + ALTER OWNER):
-- Migration ister postgres ister app kullanıcısıyla koşsun, dokunulan tablolar
-- app DB kullanıcısına devredilir. @APP_DB_USER@ yer tutucusu çalıştırıcı
-- tarafından secrets.php deki db_user ile değiştirilir (config/health.php ve
-- scripts/apply-migrations-postgres.sh); elle psql -f ile koşarsanız önce
-- sed "s/@APP_DB_USER@/<kullanici>/g" ile değiştirin.
GRANT ALL PRIVILEGES ON TABLE product_type_catalog, product_verification_requirements TO @APP_DB_USER@;
ALTER TABLE product_type_catalog OWNER TO @APP_DB_USER@;
ALTER TABLE product_verification_requirements OWNER TO @APP_DB_USER@;


-- ═══ 016-agency-channel-and-finance-core-postgres.sql ═══
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


-- ═══ 017-discounts-and-sms-postgres.sql ═══
CREATE TABLE IF NOT EXISTS supplier_discounts (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,supplier_id BIGINT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,rate_plan_id BIGINT REFERENCES rate_plans(id) ON DELETE SET NULL,start_date DATE NOT NULL,end_date DATE NOT NULL,discount_type VARCHAR(12) NOT NULL CHECK(discount_type IN ('percent','fixed')),discount_value NUMERIC(12,2) NOT NULL CHECK(discount_value>0),currency CHAR(3),status VARCHAR(16) NOT NULL DEFAULT 'active' CHECK(status IN ('active','inactive')),created_at TIMESTAMPTZ NOT NULL DEFAULT now(),CHECK(end_date>=start_date));
CREATE TABLE IF NOT EXISTS agency_discount_requests (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,agency_id BIGINT NOT NULL REFERENCES agencies(id) ON DELETE CASCADE,supplier_id BIGINT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,rate_plan_id BIGINT REFERENCES rate_plans(id) ON DELETE SET NULL,start_date DATE NOT NULL,end_date DATE NOT NULL,requested_discount_percent NUMERIC(5,2),requested_price NUMERIC(12,2),currency CHAR(3),message TEXT,status VARCHAR(16) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected','expired')),approved_discount_percent NUMERIC(5,2),approved_price NUMERIC(12,2),approved_by BIGINT REFERENCES supplier_users(id),approved_at TIMESTAMPTZ,created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE TABLE IF NOT EXISTS agency_special_rates (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,agency_id BIGINT NOT NULL REFERENCES agencies(id) ON DELETE CASCADE,supplier_id BIGINT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,rate_plan_id BIGINT REFERENCES rate_plans(id) ON DELETE SET NULL,start_date DATE NOT NULL,end_date DATE NOT NULL,discount_percent NUMERIC(5,2),fixed_price NUMERIC(12,2),currency CHAR(3),request_id BIGINT REFERENCES agency_discount_requests(id) ON DELETE SET NULL,status VARCHAR(16) NOT NULL DEFAULT 'active',created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE TABLE IF NOT EXISTS sms_packages (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,code VARCHAR(40) NOT NULL UNIQUE,name VARCHAR(120) NOT NULL,credit_count INTEGER NOT NULL CHECK(credit_count>0),price_amount NUMERIC(12,2) NOT NULL,currency CHAR(3) NOT NULL DEFAULT 'TRY',is_active BOOLEAN NOT NULL DEFAULT true,created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE TABLE IF NOT EXISTS sms_entitlements (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,account_type VARCHAR(16) NOT NULL CHECK(account_type IN ('supplier','agency')),account_id BIGINT NOT NULL,is_enabled BOOLEAN NOT NULL DEFAULT false,credits_remaining INTEGER NOT NULL DEFAULT 0,updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),UNIQUE(account_type,account_id));
CREATE TABLE IF NOT EXISTS sms_outbox (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,account_type VARCHAR(16) NOT NULL,account_id BIGINT NOT NULL,phone VARCHAR(40) NOT NULL,message TEXT NOT NULL,related_type VARCHAR(40),related_id BIGINT,status VARCHAR(16) NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','sent','failed','skipped')),provider_message_id VARCHAR(120),error_message TEXT,created_at TIMESTAMPTZ NOT NULL DEFAULT now(),sent_at TIMESTAMPTZ);
CREATE INDEX IF NOT EXISTS idx_sms_outbox_status ON sms_outbox(status,created_at);

-- ============================================================
-- Sahiplikten bağımsız çalışma (GRANT + ALTER OWNER):
-- Migration ister postgres ister app kullanıcısıyla koşsun, dokunulan tablolar
-- app DB kullanıcısına devredilir. @APP_DB_USER@ yer tutucusu çalıştırıcı
-- tarafından secrets.php deki db_user ile değiştirilir (config/health.php ve
-- scripts/apply-migrations-postgres.sh); elle psql -f ile koşarsanız önce
-- sed "s/@APP_DB_USER@/<kullanici>/g" ile değiştirin.
GRANT ALL PRIVILEGES ON TABLE supplier_discounts, agency_discount_requests, agency_special_rates, sms_packages, sms_entitlements, sms_outbox TO @APP_DB_USER@;
ALTER TABLE supplier_discounts OWNER TO @APP_DB_USER@;
ALTER TABLE agency_discount_requests OWNER TO @APP_DB_USER@;
ALTER TABLE agency_special_rates OWNER TO @APP_DB_USER@;
ALTER TABLE sms_packages OWNER TO @APP_DB_USER@;
ALTER TABLE sms_entitlements OWNER TO @APP_DB_USER@;
ALTER TABLE sms_outbox OWNER TO @APP_DB_USER@;


-- ═══ 018-netgsm-admin-settings-postgres.sql ═══
ALTER TABLE sms_entitlements ADD COLUMN IF NOT EXISTS notification_phone VARCHAR(40);

INSERT INTO platform_settings(setting_key,value) VALUES
('netgsm_sms_enabled','false'::jsonb)
ON CONFLICT (setting_key) DO NOTHING;

-- ============================================================
-- Sahiplikten bağımsız çalışma (GRANT + ALTER OWNER):
-- Migration ister postgres ister app kullanıcısıyla koşsun, dokunulan tablolar
-- app DB kullanıcısına devredilir. @APP_DB_USER@ yer tutucusu çalıştırıcı
-- tarafından secrets.php deki db_user ile değiştirilir (config/health.php ve
-- scripts/apply-migrations-postgres.sh); elle psql -f ile koşarsanız önce
-- sed "s/@APP_DB_USER@/<kullanici>/g" ile değiştirin.
GRANT ALL PRIVILEGES ON TABLE sms_entitlements, platform_settings TO @APP_DB_USER@;
ALTER TABLE sms_entitlements OWNER TO @APP_DB_USER@;
ALTER TABLE platform_settings OWNER TO @APP_DB_USER@;


-- ═══ 019-distribution-and-rate-management-postgres.sql ═══
-- Channel mapping, ARI scopes, advanced restrictions and contract/rate rules.
ALTER TABLE channel_connections ADD COLUMN IF NOT EXISTS sync_scopes JSONB NOT NULL DEFAULT '{"availability":true,"rates":true,"restrictions":true,"reservations":true}'::jsonb;
ALTER TABLE channel_connections ADD COLUMN IF NOT EXISTS property_code VARCHAR(120);
ALTER TABLE channel_connections ADD COLUMN IF NOT EXISTS last_sync_status VARCHAR(16) NOT NULL DEFAULT 'never';

CREATE TABLE IF NOT EXISTS channel_property_mappings (
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,channel_connection_id BIGINT NOT NULL REFERENCES channel_connections(id) ON DELETE CASCADE,property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,external_property_id VARCHAR(120) NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'active' CHECK(status IN ('active','inactive')),created_at TIMESTAMPTZ NOT NULL DEFAULT now(),UNIQUE(channel_connection_id,property_id),UNIQUE(channel_connection_id,external_property_id)
);
-- NOT: channel_room_mappings ve channel_rate_plan_mappings bu dosyada artık oluşturulmaz.
-- channel_room_mappings'in güncel şeması 045 + 047 + 049 + 052 migration'larında tanımlıdır
-- (kanal dış oda kodu -> NEXUS oda tipi + fiyat planı + öneri/onay akışı).
-- channel_rate_plan_mappings uygulama tarafından kullanılmıyor; eski kopyalarda kalmışsa
-- zararsızdır ve scripts/health-check.php --repair ile temizlenebilir.
CREATE TABLE IF NOT EXISTS channel_sync_logs (
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,channel_connection_id BIGINT NOT NULL REFERENCES channel_connections(id) ON DELETE CASCADE,property_id BIGINT REFERENCES properties(id) ON DELETE SET NULL,direction VARCHAR(8) NOT NULL CHECK(direction IN ('push','pull')),scope VARCHAR(20) NOT NULL CHECK(scope IN ('availability','rates','restrictions','reservations','content')),status VARCHAR(16) NOT NULL CHECK(status IN ('queued','running','success','failed','skipped')),request_payload JSONB NOT NULL DEFAULT '{}'::jsonb,response_payload JSONB NOT NULL DEFAULT '{}'::jsonb,error_message TEXT,attempt_count SMALLINT NOT NULL DEFAULT 0,created_at TIMESTAMPTZ NOT NULL DEFAULT now(),completed_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_channel_sync_logs_connection ON channel_sync_logs(channel_connection_id,created_at DESC);

ALTER TABLE inventory_calendar ADD COLUMN IF NOT EXISTS max_stay SMALLINT;
ALTER TABLE inventory_calendar ADD COLUMN IF NOT EXISTS closed_to_arrival BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE inventory_calendar ADD COLUMN IF NOT EXISTS closed_to_departure BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE inventory_calendar ADD COLUMN IF NOT EXISTS min_advance_days SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE inventory_calendar ADD COLUMN IF NOT EXISTS max_advance_days SMALLINT;

CREATE TABLE IF NOT EXISTS rate_rules (
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,supplier_id BIGINT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,rate_plan_id BIGINT REFERENCES rate_plans(id) ON DELETE SET NULL,name VARCHAR(190) NOT NULL,rule_type VARCHAR(20) NOT NULL CHECK(rule_type IN ('percent','fixed','derived','promo_code','free_night')),value NUMERIC(12,2) NOT NULL DEFAULT 0,currency CHAR(3),booking_start DATE,booking_end DATE,stay_start DATE,stay_end DATE,min_advance_days SMALLINT NOT NULL DEFAULT 0,markets JSONB NOT NULL DEFAULT '[]'::jsonb,nationalities JSONB NOT NULL DEFAULT '[]'::jsonb,channels JSONB NOT NULL DEFAULT '[]'::jsonb,occupancy_rules JSONB NOT NULL DEFAULT '{}'::jsonb,promo_code VARCHAR(60),priority SMALLINT NOT NULL DEFAULT 100,stackable BOOLEAN NOT NULL DEFAULT false,status VARCHAR(16) NOT NULL DEFAULT 'active' CHECK(status IN ('active','inactive')),created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_rate_rules_property_dates ON rate_rules(property_id,status,stay_start,stay_end);

-- ============================================================
-- Sahiplikten bağımsız çalışma (GRANT + ALTER OWNER):
-- Migration ister postgres ister app kullanıcısıyla koşsun, dokunulan tablolar
-- app DB kullanıcısına devredilir. @APP_DB_USER@ yer tutucusu çalıştırıcı
-- tarafından secrets.php deki db_user ile değiştirilir (config/health.php ve
-- scripts/apply-migrations-postgres.sh); elle psql -f ile koşarsanız önce
-- sed "s/@APP_DB_USER@/<kullanici>/g" ile değiştirin.
GRANT ALL PRIVILEGES ON TABLE channel_property_mappings, channel_sync_logs, rate_rules, channel_connections, inventory_calendar TO @APP_DB_USER@;
ALTER TABLE channel_property_mappings OWNER TO @APP_DB_USER@;
ALTER TABLE channel_sync_logs OWNER TO @APP_DB_USER@;
ALTER TABLE rate_rules OWNER TO @APP_DB_USER@;
ALTER TABLE channel_connections OWNER TO @APP_DB_USER@;
ALTER TABLE inventory_calendar OWNER TO @APP_DB_USER@;


-- ═══ 020-ical-vacation-rental-and-yacht-postgres.sql ═══
CREATE TABLE IF NOT EXISTS ical_connections (
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 supplier_id BIGINT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,
 property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,
 direction VARCHAR(10) NOT NULL CHECK(direction IN ('import','export')),
 label VARCHAR(120) NOT NULL,
 access_token CHAR(64) NOT NULL UNIQUE,
 source_url TEXT,
 status VARCHAR(16) NOT NULL DEFAULT 'active' CHECK(status IN ('active','paused','error')),
 last_sync_at TIMESTAMPTZ,
 last_error TEXT,
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
 CHECK((direction='import' AND source_url IS NOT NULL) OR direction='export')
);
CREATE INDEX IF NOT EXISTS idx_ical_connections_property ON ical_connections(property_id,status);

CREATE TABLE IF NOT EXISTS ical_events (
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 ical_connection_id BIGINT NOT NULL REFERENCES ical_connections(id) ON DELETE CASCADE,
 property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,
 external_uid VARCHAR(255) NOT NULL,
 starts_on DATE NOT NULL,
 ends_on DATE NOT NULL,
 summary VARCHAR(255),
 event_status VARCHAR(24) NOT NULL DEFAULT 'confirmed',
 raw_event TEXT,
 synced_at TIMESTAMPTZ NOT NULL DEFAULT now(),
 UNIQUE(ical_connection_id,external_uid),
 CHECK(ends_on>=starts_on)
);
CREATE INDEX IF NOT EXISTS idx_ical_events_property_dates ON ical_events(property_id,starts_on,ends_on);

-- ============================================================
-- Sahiplikten bağımsız çalışma (GRANT + ALTER OWNER):
-- Migration ister postgres ister app kullanıcısıyla koşsun, dokunulan tablolar
-- app DB kullanıcısına devredilir. @APP_DB_USER@ yer tutucusu çalıştırıcı
-- tarafından secrets.php deki db_user ile değiştirilir (config/health.php ve
-- scripts/apply-migrations-postgres.sh); elle psql -f ile koşarsanız önce
-- sed "s/@APP_DB_USER@/<kullanici>/g" ile değiştirin.
GRANT ALL PRIVILEGES ON TABLE ical_connections, ical_events TO @APP_DB_USER@;
ALTER TABLE ical_connections OWNER TO @APP_DB_USER@;
ALTER TABLE ical_events OWNER TO @APP_DB_USER@;


-- ═══ 021-hotel-pms-operations-postgres.sql ═══
-- Hotel PMS operational core: physical rooms, guests, folios, housekeeping and service operations.
ALTER TABLE supplier_bookings ADD COLUMN IF NOT EXISTS source_code VARCHAR(60) NOT NULL DEFAULT 'manual';
ALTER TABLE supplier_bookings ADD COLUMN IF NOT EXISTS channel_connection_id BIGINT REFERENCES channel_connections(id) ON DELETE SET NULL;
ALTER TABLE supplier_bookings ADD COLUMN IF NOT EXISTS booking_status VARCHAR(24) NOT NULL DEFAULT 'reserved';
ALTER TABLE supplier_bookings ADD COLUMN IF NOT EXISTS option_expires_at TIMESTAMPTZ;
ALTER TABLE supplier_bookings ADD COLUMN IF NOT EXISTS checked_in_at TIMESTAMPTZ;
ALTER TABLE supplier_bookings ADD COLUMN IF NOT EXISTS checked_out_at TIMESTAMPTZ;
ALTER TABLE supplier_bookings ADD COLUMN IF NOT EXISTS notes TEXT;

CREATE TABLE IF NOT EXISTS physical_rooms (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,room_type_id BIGINT REFERENCES room_types(id) ON DELETE SET NULL,room_number VARCHAR(40) NOT NULL,floor_label VARCHAR(40),status VARCHAR(20) NOT NULL DEFAULT 'clean' CHECK(status IN ('clean','dirty','inspected','out_of_order','out_of_service','occupied')),notes TEXT,UNIQUE(property_id,room_number));
CREATE TABLE IF NOT EXISTS guest_profiles (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,supplier_id BIGINT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,first_name VARCHAR(100) NOT NULL,last_name VARCHAR(100) NOT NULL,email VARCHAR(190),phone VARCHAR(40),nationality CHAR(2),birth_date DATE,identity_type VARCHAR(30),identity_number VARCHAR(120),passport_country CHAR(2),vip_level VARCHAR(30),marketing_consent BOOLEAN NOT NULL DEFAULT false,preferences JSONB NOT NULL DEFAULT '{}'::jsonb,created_at TIMESTAMPTZ NOT NULL DEFAULT now(),UNIQUE(supplier_id,email));
CREATE TABLE IF NOT EXISTS booking_guests (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,booking_id BIGINT NOT NULL REFERENCES supplier_bookings(id) ON DELETE CASCADE,guest_id BIGINT NOT NULL REFERENCES guest_profiles(id) ON DELETE CASCADE,is_primary BOOLEAN NOT NULL DEFAULT false,checkin_status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK(checkin_status IN ('pending','submitted','verified','checked_in')),UNIQUE(booking_id,guest_id));
CREATE TABLE IF NOT EXISTS booking_rooms (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,booking_id BIGINT NOT NULL REFERENCES supplier_bookings(id) ON DELETE CASCADE,room_type_id BIGINT REFERENCES room_types(id) ON DELETE SET NULL,physical_room_id BIGINT REFERENCES physical_rooms(id) ON DELETE SET NULL,adults SMALLINT NOT NULL DEFAULT 2,children SMALLINT NOT NULL DEFAULT 0,nightly_rate NUMERIC(12,2) NOT NULL DEFAULT 0,currency CHAR(3) NOT NULL DEFAULT 'EUR',status VARCHAR(20) NOT NULL DEFAULT 'reserved' CHECK(status IN ('reserved','checked_in','checked_out','cancelled','no_show')),UNIQUE(booking_id,physical_room_id));
CREATE TABLE IF NOT EXISTS booking_folios (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,booking_id BIGINT NOT NULL REFERENCES supplier_bookings(id) ON DELETE CASCADE,folio_type VARCHAR(20) NOT NULL DEFAULT 'guest' CHECK(folio_type IN ('guest','agency','company')),currency CHAR(3) NOT NULL DEFAULT 'EUR',status VARCHAR(16) NOT NULL DEFAULT 'open' CHECK(status IN ('open','locked','closed')),created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE TABLE IF NOT EXISTS folio_transactions (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,folio_id BIGINT NOT NULL REFERENCES booking_folios(id) ON DELETE CASCADE,transaction_type VARCHAR(20) NOT NULL CHECK(transaction_type IN ('room_charge','service_charge','payment','refund','adjustment','tax')),department VARCHAR(100),description VARCHAR(255) NOT NULL,amount NUMERIC(12,2) NOT NULL,transaction_at TIMESTAMPTZ NOT NULL DEFAULT now(),created_by BIGINT REFERENCES supplier_users(id) ON DELETE SET NULL);
CREATE INDEX IF NOT EXISTS idx_folio_transactions_folio ON folio_transactions(folio_id,transaction_at);
CREATE TABLE IF NOT EXISTS housekeeping_tasks (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,physical_room_id BIGINT REFERENCES physical_rooms(id) ON DELETE SET NULL,task_type VARCHAR(30) NOT NULL DEFAULT 'cleaning',priority VARCHAR(12) NOT NULL DEFAULT 'normal',status VARCHAR(20) NOT NULL DEFAULT 'open' CHECK(status IN ('open','assigned','in_progress','inspected','completed')),assigned_to VARCHAR(190),due_at TIMESTAMPTZ,notes TEXT,created_at TIMESTAMPTZ NOT NULL DEFAULT now(),completed_at TIMESTAMPTZ);
CREATE TABLE IF NOT EXISTS maintenance_tickets (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,physical_room_id BIGINT REFERENCES physical_rooms(id) ON DELETE SET NULL,title VARCHAR(190) NOT NULL,description TEXT,priority VARCHAR(12) NOT NULL DEFAULT 'normal',status VARCHAR(20) NOT NULL DEFAULT 'open' CHECK(status IN ('open','assigned','in_progress','resolved','cancelled')),assigned_to VARCHAR(190),created_at TIMESTAMPTZ NOT NULL DEFAULT now(),resolved_at TIMESTAMPTZ);
CREATE TABLE IF NOT EXISTS property_services (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,service_type VARCHAR(40) NOT NULL,name VARCHAR(190) NOT NULL,duration_minutes SMALLINT,capacity INTEGER,price NUMERIC(12,2) NOT NULL DEFAULT 0,currency CHAR(3) NOT NULL DEFAULT 'EUR',is_active BOOLEAN NOT NULL DEFAULT true);
CREATE TABLE IF NOT EXISTS service_bookings (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,property_service_id BIGINT NOT NULL REFERENCES property_services(id) ON DELETE CASCADE,booking_id BIGINT REFERENCES supplier_bookings(id) ON DELETE SET NULL,guest_id BIGINT REFERENCES guest_profiles(id) ON DELETE SET NULL,starts_at TIMESTAMPTZ NOT NULL,ends_at TIMESTAMPTZ,quantity INTEGER NOT NULL DEFAULT 1,status VARCHAR(20) NOT NULL DEFAULT 'reserved',folio_id BIGINT REFERENCES booking_folios(id) ON DELETE SET NULL,notes TEXT,created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE TABLE IF NOT EXISTS hotel_daily_closures (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,business_date DATE NOT NULL,closed_by BIGINT REFERENCES supplier_users(id) ON DELETE SET NULL,closed_at TIMESTAMPTZ NOT NULL DEFAULT now(),notes TEXT,UNIQUE(property_id,business_date));


-- ═══ 022-hotel-guest-direct-booking-postgres.sql ═══
CREATE TABLE IF NOT EXISTS booking_checkin_links (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,booking_id BIGINT NOT NULL REFERENCES supplier_bookings(id) ON DELETE CASCADE,token_hash CHAR(64) NOT NULL UNIQUE,locale CHAR(2) NOT NULL DEFAULT 'tr',expires_at TIMESTAMPTZ NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'active' CHECK(status IN ('active','submitted','expired','revoked')),created_at TIMESTAMPTZ NOT NULL DEFAULT now(),submitted_at TIMESTAMPTZ);
CREATE TABLE IF NOT EXISTS guest_document_records (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,guest_id BIGINT NOT NULL REFERENCES guest_profiles(id) ON DELETE CASCADE,booking_id BIGINT REFERENCES supplier_bookings(id) ON DELETE SET NULL,document_type VARCHAR(30) NOT NULL,document_number_masked VARCHAR(80),verification_status VARCHAR(16) NOT NULL DEFAULT 'pending',consent_at TIMESTAMPTZ,created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE TABLE IF NOT EXISTS property_booking_widgets (property_id BIGINT PRIMARY KEY REFERENCES properties(id) ON DELETE CASCADE,is_enabled BOOLEAN NOT NULL DEFAULT false,public_token CHAR(64) NOT NULL UNIQUE,confirmation_mode VARCHAR(20) NOT NULL DEFAULT 'request' CHECK(confirmation_mode IN ('request','instant')),created_at TIMESTAMPTZ NOT NULL DEFAULT now(),updated_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE TABLE IF NOT EXISTS hotel_compliance_queue (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,booking_id BIGINT REFERENCES supplier_bookings(id) ON DELETE SET NULL,queue_type VARCHAR(40) NOT NULL,encrypted_payload TEXT,status VARCHAR(16) NOT NULL DEFAULT 'queued',last_error TEXT,created_at TIMESTAMPTZ NOT NULL DEFAULT now(),processed_at TIMESTAMPTZ);


-- ═══ 023-hotel-commercial-core-postgres.sql ═══
-- Commercial PMS core: groups, room movements, multi-folio, payment allocation, invoice and night-audit controls.
CREATE TABLE IF NOT EXISTS booking_groups (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,supplier_id BIGINT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,group_code VARCHAR(60) NOT NULL,group_name VARCHAR(190) NOT NULL,agency_id BIGINT REFERENCES agencies(id) ON DELETE SET NULL,status VARCHAR(20) NOT NULL DEFAULT 'option' CHECK(status IN ('option','confirmed','cancelled','lost')),option_expires_at TIMESTAMPTZ,notes TEXT,created_at TIMESTAMPTZ NOT NULL DEFAULT now(),UNIQUE(supplier_id,group_code));
ALTER TABLE supplier_bookings ADD COLUMN IF NOT EXISTS group_id BIGINT REFERENCES booking_groups(id) ON DELETE SET NULL;
ALTER TABLE supplier_bookings ADD COLUMN IF NOT EXISTS confirmation_number VARCHAR(100);
ALTER TABLE supplier_bookings ADD COLUMN IF NOT EXISTS cancellation_reason TEXT;
ALTER TABLE supplier_bookings ADD COLUMN IF NOT EXISTS cancelled_at TIMESTAMPTZ;
CREATE TABLE IF NOT EXISTS booking_room_moves (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,booking_room_id BIGINT NOT NULL REFERENCES booking_rooms(id) ON DELETE CASCADE,from_physical_room_id BIGINT REFERENCES physical_rooms(id) ON DELETE SET NULL,to_physical_room_id BIGINT REFERENCES physical_rooms(id) ON DELETE SET NULL,effective_at TIMESTAMPTZ NOT NULL DEFAULT now(),reason VARCHAR(255),moved_by BIGINT REFERENCES supplier_users(id) ON DELETE SET NULL);
CREATE TABLE IF NOT EXISTS booking_options (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,booking_id BIGINT NOT NULL REFERENCES supplier_bookings(id) ON DELETE CASCADE,expires_at TIMESTAMPTZ NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'active' CHECK(status IN ('active','confirmed','expired','cancelled')),created_at TIMESTAMPTZ NOT NULL DEFAULT now());
ALTER TABLE booking_folios ADD COLUMN IF NOT EXISTS folio_number VARCHAR(60);
ALTER TABLE booking_folios ADD COLUMN IF NOT EXISTS billed_to_name VARCHAR(190);
ALTER TABLE booking_folios ADD COLUMN IF NOT EXISTS due_date DATE;
CREATE UNIQUE INDEX IF NOT EXISTS uq_booking_folio_number ON booking_folios(folio_number) WHERE folio_number IS NOT NULL;
CREATE TABLE IF NOT EXISTS payment_records (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,supplier_id BIGINT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,booking_id BIGINT REFERENCES supplier_bookings(id) ON DELETE SET NULL,payment_reference VARCHAR(100) NOT NULL,payment_method VARCHAR(30) NOT NULL,amount NUMERIC(12,2) NOT NULL CHECK(amount>0),currency CHAR(3) NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'captured' CHECK(status IN ('pending','authorized','captured','refunded','failed')),provider_transaction_id VARCHAR(190),received_at TIMESTAMPTZ NOT NULL DEFAULT now(),UNIQUE(supplier_id,payment_reference));
CREATE TABLE IF NOT EXISTS payment_allocations (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,payment_id BIGINT NOT NULL REFERENCES payment_records(id) ON DELETE CASCADE,folio_id BIGINT NOT NULL REFERENCES booking_folios(id) ON DELETE CASCADE,amount NUMERIC(12,2) NOT NULL CHECK(amount>0),created_at TIMESTAMPTZ NOT NULL DEFAULT now(),UNIQUE(payment_id,folio_id));
CREATE TABLE IF NOT EXISTS hotel_invoices (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,supplier_id BIGINT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,folio_id BIGINT NOT NULL REFERENCES booking_folios(id) ON DELETE RESTRICT,invoice_number VARCHAR(100) NOT NULL,invoice_type VARCHAR(20) NOT NULL DEFAULT 'invoice',recipient_name VARCHAR(190) NOT NULL,recipient_tax_number VARCHAR(80),subtotal NUMERIC(12,2) NOT NULL DEFAULT 0,tax_amount NUMERIC(12,2) NOT NULL DEFAULT 0,total_amount NUMERIC(12,2) NOT NULL DEFAULT 0,currency CHAR(3) NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','issued','cancelled','sent')),issued_at TIMESTAMPTZ,UNIQUE(supplier_id,invoice_number));
CREATE TABLE IF NOT EXISTS night_audit_runs (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,business_date DATE NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'started' CHECK(status IN ('started','validated','closed','failed','reversed')),validation_errors JSONB NOT NULL DEFAULT '[]'::jsonb,room_charges_posted INTEGER NOT NULL DEFAULT 0,performed_by BIGINT REFERENCES supplier_users(id) ON DELETE SET NULL,started_at TIMESTAMPTZ NOT NULL DEFAULT now(),closed_at TIMESTAMPTZ,UNIQUE(property_id,business_date));
CREATE TABLE IF NOT EXISTS hotel_staff (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,supplier_id BIGINT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,full_name VARCHAR(190) NOT NULL,department VARCHAR(80) NOT NULL,role_code VARCHAR(60) NOT NULL,pin_hash VARCHAR(255),is_active BOOLEAN NOT NULL DEFAULT true,created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE TABLE IF NOT EXISTS hotel_roles (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,supplier_id BIGINT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,role_code VARCHAR(60) NOT NULL,role_name VARCHAR(120) NOT NULL,permissions JSONB NOT NULL DEFAULT '[]'::jsonb,UNIQUE(supplier_id,role_code));


-- ═══ 024-hotel-revenue-crm-loyalty-postgres.sql ═══
CREATE TABLE IF NOT EXISTS loyalty_tiers (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,supplier_id BIGINT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,code VARCHAR(40) NOT NULL,name VARCHAR(120) NOT NULL,min_nights INTEGER NOT NULL DEFAULT 0,min_revenue NUMERIC(12,2) NOT NULL DEFAULT 0,stay_discount_percent NUMERIC(5,2) NOT NULL DEFAULT 0,service_discount_percent NUMERIC(5,2) NOT NULL DEFAULT 0,bonus_expiry_days INTEGER,UNIQUE(supplier_id,code));
CREATE TABLE IF NOT EXISTS guest_loyalty_accounts (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,guest_id BIGINT NOT NULL REFERENCES guest_profiles(id) ON DELETE CASCADE,tier_id BIGINT REFERENCES loyalty_tiers(id) ON DELETE SET NULL,points_balance NUMERIC(12,2) NOT NULL DEFAULT 0,lifetime_nights INTEGER NOT NULL DEFAULT 0,lifetime_revenue NUMERIC(12,2) NOT NULL DEFAULT 0,status VARCHAR(16) NOT NULL DEFAULT 'active',UNIQUE(guest_id));
CREATE TABLE IF NOT EXISTS loyalty_ledger (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,account_id BIGINT NOT NULL REFERENCES guest_loyalty_accounts(id) ON DELETE CASCADE,booking_id BIGINT REFERENCES supplier_bookings(id) ON DELETE SET NULL,transaction_type VARCHAR(20) NOT NULL CHECK(transaction_type IN ('earn','redeem','expire','adjustment')),points NUMERIC(12,2) NOT NULL,description VARCHAR(255),expires_at TIMESTAMPTZ,created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE TABLE IF NOT EXISTS guest_segments (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,supplier_id BIGINT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,name VARCHAR(120) NOT NULL,rules JSONB NOT NULL DEFAULT '{}'::jsonb,is_active BOOLEAN NOT NULL DEFAULT true,UNIQUE(supplier_id,name));
CREATE TABLE IF NOT EXISTS guest_communications (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,supplier_id BIGINT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,guest_id BIGINT REFERENCES guest_profiles(id) ON DELETE SET NULL,booking_id BIGINT REFERENCES supplier_bookings(id) ON DELETE SET NULL,channel VARCHAR(20) NOT NULL CHECK(channel IN ('email','sms','whatsapp','in_app')),template_code VARCHAR(80),subject VARCHAR(190),body TEXT NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'queued',scheduled_at TIMESTAMPTZ,delivered_at TIMESTAMPTZ,error_message TEXT,created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE TABLE IF NOT EXISTS guest_service_requests (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,booking_id BIGINT NOT NULL REFERENCES supplier_bookings(id) ON DELETE CASCADE,guest_id BIGINT REFERENCES guest_profiles(id) ON DELETE SET NULL,request_type VARCHAR(40) NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'open',priority VARCHAR(12) NOT NULL DEFAULT 'normal',details TEXT,assigned_to BIGINT REFERENCES hotel_staff(id) ON DELETE SET NULL,created_at TIMESTAMPTZ NOT NULL DEFAULT now(),completed_at TIMESTAMPTZ);
CREATE TABLE IF NOT EXISTS revenue_snapshots (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,snapshot_date DATE NOT NULL,occupancy_percent NUMERIC(5,2) NOT NULL DEFAULT 0,adr NUMERIC(12,2) NOT NULL DEFAULT 0,revpar NUMERIC(12,2) NOT NULL DEFAULT 0,pickup_count INTEGER NOT NULL DEFAULT 0,cancellation_count INTEGER NOT NULL DEFAULT 0,source_data JSONB NOT NULL DEFAULT '{}'::jsonb,UNIQUE(property_id,snapshot_date));
CREATE TABLE IF NOT EXISTS revenue_recommendations (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,rate_plan_id BIGINT REFERENCES rate_plans(id) ON DELETE SET NULL,stay_date DATE NOT NULL,recommendation_type VARCHAR(30) NOT NULL,recommended_value NUMERIC(12,2),currency CHAR(3),confidence NUMERIC(5,2),reason TEXT,status VARCHAR(16) NOT NULL DEFAULT 'new' CHECK(status IN ('new','accepted','dismissed','applied')),created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE TABLE IF NOT EXISTS report_exports (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,supplier_id BIGINT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,report_type VARCHAR(60) NOT NULL,filters JSONB NOT NULL DEFAULT '{}'::jsonb,format VARCHAR(10) NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'queued',file_path TEXT,created_at TIMESTAMPTZ NOT NULL DEFAULT now(),completed_at TIMESTAMPTZ);


-- ═══ 025-login-throttle-postgres.sql ═══
-- 025: Login brute-force koruması ve lead KVKK onayı
CREATE TABLE IF NOT EXISTS login_throttle (
  bucket        VARCHAR(128) PRIMARY KEY,
  attempts      INTEGER NOT NULL DEFAULT 0,
  window_start  TIMESTAMPTZ NOT NULL DEFAULT now(),
  locked_until  TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_login_throttle_locked ON login_throttle (locked_until) WHERE locked_until IS NOT NULL;

-- Erken erişim başvurularında KVKK onay zamanı
ALTER TABLE early_access_leads ADD COLUMN IF NOT EXISTS consent_at TIMESTAMPTZ;


-- ═══ 026-agency-bookings-reviews-compliance-postgres.sql ═══
-- 026: B2B canlı rezervasyon, misafir değerlendirme ve kimlik bildirimi takibi

-- Acentelerden gelen canlı rezervasyon talepleri; tedarikçi onayıyla gerçek rezervasyona dönüşür.
CREATE TABLE IF NOT EXISTS agency_booking_requests (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  agency_id BIGINT NOT NULL REFERENCES agencies(id) ON DELETE CASCADE,
  supplier_id BIGINT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,
  property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,
  room_type_id BIGINT REFERENCES room_types(id) ON DELETE SET NULL,
  rate_plan_id BIGINT REFERENCES rate_plans(id) ON DELETE SET NULL,
  check_in DATE NOT NULL,
  check_out DATE NOT NULL,
  nights INTEGER NOT NULL DEFAULT 1,
  adults SMALLINT NOT NULL DEFAULT 2,
  children SMALLINT NOT NULL DEFAULT 0,
  total_amount NUMERIC(12,2) NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  status VARCHAR(16) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected','cancelled','expired')),
  guest_first_name VARCHAR(100) NOT NULL,
  guest_last_name VARCHAR(100) NOT NULL,
  guest_email VARCHAR(190),
  guest_phone VARCHAR(40),
  agency_reference VARCHAR(80),
  note TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  responded_at TIMESTAMPTZ,
  responded_by BIGINT REFERENCES supplier_users(id) ON DELETE SET NULL,
  response_note TEXT
);
CREATE INDEX IF NOT EXISTS idx_agency_booking_requests_status ON agency_booking_requests(status,created_at DESC);
CREATE INDEX IF NOT EXISTS idx_agency_booking_requests_agency ON agency_booking_requests(agency_id,status);
CREATE INDEX IF NOT EXISTS idx_agency_booking_requests_supplier ON agency_booking_requests(supplier_id,status,created_at DESC);

-- Konaklama sonrası misafir değerlendirmeleri (itibar yönetimi).
CREATE TABLE IF NOT EXISTS guest_reviews (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,
  booking_id BIGINT REFERENCES supplier_bookings(id) ON DELETE SET NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  guest_name VARCHAR(190),
  rating SMALLINT CHECK(rating BETWEEN 1 AND 5),
  title VARCHAR(190),
  body TEXT,
  response TEXT,
  responded_by BIGINT REFERENCES supplier_users(id) ON DELETE SET NULL,
  response_at TIMESTAMPTZ,
  status VARCHAR(16) NOT NULL DEFAULT 'invited' CHECK(status IN ('invited','pending','published','hidden')),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  submitted_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_guest_reviews_property_status ON guest_reviews(property_id,status,created_at DESC);

-- Kimlik bildirimi raporu için bildirim durumu.
ALTER TABLE guest_document_records ADD COLUMN IF NOT EXISTS reported_at TIMESTAMPTZ;


-- ═══ 027-email-outbox-postgres.sql ═══
-- 027: E-posta kuyruğu (misafir bildirimleri, admin uyarıları)
CREATE TABLE IF NOT EXISTS email_outbox (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  to_address VARCHAR(190) NOT NULL,
  subject VARCHAR(190) NOT NULL,
  body_html TEXT NOT NULL,
  related_type VARCHAR(40),
  related_id BIGINT,
  status VARCHAR(16) NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','sent','failed','skipped')),
  error_message TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  sent_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_email_outbox_status ON email_outbox(status,created_at);


-- ═══ 028-webhooks-settlements-postgres.sql ═══
-- 028: Webhook abonelikleri ve mutabakat benzersizliği

CREATE TABLE IF NOT EXISTS webhook_subscriptions (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  agency_id BIGINT NOT NULL REFERENCES agencies(id) ON DELETE CASCADE,
  url VARCHAR(500) NOT NULL,
  secret VARCHAR(120),
  events JSONB NOT NULL DEFAULT '[]'::jsonb,
  status VARCHAR(16) NOT NULL DEFAULT 'active' CHECK(status IN ('active','paused')),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  last_sent_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_webhook_subscriptions_agency ON webhook_subscriptions(agency_id);

CREATE TABLE IF NOT EXISTS webhook_deliveries (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  subscription_id BIGINT NOT NULL REFERENCES webhook_subscriptions(id) ON DELETE CASCADE,
  event VARCHAR(60) NOT NULL,
  payload JSONB NOT NULL DEFAULT '{}'::jsonb,
  status VARCHAR(16) NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','sending','sent','failed')),
  http_status SMALLINT,
  attempts SMALLINT NOT NULL DEFAULT 0,
  error_message TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  sent_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_webhook_deliveries_status ON webhook_deliveries(status,created_at);

-- Bir rezervasyon için tek mutabakat kaydı.
-- Önce olası mükerrer kayıtları temizle (en son kayıt kalır), sonra benzersiz indeksi kur;
-- aksi halde mevcut verideki aynı booking_id'li satırlar indeks oluşumunu engeller.
DELETE FROM supplier_settlements a USING supplier_settlements b
WHERE a.booking_id IS NOT NULL AND a.id < b.id AND a.booking_id = b.booking_id;
CREATE UNIQUE INDEX IF NOT EXISTS uq_settlements_booking ON supplier_settlements(booking_id) WHERE booking_id IS NOT NULL;


-- ═══ 029-booking-cancel-postgres.sql ═══
-- 029: İptal akışı için rezervasyon-fiyat planı ve talep-rezervasyon bağları

ALTER TABLE supplier_bookings ADD COLUMN IF NOT EXISTS rate_plan_id BIGINT REFERENCES rate_plans(id) ON DELETE SET NULL;
ALTER TABLE agency_booking_requests ADD COLUMN IF NOT EXISTS booking_id BIGINT REFERENCES supplier_bookings(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_agency_booking_requests_booking ON agency_booking_requests(booking_id);


-- ═══ 030-monitoring-payments-fx-postgres.sql ═══
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


-- ═══ 031-notifications-agency-signup-postgres.sql ═══
-- 031: Panel içi bildirimler ve acente self-servis kayıt doğrulaması

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_type VARCHAR(12) NOT NULL CHECK(user_type IN ('supplier','agency')),
    user_id BIGINT NOT NULL,
    type VARCHAR(40) NOT NULL,
    message VARCHAR(500) NOT NULL,
    link VARCHAR(500),
    is_read BOOLEAN NOT NULL DEFAULT false,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_type,user_id,is_read,created_at DESC);

ALTER TABLE agencies ADD COLUMN IF NOT EXISTS verify_token VARCHAR(64);
ALTER TABLE agencies ADD COLUMN IF NOT EXISTS verified_at TIMESTAMPTZ;
ALTER TABLE agencies ADD COLUMN IF NOT EXISTS self_registered BOOLEAN NOT NULL DEFAULT false;


-- ═══ 032-hotel-finance-security-postgres.sql ═══
-- 032: İptal politikası, depozito, 2FA, acente kredi limiti ve e-posta şablonları

-- Fiyat planı bazında yapılandırılmış iptal politikası (ücretsiz iptal penceresi + iptal ücreti %).
ALTER TABLE rate_plans ADD COLUMN IF NOT EXISTS free_cancel_before_days INTEGER;
ALTER TABLE rate_plans ADD COLUMN IF NOT EXISTS cancel_fee_percent NUMERIC(5,2) NOT NULL DEFAULT 0;

-- Ön büro operasyonları: erken giriş / geç çıkış, depozito, no-show ve iptal izi.
ALTER TABLE supplier_bookings ADD COLUMN IF NOT EXISTS early_arrival BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE supplier_bookings ADD COLUMN IF NOT EXISTS late_departure BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE supplier_bookings ADD COLUMN IF NOT EXISTS deposit_amount NUMERIC(12,2) NOT NULL DEFAULT 0;
ALTER TABLE supplier_bookings ADD COLUMN IF NOT EXISTS deposit_status VARCHAR(20) NOT NULL DEFAULT 'not_required';
ALTER TABLE supplier_bookings ADD COLUMN IF NOT EXISTS deposit_paid_at TIMESTAMPTZ;
ALTER TABLE supplier_bookings ADD COLUMN IF NOT EXISTS no_show_at TIMESTAMPTZ;
ALTER TABLE supplier_bookings ADD COLUMN IF NOT EXISTS cancelled_at TIMESTAMPTZ;
ALTER TABLE supplier_bookings ADD COLUMN IF NOT EXISTS cancellation_reason TEXT;

-- İki adımlı doğrulama (TOTP) için kullanıcı seviyesinde gizli anahtar.
ALTER TABLE supplier_users ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(64);
ALTER TABLE agency_users ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(64);

-- Admin girişi secrets.php tabanlı olduğu için 2FA durumu burada tutulur (tek satır).
CREATE TABLE IF NOT EXISTS admin_2fa (
  id SMALLINT PRIMARY KEY CHECK (id = 1),
  secret VARCHAR(64) NOT NULL DEFAULT '',
  enabled BOOLEAN NOT NULL DEFAULT false,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Acente güven katmanı: kredi limiti ve ödeme geçmişi skoru (0-100).
ALTER TABLE agencies ADD COLUMN IF NOT EXISTS credit_limit NUMERIC(12,2);
ALTER TABLE agencies ADD COLUMN IF NOT EXISTS payment_score NUMERIC(5,2) NOT NULL DEFAULT 0;
ALTER TABLE agencies ADD COLUMN IF NOT EXISTS last_payment_at TIMESTAMPTZ;

-- Yönetilebilir e-posta şablonları; {degisken} sözdizimi destekler.
CREATE TABLE IF NOT EXISTS email_templates (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  code VARCHAR(60) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  subject VARCHAR(190) NOT NULL,
  body_html TEXT NOT NULL,
  is_active BOOLEAN NOT NULL DEFAULT true,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);


-- ═══ 033-performance-indexes-postgres.sql ═══
-- 033: Performans denetiminin (scripts/audit-performance.php) tespit ettiği eksik indeksler
CREATE INDEX IF NOT EXISTS idx_supplier_bookings_property_checkin ON supplier_bookings(property_id, check_in);
CREATE INDEX IF NOT EXISTS idx_supplier_bookings_property_status ON supplier_bookings(property_id, booking_status);
CREATE INDEX IF NOT EXISTS idx_loyalty_ledger_account ON loyalty_ledger(account_id, created_at);


-- ═══ 034-scheduler-timers-postgres.sql ═══
-- 034: Panel yönetimli zamanlayıcılar (scheduled jobs)
-- Sistem cron'ları yerine tek bir "nabız" (cron/tick.php veya token'lı URL görevi)
-- bu tablodaki görevleri tarar ve vadesi gelenleri çalıştırır.

CREATE TABLE IF NOT EXISTS scheduled_jobs (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  code VARCHAR(60) NOT NULL UNIQUE,
  name VARCHAR(190) NOT NULL,
  command VARCHAR(500) NOT NULL,
  schedule VARCHAR(60) NOT NULL,          -- cron ifadesi: dakika saat gün ay hafta
  enabled BOOLEAN NOT NULL DEFAULT true,
  last_run_at TIMESTAMPTZ,
  last_status VARCHAR(16),
  last_output TEXT,
  run_count BIGINT NOT NULL DEFAULT 0,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

INSERT INTO scheduled_jobs(code,name,command,schedule) VALUES
('nexus-sync-ical','iCal senkronizasyonu','cron/sync-ical-calendars.php','*/15 * * * *'),
('nexus-revenue-rec','Gelir önerisi üretimi','cron/generate-revenue-recommendations.php','15 2 * * *'),
('nexus-netgsm-sms','Netgsm SMS işleme','cron/process-netgsm-sms.php','* * * * *'),
('nexus-process-emails','E-posta kuyruğu','cron/process-emails.php','*/5 * * * *'),
('nexus-process-webhooks','Webhook teslimatı','cron/process-webhooks.php','*/1 * * * *'),
('nexus-welcome-emails','Hoş geldiniz e-postaları','cron/send-welcome-emails.php','0 8 * * *'),
('nexus-notification-digest','Bildirim özeti','cron/send-notification-digest.php','15 9 * * *'),
('nexus-expire-group-options','Grup opsiyon süresi','cron/expire-group-options.php','30 3 * * *')
ON CONFLICT (code) DO NOTHING;


-- ═══ 035-public-ai-chat-postgres.sql ═══
-- Kamuya açık (önyüz) AI sohbet: ziyaretçi soruları kaydı + IP hız sınırlama verisi.
CREATE TABLE IF NOT EXISTS public_chat_messages (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ip INET,
    user_message TEXT,
    ai_reply TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_public_chat_ip_time ON public_chat_messages (ip, created_at);


-- ═══ 036-blocked-ips-postgres.sql ═══
-- Kötü niyetli ziyaretçi trafiği için IP engelleme / bayraklama.
CREATE TABLE IF NOT EXISTS blocked_ips (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ip INET NOT NULL,
    action VARCHAR(10) NOT NULL DEFAULT 'block' CHECK (action IN ('block','flag')),
    reason TEXT,
    created_by VARCHAR(60),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_blocked_ips_ip ON blocked_ips (ip);


-- ═══ 037-email-attachments-postgres.sql ═══
-- E-posta kuyruğuna ek (PDF vb.) desteği: dosya adı + base64 içerik.
ALTER TABLE email_outbox ADD COLUMN IF NOT EXISTS attachment_name VARCHAR(190);
ALTER TABLE email_outbox ADD COLUMN IF NOT EXISTS attachment_base64 TEXT;


-- ═══ 038-panel-chat-messages-postgres.sql ═══
-- Panel AI sohbet kayıtları (admin/tedarikçi/acente asistanları) — panel bazlı aylık raporlar için.
CREATE TABLE IF NOT EXISTS panel_chat_messages (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  role VARCHAR(16) NOT NULL CHECK(role IN ('admin','supplier','agency')),
  supplier_id BIGINT,
  agency_id BIGINT,
  user_message TEXT NOT NULL,
  ai_reply TEXT NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_panel_chat_role_created ON panel_chat_messages(role,created_at);
CREATE INDEX IF NOT EXISTS idx_panel_chat_supplier ON panel_chat_messages(supplier_id,created_at);
CREATE INDEX IF NOT EXISTS idx_panel_chat_agency ON panel_chat_messages(agency_id,created_at);


-- ═══ 039-scheduled-job-runs-postgres.sql ═══
-- Zamanlayıcı görev çalışma geçmişi — her çalıştırma (tick / manuel / AI) ayrı satır olarak kaydedilir.
CREATE TABLE IF NOT EXISTS scheduled_job_runs (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  job_id BIGINT NOT NULL,
  status VARCHAR(16) NOT NULL,
  output TEXT,
  duration_ms INTEGER,
  triggered_by VARCHAR(24) NOT NULL DEFAULT 'tick',
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_job_runs_job_created ON scheduled_job_runs(job_id,created_at);
CREATE INDEX IF NOT EXISTS idx_job_runs_status_created ON scheduled_job_runs(status,created_at);


-- ═══ 040-job-fail-alert-postgres.sql ═══
-- Görev hata uyarısı: aynı ardışık hata serisi için yalnızca bir kez e-posta gönderilir.
ALTER TABLE scheduled_jobs ADD COLUMN IF NOT EXISTS last_fail_alert_at TIMESTAMPTZ;


-- ═══ 041-villa-yacht-catalog-postgres.sql ═══
-- 041: Villa ve yat ürün şablonlarını genişletilmiş alanlar + birim kurulumuyla güncelle.
-- default_product_types() ile aynı içerik; mevcut DB kataloğuna uygulanır.

UPDATE product_type_catalog
SET fields = '[
  {"key":"bedrooms","label":"Yatak odası","type":"number","min":0,"max":50,"placeholder":"Örn. 4"},
  {"key":"max_guests","label":"Maksimum misafir","type":"number","min":1,"max":100,"required":true,"placeholder":"Örn. 8"},
  {"key":"pool","label":"Havuz tipi","type":"select","options":["Özel havuz","Ortak havuz","Havuz yok"]},
  {"key":"area_m2","label":"Alan (m²)","type":"number","min":0,"max":100000,"placeholder":"Örn. 180"},
  {"key":"floors","label":"Kat sayısı","type":"number","min":0,"max":50,"placeholder":"Örn. 2"},
  {"key":"building_type","label":"Yapı tipi","type":"select","options":["Müstakil","Yarı müstakil","Dubleks","Rezidans"]}
]'::jsonb,
    steps = '["Villa bilgisi","Birim & kapasite","Müsaitlik takvimi","Fiyat & kurallar"]'::jsonb,
    room_setup = true,
    hint = 'Kapasite, giriş-çıkış günleri ve villa takvimi ile satışa açılır.',
    updated_at = now()
WHERE code = 'villa';

UPDATE product_type_catalog
SET fields = '[
  {"key":"cabins","label":"Kabin sayısı","type":"number","min":0,"max":50,"placeholder":"Örn. 4"},
  {"key":"guest_capacity","label":"Misafir kapasitesi","type":"number","min":1,"max":200,"required":true,"placeholder":"Örn. 8"},
  {"key":"length","label":"Yat uzunluğu (m)","type":"number","min":0,"max":500,"step":0.1,"required":true,"placeholder":"Örn. 22"},
  {"key":"home_port","label":"Bağlama limanı","type":"text","placeholder":"Örn. Göcek Marina"},
  {"key":"crew","label":"Mürettebat","type":"text","placeholder":"Örn. Kaptan + 2 personel"},
  {"key":"year_built","label":"Yapım yılı","type":"number","min":1900,"max":2100,"placeholder":"Örn. 2021"}
]'::jsonb,
    steps = '["Yat bilgisi","Kabin & kapasite","Rota & müsaitlik","Kiralama kuralları"]'::jsonb,
    room_setup = true,
    hint = 'Kabin yapısı, rota, liman ve kiralama periyotları ile devam eder.',
    updated_at = now()
WHERE code = 'yacht';


-- ═══ 042-property-feature-catalog-postgres.sql ═══
-- 042: Villa/yat özellik kataloğu — admin panelinden yönetilebilir listeler.
-- villa-detay sayfasındaki özellik listeleri bu tablodan okunur.

CREATE TABLE IF NOT EXISTS property_feature_catalog (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  code VARCHAR(20) NOT NULL CHECK (code IN ('villa','yacht')),
  label VARCHAR(120) NOT NULL,
  sort_order SMALLINT NOT NULL DEFAULT 100,
  is_active BOOLEAN NOT NULL DEFAULT true,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (code, label)
);

CREATE INDEX IF NOT EXISTS idx_feature_catalog_code ON property_feature_catalog(code, is_active, sort_order);

-- Varsayılan listeler (idempotent — tekrar çalıştırmada çakışma yok).
INSERT INTO property_feature_catalog (code, label, sort_order) VALUES
  ('villa','Özel havuz',10),
  ('villa','Jakuzi',20),
  ('villa','Klima',30),
  ('villa','Wi-Fi',40),
  ('villa','Televizyon',50),
  ('villa','Mutfak',60),
  ('villa','Bulaşık makinesi',70),
  ('villa','Çamaşır makinesi',80),
  ('villa','Bahçe',90),
  ('villa','Teras',100),
  ('villa','Mangal',110),
  ('villa','Otopark',120),
  ('villa','Güvenlik',130),
  ('villa','Özel giriş',140),
  ('villa','Deniz manzarası',150),
  ('villa','Ebeveyn banyosu',160),
  ('villa','Şömine',170),
  ('villa','Isıtmalı havuz',180),
  ('yacht','Güverte',10),
  ('yacht','Şezlong',20),
  ('yacht','Kabin TV',30),
  ('yacht','Klima',40),
  ('yacht','Müzik sistemi',50),
  ('yacht','Su sporları ekipmanı',60),
  ('yacht','Balıkçılık ekipmanı',70),
  ('yacht','Şnorkel',80),
  ('yacht','Dalış ekipmanı',90),
  ('yacht','Mutfak',100),
  ('yacht','Buzdolabı',110),
  ('yacht','Barbekü',120),
  ('yacht','Mürettebat',130),
  ('yacht','Yüzme merdiveni',140),
  ('yacht','Güneşlenme alanı',150),
  ('yacht','Wi-Fi',160)
ON CONFLICT (code, label) DO NOTHING;


-- ═══ 043-hotel-feature-catalog-postgres.sql ═══
-- 043: Otel olanak/aktivite/etkinlik gruplarını property_feature_catalog'a taşı.
-- Katalog artık villa/yat yanında otel hizmetlerini de yönetir (code: amenity/activity/event,
-- grup bilgisi group_label sütununda).

ALTER TABLE property_feature_catalog DROP CONSTRAINT IF EXISTS property_feature_catalog_code_check;
ALTER TABLE property_feature_catalog ADD CONSTRAINT property_feature_catalog_code_check CHECK (code IN ('villa','yacht','amenity','activity','event'));
ALTER TABLE property_feature_catalog ADD COLUMN IF NOT EXISTS group_label VARCHAR(120) NOT NULL DEFAULT '';

-- Otel olanakları (amenity)
INSERT INTO property_feature_catalog (code, group_label, label, sort_order) VALUES
  ('amenity','Genel hizmetler','Wi-Fi',10),
  ('amenity','Genel hizmetler','Otopark',20),
  ('amenity','Genel hizmetler','Vale',30),
  ('amenity','Genel hizmetler','Resepsiyon 24 saat',40),
  ('amenity','Genel hizmetler','Oda servisi',50),
  ('amenity','Genel hizmetler','Çamaşırhane',60),
  ('amenity','Genel hizmetler','Kuru temizleme',70),
  ('amenity','Genel hizmetler','Elektrikli araç şarjı',80),
  ('amenity','Genel hizmetler','Transfer hizmeti',90),
  ('amenity','Genel hizmetler','Araç kiralama',100),
  ('amenity','Yeme & içme','Ana restoran',10),
  ('amenity','Yeme & içme','A la carte restoran',20),
  ('amenity','Yeme & içme','Bar',30),
  ('amenity','Yeme & içme','Snack bar',40),
  ('amenity','Yeme & içme','Çocuk büfesi',50),
  ('amenity','Yeme & içme','Vegan menü',60),
  ('amenity','Yeme & içme','Glutensiz menü',70),
  ('amenity','Yeme & içme','Odaya kahvaltı',80),
  ('amenity','Havuz & plaj','Özel plaj',10),
  ('amenity','Havuz & plaj','Mavi bayraklı plaj',20),
  ('amenity','Havuz & plaj','İskele',30),
  ('amenity','Havuz & plaj','Açık havuz',40),
  ('amenity','Havuz & plaj','Kapalı havuz',50),
  ('amenity','Havuz & plaj','Isıtmalı havuz',60),
  ('amenity','Havuz & plaj','Çocuk havuzu',70),
  ('amenity','Havuz & plaj','Aquapark',80),
  ('amenity','Havuz & plaj','Şezlong ve şemsiye',90),
  ('amenity','Spa & spor','SPA merkezi',10),
  ('amenity','Spa & spor','Fitness',20),
  ('amenity','Spa & spor','Türk hamamı',30),
  ('amenity','Spa & spor','Sauna',40),
  ('amenity','Spa & spor','Buhar odası',50),
  ('amenity','Spa & spor','Masaj',60),
  ('amenity','Spa & spor','Jakuzi',70),
  ('amenity','Spa & spor','Yoga',80),
  ('amenity','Spa & spor','Tenis',90),
  ('amenity','Spa & spor','Su sporları',100),
  ('amenity','Çocuk & aile','Mini kulüp',10),
  ('amenity','Çocuk & aile','Çocuk animasyonu',20),
  ('amenity','Çocuk & aile','Çocuk oyun alanı',30),
  ('amenity','Çocuk & aile','Bebek yatağı',40),
  ('amenity','Çocuk & aile','Bebek bakım hizmeti',50),
  ('amenity','Çocuk & aile','Çocuk menüsü',60),
  ('amenity','İş & erişilebilirlik','Toplantı salonu',10),
  ('amenity','İş & erişilebilirlik','Konferans salonu',20),
  ('amenity','İş & erişilebilirlik','Engelli erişimi',30),
  ('amenity','İş & erişilebilirlik','Engelli odası',40),
  ('amenity','İş & erişilebilirlik','Asansör',50),
  ('amenity','İş & erişilebilirlik','Yetişkin oteli',60)
ON CONFLICT (code, label) DO NOTHING;

-- Otel aktiviteleri (activity)
INSERT INTO property_feature_catalog (code, group_label, label, sort_order) VALUES
  ('activity','Spor & su aktiviteleri','Fitness dersi',10),
  ('activity','Spor & su aktiviteleri','Yoga / pilates',20),
  ('activity','Spor & su aktiviteleri','Tenis',30),
  ('activity','Spor & su aktiviteleri','Plaj voleybolu',40),
  ('activity','Spor & su aktiviteleri','Basketbol',50),
  ('activity','Spor & su aktiviteleri','Mini futbol',60),
  ('activity','Spor & su aktiviteleri','Okçuluk',70),
  ('activity','Spor & su aktiviteleri','Dalış',80),
  ('activity','Spor & su aktiviteleri','Şnorkel',90),
  ('activity','Spor & su aktiviteleri','Kano',100),
  ('activity','Spor & su aktiviteleri','Paddle board',110),
  ('activity','Spor & su aktiviteleri','Jet ski',120),
  ('activity','Spor & su aktiviteleri','Parasailing',130),
  ('activity','Spor & su aktiviteleri','Banana',140),
  ('activity','Spor & su aktiviteleri','Su kayağı',150),
  ('activity','Çocuk & aile aktiviteleri','Mini kulüp aktivitesi',10),
  ('activity','Çocuk & aile aktiviteleri','Çocuk disko',20),
  ('activity','Çocuk & aile aktiviteleri','Çocuk atölyesi',30),
  ('activity','Çocuk & aile aktiviteleri','Bebek bakım hizmeti',40),
  ('activity','Çocuk & aile aktiviteleri','Oyun salonu',50),
  ('activity','Çocuk & aile aktiviteleri','Lunapark',60),
  ('activity','Wellness & deneyim','Türk hamamı ritüeli',10),
  ('activity','Wellness & deneyim','Masaj terapisi',20),
  ('activity','Wellness & deneyim','Cilt bakımı',30),
  ('activity','Wellness & deneyim','Kişisel antrenör',40),
  ('activity','Wellness & deneyim','Yemek atölyesi',50),
  ('activity','Wellness & deneyim','Şarap tadımı',60),
  ('activity','Wellness & deneyim','Çevre gezisi',70)
ON CONFLICT (code, label) DO NOTHING;

-- Otel etkinlikleri (event)
INSERT INTO property_feature_catalog (code, group_label, label, sort_order) VALUES
  ('event','Gündüz & akşam programı','Canlı müzik',10),
  ('event','Gündüz & akşam programı','DJ performansı',20),
  ('event','Gündüz & akşam programı','Gece şovu',30),
  ('event','Gündüz & akşam programı','Sahne gösterisi',40),
  ('event','Gündüz & akşam programı','Tema gecesi',50),
  ('event','Gündüz & akşam programı','Karaoke',60),
  ('event','Gündüz & akşam programı','Sinema gösterimi',70),
  ('event','Sezonluk & özel etkinlik','Çocuk festivali',10),
  ('event','Sezonluk & özel etkinlik','Bayram programı',20),
  ('event','Sezonluk & özel etkinlik','Yılbaşı galası',30),
  ('event','Sezonluk & özel etkinlik','Konser',40),
  ('event','Sezonluk & özel etkinlik','Festival',50),
  ('event','Sezonluk & özel etkinlik','Düğün / davet',60),
  ('event','Sezonluk & özel etkinlik','Kurumsal etkinlik',70)
ON CONFLICT (code, label) DO NOTHING;


-- ═══ 044-channel-webhook-token-postgres.sql ═══
-- 044: Kanal bağlantılarına webhook erişim token'ı.
-- Dağıtım merkezindeki entegrasyon/webhook URL'si bu token ile çalışır (api/channel-webhook).
-- Not: pgcrypto (gen_random_bytes) gerekmeden, yalnızca PG yerleşik fonksiyonlarıyla
-- (md5/random/clock_timestamp) 64 hex karakter üretilir — satır id'si karışıma katıldığı
-- için tekrarlanan çalıştırmalarda bile benzersizlik garantilidir.

ALTER TABLE channel_connections ADD COLUMN IF NOT EXISTS access_token VARCHAR(64);

UPDATE channel_connections
SET access_token = md5(id::text || ':' || clock_timestamp()::text || ':' || random()::text)
                || md5(random()::text || ':' || clock_timestamp()::text || ':' || id::text || ':' || random()::text)
WHERE access_token IS NULL OR access_token = '';

CREATE UNIQUE INDEX IF NOT EXISTS idx_channel_connections_token ON channel_connections(access_token);


-- ═══ 045-channel-room-mappings-postgres.sql ═══
-- 045: Kanal webhook senkronizasyonu altyapısı.
-- 1) channel_room_mappings: kanal dış oda kodu -> NEXUS room_type eşleştirmesi
--    (webhook'ta fiyat/kontenjan uygulamak için gerekli).
CREATE TABLE IF NOT EXISTS channel_room_mappings (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    channel_connection_id BIGINT NOT NULL REFERENCES channel_connections(id) ON DELETE CASCADE,
    property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,
    room_type_id BIGINT NOT NULL REFERENCES room_types(id) ON DELETE CASCADE,
    external_room_id VARCHAR(120) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE(channel_connection_id, room_type_id),
    UNIQUE(channel_connection_id, external_room_id)
);

-- 2) Webhook alımı: kanalın gönderdiği kaynak/ref bilgileri.
ALTER TABLE channel_sync_logs ADD COLUMN IF NOT EXISTS source VARCHAR(16) NOT NULL DEFAULT 'webhook';
ALTER TABLE channel_sync_logs ADD COLUMN IF NOT EXISTS external_ref VARCHAR(190);


-- ═══ 046-feature-delete-restore-postgres.sql ═══
-- 046: Özellik silme işlemini geri alınabilir yapar.
-- 1) property_feature_catalog'a soft-delete kolonu (deleted_at).
-- 2) feature_delete_backups: silinen özelliğin ve kaldırıldığı ilanların
--    (hangi bölümde olduğu dahil) yedeği — "geri al" akışı buradan beslenir.

ALTER TABLE property_feature_catalog ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMPTZ;

CREATE TABLE IF NOT EXISTS feature_delete_backups (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    feature_id BIGINT NOT NULL,
    code VARCHAR(20) NOT NULL,
    group_label VARCHAR(120) NOT NULL DEFAULT '',
    label VARCHAR(120) NOT NULL,
    sort_order INT NOT NULL DEFAULT 100,
    is_active BOOLEAN NOT NULL DEFAULT true,
    deleted_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_by VARCHAR(190),
    -- [{id, name, sections: ['service_pricing','amenities',...], price: 'free'|'paid'|null}]
    affected_properties JSONB NOT NULL DEFAULT '[]'::jsonb
);

CREATE INDEX IF NOT EXISTS idx_feature_delete_backups_feature ON feature_delete_backups(feature_id);


-- ═══ 047-room-mapping-suggestions-postgres.sql ═══
-- 047: Oda eşleştirme önerileri — tanınmayan dış oda kodu onay bekleyen öneri olarak kaydedilir.
-- Webhook'ta eşleşmeyen external_room_id artık ilk aktif oda tipine yazılmaz;
-- status='suggested' satır olarak bekletilir, dağıtım merkezi bölüm 3'ten onaylanır/reddedilir.

ALTER TABLE channel_room_mappings ADD COLUMN IF NOT EXISTS status VARCHAR(16) NOT NULL DEFAULT 'confirmed';
ALTER TABLE channel_room_mappings ADD COLUMN IF NOT EXISTS suggested_at TIMESTAMPTZ;
ALTER TABLE channel_room_mappings ADD COLUMN IF NOT EXISTS suggestion_count INT NOT NULL DEFAULT 0;

-- Panel sorguları: bağlantı başına bekleyen öneriler hızlı listelenir.
CREATE INDEX IF NOT EXISTS idx_channel_room_mappings_status ON channel_room_mappings(channel_connection_id, status);


-- ═══ 048-fx-audit-postgres.sql ═══
-- 048: Webhook fiyat dönüşümü denetimi — dönüştürülen fiyatın orijinal ve hedef birimi.
-- channel_webhook_apply her başarılı kur dönüşümünü burada biriktirir:
--   [{from: "USD", to: "TRY", rate: 38.5, count: 3, original_total: 555.0,
--     converted_total: 21367.5, first_date: "2026-09-01", last_date: "2026-09-03"}]

ALTER TABLE channel_sync_logs ADD COLUMN IF NOT EXISTS fx_audit JSONB NOT NULL DEFAULT '[]'::jsonb;


-- ═══ 049-room-plan-mapping-postgres.sql ═══
-- 049: Oda eşleştirmesi fiyat planıyla birlikte — aynı satırda oda + plan çifti.
-- Eşleştirmede rate_plan_id belirtilirse webhook o koda ait fiyat/kontenjanı o plana yazar;
-- NULL ise eski davranış korunur (ilk aktif fiyat planı).

ALTER TABLE channel_room_mappings ADD COLUMN IF NOT EXISTS rate_plan_id BIGINT REFERENCES rate_plans(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_channel_room_mappings_plan ON channel_room_mappings(rate_plan_id);


-- ═══ 050-ical-sync-logs-postgres.sql ═══
-- 050: iCal içe aktarma/senkron işlerinin işlem günlüğü — webhook loop tespiti gibi
-- "tekrar eden aynı hata" uyarısı için her deneme satır bazında tutulur.
-- error_hash = md5(error_message); aynı hata içeriği 24 saatte tekrar tekrar düşerse
-- cron/alert-ical-repeat.php tedarikçi + admin'e bildirir.

CREATE TABLE IF NOT EXISTS ical_sync_logs (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ical_connection_id BIGINT NOT NULL REFERENCES ical_connections(id) ON DELETE CASCADE,
    property_id BIGINT REFERENCES properties(id) ON DELETE SET NULL,
    status VARCHAR(16) NOT NULL CHECK(status IN ('success','failed')),
    error_message TEXT,
    error_hash VARCHAR(32),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_ical_sync_logs_conn ON ical_sync_logs(ical_connection_id, created_at DESC);


-- ═══ 051-trash-purge-approval-postgres.sql ═══
-- 051: Çöp kutusu "son şans" onayı — TTL dolan özellikler yönetici onayı olmadan kalıcı silinmez.
-- Temizlik görevi (cron/purge-feature-trash.php) TTL dolan özellikler için tek kullanımlık
-- onay bağlantısı üretir ve admin'e e-posta gönderir; admin/approve-trash-purge.php
-- bağlantıyı onaylayınca (3 gün geçerli) bir sonraki temizlikte kalıcı silme gerçekleşir.

CREATE TABLE IF NOT EXISTS pending_trash_purges (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    feature_id BIGINT NOT NULL REFERENCES property_feature_catalog(id) ON DELETE CASCADE,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMPTZ NOT NULL,
    approved_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_pending_trash_purges_feature ON pending_trash_purges(feature_id);
CREATE INDEX IF NOT EXISTS idx_pending_trash_purges_token ON pending_trash_purges(token);


-- ═══ 052-suggestion-score-postgres.sql ═══
-- 052: Eşleştirme önerisi benzerlik skoru
-- Webhook'ta tanınmayan dış oda kodu için "ilk aktif tip" yerine isim benzerliğine
-- göre en iyi eşleşen oda tipi seçilir; seçim skoru bu kolonda saklanır (0-100 arası).
ALTER TABLE channel_room_mappings ADD COLUMN IF NOT EXISTS suggestion_score SMALLINT;


-- ═══ 053-product-step-targets-postgres.sql ═══
-- 053: Ürün türü kurulum adımlarının hedef bölüm eşlemesi.
-- steps JSON dizisindeki her adımın karşılık geldiği bölüm çapası
-- (örn. sec-01, sec-02) admin panelinden yönetilebilir.
-- Yeni ürün türlerinde adım başına hedef bölüm tanımlanmazsa tesis-ekle
-- eski varsayılan eşlemeyi kullanır (adım 0 -> sec-01, adım 1 -> sec-02).

ALTER TABLE product_type_catalog ADD COLUMN IF NOT EXISTS step_targets JSONB NOT NULL DEFAULT '[]'::jsonb;


-- ═══ 054-rate-plan-mappings-postgres.sql ═══
-- 054: Fiyat planı eşleştirmeleri — kanal dış fiyat planı kodu -> NEXUS rate_plan.
-- Çift modlu: tablo yoksa CREATE, eski şemaysa yedekle+düşür+CREATE, doğru şeması varsa ATLA.
-- İdempotent: her durumda güvenle çalışır.

-- Eski şemalı tabloyu güvenle düşür (channel_connection_id yoksa = eski şema)
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_tables WHERE schemaname='public' AND tablename='channel_rate_plan_mappings') THEN
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='channel_rate_plan_mappings' AND column_name='channel_connection_id') THEN
            -- Eski şema: verileri yedekle, tabloyu düşür
            BEGIN
                CREATE TABLE IF NOT EXISTS channel_rate_plan_mappings_bak_054 AS SELECT * FROM channel_rate_plan_mappings;
            EXCEPTION WHEN others THEN NULL;
            END;
            DROP TABLE IF EXISTS channel_rate_plan_mappings CASCADE;
        ELSIF (SELECT COUNT(*) FROM channel_rate_plan_mappings) = 0 THEN
            -- Boş tablo: güvenle düşür
            DROP TABLE IF EXISTS channel_rate_plan_mappings CASCADE;
        END IF;
    END IF;
END
$$;

-- Tablo oluştur (yeni kurulumlarda veya düşürülen eski tablo yerine)
CREATE TABLE IF NOT EXISTS channel_rate_plan_mappings (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    channel_connection_id BIGINT NOT NULL REFERENCES channel_connections(id) ON DELETE CASCADE,
    property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,
    rate_plan_id BIGINT REFERENCES rate_plans(id) ON DELETE SET NULL,
    external_rate_plan_id VARCHAR(120) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'suggested',
    suggested_at TIMESTAMPTZ,
    suggestion_count INT NOT NULL DEFAULT 0,
    suggestion_score SMALLINT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE(channel_connection_id, external_rate_plan_id)
);

-- Mevcut tabloya eksik kolonları ekle (IF NOT EXISTS ile çakışmasız)
ALTER TABLE channel_rate_plan_mappings ADD COLUMN IF NOT EXISTS channel_connection_id BIGINT NOT NULL REFERENCES channel_connections(id) ON DELETE CASCADE;
ALTER TABLE channel_rate_plan_mappings ADD COLUMN IF NOT EXISTS property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE;
ALTER TABLE channel_rate_plan_mappings ADD COLUMN IF NOT EXISTS rate_plan_id BIGINT REFERENCES rate_plans(id) ON DELETE SET NULL;
ALTER TABLE channel_rate_plan_mappings ADD COLUMN IF NOT EXISTS external_rate_plan_id VARCHAR(120) NOT NULL DEFAULT '';
ALTER TABLE channel_rate_plan_mappings ADD COLUMN IF NOT EXISTS status VARCHAR(16) NOT NULL DEFAULT 'suggested';
ALTER TABLE channel_rate_plan_mappings ADD COLUMN IF NOT EXISTS suggested_at TIMESTAMPTZ;
ALTER TABLE channel_rate_plan_mappings ADD COLUMN IF NOT EXISTS suggestion_count INT NOT NULL DEFAULT 0;
ALTER TABLE channel_rate_plan_mappings ADD COLUMN IF NOT EXISTS suggestion_score SMALLINT;
ALTER TABLE channel_rate_plan_mappings ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT now();

-- UNIQUE kısıtlaması
DO $do$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'channel_rate_plan_mappings_conn_ext') THEN
        ALTER TABLE channel_rate_plan_mappings ADD CONSTRAINT channel_rate_plan_mappings_conn_ext UNIQUE (channel_connection_id, external_rate_plan_id);
    END IF;
END
$do$;

-- İndeks
CREATE INDEX IF NOT EXISTS idx_channel_rate_plan_mappings_status ON channel_rate_plan_mappings(channel_connection_id, status);


-- ═══ 055-fx-audit-daily-postgres.sql ═══
-- 055: Günlük kur denetim geçmişi — her günkü eksik/bayat kur çifti özeti.
-- cron/audit-fx-missing.php her çalıştığında bugünün sonucunu buraya yazar
-- (temiz günler de dahil); admin paneli (kur-yonetimi) zaman çizelgesini buradan okur.

CREATE TABLE IF NOT EXISTS fx_audit_daily (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    audit_date DATE NOT NULL UNIQUE,
    missing_count INT NOT NULL DEFAULT 0,
    stale_count INT NOT NULL DEFAULT 0,
    details JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_fx_audit_daily_date ON fx_audit_daily(audit_date DESC);


-- ═══ 056-channel-webhook-threshold-postgres.sql ═══
-- 056: Kanal başına webhook döngü uyarı eşiği — channel_connections.webhook_loop_threshold.
-- NULL = kontrol merkezindeki channel_webhook_loop_threshold varsayılanı kullanılır (3).
-- cron/alert-channel-webhook-loop.php her bağlantı için önce bu değere bakar,
-- boşsa platform varsayılanına düşer. Tedarikçi dağıtım merkezi bölüm 1'de düzenlenir.

ALTER TABLE channel_connections ADD COLUMN IF NOT EXISTS webhook_loop_threshold INT;


-- ═══ 057-feature-purge-at-postgres.sql ═══
-- Özellik bazında çöp kutusu TTL geçersiz kılma:
-- property_feature_catalog.purge_at dolduğunda o özellik, genel feature_trash_ttl_days
-- ayarından bağımsız olarak belirtilen tarihte kalıcı silinir.
-- NULL = varsayılan davranış (silinme + feature_trash_ttl_days gün).
ALTER TABLE property_feature_catalog ADD COLUMN IF NOT EXISTS purge_at TIMESTAMPTZ;


-- ═══ 058-trash-upcoming-alerts-postgres.sql ═══
-- Çöp kutusu "yaklaşan kalıcı silme" uyarısı için tekilleştirme tablosu:
-- aynı özellik + kalıcı silme tarihi için uyarı yalnızca bir kez gönderilir.
CREATE TABLE IF NOT EXISTS trash_upcoming_alerts (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    feature_id BIGINT NOT NULL,
    purge_date DATE NOT NULL,
    sent_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (feature_id, purge_date)
);
CREATE INDEX IF NOT EXISTS idx_trash_upcoming_alerts_feature ON trash_upcoming_alerts(feature_id);


-- ═══ 059-channel-mapping-blacklist-postgres.sql ═══
-- 059: Reddedilen öneri karalistesi — tedarikçi bir eşleştirme önerisini reddedince kod karalisteye
-- alınır; aynı kod webhook'ta tekrar gelirse yeniden öneri oluşturulmaz (veri yazılmaz, işlem
-- günlüğünde yalnızca 'blacklisted_room/blacklisted_plan' notu düşer). Kodu elle eşleştirmek
-- (dağıtım merkezi bölüm 3'te kaydetmek) karalisteyi otomatik temizler.

CREATE TABLE IF NOT EXISTS channel_mapping_blacklist (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    channel_connection_id BIGINT NOT NULL REFERENCES channel_connections(id) ON DELETE CASCADE,
    property_id BIGINT REFERENCES properties(id) ON DELETE CASCADE,
    code_type VARCHAR(4) NOT NULL CHECK(code_type IN ('room','plan')),
    external_code VARCHAR(190) NOT NULL,
    rejected_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    rejected_by VARCHAR(120),
    UNIQUE(channel_connection_id, code_type, external_code)
);

CREATE INDEX IF NOT EXISTS idx_channel_mapping_blacklist_conn ON channel_mapping_blacklist(channel_connection_id, code_type);


-- ═══ 060-supplier-settings-postgres.sql ═══
-- Tedarikçi panel tercihleri: suppliers.settings (JSONB)
-- Per-tedarikçi yapılandırılabilir ayarlar (kod değiştirmeden panelden yönetilir).
-- İlk kullanım: seen_codes_window — "son N işlemde görülen kodlar" listelerinin
-- pencere genişliği (dağıtım merkezi bölüm 1/3 ve işlem günlüğü; varsayılan 30, 5-500).
ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS settings JSONB NOT NULL DEFAULT '{}'::jsonb;


-- ═══ 061-room-mapping-audit-trail-postgres.sql ═══
-- 061: Oda eşleştirme denetim izi
-- Confirmed eşleştirmelerin hangi admin/tedarikçi tarafından ve ne zaman
-- onaylandığını kaydeder. Eski satırlar bilinmediği için NULL bırakılır;
-- bundan sonraki tüm onay noktaları (dağıtım merkezi onayla/kaydet/toplu,
-- health-check otomatik onayı) bu kolonları doldurur.
ALTER TABLE channel_room_mappings ADD COLUMN IF NOT EXISTS approved_by_type VARCHAR(16);
ALTER TABLE channel_room_mappings ADD COLUMN IF NOT EXISTS approved_by_name VARCHAR(190);
ALTER TABLE channel_room_mappings ADD COLUMN IF NOT EXISTS approved_by_user_id BIGINT;
ALTER TABLE channel_room_mappings ADD COLUMN IF NOT EXISTS approved_at TIMESTAMPTZ;


-- ═══ 062-previous-purge-at-postgres.sql ═══
-- 062: previous_purge_at — restore edilen özelliklerin eski purge_at değerini hatırlar.
-- Bir özellik geri yüklendiğinde eski purge_at bu kolona kaydedilir; yeniden silinirken
-- silme onay ekranında varsayılan tarih olarak önerilir.
ALTER TABLE property_feature_catalog ADD COLUMN IF NOT EXISTS previous_purge_at TIMESTAMPTZ;


-- ═══ 063-failure-category-postgres.sql ═══
-- Migration 063: channel_sync_logs'a akıllı hata sınıflandırma kolonu ekle.
-- 'expected' = eşleşme yok/suggestion_pending (tekrar denemek faydasız)
-- 'transient' = geçici ağ/kilitleme hatası (retry faydalı olabilir)
-- 'permanent' = kalıcı yapılandırma hatası (eksik ürün/plan/tablo)
-- NULL = başarı (failure_category yalnızca failed satırlarda doldurulur)

ALTER TABLE channel_sync_logs ADD COLUMN IF NOT EXISTS failure_category VARCHAR(20) DEFAULT NULL;

-- Mevcut failed satırlarını sınıflandır: error_message içeriğine göre geriye dönük etiketle.
UPDATE channel_sync_logs SET failure_category = CASE
    WHEN error_message ILIKE '%property_not_mapped%' THEN 'permanent'
    WHEN error_message ILIKE '%unsupported_scope%' THEN 'permanent'
    WHEN error_message ILIKE '%no_rooms%' THEN 'permanent'
    WHEN error_message ILIKE '%no_rate_plan%' THEN 'permanent'
    WHEN error_message ILIKE '%invalid_date%' THEN 'permanent'
    WHEN error_message ILIKE '%invalid_schema%' THEN 'permanent'
    WHEN error_message ILIKE '%malformed_payload%' THEN 'permanent'
    WHEN error_message ILIKE '%blacklisted_room%' THEN 'expected'
    WHEN error_message ILIKE '%blacklisted_plan%' THEN 'expected'
    WHEN error_message ILIKE '%fx_rate_missing%' THEN 'transient'
    WHEN error_message IS NOT NULL AND error_message <> '' THEN 'transient'
    ELSE NULL
END
WHERE status = 'failed' AND (failure_category IS NULL OR failure_category = '');

-- İndeks: hızlandırma — haven't check/filtrlere göre.
CREATE INDEX IF NOT EXISTS idx_sync_logs_failure_cat ON channel_sync_logs(failure_category) WHERE failure_category IS NOT NULL;


-- ═══ 064-user-language-pref-postgres.sql ═══
-- 064: Kullanıcı dil tercihi — tedarikçi/acenteler kendi arayüz dillerini seçebilsin
-- Admin genel ayarını (tooltip_language) ezer; boşsa genel ayar kullanılır.

ALTER TABLE supplier_users
  ADD COLUMN IF NOT EXISTS language VARCHAR(5) DEFAULT NULL;

COMMENT ON COLUMN supplier_users.language IS 'Kullanıcının seçtiği arayüz dili (tr, en, de, ru, ar, fr). NULL ise platform_setting(tooltip_language) kullanılır.';

