-- 049: Oda eşleştirmesi fiyat planıyla birlikte — aynı satırda oda + plan çifti.
-- Eşleştirmede rate_plan_id belirtilirse webhook o koda ait fiyat/kontenjanı o plana yazar;
-- NULL ise eski davranış korunur (ilk aktif fiyat planı).

ALTER TABLE channel_room_mappings ADD COLUMN IF NOT EXISTS rate_plan_id BIGINT REFERENCES rate_plans(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_channel_room_mappings_plan ON channel_room_mappings(rate_plan_id);
