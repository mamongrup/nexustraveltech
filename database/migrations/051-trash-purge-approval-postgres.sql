-- 051: Çöp kutusu "son şans" onayı — TTL dolan özellikler yönetici onayı olmadan kalıcı silinmez.
-- Temizlik görevi (cron/purge-feature-trash.php) TTL dolan özellikler için tek kullanımlık
-- onay bağlantısı üretir ve admin'e e-posta gönderir; admin/approve-trash-purge.php
-- bağlantıyı onaylayınca (3 gün geçerli) bir sonraki temizlikte kalıcı silme gerçekleşir.

CREATE TABLE IF NOT EXISTS pending_trash_purges (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    feature_id BIGINT NOT NULL REFERENCES property_feature_catalog(id) ON DELETE CASCADE,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMPTZ NOT NULL,
    approved_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_pending_trash_purges_feature ON pending_trash_purges(feature_id);
CREATE INDEX IF NOT EXISTS idx_pending_trash_purges_token ON pending_trash_purges(token);
