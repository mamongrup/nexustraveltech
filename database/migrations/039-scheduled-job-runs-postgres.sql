-- Zamanlayıcı görev çalışma geçmişi — her çalıştırma (tick / manuel / AI) ayrı satır olarak kaydedilir.
CREATE TABLE IF NOT EXISTS scheduled_job_runs (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  job_id BIGINT NOT NULL,
  status VARCHAR(16) NOT NULL,
  output TEXT,
  duration_ms INTEGER,
  triggered_by VARCHAR(24) NOT NULL DEFAULT 'tick',
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_job_runs_job_created ON scheduled_job_runs(job_id,created_at);
CREATE INDEX IF NOT EXISTS idx_job_runs_status_created ON scheduled_job_runs(status,created_at);
