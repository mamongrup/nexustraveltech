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
