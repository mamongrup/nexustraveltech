-- 045: Kanal webhook senkronizasyonu altyapısı.
-- 1) channel_room_mappings: kanal dış oda kodu -> NEXUS room_type eşleştirmesi
--    (webhook'ta fiyat/kontenjan uygulamak için gerekli).
CREATE TABLE IF NOT EXISTS channel_room_mappings (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    channel_connection_id BIGINT NOT NULL REFERENCES channel_connections(id) ON DELETE CASCADE,
    property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,
    room_type_id BIGINT NOT NULL REFERENCES room_types(id) ON DELETE CASCADE,
    external_room_id VARCHAR(120) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE(channel_connection_id, room_type_id),
    UNIQUE(channel_connection_id, external_room_id)
);

-- 2) Webhook alımı: kanalın gönderdiği kaynak/ref bilgileri.
ALTER TABLE channel_sync_logs ADD COLUMN IF NOT EXISTS source VARCHAR(16) NOT NULL DEFAULT 'webhook';
ALTER TABLE channel_sync_logs ADD COLUMN IF NOT EXISTS external_ref VARCHAR(190);
