-- 048: Webhook fiyat dönüşümü denetimi — dönüştürülen fiyatın orijinal ve hedef birimi.
-- channel_webhook_apply her başarılı kur dönüşümünü burada biriktirir:
--   [{from: "USD", to: "TRY", rate: 38.5, count: 3, original_total: 555.0,
--     converted_total: 21367.5, first_date: "2026-09-01", last_date: "2026-09-03"}]

ALTER TABLE channel_sync_logs ADD COLUMN IF NOT EXISTS fx_audit JSONB NOT NULL DEFAULT '[]'::jsonb;
