-- Tedarikçi panel tercihleri: suppliers.settings (JSONB)
-- Per-tedarikçi yapılandırılabilir ayarlar (kod değiştirmeden panelden yönetilir).
-- İlk kullanım: seen_codes_window — "son N işlemde görülen kodlar" listelerinin
-- pencere genişliği (dağıtım merkezi bölüm 1/3 ve işlem günlüğü; varsayılan 30, 5-500).
ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS settings JSONB NOT NULL DEFAULT '{}'::jsonb;
