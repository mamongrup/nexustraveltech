#!/usr/bin/env bash
#
# NEXUS cron kurulum script'i — root crontab'a tüm görevleri idempotent ekler.
#
# Kullanım (sunucuda root olarak):
#   bash scripts/install-crons.sh
#
# Alternatif: Plesk → Websites & Domains → nexustraveltech.com → Scheduled Tasks
# (aynı komutları GUI'den de ekleyebilirsiniz; her iki yöntem de geçerlidir).
#
set -euo pipefail

BASE="${NEXUS_BASE:-/var/www/vhosts/nexustraveltech.com/httpdocs}"
[ -d "$BASE" ] || { echo "Dizin bulunamadı: $BASE (NEXUS_BASE ile ezebilirsiniz)" >&2; exit 1; }

PHP=""
for cand in /opt/plesk/php/8.5/bin/php /opt/plesk/php/8.2/bin/php /opt/plesk/php/8.1/bin/php; do
  [ -x "$cand" ] && PHP="$cand" && break
done
[ -z "$PHP" ] && PHP="$(command -v php || true)"
[ -z "$PHP" ] && { echo "PHP bulunamadı (NEXUS_PHP ile ezebilirsiniz)" >&2; exit 1; }
echo "PHP: $PHP"
echo "Kök: $BASE"

# marker -> crontab satırı
TASKS=(
  "nexus-sync-ical|*/15 * * * * $PHP $BASE/cron/sync-ical-calendars.php >/dev/null 2>&1"
  "nexus-revenue-rec|15 2 * * * $PHP $BASE/cron/generate-revenue-recommendations.php >/dev/null 2>&1"
  "nexus-netgsm-sms|* * * * * $PHP $BASE/cron/process-netgsm-sms.php >/dev/null 2>&1"
  "nexus-process-emails|*/5 * * * * $PHP $BASE/cron/process-emails.php >/dev/null 2>&1"
  "nexus-process-webhooks|*/1 * * * * $PHP $BASE/cron/process-webhooks.php >/dev/null 2>&1"
  "nexus-welcome-emails|0 8 * * * $PHP $BASE/cron/send-welcome-emails.php >/dev/null 2>&1"
  "nexus-notification-digest|15 9 * * * $PHP $BASE/cron/send-notification-digest.php >/dev/null 2>&1"
  "nexus-expire-group-options|30 3 * * * $PHP $BASE/cron/expire-group-options.php >/dev/null 2>&1"
)

CURRENT="$(crontab -l 2>/dev/null || true)"
CHANGED=0
for entry in "${TASKS[@]}"; do
  marker="${entry%%|*}"
  line="${entry#*|}"
  if grep -qF "# $marker" <<<"$CURRENT"; then
    echo "mevcut : $marker"
  else
    CURRENT="$(printf '%s\n# %s\n%s\n' "$CURRENT" "$marker" "$line")"
    CHANGED=1
    echo "eklendi: $marker"
  fi
done

if [ "$CHANGED" -eq 1 ]; then
  printf '%s\n' "$CURRENT" | crontab -
  echo "crontab güncellendi."
else
  echo "Tüm görevler zaten kurulu."
fi

echo "--- Mevcut NEXUS görevleri ---"
crontab -l 2>/dev/null | grep -B1 "nexus-" || true
