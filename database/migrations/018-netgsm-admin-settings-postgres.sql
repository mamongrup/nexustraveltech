ALTER TABLE sms_entitlements ADD COLUMN IF NOT EXISTS notification_phone VARCHAR(40);

INSERT INTO platform_settings(setting_key,value) VALUES
('netgsm_sms_enabled','false'::jsonb)
ON CONFLICT (setting_key) DO NOTHING;
