#!/bin/bash
# Tüm migration'ları tek sorguyla listeler: uygulanmış/bekleyen, commit_hash ile.
# Kullanım: bash scripts/list-migrations.sh [--json]

set -e
cd "$(dirname "$0")/.." || exit 1

APP_DB_NAME=$(grep -oP "'db_name'\s*=>\s*'\K[^']+" config/secrets.php)
APP_DB_NAME=${APP_DB_NAME:-nexus_traveltech}
JSON_MODE=false
[ "$1" = "--json" ] && JSON_MODE=true

# Tüm dosyaları listele
MIG_DIR="database/migrations"
ALL_FILES=$(ls "$MIG_DIR"/*.sql 2>/dev/null | xargs -I{} basename {} | sort)

# DB'deki uygulanmış migration'ları çek
APPLIED_RAW=$(sudo -u postgres psql -d "$APP_DB_NAME" -Atc \
    "SELECT file, COALESCE(commit_hash,''), COALESCE(applied_at::text,'') FROM schema_migrations ORDER BY file" 2>/dev/null || echo "")

declare -A COMMIT_MAP
declare -A DATE_MAP
while IFS='|' read -r file commit date; do
    [ -z "$file" ] && continue
    COMMIT_MAP["$file"]="$commit"
    DATE_MAP["$file"]="$date"
done <<< "$APPLIED_RAW"

TOTAL=$(echo "$ALL_FILES" | wc -l | tr -d ' ')
APPLIED_COUNT=0
PENDING_COUNT=0

# JSON toplama dizisi
JSON_ITEMS=""

for f in $ALL_FILES; do
    NUM=$(echo "$f" | grep -oP '^\d+')
    COMMIT_GIT=$(git log --all --oneline -- "database/migrations/$f" 2>/dev/null | head -1 | awk '{print $1}')
    COMMIT_DB="${COMMIT_MAP[$f]:-}"
    DATE_DB="${DATE_MAP[$f]:-}"

    if [ -n "$COMMIT_DB" ]; then
        STATUS="applied"
        APPLIED_COUNT=$((APPLIED_COUNT+1))
        COMMIT_SHOW="${COMMIT_DB}"
        DATE_SHOW="${DATE_DB}"
    else
        STATUS="pending"
        PENDING_COUNT=$((PENDING_COUNT+1))
        COMMIT_SHOW="${COMMIT_GIT:-—}"
        DATE_SHOW="—"
    fi

    if $JSON_MODE; then
        JSON_ITEMS="${JSON_ITEMS}    {\"file\":\"$f\",\"status\":\"$STATUS\",\"commit_db\":\"$COMMIT_DB\",\"commit_git\":\"$COMMIT_GIT\",\"applied_at\":\"$DATE_DB\"},\n"
    else
        printf "%-4s %-8s %-46s %-12s %s\n" "$NUM" "$STATUS" "$f" "$COMMIT_SHOW" "$DATE_SHOW"
    fi
done

if $JSON_MODE; then
    # Son virgülü temizle
    JSON_ITEMS=$(echo -e "$JSON_ITEMS" | sed '$ s/,$//')
    echo "{"
    echo "  \"database\": \"$APP_DB_NAME\","
    echo "  \"total_files\": $TOTAL,"
    echo "  \"applied\": $APPLIED_COUNT,"
    echo "  \"pending\": $PENDING_COUNT,"
    echo "  \"migrations\": ["
    echo -e "$JSON_ITEMS"
    echo "  ]"
    echo "}"
else
    echo
    echo "Toplam: $TOTAL dosya · Uygulanan: $APPLIED_COUNT · Bekleyen: $PENDING_COUNT"
    if [ "$PENDING_COUNT" -gt 0 ]; then
        echo
        echo "Bekleyen migration'ları uygulamak için:"
        echo "  sudo bash scripts/apply-migrations-postgres.sh"
    fi
fi
