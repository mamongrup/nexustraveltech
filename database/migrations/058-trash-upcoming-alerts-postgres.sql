-- Çöp kutusu "yaklaşan kalıcı silme" uyarısı için tekilleştirme tablosu:
-- aynı özellik + kalıcı silme tarihi için uyarı yalnızca bir kez gönderilir.
CREATE TABLE IF NOT EXISTS trash_upcoming_alerts (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    feature_id BIGINT NOT NULL,
    purge_date DATE NOT NULL,
    sent_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (feature_id, purge_date)
);
CREATE INDEX IF NOT EXISTS idx_trash_upcoming_alerts_feature ON trash_upcoming_alerts(feature_id);
