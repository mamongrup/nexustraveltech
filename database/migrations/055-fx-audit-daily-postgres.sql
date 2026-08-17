-- 055: Günlük kur denetim geçmişi — her günkü eksik/bayat kur çifti özeti.
-- cron/audit-fx-missing.php her çalıştığında bugünün sonucunu buraya yazar
-- (temiz günler de dahil); admin paneli (kur-yonetimi) zaman çizelgesini buradan okur.

CREATE TABLE IF NOT EXISTS fx_audit_daily (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    audit_date DATE NOT NULL UNIQUE,
    missing_count INT NOT NULL DEFAULT 0,
    stale_count INT NOT NULL DEFAULT 0,
    details JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_fx_audit_daily_date ON fx_audit_daily(audit_date DESC);
