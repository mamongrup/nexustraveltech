#!/bin/bash
# NEXUS — tüm public şemadaki nesnelerin sahipliğini secrets.php'deki db_user'a devreder.
# Kullanıcı/DB adı elle yazılmaz: config/secrets.php'den okunur.
# Kapsam: tablolar + diziler (sequence) + görünümler. Sahip zaten doğruysa no-op'dur.
#
# Kullanım (root olarak):
#   bash scripts/transfer-ownership.sh
# Tek satır (kopyala-yapıştır) için aşağıdaki komut:
#   cd "$(pwd)" && OWNER="$(/opt/plesk/php/8.5/bin/php -r '$c=require "config/secrets.php"; echo $c["db_user"] ?? "app";')" && DB="$(/opt/plesk/php/8.5/bin/php -r '$c=require "config/secrets.php"; echo $c["db_name"] ?? "nexus_traveltech";')" && sudo -u postgres psql -d "$DB" -v ON_ERROR_STOP=1 -v owner="$OWNER" -c "SELECT format('ALTER TABLE %I OWNER TO %I', tablename, :'owner') FROM pg_tables WHERE schemaname='public' ORDER BY tablename \gexec" -c "SELECT format('ALTER SEQUENCE %I OWNER TO %I', sequencename, :'owner') FROM pg_sequences WHERE schemaname='public' ORDER BY sequencename \gexec" -c "SELECT format('ALTER VIEW %I OWNER TO %I', viewname, :'owner') FROM pg_views WHERE schemaname='public' ORDER BY viewname \gexec" && echo "OK"

set -e
cd "$(dirname "$0")/.." || exit 1

PHP_BIN=$(command -v /opt/plesk/php/8.5/bin/php || command -v php || echo php)
DB="$("$PHP_BIN" -r '$c=require "config/secrets.php"; echo $c["db_name"] ?? "nexus_traveltech";')"
OWNER="$("$PHP_BIN" -r '$c=require "config/secrets.php"; echo $c["db_user"] ?? "app";')"
echo "→ DB: $DB · Sahip: $OWNER"

sudo -u postgres psql -d "$DB" -v ON_ERROR_STOP=1 -v owner="$OWNER" \
  -c "SELECT format('ALTER TABLE %I OWNER TO %I', tablename, :'owner') FROM pg_tables WHERE schemaname='public' ORDER BY tablename \gexec" \
  -c "SELECT format('ALTER SEQUENCE %I OWNER TO %I', sequencename, :'owner') FROM pg_sequences WHERE schemaname='public' ORDER BY sequencename \gexec" \
  -c "SELECT format('ALTER VIEW %I OWNER TO %I', viewname, :'owner') FROM pg_views WHERE schemaname='public' ORDER BY viewname \gexec"

echo "✓ Tüm nesneler $OWNER kullanıcısına devredildi"
