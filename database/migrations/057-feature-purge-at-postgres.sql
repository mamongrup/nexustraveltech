-- Özellik bazında çöp kutusu TTL geçersiz kılma:
-- property_feature_catalog.purge_at dolduğunda o özellik, genel feature_trash_ttl_days
-- ayarından bağımsız olarak belirtilen tarihte kalıcı silinir.
-- NULL = varsayılan davranış (silinme + feature_trash_ttl_days gün).
ALTER TABLE property_feature_catalog ADD COLUMN IF NOT EXISTS purge_at TIMESTAMPTZ;
