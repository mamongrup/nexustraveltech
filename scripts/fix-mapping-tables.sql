-- ═══════════════════════════════════════════════════════════════════════════════
-- NEXUS: channel_room_mappings + channel_rate_plan_mappings tek seferlik onarım
--
-- Bu betik, eski şemalı tabloları doğru kolon yapısına getirir.
-- Verileri korur: eksik kolonları ekler, fazla kolonları dokunmaz.
--
-- Kullanım (postgres olarak):
--   sudo -u postgres psql -d nexus_traveltech -f scripts/fix-mapping-tables.sql
--
-- veya tek satır:
--   sudo -u postgres psql -d nexus_traveltech < scripts/fix-mapping-tables.sql
-- ═══════════════════════════════════════════════════════════════════════════════

-- ─── channel_room_mappings ───
-- Beklenen: 15 kolon (id dahil)
-- Eksik olabilecekler: rate_plan_id, status, suggested_at, suggestion_count,
--   suggestion_score, approved_by_type, approved_by_name, approved_by_user_id, approved_at

DO $$
BEGIN
    -- Tablo yoksa oluştur
    IF NOT EXISTS (SELECT 1 FROM pg_tables WHERE schemaname='public' AND tablename='channel_room_mappings') THEN
        CREATE TABLE channel_room_mappings (
            id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            channel_connection_id BIGINT NOT NULL REFERENCES channel_connections(id) ON DELETE CASCADE,
            property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,
            room_type_id BIGINT NOT NULL REFERENCES room_types(id) ON DELETE CASCADE,
            external_room_id VARCHAR(120) NOT NULL,
            created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
            UNIQUE(channel_connection_id, room_type_id),
            UNIQUE(channel_connection_id, external_room_id)
        );
        RAISE NOTICE 'channel_room_mappings: yeni tablo oluşturuldu';
    END IF;

    -- Eksik kolonları ekle (IF NOT EXISTS ile güvenli)
    ALTER TABLE channel_room_mappings ADD COLUMN IF NOT EXISTS rate_plan_id BIGINT REFERENCES rate_plans(id) ON DELETE SET NULL;
    ALTER TABLE channel_room_mappings ADD COLUMN IF NOT EXISTS status VARCHAR(16) NOT NULL DEFAULT 'confirmed';
    ALTER TABLE channel_room_mappings ADD COLUMN IF NOT EXISTS suggested_at TIMESTAMPTZ;
    ALTER TABLE channel_room_mappings ADD COLUMN IF NOT EXISTS suggestion_count INT NOT NULL DEFAULT 0;
    ALTER TABLE channel_room_mappings ADD COLUMN IF NOT EXISTS suggestion_score SMALLINT;
    ALTER TABLE channel_room_mappings ADD COLUMN IF NOT EXISTS approved_by_type VARCHAR(16);
    ALTER TABLE channel_room_mappings ADD COLUMN IF NOT EXISTS approved_by_name VARCHAR(190);
    ALTER TABLE channel_room_mappings ADD COLUMN IF NOT EXISTS approved_by_user_id BIGINT;
    ALTER TABLE channel_room_mappings ADD COLUMN IF NOT EXISTS approved_at TIMESTAMPTZ;
END
$$;

-- İndeksler (çakışmasız)
CREATE INDEX IF NOT EXISTS idx_channel_room_mappings_status ON channel_room_mappings(channel_connection_id, status);
CREATE INDEX IF NOT EXISTS idx_channel_room_mappings_plan ON channel_room_mappings(rate_plan_id);

-- ─── channel_rate_plan_mappings ───
-- Beklenen: 10 kolon (id dahil)

DO $$
BEGIN
    -- Tablo yoksa oluştur
    IF NOT EXISTS (SELECT 1 FROM pg_tables WHERE schemaname='public' AND tablename='channel_rate_plan_mappings') THEN
        CREATE TABLE channel_rate_plan_mappings (
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
        RAISE NOTICE 'channel_rate_plan_mappings: yeni tablo oluşturuldu';
    END IF;

    -- Eksik kolonları ekle
    ALTER TABLE channel_rate_plan_mappings ADD COLUMN IF NOT EXISTS channel_connection_id BIGINT NOT NULL REFERENCES channel_connections(id) ON DELETE CASCADE;
    ALTER TABLE channel_rate_plan_mappings ADD COLUMN IF NOT EXISTS property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE;
    ALTER TABLE channel_rate_plan_mappings ADD COLUMN IF NOT EXISTS rate_plan_id BIGINT REFERENCES rate_plans(id) ON DELETE SET NULL;
    ALTER TABLE channel_rate_plan_mappings ADD COLUMN IF NOT EXISTS external_rate_plan_id VARCHAR(120) NOT NULL DEFAULT '';
    ALTER TABLE channel_rate_plan_mappings ADD COLUMN IF NOT EXISTS status VARCHAR(16) NOT NULL DEFAULT 'suggested';
    ALTER TABLE channel_rate_plan_mappings ADD COLUMN IF NOT EXISTS suggested_at TIMESTAMPTZ;
    ALTER TABLE channel_rate_plan_mappings ADD COLUMN IF NOT EXISTS suggestion_count INT NOT NULL DEFAULT 0;
    ALTER TABLE channel_rate_plan_mappings ADD COLUMN IF NOT EXISTS suggestion_score SMALLINT;
    ALTER TABLE channel_rate_plan_mappings ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT now();
END
$$;

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

-- ═══════════════════════════════════════════════════════════════════════════════
-- DOĞRULAMA: Beklenen kolon sayısını kontrol et
-- ═══════════════════════════════════════════════════════════════════════════════

SELECT '=== DOĞRULAMA ===' AS bolum;

SELECT 'channel_room_mappings' AS tablo,
       COUNT(*) AS kolon_sayisi,
       CASE WHEN COUNT(*) >= 15 THEN '✓' ELSE '✗ EKSİK' END AS durum
FROM information_schema.columns
WHERE table_schema='public' AND table_name='channel_room_mappings';

SELECT 'channel_rate_plan_mappings' AS tablo,
       COUNT(*) AS kolon_sayisi,
       CASE WHEN COUNT(*) >= 10 THEN '✓' ELSE '✗ EKSİK' END AS durum
FROM information_schema.columns
WHERE table_schema='public' AND table_name='channel_rate_plan_mappings';

-- Eksik kolon listesi (varsa)
SELECT 'Eksik olaBILIR kolonlar:' AS bilgi;

SELECT 'channel_room_mappings' AS tablo, column_name AS beklenen_kolon
FROM (VALUES ('rate_plan_id'),('status'),('suggested_at'),('suggestion_count'),('suggestion_score'),('approved_by_type'),('approved_by_name'),('approved_by_user_id'),('approved_at')) AS expected(col)
WHERE NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema='public' AND table_name='channel_room_mappings' AND column_name=expected.col
)
UNION ALL
SELECT 'channel_rate_plan_mappings' AS tablo, column_name AS beklenen_kolon
FROM (VALUES ('channel_connection_id'),('property_id'),('rate_plan_id'),('external_rate_plan_id'),('status'),('suggested_at'),('suggestion_count'),('suggestion_score'),('created_at')) AS expected(col)
WHERE NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema='public' AND table_name='channel_rate_plan_mappings' AND column_name=expected.col
);

-- Veri koruma: mevcut satır sayılarını göster
SELECT '=== VERİ KORUMA ===' AS bolum;
SELECT 'channel_room_mappings' AS tablo, COUNT(*) AS satir_sayisi FROM channel_room_mappings;
SELECT 'channel_rate_plan_mappings' AS tablo, COUNT(*) AS satir_sayisi FROM channel_rate_plan_mappings;
