-- 059: Reddedilen öneri karalistesi — tedarikçi bir eşleştirme önerisini reddedince kod karalisteye
-- alınır; aynı kod webhook'ta tekrar gelirse yeniden öneri oluşturulmaz (veri yazılmaz, işlem
-- günlüğünde yalnızca 'blacklisted_room/blacklisted_plan' notu düşer). Kodu elle eşleştirmek
-- (dağıtım merkezi bölüm 3'te kaydetmek) karalisteyi otomatik temizler.

CREATE TABLE IF NOT EXISTS channel_mapping_blacklist (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    channel_connection_id BIGINT NOT NULL REFERENCES channel_connections(id) ON DELETE CASCADE,
    property_id BIGINT REFERENCES properties(id) ON DELETE CASCADE,
    code_type VARCHAR(4) NOT NULL CHECK(code_type IN ('room','plan')),
    external_code VARCHAR(190) NOT NULL,
    rejected_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    rejected_by VARCHAR(120),
    UNIQUE(channel_connection_id, code_type, external_code)
);

CREATE INDEX IF NOT EXISTS idx_channel_mapping_blacklist_conn ON channel_mapping_blacklist(channel_connection_id, code_type);
