#!/bin/bash
# webhook-test-curl.sh — Gerçek webhook test yükünü DB'den otomatik oluşturup gönderir.
#
# Kullanım:
#   bash scripts/webhook-test-curl.sh
#   bash scripts/webhook-test-curl.sh --dry-run    (payload'ı göster, gönderme)
#   bash scripts/webhook-test-curl.sh --code=OTA-STD --price=185.50 --currency=EUR

set -euo pipefail
DRY_RUN=false
OVERRIDE_CODE=""
OVERRIDE_PRICE=""
OVERRIDE_CURRENCY=""
for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=true ;;
        --code=*) OVERRIDE_CODE="${arg#*=}" ;;
        --price=*) OVERRIDE_PRICE="${arg#*=}" ;;
        --currency=*) OVERRIDE_CURRENCY="${arg#*=}" ;;
    esac
done

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PHP="/opt/plesk/php/8.5/bin/php"
[ ! -x "$PHP" ] && PHP="php"

# DB'den değerleri oku ve eval ile shell'e aktar.
VALUES=$("$PHP" "$SCRIPT_DIR/_webhook-db-values.php" 2>/dev/null)
eval "$VALUES"

# Overrides.
[ -n "$OVERRIDE_CODE" ] && room_code="$OVERRIDE_CODE"
[ -n "$OVERRIDE_PRICE" ] && price="$OVERRIDE_PRICE"
[ -n "$OVERRIDE_CURRENCY" ] && currency="$OVERRIDE_CURRENCY"

if [ -z "${token:-}" ] || [ -z "${room_code:-}" ]; then
    echo "✗ Değerler okunamadı"
    echo "$VALUES"
    exit 1
fi

price2=$(echo "$price * 1.05" | bc 2>/dev/null || echo "$price")

echo ""
echo "═══════════════════════════════════════════════════════════════"
echo "  🔬 WEBHOOK TEST YÜKÜ"
echo "═══════════════════════════════════════════════════════════════"
echo "  Kanal:     ${conn_name} (ID: ${conn_id})"
echo "  Ürün kodu: ${ext_property}"
echo "  Oda kodu:  ${room_code} (durum: ${room_status})"
echo "  Plan:      ${plan_name} (${plan_currency})"
echo "  Fiyat:     ${price} ${currency} × 2 gün"
echo "  Tarihler:  ${date1}, ${date2}"
echo "  Endpoint:  ${host}/api/channel-webhook?token=${token:0:10}…${token:30:4}"
echo "═══════════════════════════════════════════════════════════════"
echo ""

# JSON payload.
PAYLOAD=$(cat <<ENDJSON
{
    "scope": "rates",
    "external_property_id": "${ext_property}",
    "currency": "${currency}",
    "entries": [
        {"external_room_id": "${room_code}", "date": "${date1}", "price": ${price}},
        {"external_room_id": "${room_code}", "date": "${date2}", "price": ${price2}}
    ]
}
ENDJSON
)

echo "📤 Payload:"
echo "$PAYLOAD" | "$PHP" -r 'echo json_encode(json_decode(file_get_contents("php://input")), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);' 2>/dev/null || echo "$PAYLOAD"
echo ""

if $DRY_RUN; then
    echo "═══════════════════════════════════════════════════════════════"
    echo "  🏷️  DRY-RUN — curl komutu:"
    echo "═══════════════════════════════════════════════════════════════"
    echo ""
    echo "curl -s -X POST '${host}/api/channel-webhook?token=${token}' \\"
    echo "  -H 'Content-Type: application/json' \\"
    echo "  -d '${PAYLOAD}'"
    exit 0
fi

# Gönder.
echo "📤 Gönderiliyor..."
RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "${host}/api/channel-webhook?token=${token}" \
    -H "Content-Type: application/json" \
    -d "$PAYLOAD" 2>&1)

HTTP_CODE=$(echo "$RESPONSE" | tail -1)
BODY=$(echo "$RESPONSE" | head -n -1)

echo ""
echo "═══════════════════════════════════════════════════════════════"
echo "  📥 YANIT (HTTP ${HTTP_CODE})"
echo "═══════════════════════════════════════════════════════════════"
echo "$BODY" | "$PHP" -r 'echo json_encode(json_decode(file_get_contents("php://input")), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);' 2>/dev/null || echo "$BODY"
echo ""

# Son 3 kaydı doğrula.
"$PHP" -r '
require_once __DIR__."/../config/database.php";
$pdo = db();
$connId = (int)'${conn_id}';
$propId = (int)'${prop_id}';
$code   = "'${room_code}'";

echo "─── channel_sync_logs (son 3 rates) ───\n";
$rows = $pdo->prepare("SELECT id, status, applied_rows, error_message, created_at FROM channel_sync_logs WHERE channel_connection_id=? AND direction='\''pull'\'' AND scope='\''rates'\'' ORDER BY id DESC LIMIT 3");
$rows->execute([$connId]);
foreach ($rows->fetchAll() as $r) {
    $icon = $r["status"]==="success" ? "✅" : "✗ ";
    echo "  $icon #{$r['id']} · {$r['status']} · applied:".($r["applied_rows"]??"—")." · {$r['created_at']}\n";
    if ($r["error_message"]) echo "     hata: {$r['error_message']}\n";
}
echo "\n─── inventory_calendar (son 3) ───\n";
$cal = $pdo->prepare("SELECT i.stay_date, i.base_price, i.currency, rt.name room_name, rp.name plan_name FROM inventory_calendar i JOIN room_types rt ON rt.id=i.room_type_id JOIN rate_plans rp ON rp.id=i.rate_plan_id WHERE rt.property_id=? ORDER BY i.stay_date DESC LIMIT 3");
$cal->execute([$propId]);
foreach ($cal->fetchAll() as $c) {
    echo "  📅 {$c['stay_date']} · {$c['base_price']} {$c['currency']} · {$c['room_name']} · {$c['plan_name']}\n";
}
echo "\n─── channel_room_mappings ('$code') ───\n";
$mp = $pdo->prepare("SELECT m.status, rt.name room_name FROM channel_room_mappings m LEFT JOIN room_types rt ON rt.id=m.room_type_id WHERE m.channel_connection_id=? AND m.external_room_id=?");
$mp->execute([$connId, $code]);
$mr = $mp->fetch();
echo $mr ? "  ✅ {$code} → {$mr['room_name']} ({$mr['status']})\n" : "  ⚠ \"$code\" eşleştirmesi yok\n";
echo "\n─── fx_audit ───\n";
try {
    $fx = $pdo->prepare("SELECT fx_audit FROM channel_sync_logs WHERE channel_connection_id=? AND scope='\''rates'\'' AND fx_audit IS NOT NULL AND fx_audit<> '\''[]'\''::jsonb ORDER BY id DESC LIMIT 1");
    $fx->execute([$connId]);
    $fxData = json_decode((string)$fx->fetchColumn()?:"[]", true);
    if (!empty($fxData)) { foreach ($fxData as $f) echo "  💱 {$f['from']}→{$f['to']} @ kur {$f['rate']} · {$f['original_total']}→{$f['converted_total']}\n"; }
    else echo "  — kur dönüşümü yok\n";
} catch (Throwable $e) { echo "  — fx_audit tablosu yok\n"; }
' 2>/dev/null

echo ""
echo "═══════════════════════════════════════════════════════════════"
echo "  ✅ Test tamamlandı"
echo "═══════════════════════════════════════════════════════════════"
