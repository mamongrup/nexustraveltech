#!/bin/bash
# NEXUS — sunucu kurulum / doğrulama betiği (5 adımlık tek komut).
#
# ee2e838+ sonrası tüm yeni özelliklerin uçtan uca doğrulanması.
# Her adımda beklenen çıkış kodu kontrol edilir; hata olursa durur ve özet basar.
#
# Kullanım (root veya sudo yetkisiyle):
#   bash scripts/sunucu-kurulum.sh                  → 5 adımı sırayla çalıştır
#   bash scripts/sunucu-kurulum.sh --dry-run        → yalnızca 1-3'ü çalıştır (webhook testi yok)
#   bash scripts/sunucu-kurulum.sh --skip-repair    → 2. adımı atla (onarım zaten yapıldıysa)
#   bash scripts/sunucu-kurulum.sh --step=N         → yalnızca N. adımı çalıştır (1-6)
#   bash scripts/sunucu-kurulum.sh --json           → her adımın sonucunu JSON olarak döndür
#
# Çıkış kodu: 0 = tümü başarılı, 1 = bir adımda hata

set -uo pipefail

# ── Parametreler ──
DRY_RUN=false
SKIP_REPAIR=false
STEP_ONLY=0
JSON_OUT=false
for arg in "$@"; do
  case "$arg" in
    --dry-run)     DRY_RUN=true ;;
    --skip-repair) SKIP_REPAIR=true ;;
    --json)        JSON_OUT=true ;;
    --step=*)      STEP_ONLY="${arg#*=}" ;;
    -h|--help)
      echo "Kullanım: bash scripts/sunucu-kurulum.sh [--dry-run] [--skip-repair] [--step=N] [--json]"
      echo "  --dry-run      Webhook testini atla (1-3 adımı çalıştır)"
      echo "  --skip-repair  Sağlık onarımını atla"
      echo "  --step=N       Yalnızca N. adımı çalıştır (1-6)"
      echo "  --json         Makinece okunabilir çıktı"
      exit 0 ;;
    *) echo "Bilinmeyen parametre: $arg"; exit 1 ;;
  esac
done

# ── Ortam değişkenleri ──
cd "$(dirname "$0")/.."
PHP_BIN=$(command -v /opt/plesk/php/8.5/bin/php || command -v php || echo php)
if [ ! -x "$PHP_BIN" ]; then echo "✗ PHP bulunamadı"; exit 1; fi
if [ ! -f config/secrets.php ]; then echo "✗ config/secrets.php yok"; exit 1; fi

DB=$("$PHP_BIN" -r '$c=require "config/secrets.php"; echo $c["db_name"] ?? "nexus_traveltech";')
APP_USER=$("$PHP_BIN" -r '$c=require "config/secrets.php"; echo $c["db_user"] ?? "app";')
GIT_HASH=$(git rev-parse --short HEAD 2>/dev/null || echo "yerel")

# ── Sonuç takibi ──
declare -A STEP_STATUS   # step_number => ok|fail|skip
declare -A STEP_OUTPUT   # step_number => çıktı (son 20 satır)
declare -A STEP_TIME     # step_number => saniye
TOTAL_START=$(date +%s)
STEP_COUNT=0
PASS_COUNT=0
FAIL_COUNT=0

run_step() {
  local num=$1 name=$2 cmd=$3
  if [ "$STEP_ONLY" -gt 0 ] && [ "$STEP_ONLY" -ne "$num" ]; then
    STEP_STATUS[$num]="skip"
    return 0
  fi
  STEP_COUNT=$((STEP_COUNT + 1))
  local t_start=$(date +%s)

  if $JSON_OUT; then
    echo "{\"step\":$num,\"name\":\"$name\",\"status\":\"running\"}"
  else
    echo ""
    echo "╔══════════════════════════════════════════════════════╗"
    printf "║  ADIM %d: %-42s ║\n" "$num" "$name"
    echo "╚══════════════════════════════════════════════════════╝"
  fi

  local output
  local rc=0
  output=$(eval "$cmd" 2>&1) || rc=$?

  local t_end=$(date +%s)
  STEP_TIME[$num]=$((t_end - t_start))

  if [ "$rc" -eq 0 ]; then
    STEP_STATUS[$num]="ok"
    PASS_COUNT=$((PASS_COUNT + 1))
  else
    STEP_STATUS[$num]="fail"
    FAIL_COUNT=$((FAIL_COUNT + 1))
  fi

  # Çıktıyı kaydet (son 30 satır)
  STEP_OUTPUT[$num]=$(echo "$output" | tail -30)

  if ! $JSON_OUT; then
    echo "$output" | tail -30
    echo ""
    if [ "$rc" -eq 0 ]; then
      echo "  ✓ $name — BAŞARILI (${STEP_TIME[$num]}s)"
    else
      echo "  ✗ $name — BAŞARISIZ (çıkış kodu: $rc, ${STEP_TIME[$num]}s)"
    fi
  fi
}

# ════════════════════════════════════════════════════════════
# ADIM 1: Sahiplik devri
# ════════════════════════════════════════════════════════════
run_step 1 "Sahiplik devri" "bash scripts/transfer-ownership.sh 2>&1"

# ════════════════════════════════════════════════════════════
# ADIM 2: Sağlık kontrolü + onarım
# ════════════════════════════════════════════════════════════
if $SKIP_REPAIR; then
  STEP_STATUS[2]="skip"
  STEP_COUNT=$((STEP_COUNT + 1))
  STEP_TIME[2]=0
  STEP_OUTPUT[2]="--skip-repair ile atlandı"
  if ! $JSON_OUT; then
    echo ""
    echo "  ⊘ ADIM 2: atlandı (--skip-repair)"
  fi
else
  run_step 2 "Sağlık kontrolü + onarım" "\"$PHP_BIN\" scripts/health-check.php --repair --yes 2>&1"
fi

# ════════════════════════════════════════════════════════════
# ADIM 3: Platform doğrulama
# ════════════════════════════════════════════════════════════
run_step 3 "Platform doğrulama" "\"$PHP_BIN\" scripts/verify-platform.php 2>&1"

# ════════════════════════════════════════════════════════════
# ADIM 4: Auto-test (tüm modüller)
# ════════════════════════════════════════════════════════════
run_step 4 "Auto-test (tüm modüller)" "\"$PHP_BIN\" scripts/auto-test.php --verbose 2>&1"

# ════════════════════════════════════════════════════════════
# ADIM 5: Webhook uçtan uca test
# ════════════════════════════════════════════════════════════
if $DRY_RUN; then
  STEP_STATUS[5]="skip"
  STEP_COUNT=$((STEP_COUNT + 1))
  STEP_TIME[5]=0
  STEP_OUTPUT[5]="--dry-run ile atlandı"
  if ! $JSON_OUT; then
    echo ""
    echo "  ⊘ ADIM 5: atlandı (--dry-run)"
  fi
else
  run_step 5 "Webhook uçtan uca test" "\"$PHP_BIN\" scripts/webhook-e2e-verify.php 2>&1"
fi

# ════════════════════════════════════════════════════════════
# ADIM 6: Eşleştirme durumu (SQL)
# ════════════════════════════════════════════════════════════
run_step 6 "Eşleştirme durumu" "sudo -u postgres psql -d \"$DB\" -Atc \"
SELECT 'oda: ' || status || ' — ' || count(*) || ' satır' FROM channel_room_mappings GROUP BY status ORDER BY 2 DESC;
\" 2>&1 && sudo -u postgres psql -d \"$DB\" -Atc \"
SELECT 'plan: ' || status || ' — ' || count(*) || ' satır' FROM channel_rate_plan_mappings GROUP BY status ORDER BY 2 DESC;
\" 2>&1 && sudo -u postgres psql -d \"$DB\" -Atc \"
SELECT 'son webhook: ' || display_name || ' · ' || status || ' · ' || created_at FROM channel_sync_logs ORDER BY id DESC LIMIT 3;
\" 2>&1"

# ════════════════════════════════════════════════════════════
# ÖZET
# ════════════════════════════════════════════════════════════
TOTAL_END=$(date +%s)
TOTAL_TIME=$((TOTAL_END - TOTAL_START))

if $JSON_OUT; then
  echo ""
  echo "{"
  echo "  \"ok\": $([ $FAIL_COUNT -eq 0 ] && echo true || echo false),"
  echo "  \"commit\": \"$GIT_HASH\","
  echo "  \"db\": \"$DB\","
  echo "  \"app_user\": \"$APP_USER\","
  echo "  \"total_seconds\": $TOTAL_TIME,"
  echo "  \"passed\": $PASS_COUNT,"
  echo "  \"failed\": $FAIL_COUNT,"
  echo "  \"steps\": ["
  FIRST=true
  for i in 1 2 3 4 5 6; do
    $FIRST || echo ","
    FIRST=false
    printf '    {"step":%d,"status":"%s","seconds":%d}' \
      "$i" "${STEP_STATUS[$i]:-skip}" "${STEP_TIME[$i]:-0}"
  done
  echo ""
  echo "  ]"
  echo "}"
  exit $([ $FAIL_COUNT -eq 0 ] && echo 0 || echo 1)
fi

echo ""
echo "╔══════════════════════════════════════════════════════╗"
echo "║  ÖZET                                               ║"
echo "╚══════════════════════════════════════════════════════╝"
echo ""
echo "  Commit:   $GIT_HASH"
echo "  DB:       $DB · kullanıcı: $APP_USER"
echo "  Süre:     ${TOTAL_TIME}s"
echo ""
printf "  %-4s %-36s %6s  %s\n" "ADIM" "AÇIKLAMA" "SÜRE" "DURUM"
echo "  ─────────────────────────────────────────────────────────────"
NAMES=("Sahiplik devri" "Sağlık + onarım" "Platform doğrulama" "Auto-test" "Webhook E2E" "Eşleştirme durumu")
for i in 1 2 3 4 5 6; do
  STATUS="${STEP_STATUS[$i]:-skip}"
  case "$STATUS" in
    ok)   ICON="✓"; COLOR="32" ;;
    fail) ICON="✗"; COLOR="31" ;;
    *)    ICON="⊘"; COLOR="33" ;;
  esac
  NAME="${NAMES[$((i-1))]}"
  printf "  \033[%sm%s\033[0m  %-34s %4ss\n" "$COLOR" "$ICON" "$NAME" "${STEP_TIME[$i]:-0}"
done
echo ""

if [ $FAIL_COUNT -eq 0 ]; then
  echo "  \033[32m✓ TÜM ADIMLAR BAŞARILI — sunucu produccióna hazır.\033[0m"
else
  echo "  \033[31m✗ $FAIL_COUNT adımda hata — yukarıdaki ✗ satırlarını kontrol edin.\033[0m"
fi
echo ""

exit $([ $FAIL_COUNT -eq 0 ] && echo 0 || echo 1)
