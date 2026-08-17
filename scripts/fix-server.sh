#!/bin/bash
# NEXUS sunucu onarım betiği — tek komutla:
#   1) En son kodu çeker (git fetch + reset --hard origin/main)
#   2) Zamanlayıcı advisory kilidini (424242) tutan oturumu sonlandırır
#   3) Tüm tablo/sequence/schema sahipliğini app DB kullanıcısına devreder
#      ("must be owner of table" hatalarının kök çözümü)
#   4) Migration'ları uygular + eski şemalı tabloları onarır (health-check --repair)
#   5) tick + verify-platform + verify-all ile doğrular
#
# Kök (root) olarak çalıştırın:
#   bash scripts/fix-server.sh
#
# Not: Adım 3, sunucunun postgres OS kullanıcısı üzerinden sudo gerektirir.

set -e
cd "$(dirname "$0")/.." || exit 1

APP_DB_USER=$(grep -oP "'db_user'\s*=>\s*'\K[^']+" config/secrets.php)
APP_DB_NAME=$(grep -oP "'db_name'\s*=>\s*'\K[^']+" config/secrets.php)
APP_DB_NAME=${APP_DB_NAME:-nexus_traveltech}
PHP_BIN=$(command -v /opt/plesk/php/8.5/bin/php || command -v php)
echo "App DB kullanıcısı: $APP_DB_USER | DB: $APP_DB_NAME | PHP: $PHP_BIN"

echo
echo "=== 1) EN SON KOD ==="
git fetch origin && git reset --hard origin/main
git log --oneline -1

echo
echo "=== 2) ZAMANLAYICI KİLİDİ ==="
sudo -u postgres psql -d "$APP_DB_NAME" -c "SELECT pg_terminate_backend(pid) FROM pg_locks WHERE locktype='advisory' AND granted AND (classid=424242 OR objid=424242);" || echo "(kilit yok veya zaten serbest)"

echo
echo "=== 3) SAHİPLİK DEVRİ ==="
sudo -u postgres psql -d "$APP_DB_NAME" <<SQL
DO \$\$
DECLARE r RECORD;
BEGIN
  FOR r IN SELECT tablename FROM pg_tables WHERE schemaname='public' LOOP
    BEGIN EXECUTE format('ALTER TABLE public.%I OWNER TO %I', r.tablename, '$APP_DB_USER'); EXCEPTION WHEN OTHERS THEN RAISE NOTICE 'tablo atlandı: %', r.tablename; END;
  END LOOP;
  FOR r IN SELECT sequence_name FROM information_schema.sequences WHERE sequence_schema='public' LOOP
    BEGIN EXECUTE format('ALTER SEQUENCE public.%I OWNER TO %I', r.sequence_name, '$APP_DB_USER'); EXCEPTION WHEN OTHERS THEN RAISE NOTICE 'seq atlandı: %', r.sequence_name; END;
  END LOOP;
  BEGIN ALTER SCHEMA public OWNER TO $APP_DB_USER; EXCEPTION WHEN OTHERS THEN RAISE NOTICE 'schema atlandı'; END;
END \$\$;
SQL
sudo -u postgres psql -d "$APP_DB_NAME" -c "SELECT tableowner, count(*) FROM pg_tables WHERE schemaname='public' GROUP BY tableowner;"

echo
echo "=== 4) MİGRASYON + ONARIM ==="
"$PHP_BIN" scripts/health-check.php
"$PHP_BIN" scripts/health-check.php --repair --dry-run || true
"$PHP_BIN" scripts/health-check.php --repair || true

echo
echo "=== 5) DOĞRULAMA ==="
"$PHP_BIN" cron/tick.php
"$PHP_BIN" scripts/verify-platform.php || true
"$PHP_BIN" scripts/verify-all.php || true

echo
echo "=== BİTTİ — yukarıda HATA satırı kalmadıysa sunucu sağlıklı ==="
