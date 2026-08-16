-- E-posta kuyruğuna ek (PDF vb.) desteği: dosya adı + base64 içerik.
ALTER TABLE email_outbox ADD COLUMN IF NOT EXISTS attachment_name VARCHAR(190);
ALTER TABLE email_outbox ADD COLUMN IF NOT EXISTS attachment_base64 TEXT;
