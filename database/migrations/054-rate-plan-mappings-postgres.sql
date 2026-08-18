-- 054: Fiyat planı eşleştirmeleri — kanal dış fiyat planı kodu -> NEXUS rate_plan.
-- Çift modlu: tablo yoksa CREATE, eski şemaysa yedekle+düşür+CREATE, doğru şeması varsa ATLA.
-- İdempotent: her durumda güvenle çalışır.

-- Eski şemalı tabloyu güvenle düşür (channel_connection_id yoksa = eski şema)
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_tables WHERE schemaname='public' AND tablename='channel_rate_plan_mappings') THEN
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='channel_rate_plan_mappings' AND column_name='channel_connection_id') THEN
            -- Eski şema: verileri yedekle, tabloyu düşür
            BEGIN
                CREATE TABLE IF NOT EXISTS channel_rate_plan_mappings_bak_054 AS SELECT * FROM channel_rate_plan_mappings;
            EXCEPTION WHEN others THEN NULL;
            END;
            DROP TABLE IF EXISTS channel_rate_plan_mappings CASCADE;
        ELSIF (SELECT COUNT(*) FROM channel_rate_plan_mappings) = 0 THEN
            -- Boş tablo: güvenle düşür
            DROP TABLE IF EXISTS channel_rate_plan_mappings CASCADE;
        END IF;
    END IF;
END
$$;

-- Tablo oluştur (yeni kurulumlarda veya düşürülen eski tablo yerine)
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

-- Mevcut tabloya eksik kolonları ekle (IF NOT EXISTS ile çakışmasız)
ALTER TABLE channel_rate_plan_mappings ADD COLUMN IF NOT EXISTS channel_connection_id BIGINT NOT NULL REFERENCES channel_connections(id) ON DELETE CASCADE;
ALTER TABLE channel_rate_plan_mappings ADD COLUMN IF NOT EXISTS property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE;
ALTER TABLE channel_rate_plan_mappings ADD COLUMN IF NOT EXISTS rate_plan_id BIGINT REFERENCES rate_plans(id) ON DELETE SET NULL;
ALTER TABLE channel_rate_plan_mappings ADD COLUMN IF NOT EXISTS external_rate_plan_id VARCHAR(120) NOT NULL DEFAULT '';
ALTER TABLE channel_rate_plan_mappings ADD COLUMN IF NOT EXISTS status VARCHAR(16) NOT NULL DEFAULT 'suggested';
ALTER TABLE channel_rate_plan_mappings ADD COLUMN IF NOT EXISTS suggested_at TIMESTAMPTZ;
ALTER TABLE channel_rate_plan_mappings ADD COLUMN IF NOT EXISTS suggestion_count INT NOT NULL DEFAULT 0;
ALTER TABLE channel_rate_plan_mappings ADD COLUMN IF NOT EXISTS suggestion_score SMALLINT;
ALTER TABLE channel_rate_plan_mappings ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT now();

-- UNIQUE kısıtlaması
DO $do$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'channel_rate_plan_mappings_conn_ext') THEN
        ALTER TABLE channel_rate_plan_mappings ADD CONSTRAINT channel_rate_plan_mappings_conn_ext UNIQUE (channel_connection_id, external_rate_plan_id);
    END IF;
END
$do$;

-- İndeks
CREATE INDEX IF NOT EXISTS idx_channel_rate_plan_mappings_status ON channel_rate_plan_mappings(channel_connection_id, status);
