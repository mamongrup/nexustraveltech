-- 044: Kanal bağlantılarına webhook erişim token'ı.
-- Dağıtım merkezindeki entegrasyon/webhook URL'si bu token ile çalışır (api/channel-webhook).

ALTER TABLE channel_connections ADD COLUMN IF NOT EXISTS access_token VARCHAR(64);

UPDATE channel_connections
SET access_token = encode(gen_random_bytes(32), 'hex')
WHERE access_token IS NULL OR access_token = '';

CREATE UNIQUE INDEX IF NOT EXISTS idx_channel_connections_token ON channel_connections(access_token);
