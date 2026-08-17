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
