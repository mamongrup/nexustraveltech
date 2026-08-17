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
