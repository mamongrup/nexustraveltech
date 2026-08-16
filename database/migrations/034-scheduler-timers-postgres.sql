-- 034: Panel yönetimli zamanlayıcılar (scheduled jobs)
-- Sistem cron'ları yerine tek bir "nabız" (cron/tick.php veya token'lı URL görevi)
-- bu tablodaki görevleri tarar ve vadesi gelenleri çalıştırır.

CREATE TABLE IF NOT EXISTS scheduled_jobs (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  code VARCHAR(60) NOT NULL UNIQUE,
  name VARCHAR(190) NOT NULL,
  command VARCHAR(500) NOT NULL,
  schedule VARCHAR(60) NOT NULL,          -- cron ifadesi: dakika saat gün ay hafta
  enabled BOOLEAN NOT NULL DEFAULT true,
  last_run_at TIMESTAMPTZ,
  last_status VARCHAR(16),
  last_output TEXT,
  run_count BIGINT NOT NULL DEFAULT 0,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

INSERT INTO scheduled_jobs(code,name,command,schedule) VALUES
('nexus-sync-ical','iCal senkronizasyonu','cron/sync-ical-calendars.php','*/15 * * * *'),
('nexus-revenue-rec','Gelir önerisi üretimi','cron/generate-revenue-recommendations.php','15 2 * * *'),
('nexus-netgsm-sms','Netgsm SMS işleme','cron/process-netgsm-sms.php','* * * * *'),
('nexus-process-emails','E-posta kuyruğu','cron/process-emails.php','*/5 * * * *'),
('nexus-process-webhooks','Webhook teslimatı','cron/process-webhooks.php','*/1 * * * *'),
('nexus-welcome-emails','Hoş geldiniz e-postaları','cron/send-welcome-emails.php','0 8 * * *'),
('nexus-notification-digest','Bildirim özeti','cron/send-notification-digest.php','15 9 * * *'),
('nexus-expire-group-options','Grup opsiyon süresi','cron/expire-group-options.php','30 3 * * *')
ON CONFLICT (code) DO NOTHING;
