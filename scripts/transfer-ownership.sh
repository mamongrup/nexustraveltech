#!/bin/bash
# NEXUS — tüm public şemadaki nesnelerin sahipliğini secrets.php'deki db_user'a devreder.
# Kullanıcı/DB adı elle yazılmaz: config/secrets.php'den okunur.
# Kapsam: tablolar + diziler (sequence) + görünümler + schema.
#
# Kullanım (root olarak):
#   bash scripts/transfer-ownership.sh                  → sahipliği devret
#   bash scripts/transfer-ownership.sh --dry-run        → yalnızca durumu göster (dokunmaz)
#   bash scripts/transfer-ownership.sh --verbose        → her nesneyi tek tek listeler
#   bash scripts/transfer-ownership.sh --dry-run --verbose → kuru verbose
#
# Çıkış kodu: 0 = başarılı, 1 = bağlantı/parametre hatası, 2 = bazı devirler başarısız

set -euo pipefail
cd "$(dirname "$0")/.."

DRY_RUN=false
VERBOSE=false
REPAIR=false
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=true ;;
    --verbose) VERBOSE=true ;;
    --repair) REPAIR=true ;;
    -h|--help)
      echo "Kullanım: bash scripts/transfer-ownership.sh [--dry-run] [--verbose]"
      echo "  --dry-run   Hiçbir şey değiştirmez, yalnızca durumu gösterir"
      echo "  --verbose   Her nesneyi tek tek listeler"
      echo "  --repair    Devir + onarım + verify-platform üçlüsünü çalıştırır"
      exit 0 ;;
    *) echo "Bilinmeyen parametre: $arg"; exit 1 ;;
  esac
done

PHP_BIN=$(command -v /opt/plesk/php/8.5/bin/php || command -v php || echo php)
if [ ! -x "$PHP_BIN" ]; then
  echo "✗ PHP bulunamadı: $PHP_BIN"; exit 1
fi

if [ ! -f config/secrets.php ]; then
  echo "✗ config/secrets.php bulunamadı — önce secrets.example.php'yi kopyalayın"; exit 1
fi

DB=$("$PHP_BIN" -r '$c=require "config/secrets.php"; echo $c["db_name"] ?? "nexus_traveltech";')
OWNER=$("$PHP_BIN" -r '$c=require "config/secrets.php"; echo $c["db_user"] ?? "app";')

if [ -z "$DB" ] || [ -z "$OWNER" ]; then
  echo "✗ DB veya OWNER boş — secrets.php'deki db_name/db_user tanımsız"; exit 1
fi

echo "→ DB: $DB · Hedef sahip: $OWNER"
if $DRY_RUN; then
  echo "  (dry-run — hiçbir şey değiştirilmeyecek)"
fi
echo ""

# ── Mevcut sahiplik durumu ──
echo "=== Mevcut sahiplik dağılımı ==="
sudo -u postgres psql -d "$DB" -Atc \
  "SELECT tableowner || ': ' || count(*) || ' tablo' FROM pg_tables WHERE schemaname='public' GROUP BY tableowner ORDER BY 2 DESC;" 2>/dev/null || true
echo ""

# ── Taslak SQL dosyası oluştur ──
SQL_FILE=$(mktemp /tmp/transfer-ownership-XXXXXX.sql)
trap 'rm -f "$SQL_FILE"' EXIT

echo "-- NEXUS ownership transfer — $(date -Iseconds)" > "$SQL_FILE"
echo "-- Hedef: $OWNER" >> "$SQL_FILE"

# Tablolar
TABLE_COUNT=$(sudo -u postgres psql -d "$DB" -Atc \
  "SELECT count(*) FROM pg_tables WHERE schemaname='public' AND tableowner <> '$OWNER';" 2>/dev/null || echo 0)
if [ "$TABLE_COUNT" -gt 0 ]; then
  echo "=== Tablolar ($TABLE_COUNT adet devredilecek) ==="
  sudo -u postgres psql -d "$DB" -Atc \
    "SELECT format('ALTER TABLE %I OWNER TO %I', tablename, '$OWNER') FROM pg_tables WHERE schemaname='public' AND tableowner <> '$OWNER' ORDER BY tablename;" \
    >> "$SQL_FILE"
  if $VERBOSE; then
    sudo -u postgres psql -d "$DB" -Atc \
      "SELECT '  → ' || tablename || ' (' || tableowner || ' → $OWNER)' FROM pg_tables WHERE schemaname='public' AND tableowner <> '$OWNER' ORDER BY tablename;"
  fi
else
  echo "=== Tablolar: tümü zaten $OWNER sahipliğinde ✓ ==="
fi

# Diziler
SEQ_COUNT=$(sudo -u postgres psql -d "$DB" -Atc \
  "SELECT count(*) FROM pg_sequences WHERE schemaname='public' AND sequenceowner <> '$OWNER';" 2>/dev/null || echo 0)
if [ "$SEQ_COUNT" -gt 0 ]; then
  echo "=== Diziler ($SEQ_COUNT adet devredilecek) ==="
  sudo -u postgres psql -d "$DB" -Atc \
    "SELECT format('ALTER SEQUENCE %I OWNER TO %I', sequencename, '$OWNER') FROM pg_sequences WHERE schemaname='public' AND sequenceowner <> '$OWNER' ORDER BY sequencename;" \
    >> "$SQL_FILE"
  if $VERBOSE; then
    sudo -u postgres psql -d "$DB" -Atc \
      "SELECT '  → ' || sequencename || ' (' || sequenceowner || ' → $OWNER)' FROM pg_sequences WHERE schemaname='public' AND sequenceowner <> '$OWNER' ORDER BY sequencename;"
  fi
else
  echo "=== Diziler: tümü zaten $OWNER sahipliğinde ✓ ==="
fi

# Görünümler
VIEW_COUNT=$(sudo -u postgres psql -d "$DB" -Atc \
  "SELECT count(*) FROM pg_views WHERE schemaname='public' AND viewowner <> '$OWNER';" 2>/dev/null || echo 0)
if [ "$VIEW_COUNT" -gt 0 ]; then
  echo "=== Görünümler ($VIEW_COUNT adet devredilecek) ==="
  sudo -u postgres psql -d "$DB" -Atc \
    "SELECT format('ALTER VIEW %I OWNER TO %I', viewname, '$OWNER') FROM pg_views WHERE schemaname='public' AND viewowner <> '$OWNER' ORDER BY viewname;" \
    >> "$SQL_FILE"
  if $VERBOSE; then
    sudo -u postgres psql -d "$DB" -Atc \
      "SELECT '  → ' || viewname || ' (' || viewowner || ' → $OWNER)' FROM pg_views WHERE schemaname='public' AND viewowner <> '$OWNER' ORDER BY viewname;"
  fi
else
  echo "=== Görünümler: tümü zaten $OWNER sahipliğinde ✓ ==="
fi

# Schema
SCHEMA_OWNER=$(sudo -u postgres psql -d "$DB" -Atc \
  "SELECT schema_owner FROM information_schema.schemata WHERE schema_name='public';" 2>/dev/null || echo "")
if [ "$SCHEMA_OWNER" != "$OWNER" ]; then
  echo "=== Schema (public): $SCHEMA_OWNER → $OWNER ==="
  echo "ALTER SCHEMA public OWNER TO $OWNER;" >> "$SQL_FILE"
  if $VERBOSE; then
    echo "  → public schema $SCHEMA_OWNER → $OWNER"
  fi
else
  echo "=== Schema (public): zaten $OWNER sahipliğinde ✓ ==="
fi

TOTAL=$((TABLE_COUNT + SEQ_COUNT + VIEW_COUNT))
echo ""

# ── Uygula veya dry-run ──
if [ "$TOTAL" -eq 0 ] && grep -q "ALTER SCHEMA" "$SQL_FILE" 2>/dev/null; then
  TOTAL=1  # sadece schema var
fi

if [ "$TOTAL" -eq 0 ]; then
  echo "✓ Tüm nesneler zaten $OWNER kullanıcısına ait — işlem gerekmiyor."
  exit 0
fi

if $DRY_RUN; then
  echo "=== Dry-run SQL (${TOTAL} işlem) ==="
  cat "$SQL_FILE" | grep -v "^--" | grep -v "^$"
  echo ""
  echo "Gerçek devir için --dry-run parametresini kaldırın."
  exit 0
fi

# Gerçek uygulama
echo "=== Sahiplik devri uygulanıyor (${TOTAL} nesne) ==="
ERRORS=0
while IFS= read -r line; do
  [[ "$line" =~ ^ALTER ]] || continue
  if ! sudo -u postgres psql -d "$DB" -v ON_ERROR_STOP=1 -q -c "$line" 2>/dev/null; then
    echo "  ✗ Başarısız: $line"
    ERRORS=$((ERRORS + 1))
  fi
done < "$SQL_FILE"

echo ""
if [ "$ERRORS" -gt 0 ]; then
  echo "⚠ Kısmi başarı: $ERRORS/$TOTAL nesne devredilemedi."
  echo "  Başarısız olanları elle kontrol edin: sudo -u postgres psql -d $DB"
  exit 2
fi

echo "✓ Tüm nesneler ($TOTAL) $OWNER kullanıcısına devredildi."

# Doğrulama
echo ""
echo "=== Doğrulama ==="
REMAINING=$(sudo -u postgres psql -d "$DB" -Atc \
  "SELECT count(*) FROM pg_tables WHERE schemaname='public' AND tableowner <> '$OWNER';" 2>/dev/null || echo "?")
if [ "$REMAINING" = "0" ]; then
  echo "✓ 0 postgres sahipli tablo kaldı — tümü $OWNER'a devredildi."
else
  echo "⚠ $REMAINING tablo hâlâ $OWNER dışı sahibe ait."
fi

# ── --repair: onarım + verify ──
if $REPAIR; then
  echo ""
  echo "═══════════════════════════════════════════════════════════"
  echo " ONARIM + DOĞRULAMA"
  echo "═══════════════════════════════════════════════════════════"
  FAIL=0
  ok()   { echo "  ✓ $1"; }
  fail() { echo "  ✗ $1"; FAIL=$((FAIL+1)); }

  echo ""
  echo "════ Onarım (--repair) ════"
  REPAIR_OUT=$("$PHP_BIN" scripts/health-check.php --repair --backup-schema --yes --orphans 2>&1 || true)
  REPAIR_DROPPED=$(echo "$REPAIR_OUT" | grep -c "düşürüldü\|yeniden kuruldu" || true)
  REPAIR_ORPHANS=$(echo "$REPAIR_OUT" | grep -c "temizlendi\|silindi" || true)
  REPAIR_FAILED=$(echo "$REPAIR_OUT" | grep -c "✗" || true)
  [ "$REPAIR_DROPPED" -gt 0 ] && ok "$REPAIR_DROPPED tablo onarıldı"
  [ "$REPAIR_ORPHANS" -gt 0 ] && ok "$REPAIR_ORPHANS yetim temizlendi"
  [ "$REPAIR_DROPPED" -eq 0 ] && [ "$REPAIR_ORPHANS" -eq 0 ] && ok "Onarım gerektirecek sorun yok"
  [ "$REPAIR_FAILED" -gt 0 ] && fail "$REPAIR_FAILED onarım hatası"

  echo ""
  echo "════ verify-platform ════"
  VP_OUT=$("$PHP_BIN" scripts/verify-platform.php 2>&1 || true)
  VP_FAIL=$(echo "$VP_OUT" | grep -c "✗" || true)
  VP_PASS=$(echo "$VP_OUT" | grep -c "✓" || true)
  [ "$VP_FAIL" -eq 0 ] && ok "verify-platform: $VP_PASS başarılı"
  [ "$VP_FAIL" -gt 0 ] && fail "verify-platform: $VP_FAIL eksik kolon"

  echo ""
  echo "════ SONUÇ ════"
  if [ "$FAIL" -eq 0 ]; then
    ok "TÜM ADIMLAR BAŞARILI — $DB güncel ve sağlam"
  else
    fail "$FAIL hata kaldı — yukarıdaki ✗ satırlarına bakın"
  fi
  exit $FAIL
fi
