<?php
declare(strict_types=1);

// Dağıtım merkezi → oda eşleştirmesi → "Test yükü gönder" butonu.
// Seçili kanal dış oda kodu için OTOMATİK örnek webhook yükü (rates kapsamı,
// bugün+60 gün, 123.45 fiyat) oluşturur, kuyruğa ekler ve işleyiciyle AYNI
// kodu (channel_webhook_apply) çalıştırır. Sonuç anında JSON döner:
// log durumu, takvim yazımı (fiyat + birim), kur dönüşümü/fx_audit.
// Kanalın gerçek tokenı gerekmez — satırın channel_connection_id'si yeterlidir.

require_once __DIR__ . '/../config/supplier_auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/channel_webhook.php';
require_once __DIR__ . '/../config/platform_settings.php';

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

    // Yetki: kanal ve ürün bu tedarikçiye ait olmalı.
    $check = $pdo->prepare("SELECT id FROM channel_connections WHERE id=? AND supplier_id=? AND status='active'");
    $check->execute([$connId, (int) $user['supplier_id']]);
    if (!$check->fetch()) {
        echo json_encode(['ok' => false, 'message' => 'Kanal bulunamadı veya aktif değil.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $check = $pdo->prepare('SELECT id FROM properties WHERE id=? AND supplier_id=?');
    $check->execute([$propId, (int) $user['supplier_id']]);
    if (!$check->fetch()) {
        echo json_encode(['ok' => false, 'message' => 'Ürün bulunamadı.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Dış ürün kodu — eşleştirmeden (varsa) alınır; yoksa yük ürün kodu içermez.
    $ext = '';
    $pm = $pdo->prepare('SELECT external_property_id FROM channel_property_mappings WHERE channel_connection_id=? AND property_id=? LIMIT 1');
    $pm->execute([$connId, $propId]);
    $pmRow = $pm->fetch();
    if ($pmRow) $ext = (string) ($pmRow['external_property_id'] ?? '');

    $testDate = date('Y-m-d', strtotime('+60 days'));
    $price = 123.45;
    $currency = strtoupper(trim((string) ($_GET['currency'] ?? '')));
    if (!preg_match('/^[A-Z]{3}$/', $currency)) $currency = 'EUR';

    $payload = [
        'scope' => 'rates',
        'external_property_id' => $ext,
        'currency' => $currency,
        'entries' => [
            ['external_room_id' => $code, 'date' => $testDate, 'price' => $price],
        ],
    ];

    // Kuyruğa ekle (api/channel-webhook ile aynı INSERT deseni).
    $pdo->prepare("INSERT INTO channel_sync_logs(channel_connection_id, property_id, direction, scope, status, request_payload, response_payload) VALUES(?,?, 'pull', ?, 'queued', ?::jsonb, ?::jsonb)")
        ->execute([$connId, $propId, 'rates', json_encode($payload, JSON_UNESCAPED_UNICODE), json_encode(['received_at' => gmdate('c')])]);
    $logId = (int) $pdo->lastInsertId();

    // İşleyiciyle aynı kod: satır içi uygula (cron araya girmişse beklenen sonucu kullan).
    $pdo->prepare("UPDATE channel_sync_logs SET status='running', attempt_count=attempt_count+1 WHERE id=?")->execute([$logId]);
    $job = $pdo->prepare('SELECT * FROM channel_sync_logs WHERE id=?');
    $job->execute([$logId]);
    $jobRow = $job->fetch();
    $res = channel_webhook_apply($jobRow, $payload);

    $hasFxAudit = (bool) $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='channel_sync_logs' AND column_name='fx_audit'")->fetchColumn();

    if ($res['ok']) {
        if ($hasFxAudit) {
            $pdo->prepare("UPDATE channel_sync_logs SET status='success', response_payload=?::jsonb, fx_audit=?::jsonb, error_message=NULL, completed_at=now() WHERE id=?")
                ->execute([json_encode(['applied' => $res['applied'], 'message' => $res['message']]), json_encode($res['fx_audit'] ?? []), $logId]);
        } else {
            $pdo->prepare("UPDATE channel_sync_logs SET status='success', response_payload=?::jsonb, error_message=NULL, completed_at=now() WHERE id=?")
                ->execute([json_encode(['applied' => $res['applied'], 'message' => $res['message']]), $logId]);
        }
        $out = [
            'ok' => true,
            'log_id' => $logId,
            'message' => (string) $res['message'],
            'applied' => (int) $res['applied'],
            'fx_audit' => array_values((array) ($res['fx_audit'] ?? [])),
        ];

        // Takvim yazımını doğrula (uygulanan satır varsa) — hangi plana yazıldığını göster.
        $mapRow = $pdo->prepare('SELECT room_type_id, rate_plan_id FROM channel_room_mappings WHERE channel_connection_id=? AND property_id=? AND external_room_id=?');
        $mapRow->execute([$connId, $propId, $code]);
        $m = $mapRow->fetch();
        if ($m && (int) $m['room_type_id'] > 0) {
            $roomId = (int) $m['room_type_id'];
            $planId = (int) $m['rate_plan_id'];
            if ($planId > 0) {
                $inv = $pdo->prepare('SELECT base_price FROM inventory_calendar WHERE room_type_id=? AND rate_plan_id=? AND stay_date=?');
                $inv->execute([$roomId, $planId, $testDate]);
                $priceRow = $inv->fetchColumn();
                $planInfo = $pdo->prepare('SELECT name, currency FROM rate_plans WHERE id=?');
                $planInfo->execute([$planId]);
                $plan = $planInfo->fetch();
                $out['calendar'] = [
                    'date' => $testDate,
                    'room_type_id' => $roomId,
                    'rate_plan_id' => $planId,
                    'plan_name' => (string) ($plan['name'] ?? ''),
                    'currency' => (string) ($plan['currency'] ?? ''),
                    'base_price' => $priceRow !== false ? (float) $priceRow : null,
                    'sent_price' => $price,
                    'sent_currency' => $currency,
                ];
            }
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
    } else {
        $errMsg = (string) $res['message'] . (isset($res['errors']) ? ' [' . implode(',', array_slice($res['errors'], 0, 4)) . ']' : '');
        if ($hasFxAudit) {
            $pdo->prepare("UPDATE channel_sync_logs SET status='failed', error_message=?, response_payload=?::jsonb, fx_audit=?::jsonb, completed_at=now() WHERE id=?")
                ->execute([mb_substr($errMsg, 0, 1000), json_encode(['message' => $res['message']]), json_encode($res['fx_audit'] ?? []), $logId]);
        } else {
            $pdo->prepare("UPDATE channel_sync_logs SET status='failed', error_message=?, response_payload=?::jsonb, completed_at=now() WHERE id=?")
                ->execute([mb_substr($errMsg, 0, 1000), json_encode(['message' => $res['message']]), $logId]);
        }
        echo json_encode([
            'ok' => false,
            'log_id' => $logId,
            'message' => $errMsg,
            'fx_audit' => array_values((array) ($res['fx_audit'] ?? [])),
        ], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => 'Test yükü gönderilirken hata: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
