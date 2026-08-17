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
        "SELECT id, scope, status, request_payload, response_payload, error_message, created_at, completed_at, fx_audit
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

    // Uygulanan/atlanan satır özeti: kodun geçtiği son 30 pull işlemindeki satır sayıları.
    // Kod onaylı bir eşleşmeye sahipse başarılı işlemlerdeki satırlar "uygulandı";
    // başarısız işlemlerdeki ve eşlenmemiş/öneri durumundaki satırlar "atlandı".
    $codeMapped = false;
    $mm = $pdo->prepare("SELECT 1 FROM channel_room_mappings WHERE channel_connection_id=? AND external_room_id=? AND status='confirmed'");
    $mm->execute([$connId, $code]);
    $codeMapped = (bool) $mm->fetchColumn();
    $sumQ = $pdo->prepare(
        "SELECT id, status, request_payload FROM channel_sync_logs
         WHERE channel_connection_id=? AND property_id=? AND direction='pull'
           AND request_payload->'entries' @> ?::jsonb
         ORDER BY id DESC LIMIT 30"
    );
    $sumQ->execute([$connId, $propId, $needle]);
    $appliedRows = 0;
    $skippedRows = 0;
    $logsSeen = 0;
    foreach ($sumQ->fetchAll() as $sl) {
        $pl = json_decode((string) $sl['request_payload'], true);
        if (!is_array($pl) || !is_array($pl['entries'] ?? null)) continue;
        $rowsInLog = 0;
        foreach ($pl['entries'] as $en) {
            if (is_array($en) && (string) ($en['external_room_id'] ?? '') === $code) $rowsInLog++;
        }
        if ($rowsInLog === 0) continue;
        $logsSeen++;
        if ($sl['status'] === 'success' && $codeMapped) {
            $appliedRows += $rowsInLog;
        } else {
            $skippedRows += $rowsInLog;
        }
    }

    // Kod bazında son 30 gün serisi: webhook kayıtlarından tarih -> en güncel fiyat/kontenjan.
    // Entry'lerin kendi date alanı esas alınır (bugün-30 .. bugün); aynı tarih birden çok
    // işlemde geldiyse en yeni işlemin değeri korunur (id ASC + üzerine yazma).
    $dateMin = date('Y-m-d', strtotime('-30 days'));
    $dateMax = date('Y-m-d');
    $seriesQ = $pdo->prepare(
        "SELECT request_payload FROM channel_sync_logs
         WHERE channel_connection_id=? AND property_id=? AND direction='pull'
           AND request_payload->'entries' @> ?::jsonb
         ORDER BY id ASC"
    );
    $seriesQ->execute([$connId, $propId, $needle]);
    $seriesMap = [];
    foreach ($seriesQ->fetchAll() as $sl) {
        $pl = json_decode((string) $sl['request_payload'], true);
        if (!is_array($pl) || !is_array($pl['entries'] ?? null)) continue;
        foreach ($pl['entries'] as $en) {
            if (!is_array($en) || (string) ($en['external_room_id'] ?? '') !== $code) continue;
            $d = (string) ($en['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || $d < $dateMin || $d > $dateMax) continue;
            if (!isset($seriesMap[$d])) $seriesMap[$d] = ['price' => null, 'allotment' => null];
            if (array_key_exists('price', $en)) $seriesMap[$d]['price'] = (float) $en['price'];
            if (array_key_exists('allotment', $en)) $seriesMap[$d]['allotment'] = (int) $en['allotment'];
        }
    }
    ksort($seriesMap);
    $seriesMap = array_slice($seriesMap, -30, 30, true);
    $seriesOut = [];
    foreach ($seriesMap as $d => $v) {
        $seriesOut[] = ['date' => $d, 'price' => $v['price'], 'allotment' => $v['allotment']];
    }

    // fx_audit'ten bu girdi için kullanılan kur ve dönüştürülmüş fiyat.
    // Çift satır bazlı özet yerine, doğrulanan girdinin kendi fiyatına uygulanan dönüşüm:
    // girdinin (veya yükün) birimi fx_audit'teki 'from' ile eşleştirilir, kur o girdiden alınır.
    $conversion = null;
    $rowFx = json_decode((string) ($row['fx_audit'] ?? '[]'), true);
    if (is_array($rowFx) && $entry !== null && array_key_exists('price', $entry)) {
        $entryPrice = (float) $entry['price'];
        $inCur = isset($entry['currency']) && is_string($entry['currency']) ? strtoupper($entry['currency']) : '';
        if ($inCur === '' && is_array($payload)) {
            $inCur = isset($payload['currency']) && is_string($payload['currency']) ? strtoupper($payload['currency']) : '';
        }
        foreach ($rowFx as $fx) {
            if (!is_array($fx)) continue;
            $fxFrom = strtoupper((string) ($fx['from'] ?? ''));
            if ($inCur !== '' && $fxFrom !== '' && $fxFrom !== $inCur) continue;
            $rate = (float) ($fx['rate'] ?? 0);
            if ($entryPrice > 0 && $rate > 0) {
                $conversion = [
                    'from' => $fxFrom,
                    'to' => strtoupper((string) ($fx['to'] ?? '')),
                    'rate' => $rate,
                    'original' => $entryPrice,
                    'converted' => round($entryPrice * $rate, 2),
                ];
            }
            break;
        }
    }

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
        'summary' => [
            'logs_seen' => $logsSeen,
            'applied_rows' => $appliedRows,
            'skipped_rows' => $skippedRows,
        ],
        'fx_audit' => json_decode((string) ($row['fx_audit'] ?? '[]'), true) ?: [],
        'conversion' => $conversion,
        'series' => $seriesOut,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => 'Doğrulama sırasında hata: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
