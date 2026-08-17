#!/bin/bash
# NEXUS — bekleyen migration'ları postgres kullanıcısıyla toplu uygular.
#
# Neden: migration dosyaları app DB kullanıcısıyla koşarken "must be owner of table"
# hatasıyla başarısız oluyorsa (eski tablolar farklı sahibe ait), bu betik aynı
# dosyaları postgres (superuser) olarak uygular; ardından tüm public şema
# sahipliğini app kullanıcısına devreder ve schema_migrations'a kaydeder.
#
# Kullanım (root olarak):
#   bash scripts/apply-migrations-postgres.sh
#
# Dosyalar idempotent (IF NOT EXISTS) olduğu için daha önce elle uygulanmış
# olanlar (örn. 019/020/050/051) güvenle yeniden koşulur ve kayda geçer.

set -e
cd "$(dirname "$0")/.." || exit 1

APP_DB_USER=$(grep -oP "'db_user'\s*=>\s*'\K[^']+" config/secrets.php)
APP_DB_NAME=$(grep -oP "'db_name'\s*=>\s*'\K[^']+" config/secrets.php)
APP_DB_NAME=${APP_DB_NAME:-nexus_traveltech}
GIT_HASH=$(git rev-parse HEAD 2>/dev/null || echo "")
echo "App DB: $APP_DB_USER @ $APP_DB_NAME | commit: ${GIT_HASH:0:7}"

echo
echo "=== 1) schema_migrations hazırlığı ==="
sudo -u postgres psql -d "$APP_DB_NAME" -q -c "CREATE TABLE IF NOT EXISTS schema_migrations (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, file VARCHAR(190) NOT NULL UNIQUE, applied_at TIMESTAMPTZ NOT NULL DEFAULT now()); ALTER TABLE schema_migrations ADD COLUMN IF NOT EXISTS commit_hash CHAR(40);"

echo
echo "=== 2) Bekleyen migration'lar (postgres olarak) ==="
APPLIED=$(sudo -u postgres psql -d "$APP_DB_NAME" -Atc "SELECT file FROM schema_migrations" 2>/dev/null || echo "")
OK=0; FAIL=0; FAILED_FILES=""
for f in database/migrations/*.sql; do
  [ -f "$f" ] || continue
  base=$(basename "$f")
  if printf '%s\n' "$APPLIED" | grep -qx "$base"; then
    echo "· atlandı (kayıtlı): $base"
    continue
  fi
  # @APP_DB_USER@ yer tutucusunu secrets'tan okunan app kullanıcısıyla değiştir
  # (GRANT + ALTER ... OWNER satırları — sahiplikten bağımsız çalışma).
  MIG_TMP=$(mktemp)
  sed "s/@APP_DB_USER@/$APP_DB_USER/g" "$f" > "$MIG_TMP"
  if sudo -u postgres psql -d "$APP_DB_NAME" -v ON_ERROR_STOP=1 -q -f "$MIG_TMP" >/dev/null 2>&1; then
    OK=$((OK+1))
    echo "✓ $base"
    if [ -n "$GIT_HASH" ]; then COMMIT_VAL="'$GIT_HASH'"; else COMMIT_VAL='NULL'; fi
    sudo -u postgres psql -d "$APP_DB_NAME" -q -c "INSERT INTO schema_migrations(file, commit_hash) VALUES('$base', $COMMIT_VAL) ON CONFLICT(file) DO NOTHING;" >/dev/null 2>&1 || true
  else
    FAIL=$((FAIL+1)); FAILED_FILES="$FAILED_FILES $base"
    echo "✗ $base — hata (atlandı, sonrakiyle devam; tam hata için: sudo -u postgres psql -d $APP_DB_NAME -v ON_ERROR_STOP=1 -f database/migrations/$base)"
  fi
  rm -f "$MIG_TMP"
done
echo "Uygulanan: $OK · Başarısız: $FAIL${FAILED_FILES:+ → $FAILED_FILES}"

echo
echo "=== 3) Sahiplik devri (tüm public şema → app kullanıcısı) ==="
sudo -u postgres psql -d "$APP_DB_NAME" -v app_user="$APP_DB_USER" <<'SQL'
DO $do$
DECLARE r RECORD;
BEGIN
  FOR r IN SELECT tablename FROM pg_tables WHERE schemaname='public' LOOP
    BEGIN
      EXECUTE format('ALTER TABLE public.%I OWNER TO %I', r.tablename, :'app_user');
    EXCEPTION WHEN OTHERS THEN RAISE NOTICE 'tablo atlandı: %', r.tablename;
    END;
  END LOOP;
  FOR r IN SELECT sequence_name FROM information_schema.sequences WHERE sequence_schema='public' LOOP
    BEGIN
      EXECUTE format('ALTER SEQUENCE public.%I OWNER TO %I', r.sequence_name, :'app_user');
    EXCEPTION WHEN OTHERS THEN RAISE NOTICE 'seq atlandı: %', r.sequence_name;
    END;
  END LOOP;
  BEGIN
    EXECUTE format('ALTER SCHEMA public OWNER TO %I', :'app_user');
  EXCEPTION WHEN OTHERS THEN RAISE NOTICE 'schema atlandı';
  END;
END
$do$;
SQL
sudo -u postgres psql -d "$APP_DB_NAME" -c "SELECT tableowner, count(*) FROM pg_tables WHERE schemaname='public' GROUP BY tableowner ORDER BY 2 DESC;"

echo
echo "=== 4) Doğrulama (health-check kuru) ==="
PHP_BIN=$(command -v /opt/plesk/php/8.5/bin/php || command -v php)
"$PHP_BIN" scripts/health-check.php --dry-run | tail -1 || true

echo
echo "=== BİTTİ — üstte '✗' satırı ve postgres sahipli tablo kalmadıysa sunucu sağlıklı ==="
