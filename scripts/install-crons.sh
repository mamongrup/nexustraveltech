#!/usr/bin/env bash
#
# NEXUS zamanlayıcı nabzı kurulumu (idempotent).
# Eski 8 ayrı cron görevi kaldırılır, yerine TEK nabız eklenir:
#   * * * * * php .../cron/tick.php
# Görev tanımları ve zamanlamalar artık admin panelinden yönetilir:
#   /nexustraveltech/admin/timerlar
#
# Kullanım (sunucuda root olarak):
#   bash scripts/install-crons.sh
#
set -euo pipefail

BASE="${NEXUS_BASE:-/var/www/vhosts/nexustraveltech.com/httpdocs}"
[ -d "$BASE" ] || { echo "Dizin bulunamadı: $BASE (NEXUS_BASE ile ezebilirsiniz)" >&2; exit 1; }

PHP=""
for cand in /opt/plesk/php/8.5/bin/php /opt/plesk/php/8.2/bin/php /opt/plesk/php/8.1/bin/php; do
  [ -x "$cand" ] && PHP="$cand" && break
done
[ -z "$PHP" ] && PHP="$(command -v php || true)"
[ -z "$PHP" ] && { echo "PHP bulunamadı" >&2; exit 1; }
echo "PHP: $PHP"
echo "Kök: $BASE"

TICK_LINE="* * * * * $PHP $BASE/cron/tick.php >/dev/null 2>&1"
OLD_MARKERS="nexus-sync-ical nexus-revenue-rec nexus-netgsm-sms nexus-process-emails nexus-process-webhooks nexus-welcome-emails nexus-notification-digest nexus-expire-group-options"

CURRENT="$(crontab -l 2>/dev/null || true)"

# 1) Eski 8 görevi (marker + komut satırı çiftlerini) ve tick.php içeren bozuk satırları kaldır
FILTERED=""
REMOVE_NEXT=0
while IFS= read -r line || [ -n "$line" ]; do
  if [ "$REMOVE_NEXT" -eq 1 ]; then REMOVE_NEXT=0; continue; fi
  if [[ "$line" == \#\ * ]]; then
    marker="${line#\# }"
    if grep -qF "$marker" <<<"$OLD_MARKERS"; then REMOVE_NEXT=1; continue; fi
  fi
  # Bozuk/eski tick satırlarını da ayıkla (düzeltme her zaman yeniden yazılır)
  if grep -qF "cron/tick.php" <<<"$line"; then continue; fi
  FILTERED+="$line"$'\n'
done <<<"$CURRENT"

# 2) Nabız satırını her zaman temiz haliyle yaz (idempotent)
FILTERED+="# nexus-tick"$'\n'"$TICK_LINE"$'\n'
echo "kuruldu : $TICK_LINE"

# 3) crontab'a yaz
printf '%s' "$FILTERED" | crontab -
echo "crontab güncellendi."

echo "--- Mevcut NEXUS görevleri ---"
crontab -l 2>/dev/null | grep -B1 -A0 "nexus-tick" || true
echo "--- Kalan cron görevleri (varsa) ---"
crontab -l 2>/dev/null | grep -v "nexus-tick" | grep -v "^#" | grep -v "^$" || echo "(boş)"
