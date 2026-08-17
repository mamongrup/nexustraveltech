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
