-- 061: Oda eşleştirme denetim izi
-- Confirmed eşleştirmelerin hangi admin/tedarikçi tarafından ve ne zaman
-- onaylandığını kaydeder. Eski satırlar bilinmediği için NULL bırakılır;
-- bundan sonraki tüm onay noktaları (dağıtım merkezi onayla/kaydet/toplu,
-- health-check otomatik onayı) bu kolonları doldurur.
ALTER TABLE channel_room_mappings ADD COLUMN IF NOT EXISTS approved_by_type VARCHAR(16);
ALTER TABLE channel_room_mappings ADD COLUMN IF NOT EXISTS approved_by_name VARCHAR(190);
ALTER TABLE channel_room_mappings ADD COLUMN IF NOT EXISTS approved_by_user_id BIGINT;
ALTER TABLE channel_room_mappings ADD COLUMN IF NOT EXISTS approved_at TIMESTAMPTZ;
