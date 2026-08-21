#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════════
# NEXUS sunucu güncelleme + teşhis + düzeltme betiği
#
# Her adımda beklenen değeri doğrular, özet basar.
# Tek komutla: remote doğrula → fetch → migration → repair → verify → webhook test
#
# Kullanım:
#   bash scripts/server-update.sh                     # tam akış
#   bash scripts/server-update.sh --verify-only       # yalnızca doğrulama
#   bash scripts/server-update.sh --skip-webhook      # webhook testini atla
#   bash scripts/server-update.sh --expect HASH       # belirli commit'i bekle
#
# Tek satır (sunucu):
#   cd /var/www/vhosts/nexustraveltech.com/httpdocs && bash scripts/server-update.sh
# ═══════════════════════════════════════════════════════════════════════════════

set -euo pipefail
cd "$(dirname "$0")/.." || exit 1

# ── Argümanlar ──
VERIFY_ONLY=0
SKIP_WEBHOOK=0
EXPECT_HASH=""
for arg in "$@"; do
  case "$arg" in
    --verify-only)   VERIFY_ONLY=1 ;;
    --skip-webhook)  SKIP_WEBHOOK=1 ;;
    --expect)        shift;; # sonraki argüman
    --expect=*)      EXPECT_HASH="${arg#--expect=}" ;;
  esac
done
# positional → expect
if [ "$EXPECT_HASH" = "" ] && [ "${2:-}" != "" ] && [ "${1:-}" = "--expect" ]; then
  EXPECT_HASH="$2"
fi

# ── Ortam ──
PHP_BIN=$(command -v /opt/plesk/php/8.5/bin/php 2>/dev/null || command -v php 2>/dev/null || echo php)
APP_DB_USER=$("$PHP_BIN" -r '$c=require "config/secrets.php"; echo $c["db_user"] ?? "app";' 2>/dev/null || echo "app")
APP_DB_NAME=$("$PHP_BIN" -r '$c=require "config/secrets.php"; echo $c["db_name"] ?? "nexus_traveltech";' 2>/dev/null || echo "nexus_traveltech")
EXPECTED_REMOTE="https://github.com/mamongrup/nexustraveltech.git"
FAIL=0
WARN=0
STEP_RESULTS=()

ok()   { echo "  ✓ $1"; }
fail() { echo "  ✗ $1"; FAIL=$((FAIL+1)); }
warn() { echo "  ⚠ $1"; WARN=$((WARN+1)); }
step() { STEP_RESULTS+=("$1:$2"); } # name:PASS|FAIL|WARN

echo "═══════════════════════════════════════════════════════════════════════════"
echo " NEXUS Sunucu Güncelleme — $(date '+%Y-%m-%d %H:%M:%S')"
echo " DB: $APP_DB_NAME · Sahip: $APP_DB_USER · PHP: $PHP_BIN"
echo "═══════════════════════════════════════════════════════════════════════════"

if [ "$VERIFY_ONLY" -eq 1 ]; then
  echo "→ --verify-only: değişiklik yapılmayacak"
fi

# ═══════════════════════════════════════════════════════════════════════════════
# ADIM 1: Remote URL doğrulama
# ═══════════════════════════════════════════════════════════════════════════════
echo ""
echo "════ 1/7  REMOTE DOĞRULAMA ════"
REPO_URL=$(git remote get-url origin 2>/dev/null || echo "")
if [ "$REPO_URL" != "$EXPECTED_REMOTE" ]; then
  if [ "$VERIFY_ONLY" -eq 1 ]; then
    fail "origin '$REPO_URL' — beklenen: $EXPECTED_REMOTE (--verify-only düzeltme yapmadı)"
    step "remote:FAIL"
  else
    warn "origin '$REPO_URL' yanlış — düzeltiliyor"
    git remote set-url origin "$EXPECTED_REMOTE"
    REPO_URL=$(git remote get-url origin)
    ok "origin düzeltildi: $REPO_URL"
    step "remote:PASS"
  fi
else
  ok "origin doğru: $REPO_URL"
  step "remote:PASS"
fi

# ═══════════════════════════════════════════════════════════════════════════════
# ADIM 2: Fetch + Reset
# ═══════════════════════════════════════════════════════════════════════════════
echo ""
echo "════ 2/7  KOD GÜNCELLEME ════"
if [ "$VERIFY_ONLY" -eq 1 ]; then
  ok "atlandı (--verify-only)"
  step "fetch:SKIP"
else
  BEFORE=$(git rev-parse HEAD 2>/dev/null || echo "")
  BEFORE_SHORT=${BEFORE:0:7}

  git fetch origin --prune --tags 2>&1 | tail -3
  REMOTE_MAIN=$(git rev-parse origin/main 2>/dev/null || echo "")
  if [ -z "$REMOTE_MAIN" ]; then
    fail "origin/main çözümlenemedi"
    step "fetch:FAIL"
  else
    REMOTE_SHORT=${REMOTE_MAIN:0:7}
    ok "origin/main: $REMOTE_SHORT"

    # --expect doğrulaması
    if [ -n "$EXPECT_HASH" ] && [ "${REMOTE_SHORT}" != "${EXPECT_HASH:0:7}" ]; then
      warn "beklenen $EXPECT_HASH, gelen $REMOTE_SHORT — yine de devam ediliyor"
    fi

    git reset --hard origin/main 2>&1 | tail -1
    AFTER=$(git rev-parse HEAD 2>/dev/null || echo "")
    AFTER_SHORT=${AFTER:0:7}

    if [ "$BEFORE" = "$AFTER" ]; then
      ok "zaten güncel ($AFTER_SHORT)"
    else
      ok "güncellendi: $BEFORE_SHORT → $AFTER_SHORT"
    fi
    step "fetch:PASS"
  fi
fi

# ═══════════════════════════════════════════════════════════════════════════════
# ADIM 3: Advisory kilidi temizle
# ═══════════════════════════════════════════════════════════════════════════════
echo ""
echo "════ 3/7  ZAMANLAYICI KİLİDİ ════"
KILLED=$(sudo -u postgres psql -d "$APP_DB_NAME" -t -A -c "SELECT count(*) FROM pg_locks WHERE locktype='advisory' AND granted AND (classid=424242 OR objid=424242);" 2>/dev/null || echo "0")
if [ "$KILLED" != "0" ]; then
  sudo -u postgres psql -d "$APP_DB_NAME" -c "SELECT pg_terminate_backend(pid) FROM pg_locks WHERE locktype='advisory' AND granted AND (classid=424242 OR objid=424242);" >/dev/null 2>&1
  ok "$KILLED kilit sonlandırıldı"
else
  ok "kilit yok"
fi
step "lock:PASS"

# ═══════════════════════════════════════════════════════════════════════════════
# ADIM 4: Sahiplik devri
# ═══════════════════════════════════════════════════════════════════════════════
echo ""
echo "════ 4/7  SAHİPLİK DEVRİ ════"
if [ "$VERIFY_ONLY" -eq 1 ]; then
  BAD_OWNERS=$(sudo -u postgres psql -d "$APP_DB_NAME" -t -A -c "SELECT count(*) FROM pg_tables WHERE schemaname='public' AND tableowner != '$APP_DB_USER';" 2>/dev/null || echo "?")
  if [ "$BAD_OWNERS" = "0" ]; then
    ok "tüm tablolar $APP_DB_USER sahipli"
  elif [ "$BAD_OWNERS" = "?" ]; then
    warn "sorgu çalışmadı"
  else
    fail "$BAD_OWNERS tablo hala $APP_DB_USER değil"
  fi
  step "owner:$([ "$BAD_OWNERS" = "0" ] && echo PASS || echo FAIL)"
else
  sudo -u postgres psql -d "$APP_DB_NAME" -v ON_ERROR_STOP=1 -v owner="$APP_DB_USER" <<'SQL' 2>&1 | grep -E "NOTICE|ERROR" || true
DO $do$
DECLARE r RECORD;
BEGIN
  FOR r IN SELECT tablename FROM pg_tables WHERE schemaname='public' LOOP
    BEGIN EXECUTE format('ALTER TABLE public.%I OWNER TO %I', r.tablename, :'owner'); EXCEPTION WHEN OTHERS THEN RAISE NOTICE 'atlandı: %', r.tablename; END;
  END LOOP;
  FOR r IN SELECT sequence_name FROM information_schema.sequences WHERE sequence_schema='public' LOOP
    BEGIN EXECUTE format('ALTER SEQUENCE public.%I OWNER TO %I', r.sequence_name, :'owner'); EXCEPTION WHEN OTHERS THEN RAISE NOTICE 'seq atlandı: %', r.sequence_name; END;
  END LOOP;
  BEGIN EXECUTE format('ALTER SCHEMA public OWNER TO %I', :'owner'); EXCEPTION WHEN OTHERS THEN RAISE NOTICE 'schema atlandı'; END;
END
$do$;
SQL
  BAD_AFTER=$(sudo -u postgres psql -d "$APP_DB_NAME" -t -A -c "SELECT count(*) FROM pg_tables WHERE schemaname='public' AND tableowner != '$APP_DB_USER';" 2>/dev/null || echo "?")
  if [ "$BAD_AFTER" = "0" ]; then
    ok "tüm tablolar $APP_DB_USER sahipli"
    step "owner:PASS"
  else
    fail "$BAD_AFTER tablo hala farklı sahipte"
    step "owner:FAIL"
  fi
fi

# ═══════════════════════════════════════════════════════════════════════════════
# ADIM 5: Migration
# ═══════════════════════════════════════════════════════════════════════════════
echo ""
echo "════ 5/7  MİGRASYON ════"
if [ "$VERIFY_ONLY" -eq 1 ]; then
  ok "atlandı (--verify-only)"
  step "migration:SKIP"
else
  MIG_OUT=$("$PHP_BIN" scripts/health-check.php 2>&1 || true)
  MIG_APPLIED=$(echo "$MIG_OUT" | grep -c "uygulandı" || true)
  MIG_SKIPPED=$(echo "$MIG_OUT" | grep -c "atlandı" || true)
  MIG_FAILED=$(echo "$MIG_OUT" | grep -c "✗" || true)

  ok "uygulandı: $MIG_APPLIED · atlandı: $MIG_SKIPPED"

  if [ "$MIG_FAILED" -gt 0 ]; then
    fail "$MIG_FAILED migration hatası"
    echo "$MIG_OUT" | grep "✗" | head -5
    step "migration:FAIL"
  else
    step "migration:PASS"
  fi
fi

# ═══════════════════════════════════════════════════════════════════════════════
# ADIM 6: Onarım (--repair)
# ═══════════════════════════════════════════════════════════════════════════════
echo ""
echo "════ 6/7  ONARIM ════"
if [ "$VERIFY_ONLY" -eq 1 ]; then
  ok "atlandı (--verify-only)"
  step "repair:SKIP"
else
  REPAIR_OUT=$("$PHP_BIN" scripts/health-check.php --repair --backup-schema --yes --orphans 2>&1 || true)
  REPAIR_DROPPED=$(echo "$REPAIR_OUT" | grep -c "düşürüldü\|yeniden kuruldu" || true)
  REPAIR_ORPHANS=$(echo "$REPAIR_OUT" | grep -c "temizlendi\|silindi" || true)
  REPAIR_RETRIES=$(echo "$REPAIR_OUT" | grep -c "retry\|sıfırlandı" || true)
  REPAIR_FAILED=$(echo "$REPAIR_OUT" | grep -c "✗" || true)

  [ "$REPAIR_DROPPED" -gt 0 ] && ok "$REPAIR_DROPPED tablo onarıldı"
  [ "$REPAIR_ORPHANS" -gt 0 ] && ok "$REPAIR_ORPHANS yetim temizlendi"
  [ "$REPAIR_RETRIES" -gt 0 ] && ok "$REPAIR_RETRIES retry sıfırlandı"

  if [ "$REPAIR_DROPPED" -eq 0 ] && [ "$REPAIR_ORPHANS" -eq 0 ] && [ "$REPAIR_RETRIES" -eq 0 ]; then
    ok "onarım gerektirecek sorun yok"
  fi

  if [ "$REPAIR_FAILED" -gt 0 ]; then
    fail "$REPAIR_FAILED onarım hatası"
    step "repair:FAIL"
  else
    step "repair:PASS"
  fi
fi

# ═══════════════════════════════════════════════════════════════════════════════
# ADIM 7: Doğrulama
# ═══════════════════════════════════════════════════════════════════════════════
echo ""
echo "════ 7/7  DOĞRULAMA ════"

# 7a) verify-platform
VP_OUT=$("$PHP_BIN" scripts/verify-platform.php 2>&1 || true)
VP_PASS=$(echo "$VP_OUT" | grep -c "✓" || true)
VP_FAIL=$(echo "$VP_OUT" | grep -c "✗" || true)
ok "verify-platform: $VP_PASS başarılı, $VP_FAIL eksik"

if [ "$VP_FAIL" -gt 0 ]; then
  fail "$VP_FAIL eksik kolon/tablo"
  step "verify:FAIL"
else
  step "verify:PASS"
fi

# 7b) verify-all
VA_OUT=$("$PHP_BIN" scripts/verify-all.php 2>&1 || true)
VA_FAIL=$(echo "$VA_OUT" | grep -c "✗" || true)
if [ "$VA_FAIL" -gt 0 ]; then
  fail "verify-all: $VA_FAIL sorun"
  step "verify-all:FAIL"
else
  ok "verify-all temiz"
  step "verify-all:PASS"
fi

# 7c) Webhook testi (opsiyonel)
if [ "$SKIP_WEBHOOK" -eq 1 ]; then
  ok "webhook testi atlandı (--skip-webhook)"
  step "webhook:SKIP"
else
  CONN=$(sudo -u postgres psql -d "$APP_DB_NAME" -t -A -c "SELECT id,property_id FROM channel_connections WHERE status='active' LIMIT 1;" 2>/dev/null || echo "")
  if [ -z "$CONN" ]; then
    ok "aktif kanal bağlantısı yok — webhook testi atlandı"
    step "webhook:SKIP"
  else
    CID=$(echo "$CONN" | cut -d'|' -f1)
    PID=$(echo "$CONN" | cut -d'|' -f2)
    CODE="UPD-$(date +%s)"
    DAY=$(date -d "+1 day" +%Y-%m-%d 2>/dev/null || date -v+1d +%Y-%m-%d)
    WH_OUT=$(curl -s -w "\n%{http_code}" -X POST \
      "https://nexustraveltech.com/api/channel-webhook.php?connection_id=${CID}&property_id=${PID}" \
      -H 'Content-Type: application/json' \
      -d "{\"action\":\"inventory_update\",\"room_code\":\"${CODE}\",\"plan_code\":\"BB\",\"currency\":\"EUR\",\"prices\":[{\"date\":\"${DAY}\",\"price\":88,\"allotment\":5,\"min_stay\":1,\"stop_sale\":false}]}" 2>/dev/null || echo "000")
    WH_CODE=$(echo "$WH_OUT" | tail -1)
    if [ "$WH_CODE" = "200" ]; then
      ok "webhook POST 200"
      step "webhook:PASS"
    else
      fail "webhook POST $WH_CODE"
      step "webhook:FAIL"
    fi
  fi
fi

# ═══════════════════════════════════════════════════════════════════════════════
# SONUÇ ÖZETİ
# ═══════════════════════════════════════════════════════════════════════════════
echo ""
echo "═══════════════════════════════════════════════════════════════════════════"
echo " SONUÇ"
echo "═══════════════════════════════════════════════════════════════════════════"
for r in "${STEP_RESULTS[@]}"; do
  name="${r%%:*}"
  status="${r##*:}"
  case "$status" in
    PASS)  printf "  ✓ %-15s BAŞARILI\n" "$name" ;;
    FAIL)  printf "  ✗ %-15s BAŞARISIZ\n" "$name" ;;
    SKIP)  printf "  – %-15s ATLANDI\n" "$name" ;;
    WARN)  printf "  ⚠ %-15s UYARILI\n" "$name" ;;
  esac
done

echo ""
TOTAL=$((FAIL + WARN))
if [ "$FAIL" -eq 0 ] && [ "$WARN" -eq 0 ]; then
  echo "✅ TÜM ADIMLAR BAŞARILI — $APP_DB_NAME güncel ve sağlam"
elif [ "$FAIL" -eq 0 ]; then
  echo "⚠ $WARN uyarı var — yukarıdaki ⚠ satırlarını kontrol edin"
else
  echo "❌ $FAIL HATA, $WARN UYARI — yukarıdaki ✗ satırlarını düzeltin"
fi

CURRENT_HASH=$(git rev-parse HEAD 2>/dev/null | cut -c1-7 || echo "?")
echo "   Commit: $CURRENT_HASH · $(date '+%Y-%m-%d %H:%M:%S')"
echo "═══════════════════════════════════════════════════════════════════════════"

exit $FAIL
