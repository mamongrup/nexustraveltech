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
    // Tedarikçinin "son N işlem" penceresi (dağıtım merkezindeki seen_codes_window ayarı; varsayılan 30, 5-500).
    $seenWindow = 30;
    try {
        $spq = $pdo->prepare('SELECT settings FROM suppliers WHERE id=?');
        $spq->execute([(int) $user['supplier_id']]);
        $sPrefs = json_decode((string) ($spq->fetchColumn() ?: '{}'), true);
        if (is_array($sPrefs)) $seenWindow = max(5, min(500, (int) ($sPrefs['seen_codes_window'] ?? 30)));
    } catch (Throwable $e) {}
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

    // Uygulanan/atlanan satır özeti: kodun geçtiği son N pull işlemindeki satır sayıları
    // (N = tedarikçinin seen_codes_window ayarı; varsayılan 30).
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
         ORDER BY id DESC LIMIT ?"
    );
    $sumQ->execute([$connId, $propId, $needle, $seenWindow]);
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
        "SELECT request_payload, fx_audit FROM channel_sync_logs
         WHERE channel_connection_id=? AND property_id=? AND direction='pull'
           AND request_payload->'entries' @> ?::jsonb
         ORDER BY id ASC"
    );
    $seriesQ->execute([$connId, $propId, $needle]);
    $seriesMap = [];
    $rateByDate = [];   // tarih -> kullanılan kur (rates_by_date; yoksa özet rate)
    $convToCur = '';    // dönüşüm hedef birimi (fx_audit 'to')
    foreach ($seriesQ->fetchAll() as $sl) {
        $pl = json_decode((string) $sl['request_payload'], true);
        if (!is_array($pl) || !is_array($pl['entries'] ?? null)) continue;
        // fx_audit: bu işlemde kullanılan kurlar (girdi bazlı kur kaydı — b6fa4e8).
        $fxA = json_decode((string) ($sl['fx_audit'] ?? '[]'), true);
        if (is_array($fxA)) {
            foreach ($fxA as $fx) {
                if (!is_array($fx)) continue;
                $rbd = (array) ($fx['rates_by_date'] ?? []);
                $to = strtoupper((string) ($fx['to'] ?? ''));
                if ($rbd !== []) {
                    foreach ($rbd as $rd => $rv) {
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $rd)) {
                            $rateByDate[(string) $rd] = (float) $rv;
                            if ($to !== '') $convToCur = $to;
                        }
                    }
                } elseif ((float) ($fx['rate'] ?? 0) > 0 && isset($fx['first_date'])) {
                    // Eski kayıt: tek kur, dönem başına uygulanır.
                    $rateByDate[(string) $fx['first_date']] = (float) $fx['rate'];
                    if ($to !== '') $convToCur = $to;
                }
            }
        }
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
    $defaultConvCur = ($planInfo['currency'] ?? $convToCur) !== '' ? ($planInfo['currency'] ?? $convToCur) : '';
    foreach ($seriesMap as $d => $v) {
        // Dönüştürülmüş fiyat: o tarihte kullanılan kur (girdi bazlı) ile orijinal fiyat.
        $converted = null;
        $convCur = $defaultConvCur;
        if ($v['price'] !== null && isset($rateByDate[$d])) {
            $converted = round($v['price'] * $rateByDate[$d], 2);
        }
        $seriesOut[] = [
            'date' => $d,
            'price' => $v['price'],
            'allotment' => $v['allotment'],
            'converted' => $converted,
            'conv_currency' => $convCur,
        ];
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

    // Hedef fiyat planı — webhook işleyicisinin kullandığı öncelikle aynı:
    // 1) girdinin external_rate_plan_id'si onaylı bir kanal plan eşleşmesine denk geliyorsa o plan,
    // 2) oda eşleştirmesinin rate_plan_id'si, 3) ilk aktif plan (geriye dönük varsayılan).
    $planInfo = null;
    $targetPlanId = 0;
    $planSource = '';
    $extPlanHint = $entry !== null && isset($entry['external_rate_plan_id']) && trim((string) $entry['external_rate_plan_id']) !== '' ? trim((string) $entry['external_rate_plan_id']) : '';
    if ($extPlanHint !== '') {
        $pm2 = $pdo->prepare("SELECT rate_plan_id FROM channel_rate_plan_mappings WHERE channel_connection_id=? AND external_rate_plan_id=? AND status='confirmed' AND rate_plan_id IS NOT NULL LIMIT 1");
        $pm2->execute([$connId, $extPlanHint]);
        $v = $pm2->fetchColumn();
        if ($v !== false && $v !== null) {
            $targetPlanId = (int) $v;
            $planSource = 'kanal planı eşleşmesi';
        }
    }
    if ($targetPlanId === 0) {
        $mm2 = $pdo->prepare('SELECT rate_plan_id FROM channel_room_mappings WHERE channel_connection_id=? AND external_room_id=? AND status=\'confirmed\' AND rate_plan_id IS NOT NULL LIMIT 1');
        $mm2->execute([$connId, $code]);
        $v = $mm2->fetchColumn();
        if ($v !== false && $v !== null) {
            $targetPlanId = (int) $v;
            $planSource = 'oda eşleştirmesi';
        }
    }
    if ($targetPlanId === 0) {
        $fp = $pdo->prepare("SELECT id FROM rate_plans WHERE property_id=? AND status='active' ORDER BY id LIMIT 1");
        $fp->execute([$propId]);
        $v = $fp->fetchColumn();
        if ($v !== false && $v !== null) {
            $targetPlanId = (int) $v;
            $planSource = 'ilk aktif plan';
        }
    }
    if ($targetPlanId > 0) {
        $pp = $pdo->prepare('SELECT id, name, currency FROM rate_plans WHERE id=?');
        $pp->execute([$targetPlanId]);
        $pr = $pp->fetch();
        if ($pr) {
            $planInfo = [
                'id' => (int) $pr['id'],
                'name' => (string) $pr['name'],
                'currency' => (string) ($pr['currency'] ?: 'EUR'),
                'source' => $planSource,
            ];
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
        'plan' => $planInfo,
        'series' => $seriesOut,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => 'Doğrulama sırasında hata: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
