-- 064: Kullanıcı dil tercihi — tedarikçi/acenteler kendi arayüz dillerini seçebilsin
-- Admin genel ayarını (tooltip_language) ezer; boşsa genel ayar kullanılır.

ALTER TABLE supplier_users
  ADD COLUMN IF NOT EXISTS language VARCHAR(5) DEFAULT NULL;

COMMENT ON COLUMN supplier_users.language IS 'Kullanıcının seçtiği arayüz dili (tr, en, de, ru, ar, fr). NULL ise platform_setting(tooltip_language) kullanılır.';
