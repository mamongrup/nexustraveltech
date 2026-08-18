-- schema-verify.sql — health-check --repair sonrası şema tutarlılık özeti
-- Kullanım:
--   sudo -u postgres psql -d nexus_traveltech -f scripts/schema-verify.sql
--   veya tek satır:
--   sudo -u postgres psql -d nexus_traveltech -c "$(cat scripts/schema-verify.sql)"

\echo ''
\echo '=== NEXUS ŞEMA DOĞRULAMA ÖZETİ ==='
\echo ''

-- 1) Tablo sayısı
\echo '--- TABLOLAR ---'
SELECT
    (SELECT COUNT(*) FROM pg_tables WHERE schemaname='public') AS toplam_tablo,
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE') AS pg_tables_sayisi,
    CASE
        WHEN (SELECT COUNT(*) FROM pg_tables WHERE schemaname='public') >= 48
        THEN '✓ Yeterli'
        ELSE '✗ Eksik (beklenen: 48+)'
    END AS durum;

-- 2) Kritik tabloların varlığı (health-check requiredTables)
\echo ''
\echo '--- KRİTİK TABLOLAR ---'
WITH expected(t) AS (
    VALUES
        ('suppliers'),('supplier_users'),('properties'),('supplier_bookings'),
        ('inventory_calendar'),('channel_connections'),('channel_property_mappings'),
        ('ical_connections'),('ical_events'),('physical_rooms'),('booking_folios'),
        ('folio_transactions'),('payment_records'),('payment_allocations'),
        ('hotel_invoices'),('night_audit_runs'),('hotel_staff'),('hotel_roles'),
        ('loyalty_tiers'),('guest_loyalty_accounts'),('revenue_recommendations'),
        ('guest_service_requests'),('login_throttle'),('guest_reviews'),
        ('email_outbox'),('blocked_ips'),('panel_chat_messages'),
        ('scheduled_jobs'),('scheduled_job_runs'),('property_feature_catalog'),
        ('channel_room_mappings'),('channel_rate_plan_mappings'),
        ('feature_delete_backups'),('channel_sync_logs'),('ical_sync_logs'),
        ('pending_trash_purges'),('fx_audit_daily'),('trash_upcoming_alerts'),
        ('channel_mapping_blacklist'),('webhook_subscriptions'),('webhook_deliveries'),
        ('error_logs'),('admin_audit_logs'),('payment_links'),('fx_rates'),
        ('booking_groups'),('notifications'),('agencies'),('agency_users'),
        ('email_templates'),('admin_2fa'),('public_chat_messages'),('product_type_catalog')
),
existing AS (
    SELECT tablename FROM pg_tables WHERE schemaname='public'
)
SELECT
    (SELECT COUNT(*) FROM expected) AS beklenen,
    (SELECT COUNT(*) FROM expected e JOIN existing x ON x.tablename=e.t) AS mevcut,
    (SELECT COUNT(*) FROM expected e WHERE NOT EXISTS (SELECT 1 FROM existing x WHERE x.tablename=e.t)) AS eksik,
    CASE
        WHEN (SELECT COUNT(*) FROM expected e WHERE NOT EXISTS (SELECT 1 FROM existing x WHERE x.tablename=e.t)) = 0
        THEN '✓ Tüm tablolar mevcut'
        ELSE '✗ Eksik tablolar var'
    END AS durum;

-- 3) Kritik kolonların varlığı (health-check requiredColumns)
\echo ''
\echo '--- KRİTİK KOLONLAR ---'
WITH checks AS (
    SELECT 'channel_room_mappings' AS tbl, 'channel_connection_id' AS col UNION ALL
    SELECT 'channel_room_mappings', 'property_id' UNION ALL
    SELECT 'channel_room_mappings', 'status' UNION ALL
    SELECT 'channel_room_mappings', 'approved_by_type' UNION ALL
    SELECT 'channel_rate_plan_mappings', 'channel_connection_id' UNION ALL
    SELECT 'channel_rate_plan_mappings', 'property_id' UNION ALL
    SELECT 'channel_rate_plan_mappings', 'status' UNION ALL
    SELECT 'channel_rate_plan_mappings', 'rate_plan_id' UNION ALL
    SELECT 'channel_sync_logs', 'fx_audit' UNION ALL
    SELECT 'channel_sync_logs', 'channel_connection_id' UNION ALL
    SELECT 'ical_sync_logs', 'error_hash' UNION ALL
    SELECT 'product_type_catalog', 'step_targets' UNION ALL
    SELECT 'scheduled_job_runs', 'triggered_by' UNION ALL
    SELECT 'fx_audit_daily', 'audit_date' UNION ALL
    SELECT 'property_feature_catalog', 'deleted_at' UNION ALL
    SELECT 'property_feature_catalog', 'purge_at'
),
missing AS (
    SELECT c.tbl, c.col
    FROM checks c
    LEFT JOIN information_schema.columns cc
        ON cc.table_schema='public' AND cc.table_name=c.tbl AND cc.column_name=c.col
    WHERE cc.column_name IS NULL
)
SELECT
    (SELECT COUNT(*) FROM checks) AS beklenen_kolon,
    (SELECT COUNT(*) FROM checks c WHERE NOT EXISTS (SELECT 1 FROM missing m WHERE m.tbl=c.tbl AND m.col=c.col)) AS mevcut,
    (SELECT COUNT(*) FROM missing) AS eksik,
    CASE
        WHEN (SELECT COUNT(*) FROM missing) = 0
        THEN '✓ Tüm kritik kolonlar mevcut'
        ELSE '✗ Eksik kolonlar: ' || (SELECT string_agg(tbl||'.'||col, ', ') FROM missing)
    END AS durum;

-- 4) Migration durumu
\echo ''
\echo '--- MİGRASYONLAR ---'
SELECT
    (SELECT COUNT(*) FROM schema_migrations) AS uygulanan,
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_name='schema_migrations') AS tablo_var,
    CASE
        WHEN (SELECT COUNT(*) FROM schema_migrations) >= 50
        THEN '✓ Yeterli'
        ELSE '⚠ ' || (50 - (SELECT COUNT(*) FROM schema_migrations)) || ' eksik olabilir'
    END AS durum;

-- 5) Son uygulanan migration'lar
\echo ''
\echo '--- SON 5 MİGRASYON ---'
SELECT file, commit_hash, applied_at::date AS tarih
FROM schema_migrations ORDER BY id DESC LIMIT 5;

-- 6) Advisory kilit durumu
\echo ''
\echo '--- ZAMANLAYICI KİLİDİ ---'
SELECT
    CASE
        WHEN EXISTS (SELECT 1 FROM pg_locks WHERE locktype='advisory' AND classid=0 AND objid=424242 AND granted=true)
        THEN '✗ Kilit tutuluyor (PID: ' || (SELECT pid FROM pg_locks WHERE locktype='advisory' AND classid=0 AND objid=424242 AND granted=true LIMIT 1) || ')'
        ELSE '✓ Kilit serbest'
    END AS durum;

-- 7) Tablo sahipliği
\echo ''
\echo '--- TABLO SAHİPLİĞİ ---'
SELECT tableowner, COUNT(*) AS tablo_sayisi
FROM pg_tables WHERE schemaname='public'
GROUP BY tableowner ORDER BY tablo_sayisi DESC;

-- 8) Kanal bağlantı durumu
\echo ''
\echo '--- KANAL BAĞLANTILARI ---'
SELECT
    status,
    COUNT(*) AS sayi
FROM channel_connections
GROUP BY status ORDER BY sayi DESC;

-- 9) Son webhook işlemleri
\echo ''
\echo '--- SON 24 SAAT WEBHOOK ---'
SELECT
    status,
    COUNT(*) AS sayi
FROM channel_sync_logs
WHERE created_at >= now() - interval '24 hours'
GROUP BY status ORDER BY sayi DESC;

-- 10) Son iCal senkronları
\echo ''
\echo '--- SON 24 SAAT iCAL ---'
SELECT
    status,
    COUNT(*) AS sayi
FROM ical_sync_logs
WHERE created_at >= now() - interval '24 hours'
GROUP BY status ORDER BY sayi DESC;

\echo ''
\echo '=== DOĞRULAMA BİTTİ ==='
\echo ''
