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
