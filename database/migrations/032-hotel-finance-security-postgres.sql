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
