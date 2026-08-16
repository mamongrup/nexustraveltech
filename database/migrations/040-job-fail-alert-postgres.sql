-- Görev hata uyarısı: aynı ardışık hata serisi için yalnızca bir kez e-posta gönderilir.
ALTER TABLE scheduled_jobs ADD COLUMN IF NOT EXISTS last_fail_alert_at TIMESTAMPTZ;
