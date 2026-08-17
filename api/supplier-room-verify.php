<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/supplier_auth.php';
require_once __DIR__ . '/../config/database.php';

$user = require_supplier();
header('Content-Type: application/json; charset=utf-8');

try {
    $connId = (int) ($_GET['connection_id'] ?? 0);
    $propId = (int) ($_GET['property_id'] ?? 0);
    $code = trim((string) ($_GET['code'] ?? ''));
    if ($connId <= 0 || $propId <= 0 || $code === '') {
        echo json_encode(['ok' => false, 'message' => 'Eksik parametre.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo = db();
    $check = $pdo->prepare('SELECT id FROM channel_connections WHERE id=? AND supplier_id=?');
    $check->execute([$connId, (int) $user['supplier_id']]);
    if (!$check->fetch()) {
        echo json_encode(['ok' => false, 'message' => 'Kanal yetkisi bulunamadı.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $check = $pdo->prepare('SELECT id FROM properties WHERE id=? AND supplier_id=?');
    $check->execute([$propId, (int) $user['supplier_id']]);
    if (!$check->fetch()) {
        echo json_encode(['ok' => false, 'message' => 'Ürün yetkisi bulunamadı.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // entries dizisinde external_room_id eşleşen son pull kaydı (JSONB içerik eşleşmesi).
    $needle = json_encode([['external_room_id' => $code]], JSON_UNESCAPED_UNICODE);
    $q = $pdo->prepare(
        "SELECT id, scope, status, request_payload, response_payload, error_message, created_at, completed_at
         FROM channel_sync_logs
         WHERE channel_connection_id=? AND property_id=? AND direction='pull'
           AND request_payload->'entries' @> ?::jsonb
         ORDER BY id DESC LIMIT 1"
    );
    $q->execute([$connId, $propId, $needle]);
    $row = $q->fetch();
    if (!$row) {
        echo json_encode(['ok' => true, 'found' => false, 'message' => 'Bu kod için kanaldan gelen webhook kaydı bulunamadı.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $payload = json_decode((string) $row['request_payload'], true);
    $entry = null;
    if (is_array($payload) && is_array($payload['entries'] ?? null)) {
        foreach ($payload['entries'] as $en) {
            if (is_array($en) && (string) ($en['external_room_id'] ?? '') === $code) {
                $entry = $en;
                break;
            }
        }
    }
    $resp = json_decode((string) ($row['response_payload'] ?? '{}'), true);
    $resp = is_array($resp) ? $resp : [];

    echo json_encode([
        'ok' => true,
        'found' => true,
        'log' => [
            'id' => (int) $row['id'],
            'scope' => (string) $row['scope'],
            'status' => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
            'completed_at' => $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
            'error_message' => $row['error_message'] !== null ? (string) $row['error_message'] : null,
            'applied' => (int) ($resp['applied'] ?? 0),
        ],
        'entry' => $entry,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => 'Doğrulama sırasında hata: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
