-- Migration 063: channel_sync_logs'a akıllı hata sınıflandırma kolonu ekle.
-- 'expected' = eşleşme yok/suggestion_pending (tekrar denemek faydasız)
-- 'transient' = geçici ağ/kilitleme hatası (retry faydalı olabilir)
-- 'permanent' = kalıcı yapılandırma hatası (eksik ürün/plan/tablo)
-- NULL = başarı (failure_category yalnızca failed satırlarda doldurulur)

ALTER TABLE channel_sync_logs ADD COLUMN IF NOT EXISTS failure_category VARCHAR(20) DEFAULT NULL;

-- Mevcut failed satırlarını sınıflandır: error_message içeriğine göre geriye dönük etiketle.
UPDATE channel_sync_logs SET failure_category = CASE
    WHEN error_message ILIKE '%property_not_mapped%' THEN 'permanent'
    WHEN error_message ILIKE '%unsupported_scope%' THEN 'permanent'
    WHEN error_message ILIKE '%no_rooms%' THEN 'permanent'
    WHEN error_message ILIKE '%no_rate_plan%' THEN 'permanent'
    WHEN error_message ILIKE '%invalid_date%' THEN 'permanent'
    WHEN error_message ILIKE '%invalid_schema%' THEN 'permanent'
    WHEN error_message ILIKE '%malformed_payload%' THEN 'permanent'
    WHEN error_message ILIKE '%blacklisted_room%' THEN 'expected'
    WHEN error_message ILIKE '%blacklisted_plan%' THEN 'expected'
    WHEN error_message ILIKE '%fx_rate_missing%' THEN 'transient'
    WHEN error_message IS NOT NULL AND error_message <> '' THEN 'transient'
    ELSE NULL
END
WHERE status = 'failed' AND (failure_category IS NULL OR failure_category = '');

-- İndeks: hızlandırma — haven't check/filtrlere göre.
CREATE INDEX IF NOT EXISTS idx_sync_logs_failure_cat ON channel_sync_logs(failure_category) WHERE failure_category IS NOT NULL;
