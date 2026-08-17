<?php
declare(strict_types=1);
// Kanal entegrasyon webhook'u: OTA/acente/metasearch sistemlerinin NEXUS'a
// bildirim (stok/fiyat/kısıt/rezervasyon değişikliği) gönderebildiği uç nokta.
// Kimlik doğrulama: channel_connections.access_token (64 hex, migration 044).
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../config/mailer.php';

$token = (string)($_GET['token'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    exit;
}
$pdo = db();
$q = $pdo->prepare('SELECT id, supplier_id, channel_code, display_name, status FROM channel_connections WHERE access_token=?');
$q->execute([$token]);
$conn = $q->fetch();
if (!$conn) {
    http_response_code(404);
    exit;
}
if ($conn['status'] !== 'active') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Bağlantı aktif değil.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    // Kanal tarafının bağlantı denetimi için hafif sağlık yanıtı.
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'channel' => $conn['channel_code']]);
    exit;
}
if ($method !== 'POST') {
    http_response_code(405);
    exit;
}

$raw  = (string)file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = ['raw' => $raw];
}
$scope = (string)($data['scope'] ?? '');
if (!in_array($scope, ['availability', 'rates', 'restrictions', 'reservations', 'content'], true)) {
    $scope = 'content';
}
$propertyExt = (string)($data['external_property_id'] ?? '');
$propertyId  = null;
if ($propertyExt !== '') {
    $pm = $pdo->prepare('SELECT property_id FROM channel_property_mappings WHERE channel_connection_id=? AND external_property_id=?');
    $pm->execute([$conn['id'], $propertyExt]);
    $map = $pm->fetch();
    if ($map) {
        $propertyId = (int)$map['property_id'];
    }
}
// Bildirimi kuyruğa işleme kaydı olarak ekle — pull yönlü, gerçek veri aktarımı
// (fiyat/kontenjan uygulaması) cron/process-channel-webhooks.php işleyicisinde yapılır.
// Örnek yük (scope=rates/availability/restrictions/reservations):
// {"scope":"rates","external_property_id":"OTA-123","entries":[{"external_room_id":"OTA-DELUXE","date":"2026-09-01","price":185.50}]}
// Oda eşleştirmesi channel_room_mappings (migration 045) üzerinden yapılır; eşleşme yoksa
// ilanın ilk aktif oda tipine yazılır. Bildirim iki aşamalıdır: burada kuyruğa eklenir,
// işleyici 1 dakika içinde uygular ve channel_sync_logs'a sonucu yazar.
$pdo->prepare("INSERT INTO channel_sync_logs(channel_connection_id, property_id, direction, scope, status, request_payload, response_payload) VALUES(?,?, 'pull', ?, 'queued', ?::jsonb, ?::jsonb)")
    ->execute([$conn['id'], $propertyId, $scope, json_encode($data), json_encode(['received_at' => gmdate('c')])]);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'queued' => true, 'scope' => $scope]);
