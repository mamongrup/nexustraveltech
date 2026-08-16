-- 027: E-posta kuyruğu (misafir bildirimleri, admin uyarıları)
CREATE TABLE IF NOT EXISTS email_outbox (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  to_address VARCHAR(190) NOT NULL,
  subject VARCHAR(190) NOT NULL,
  body_html TEXT NOT NULL,
  related_type VARCHAR(40),
  related_id BIGINT,
  status VARCHAR(16) NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','sent','failed','skipped')),
  error_message TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  sent_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_email_outbox_status ON email_outbox(status,created_at);
