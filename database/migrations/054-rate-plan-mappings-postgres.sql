-- 054: Fiyat planı eşleştirmeleri — kanal dış fiyat planı kodu -> NEXUS rate_plan.
-- Webhook'ta tanınmayan external_rate_plan_id, oda eşleştirmesiyle aynı onay akışına
-- tabidir: status='suggested' satır olarak bekletilir, dağıtım merkezi bölüm 3'ten
-- onaylanır/reddedilir; onaylanana kadar o koda ait fiyat/kontenjan yazılmaz.

CREATE TABLE IF NOT EXISTS channel_rate_plan_mappings (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    channel_connection_id BIGINT NOT NULL REFERENCES channel_connections(id) ON DELETE CASCADE,
    property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,
    rate_plan_id BIGINT REFERENCES rate_plans(id) ON DELETE SET NULL,
    external_rate_plan_id VARCHAR(120) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'suggested',
    suggested_at TIMESTAMPTZ,
    suggestion_count INT NOT NULL DEFAULT 0,
    suggestion_score SMALLINT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE(channel_connection_id, external_rate_plan_id)
);

-- Panel sorguları: bağlantı başına bekleyen öneriler hızlı listelenir.
CREATE INDEX IF NOT EXISTS idx_channel_rate_plan_mappings_status ON channel_rate_plan_mappings(channel_connection_id, status);
