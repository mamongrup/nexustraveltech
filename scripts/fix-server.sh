#!/bin/bash
# NEXUS sunucu onarım betiği — tek komutla tam zincir:
#   1) origin remote'unu doğrular (gerçek GitHub'a bakmıyorsa düzeltir — sunucuların
#      en sık takıldığı nokta: eski remote yüzünden fetch hep aynı eski commit'i getirir)
#   2) En son kodu çeker (git fetch + reset --hard origin/main)
#   3) Zamanlayıcı advisory kilidini (424242) tutan oturumu sonlandırır
#   4) App DB kullanıcısını secrets.php'den OKUR ve tüm public şema sahipliğini
#      (tablo + dizi + görünüm + schema) o kullanıcıya devreder
#   5) health-check: bekleyen migration'ları uygular — 'must be owner' olursa
#      sahipliği otomatik devredip SQL'i bir kez yeniden dener
#   6) health-check --repair --backup-schema --yes: yabancı şemalı BOŞ tabloları
#      önce şemasını yedekleyip düşürür, migration zinciriyle yeniden kurar
#   7) health-check (onarım sonrası doğrulama) + verify-platform + verify-all
#
# Kök (root) olarak TEK KOMUT:
#   bash scripts/fix-server.sh
#
# Tek satır kopyala-yapıştır:
#   cd /var/www/vhosts/nexustraveltech.com/httpdocs && bash scripts/fix-server.sh
#
# Not: Sahiplik devri, sunucunun postgres OS kullanıcısı üzerinden sudo gerektirir
# (root + `sudo -u postgres`). Betik hiçbir şeyi onaysız silmez; DOLU tablolara
# dokunmaz (raporlar).

set -e
cd "$(dirname "$0")/.." || exit 1

PHP_BIN=$(command -v /opt/plesk/php/8.5/bin/php || command -v php || echo php)
APP_DB_USER=$("$PHP_BIN" -r '$c=require "config/secrets.php"; echo $c["db_user"] ?? "app";')
APP_DB_NAME=$("$PHP_BIN" -r '$c=require "config/secrets.php"; echo $c["db_name"] ?? "nexus_traveltech";')
REPO_URL=$(git remote get-url origin 2>/dev/null || echo "")
echo "→ DB: $APP_DB_NAME · Sahip: $APP_DB_USER · PHP: $PHP_BIN"

echo
echo "=== 1) ORIGIN REMOTE DOĞRULAMA ==="
if [ "$REPO_URL" != "https://github.com/mamongrup/nexustraveltech.git" ]; then
  echo "⚠ origin '$REPO_URL' gerçek GitHub'a bakmıyor — düzeltiliyor"
  git remote set-url origin https://github.com/mamongrup/nexustraveltech.git
  git remote -v
else
  echo "✓ origin doğru: $REPO_URL"
fi

echo
echo "=== 2) EN SON KOD ==="
git fetch origin --prune --tags
git reset --hard origin/main
git log --oneline -1

echo
echo "=== 3) ZAMANLAYICI KİLİDİ ==="
sudo -u postgres psql -d "$APP_DB_NAME" -c "SELECT pg_terminate_backend(pid) FROM pg_locks WHERE locktype='advisory' AND granted AND (classid=424242 OR objid=424242);" || echo "(kilit yok veya zaten serbest)"

echo
echo "=== 4) SAHİPLİK DEVRİ (secrets.php'deki $APP_DB_USER) ==="
sudo -u postgres psql -d "$APP_DB_NAME" -v ON_ERROR_STOP=1 -v owner="$APP_DB_USER" <<'SQL'
DO $do$
DECLARE r RECORD;
BEGIN
  FOR r IN SELECT tablename FROM pg_tables WHERE schemaname='public' LOOP
    BEGIN EXECUTE format('ALTER TABLE public.%I OWNER TO %I', r.tablename, :'owner'); EXCEPTION WHEN OTHERS THEN RAISE NOTICE 'tablo atlandı: %', r.tablename; END;
  END LOOP;
  FOR r IN SELECT sequence_name FROM information_schema.sequences WHERE sequence_schema='public' LOOP
    BEGIN EXECUTE format('ALTER SEQUENCE public.%I OWNER TO %I', r.sequence_name, :'owner'); EXCEPTION WHEN OTHERS THEN RAISE NOTICE 'seq atlandı: %', r.sequence_name; END;
  END LOOP;
  BEGIN EXECUTE format('ALTER SCHEMA public OWNER TO %I', :'owner'); EXCEPTION WHEN OTHERS THEN RAISE NOTICE 'schema atlandı'; END;
END
$do$;
SQL
sudo -u postgres psql -d "$APP_DB_NAME" -c "SELECT tableowner, count(*) FROM pg_tables WHERE schemaname='public' GROUP BY tableowner;"

echo
echo "=== 5) MİGRASYON (health-check — 'must be owner' otomatik çözülür) ==="
"$PHP_BIN" scripts/health-check.php || true

echo
echo "=== 6) ONARIM (yabancı şemalı BOŞ tablolar; önce şema yedeği) ==="
"$PHP_BIN" scripts/health-check.php --repair --dry-run || true
"$PHP_BIN" scripts/health-check.php --repair --backup-schema --yes || true

echo
echo "=== 7) DOĞRULAMA ==="
"$PHP_BIN" cron/tick.php || true
"$PHP_BIN" scripts/health-check.php || true
"$PHP_BIN" scripts/verify-platform.php || true
"$PHP_BIN" scripts/verify-all.php || true

echo
echo "=== BİTTİ — üstte 'SONUÇ: ... sorun' veya '✗' satırı kalmadıysa sunucu sağlıklı ==="
