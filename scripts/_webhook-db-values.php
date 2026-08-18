<?php
declare(strict_types=1);
/**
 * _webhook-db-values.php — DB'den webhook test değerlerini key=value çıktısı olarak üretir.
 * Shell script'ler tarafından çağrılır: eval $(php scripts/_webhook-db-values.php)
 */
require_once __DIR__ . '/../config/database.php';
$pdo = db();

$conn = $pdo->query("SELECT id, access_token, display_name, channel_code FROM channel_connections WHERE status='active' ORDER BY id LIMIT 1")->fetch();
if (!$conn) { echo "error='Aktif kanal bağlantısı yok'\n"; exit(1); }

$pm = $pdo->prepare("SELECT property_id, external_property_id FROM channel_property_mappings WHERE channel_connection_id=? ORDER BY id LIMIT 1");
$pm->execute([(int)$conn['id']]);
$propMap = $pm->fetch();
$propId = (int)($propMap['property_id'] ?? 0);
$extProp = (string)($propMap['external_property_id'] ?? '');

// Oda kodu: eşleşmemiş kodu tercih et (denetim kaydından).
$roomCode = '';
$roomStatus = 'unmatched';
$logQ = $pdo->prepare("SELECT request_payload FROM channel_sync_logs WHERE channel_connection_id=? AND direction='pull' AND request_payload IS NOT NULL ORDER BY id DESC LIMIT 10");
$logQ->execute([(int)$conn['id']]);
foreach ($logQ->fetchAll() as $lr) {
    $dec = json_decode((string)$lr['request_payload'] ?: '{}', true);
    if (!isset($dec['entries']) || !is_array($dec['entries'])) continue;
    foreach ($dec['entries'] as $en) {
        if (!isset($en['external_room_id'])) continue;
        $code = trim((string)$en['external_room_id']);
        $mp = $pdo->prepare("SELECT status FROM channel_room_mappings WHERE channel_connection_id=? AND property_id=? AND external_room_id=?");
        $mp->execute([(int)$conn['id'], $propId, $code]);
        $row = $mp->fetch();
        if (!$row || $row['status'] !== 'confirmed') {
            $roomCode = $code;
            $roomStatus = $row ? $row['status'] : 'unmatched';
            break 2;
        }
    }
}
// Eşleşen kod.
if ($roomCode === '') {
    $rm = $pdo->prepare("SELECT external_room_id FROM channel_room_mappings WHERE channel_connection_id=? AND property_id=? AND status='confirmed' ORDER BY id LIMIT 1");
    $rm->execute([(int)$conn['id'], $propId]);
    $roomCode = (string)($rm->fetchColumn() ?: '');
}

$plan = $pdo->prepare("SELECT id, name, currency FROM rate_plans WHERE property_id=? AND status='active' ORDER BY id LIMIT 1");
$plan->execute([$propId]);
$planRow = $plan->fetch();
$planId = $planRow ? (int)$planRow['id'] : 0;
$planName = $planRow ? (string)$planRow['name'] : '';
$planCur = $planRow ? strtoupper((string)$planRow['currency']) : 'EUR';

$esc = fn(string $v) => "'" . str_replace("'", "'\\''", $v) . "'";
$escQ = fn(string $v) => '"' . str_replace('"', '\\"', $v) . '"';

echo "token=" . $esc($conn['access_token']) . "\n";
echo "conn_id=" . (int)$conn['id'] . "\n";
echo "conn_name=" . $esc($conn['display_name']) . "\n";
echo "conn_code=" . $esc($conn['channel_code']) . "\n";
echo "ext_property=" . $esc($extProp) . "\n";
echo "property_id=" . $propId . "\n";
echo "room_code=" . $esc($roomCode) . "\n";
echo "room_status=" . $esc($roomStatus) . "\n";
echo "plan_id=" . $planId . "\n";
echo "plan_name=" . $esc($planName) . "\n";
echo "plan_currency=" . $esc($planCur) . "\n";
echo "price=185.50\n";
echo "price2=194.78\n";
echo "currency=EUR\n";
echo "date1=" . date('Y-m-d', strtotime('+30 days')) . "\n";
echo "date2=" . date('Y-m-d', strtotime('+31 days')) . "\n";
echo "host=https://nexustraveltech.com\n";
