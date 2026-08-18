#!/bin/bash
# NEXUS sunucu onarım betiği — tek komutla tam zincir:
#   1) origin remote'u doğrular (gerçek GitHub'a bakmıyorsa düzeltir)
#   2) En son kodu çeker (git fetch + reset --hard origin/main)
#   3) Zamanlayıcı advisory kilidini (424242) tutan oturumu sonlandırır
#   4) App DB kullanıcısını secrets.php'den OKUR ve tüm public şema sahipliğini
#      o kullanıcıya devreder
#   5) health-check: bekleyen migration'ları uygular
#   6) health-check --repair --backup-schema --yes: yabancı şemalı tabloları yeniden kurar
#   7) health-check + verify-platform + verify-all (doğrulama)
#
# Kullanım:
#   bash scripts/fix-server.sh                 # tam onarım + doğrulama
#   bash scripts/fix-server.sh --verify-only   # hi285cir degisiklik yapmadan sadece dogrulama
#
# Tek satır:
#   cd /var/www/vhosts/nexustraveltech.com/httpdocs && bash scripts/fix-server.sh

# --verify-only: hicbir sey degistirmeden yalnizca dogrulama modu
VERIFY_ONLY=0
for arg in "$@"; do
  case "$arg" in
    --verify-only) VERIFY_ONLY=1 ;;
  esac
done
if [ "$VERIFY_ONLY" -eq 1 ]; then
  echo "--> --verify-only: degisiklik yapilmayacak, yalnizca dogrulama"
fi

set -e
cd "$(dirname "$0")/.." || exit 1

PHP_BIN=$(command -v /opt/plesk/php/8.5/bin/php || command -v php || echo php)
APP_DB_USER=$("$PHP_BIN" -r '$c=require "config/secrets.php"; echo $c["db_user"] ?? "app";')
APP_DB_NAME=$("$PHP_BIN" -r '$c=require "config/secrets.php"; echo $c["db_name"] ?? "nexus_traveltech";')
REPO_URL=$(git remote get-url origin 2>/dev/null || echo "")
echo "→ DB: $APP_DB_NAME · Sahip: $APP_DB_USER · PHP: $PHP_BIN"

if [ "$VERIFY_ONLY" -eq 0 ]; then

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
echo "=== 5) MİGRASYON (health-check) ==="
"$PHP_BIN" scripts/health-check.php || true

echo
echo "=== 6) ONARIM (yabancı şemalı tablolar; önce şema yedeği) ==="
"$PHP_BIN" scripts/health-check.php --repair --dry-run || true
"$PHP_BIN" scripts/health-check.php --repair --backup-schema --yes || true

fi # --verify-only: Steps 1-6 bitti

echo
echo "=== 7) DOĞRULAMA ==="
"$PHP_BIN" cron/tick.php || true
"$PHP_BIN" scripts/health-check.php || true
"$PHP_BIN" scripts/verify-platform.php || true
"$PHP_BIN" scripts/verify-all.php || true

echo
# ─── ÖZET: tek satır durum raporu ───
ERRORS=0
# Tablo sahipliği
OWNER_COUNT=$(sudo -u postgres psql -d "$APP_DB_NAME" -t -A -c "SELECT COUNT(*) FROM pg_tables WHERE schemaname='public' AND tableowner != '$APP_DB_USER'" 2>/dev/null || echo "?")
if [ "$OWNER_COUNT" != "0" ] && [ "$OWNER_COUNT" != "?" ]; then
  echo "⚠ Sahiplik: $OWNER_COUNT tablo hala $APP_DB_USER değil"
  ERRORS=$((ERRORS+1))
fi
# Migration: başarısız var mı?
MIG_FAIL=$("$PHP_BIN" scripts/health-check.php 2>&1 | grep -c "✗" || true)
if [ "$MIG_FAIL" -gt 0 ]; then
  echo "✗ Migration: $MIG_FAIL sorun"
  ERRORS=$((ERRORS+1))
fi
# Verify: eksik kolon var mı?
VERIFY_OUT=$("$PHP_BIN" scripts/verify-platform.php 2>&1 || true)
if echo "$VERIFY_OUT" | grep -q "✗"; then
  VERIFY_ERRS=$(echo "$VERIFY_OUT" | grep -c "✗" || true)
  echo "✗ Verify: $VERIFY_ERRS eksik kolon"
  ERRORS=$((ERRORS+1))
fi
# trash_upcoming_alerts tablosu var mı?
TRASH_TBL=$(sudo -u postgres psql -d "$APP_DB_NAME" -t -A -c "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_name='trash_upcoming_alerts'" 2>/dev/null || echo "0")
if [ "$TRASH_TBL" = "0" ]; then
  echo "✗ trash_upcoming_alerts tablosu eksik"
  ERRORS=$((ERRORS+1))
fi
# Sonuç
if [ "$ERRORS" -eq 0 ]; then
  echo "✅ OK — tüm kontroller temiz ($APP_DB_NAME)"
else
  echo "❌ HATA — $ERRORS sorun kaldı ($APP_DB_NAME) — yukarıdaki ✗ satırlarına bakın"
fi
exit $ERRORS
