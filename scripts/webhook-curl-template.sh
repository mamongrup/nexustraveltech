#!/bin/bash
# webhook-curl-template.sh — DB'den gerçek değerleri çekip kopyalanabilir curl komutları üretir.
#
# Kullanım:
#   bash scripts/webhook-curl-template.sh
#   bash scripts/webhook-curl-template.sh --unmatched

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PHP="/opt/plesk/php/8.5/bin/php"
[ ! -x "$PHP" ] && PHP="php"

VALUES=$("$PHP" "$SCRIPT_DIR/_webhook-db-values.php" 2>/dev/null)
eval "$VALUES"

if [ -z "${token:-}" ]; then
    echo "✗ Değerler okunamadı"
    exit 1
fi

TOKEN_SHORT="${token:0:10}…${token:30:4}"

cat <<ENDOUT
# ═══════════════════════════════════════════════════════════
# 🔬 WEBHOOK TEST — Kopyalayıp çalıştırın
# ═══════════════════════════════════════════════════════════
# Kanal:     ${conn_name} (${conn_code})
# Ürün kodu: ${ext_property}
# Oda kodu:  ${room_code} (durum: ${room_status})
# Plan:      ${plan_name} (${plan_currency})
# Fiyat:     ${price} EUR × 2 gün
# Tarihler:  ${date1}, ${date2}
# Token:     ${TOKEN_SHORT}
# ═══════════════════════════════════════════════════════════

# ─── 1. RATES: Fiyat/kontenjan bildirimi ───
curl -s -X POST '${host}/api/channel-webhook?token=${token}' \
  -H 'Content-Type: application/json' \
  -d '{
    "scope": "rates",
    "external_property_id": "${ext_property}",
    "currency": "${currency}",
    "entries": [
      {"external_room_id": "${room_code}", "date": "${date1}", "price": ${price}},
      {"external_room_id": "${room_code}", "date": "${date2}", "price": 194.78}
    ]
  }' | python3 -m json.tool

# ─── 2. RESTRICTIONS: Kısıt bildirimi ───
curl -s -X POST '${host}/api/channel-webhook?token=${token}' \
  -H 'Content-Type: application/json' \
  -d '{
    "scope": "restrictions",
    "external_property_id": "${ext_property}",
    "entries": [
      {"external_room_id": "${room_code}", "date": "${date1}", "stop_sale": false, "min_stay": 2, "max_stay": 14}
    ]
  }' | python3 -m json.tool

# ─── 3. AVAILABILITY: Kontenjan bildirimi ───
curl -s -X POST '${host}/api/channel-webhook?token=${token}' \
  -H 'Content-Type: application/json' \
  -d '{
    "scope": "availability",
    "external_property_id": "${ext_property}",
    "entries": [
      {"external_room_id": "${room_code}", "date": "${date1}", "allotment": 5},
      {"external_room_id": "${room_code}", "date": "${date2}", "allotment": 3}
    ]
  }' | python3 -m json.tool

# ─── 4. RESERVATIONS: Rezervasyon bildirimi ───
curl -s -X POST '${host}/api/channel-webhook?token=${token}' \
  -H 'Content-Type: application/json' \
  -d '{
    "scope": "reservations",
    "external_property_id": "${ext_property}",
    "entries": [
      {"external_room_id": "${room_code}", "date": "${date1}", "qty": 1, "reservation_id": "TEST-$(date +%s)"}
    ]
  }' | python3 -m json.tool

# ─── 5. Bilinmeyen kod testi (öneri akışı) ───
curl -s -X POST '${host}/api/channel-webhook?token=${token}' \
  -H 'Content-Type: application/json' \
  -d '{
    "scope": "rates",
    "external_property_id": "${ext_property}",
    "currency": "EUR",
    "entries": [
      {"external_room_id": "OTA-UNKNOWN-$(date +%s)", "date": "${date1}", "price": 99.99}
    ]
  }' | python3 -m json.tool

# ─── 6. Kur dönüşümü testi (EUR→${plan_currency}) ───
curl -s -X POST '${host}/api/channel-webhook?token=${token}' \
  -H 'Content-Type: application/json' \
  -d '{
    "scope": "rates",
    "external_property_id": "${ext_property}",
    "currency": "EUR",
    "entries": [
      {"external_room_id": "${room_code}", "date": "${date1}", "price": 250.00}
    ]
  }' | python3 -m json.tool

echo ""
echo "─── Sonuçları doğrulamak için: ───"
echo "php scripts/webhook-e2e-verify.php"
echo "php scripts/webhook-e2e-verify.php --json"
ENDOUT
