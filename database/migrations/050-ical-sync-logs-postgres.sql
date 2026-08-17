-- 050: iCal içe aktarma/senkron işlerinin işlem günlüğü — webhook loop tespiti gibi
-- "tekrar eden aynı hata" uyarısı için her deneme satır bazında tutulur.
-- error_hash = md5(error_message); aynı hata içeriği 24 saatte tekrar tekrar düşerse
-- cron/alert-ical-repeat.php tedarikçi + admin'e bildirir.

CREATE TABLE IF NOT EXISTS ical_sync_logs (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ical_connection_id BIGINT NOT NULL REFERENCES ical_connections(id) ON DELETE CASCADE,
    property_id BIGINT REFERENCES properties(id) ON DELETE SET NULL,
    status VARCHAR(16) NOT NULL CHECK(status IN ('success','failed')),
    error_message TEXT,
    error_hash VARCHAR(32),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_ical_sync_logs_conn ON ical_sync_logs(ical_connection_id, created_at DESC);
