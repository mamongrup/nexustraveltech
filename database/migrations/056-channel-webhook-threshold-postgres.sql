-- 056: Kanal başına webhook döngü uyarı eşiği — channel_connections.webhook_loop_threshold.
-- NULL = kontrol merkezindeki channel_webhook_loop_threshold varsayılanı kullanılır (3).
-- cron/alert-channel-webhook-loop.php her bağlantı için önce bu değere bakar,
-- boşsa platform varsayılanına düşer. Tedarikçi dağıtım merkezi bölüm 1'de düzenlenir.

ALTER TABLE channel_connections ADD COLUMN IF NOT EXISTS webhook_loop_threshold INT;
