-- Encrypted AI provider settings. The application encryption key stays in config/secrets.php.
CREATE TABLE IF NOT EXISTS ai_provider_settings (
  provider VARCHAR(32) PRIMARY KEY,
  encrypted_api_key TEXT,
  model VARCHAR(80) NOT NULL DEFAULT 'deepseek-chat',
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
