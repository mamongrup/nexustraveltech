-- 047: Oda eşleştirme önerileri — tanınmayan dış oda kodu onay bekleyen öneri olarak kaydedilir.
-- Webhook'ta eşleşmeyen external_room_id artık ilk aktif oda tipine yazılmaz;
-- status='suggested' satır olarak bekletilir, dağıtım merkezi bölüm 3'ten onaylanır/reddedilir.

ALTER TABLE channel_room_mappings ADD COLUMN IF NOT EXISTS status VARCHAR(16) NOT NULL DEFAULT 'confirmed';
ALTER TABLE channel_room_mappings ADD COLUMN IF NOT EXISTS suggested_at TIMESTAMPTZ;
ALTER TABLE channel_room_mappings ADD COLUMN IF NOT EXISTS suggestion_count INT NOT NULL DEFAULT 0;

-- Panel sorguları: bağlantı başına bekleyen öneriler hızlı listelenir.
CREATE INDEX IF NOT EXISTS idx_channel_room_mappings_status ON channel_room_mappings(channel_connection_id, status);
