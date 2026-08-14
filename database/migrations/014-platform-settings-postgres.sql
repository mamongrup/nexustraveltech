CREATE TABLE IF NOT EXISTS platform_settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  value JSONB NOT NULL,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
INSERT INTO platform_settings(setting_key,value) VALUES
  ('gemini_visual_similarity_threshold','90'::jsonb),
  ('gemini_auto_pause_duplicate','true'::jsonb),
  ('kps_identity_verification_enabled','false'::jsonb),
  ('admin_alert_email','""'::jsonb)
ON CONFLICT(setting_key) DO NOTHING;
