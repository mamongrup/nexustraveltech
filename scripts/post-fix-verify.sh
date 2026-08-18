#!/bin/bash
# NEXUS fix-server.sh sonrası doğrulama betiği — tek komutla 7 kontrol:
#   1) Advisory kilit (424242) serbest mi?
#   2) Tablo sahipliği — tüm tablolar app kullanıcısında mı?
#   3) Tablo sayısı — ≥ 48 tablo var mı?
#   4) Kritik tablolar — eksik olan var mı?
#   5) tick.php nabız — son 5 dkda görev çalıştı mı?
#   6) Migration durumu — başarısız/muştar var mı?
#   7) Bekleyen silme onayı — pending_trash_purges durumu
#
# Kullanım:
#   bash scripts/post-fix-verify.sh
#   bash scripts/post-fix-verify.sh --json   # JSON çıktı

set -o pipefail

JSON_MODE=0
for arg in "$@"; do
  case "$arg" in
    --json) JSON_MODE=1 ;;
  esac
done

# DB ayarları
SECRETS="/var/www/vhosts/nexustraveltech.com/httpdocs/config/secrets.php"
if [ -f "$SECRETS" ]; then
  APP_DB_USER=$(php -r "require '$SECRETS'; echo DB_USER ?? 'app';" 2>/dev/null || echo "app")
  APP_DB_NAME=$(php -r "require '$SECRETS'; echo DB_NAME ?? 'nexus_traveltech';" 2>/dev/null || echo "nexus_traveltech")
else
  APP_DB_USER="app"
  APP_DB_NAME="nexus_traveltech"
fi

PHP_BIN="/opt/plesk/php/8.5/bin/php"
HTTPDOCS="/var/www/vhosts/nexustraveltech.com/httpdocs"
cd "$HTTPDOCS" 2>/dev/null || true

PASS=0
FAIL=0
WARN=0
RESULTS=""

check_pass() { PASS=$((PASS+1)); RESULTS+="✓ $1\n"; }
check_fail() { FAIL=$((FAIL+1)); RESULTS+="✗ $1 — $2\n"; }
check_warn() { WARN=$((WARN+1)); RESULTS+="⚠ $1 — $2\n"; }

echo "═══════════════════════════════════════════════"
echo " NEXUS POST-FIX DOĞRULAMA — $(date '+%Y-%m-%d %H:%M:%S')"
echo "═══════════════════════════════════════════════"
echo

# ─── 1) Advisory kilit ───
echo "── 1) Advisory kilit (424242) ──"
LOCK_INFO=$(sudo -u postgres psql -d "$APP_DB_NAME" -t -A -c "SELECT pid, now()-state_change AS age FROM pg_locks l JOIN pg_stat_activity a ON a.pid=l.pid WHERE l.locktype='advisory' AND l.classid=0 AND l.objid=424242 AND l.granted=true AND a.pid<>pg_backend_pid() LIMIT 1" 2>/dev/null || echo "")
if [ -z "$LOCK_INFO" ] || [ "$LOCK_INFO" = "" ]; then
  check_pass "Advisory kilit serbest"
  echo "  ✓ Kilit serbest"
else
  LOCK_PID=$(echo "$LOCK_INFO" | cut -d'|' -f1)
  LOCK_AGE=$(echo "$LOCK_INFO" | cut -d'|' -f2)
  check_fail "Advisory kilit tutuluyor" "PID=$LOCK_PID yaş=${LOCK_AGE}"
  echo "  ✗ Kilit tutuluyor — PID=$LOCK_PID yaş=${LOCK_AGE}"
fi
echo

# ─── 2) Tablo sahipliği ───
echo "── 2) Tablo sahipliği ──"
OWNER_BAD=$(sudo -u postgres psql -d "$APP_DB_NAME" -t -A -c "SELECT COUNT(*) FROM pg_tables WHERE schemaname='public' AND tableowner != '$APP_DB_USER'" 2>/dev/null || echo "?")
OWNER_TOTAL=$(sudo -u postgres psql -d "$APP_DB_NAME" -t -A -c "SELECT COUNT(*) FROM pg_tables WHERE schemaname='public'" 2>/dev/null || echo "?")
if [ "$OWNER_BAD" = "0" ]; then
  check_pass "Tüm tablolar $APP_DB_USER kullanıcısında ($OWNER_TOTAL tablo)"
  echo "  ✓ Tüm tablolar $APP_DB_USER ($OWNER_TOTAL)"
elif [ "$OWNER_BAD" = "?" ]; then
  check_warn "Tablo sahipliği kontrol edilemedi" ""
  echo "  ⚠ Sorgu çalışmadı"
else
  check_fail "Tablo sahipliği hatalı" "$OWNER_BAD tablo hala $APP_DB_USER değil"
  echo "  ✗ $OWNER_BAD / $OWNER_TOTAL tablo hala $APP_DB_USER değil"
fi
echo

# ─── 3) Tablo sayısı ───
echo "── 3) Tablo sayısı ──"
if [ "$OWNER_TOTAL" != "?" ] && [ "$OWNER_TOTAL" -ge 48 ] 2>/dev/null; then
  check_pass "Tablo sayısı yeterli ($OWNER_TOTAL ≥ 48)"
  echo "  ✓ $OWNER_TOTAL tablo (≥48)"
elif [ "$OWNER_TOTAL" = "?" ]; then
  check_warn "Tablo sayısı kontrol edilemedi" ""
  echo "  ⚠ Sorgu çalışmadı"
else
  check_fail "Tablo sayısı eksik" "$OWNER_TOTAL tablo (beklenen ≥48)"
  echo "  ✗ $OWNER_TOTAL tablo (beklenen ≥48)"
fi
echo

# ─── 4) Kritik tablolar ───
echo "── 4) Kritik tablolar ──"
CRITICAL_TABLES="property_feature_catalog channel_room_mappings channel_rate_plan_mappings pending_trash_purges feature_delete_backups scheduled_jobs scheduled_job_runs"
MISSING_TBL=""
for tbl in $CRITICAL_TABLES; do
  EXISTS=$(sudo -u postgres psql -d "$APP_DB_NAME" -t -A -c "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_name='$tbl'" 2>/dev/null || echo "0")
  if [ "$EXISTS" = "0" ]; then
    MISSING_TBL="$MISSING_TBL $tbl"
  fi
done
if [ -z "$MISSING_TBL" ]; then
  check_pass "Tüm kritik tablolar mevcut"
  echo "  ✓ 7/7 kritik tablo mevcut"
else
  check_fail "Kritik tablo eksik" "$MISSING_TBL"
  echo "  ✗ Eksik:$MISSING_TBL"
fi
echo

# ─── 5) tick.php nabız ───
echo "── 5) tick.php nabız ──"
TICK_RECENT=$(sudo -u postgres psql -d "$APP_DB_NAME" -t -A -c "SELECT COUNT(*) FROM scheduled_job_runs WHERE created_at >= now() - interval '5 minutes'" 2>/dev/null || echo "?")
if [ "$TICK_RECENT" != "?" ] && [ "$TICK_RECENT" -gt 0 ] 2>/dev/null; then
  check_pass "Son 5 dkda $TICK_RECENT görev çalıştı"
  echo "  ✓ Son 5 dk: $TICK_RECENT görev"
elif [ "$TICK_RECENT" = "?" ]; then
  check_warn "Nabız kontrol edilemedi" ""
  echo "  ⚠ Sorgu çalışmadı"
else
  check_warn "Son 5 dkda hiç görev çalışmadı" "tick.php durmuş olabilir"
  echo "  ⚠ Son 5 dk: 0 görev (tick durmuş olabilir)"
fi
echo

# ─── 6) Migration durumu ───
echo "── 6) Migration durumu ──"
MIG_TOTAL=$(sudo -u postgres psql -d "$APP_DB_NAME" -t -A -c "SELECT COUNT(*) FROM schema_migrations" 2>/dev/null || echo "?")
MIG_OUTPUT=$("$PHP_BIN" scripts/health-check.php 2>&1 || true)
MIG_FAILS=$(echo "$MIG_OUTPUT" | grep -c "✗" || true)
if [ "$MIG_FAILS" = "0" ] && [ "$MIG_TOTAL" != "?" ] && [ "$MIG_TOTAL" -ge 55 ] 2>/dev/null; then
  check_pass "Migration temiz ($MIG_TOTAL uygulanan, 0 hata)"
  echo "  ✓ $MIG_TOTAL migration, 0 hata"
elif [ "$MIG_FAILS" -gt 0 ]; then
  check_fail "Migration hataları var" "$MIG_FAILS sorun"
  echo "  ✗ $MIG_FAILS migration sorunu"
else
  check_warn "Migration: $MIG_TOTAL uygulanan" "eşik 55'in altında olabilir"
  echo "  ⚠ $MIG_TOTAL uygulanan (eşik: 55)"
fi
echo

# ─── 7) Bekleyen silme onayı ───
echo "── 7) Bekleyen silme onayı ──"
PEND_PURGE=$(sudo -u postgres psql -d "$APP_DB_NAME" -t -A -c "SELECT COUNT(*) FROM pending_trash_purges WHERE approved_at IS NULL AND expires_at > now()" 2>/dev/null || echo "?")
TRASH_COUNT=$(sudo -u postgres psql -d "$APP_DB_NAME" -t -A -c "SELECT COUNT(*) FROM property_feature_catalog WHERE deleted_at IS NOT NULL" 2>/dev/null || echo "?")
if [ "$PEND_PURGE" != "?" ] && [ "$PEND_PURGE" -gt 0 ] 2>/dev/null; then
  check_warn "Bekleyen silme onayı: $PEND_PURGE özellik" "çöp kutusunda $TRASH_COUNT toplam"
  echo "  ⏳ $PEND_PURGE onay bekliyor · $TRASH_COUNT çöp kutusunda"
elif [ "$PEND_PURGE" = "?" ]; then
  check_warn "Bekleyen onay kontrol edilemedi" ""
  echo "  ⚠ Sorgu çalışmadı"
else
  check_pass "Bekleyen silme onayı yok (çöp kutusu: $TRASH_COUNT)"
  echo "  ✓ Onay bekleyen yok · çöp kutusu: $TRASH_COUNT"
fi
echo

# ─── ÖZET ───
echo "═══════════════════════════════════════════════"
TOTAL=$((PASS+FAIL+WARN))
if [ "$JSON_MODE" -eq 1 ]; then
  echo "{"
  echo "  \"pass\": $PASS,"
  echo "  \"fail\": $FAIL,"
  echo "  \"warn\": $WARN,"
  echo "  \"total\": $TOTAL,"
  echo "  \"status\": \"$([ $FAIL -eq 0 ] && echo 'ok' || echo 'error')\","
  echo "  \"db\": \"$APP_DB_NAME\","
  echo "  \"timestamp\": \"$(date -Iseconds)\""
  echo "}"
else
  echo " 📊 SONUÇ: $PASS/$TOTAL geçti"
  [ "$FAIL" -gt 0 ] && echo " ❌ $FAIL hata"
  [ "$WARN" -gt 0 ] && echo " ⚠️  $WARN uyarı"
  [ "$FAIL" -eq 0 ] && [ "$WARN" -eq 0 ] && echo " ✅ Tüm kontroller temiz"
  echo
  echo -e "$RESULTS"
fi
echo "═══════════════════════════════════════════════"

exit $FAIL
