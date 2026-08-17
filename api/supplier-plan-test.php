<?php
declare(strict_types=1);

// Dağıtım merkezi → fiyat planı eşleştirmesi → "Test et" butonu.
// Dış plan kodunun (external_rate_plan_id) doğru NEXUS fiyat planına yazılıp
// yazılmayacağını webhook işleyicisiyle aynı öncelikle doğrular: eşleşme var mı,
// hedef plan aktif mi? Sonuç anında JSON döner; gerçek veri YAZILMAZ (kuru test).

require_once __DIR__ . '/../config/supplier_auth.php';
require_once __DIR__ . '/../config/database.php';
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

    // İlanın fiyat planları (webhook işleyicisiyle aynı kaynak).
    $plans = $pdo->prepare("SELECT id, name, currency, status FROM rate_plans WHERE property_id=? ORDER BY id");
    $plans->execute([$propId]);
    $planRows = $plans->fetchAll();
    $planById = [];
    $firstActivePlan = null;
    foreach ($planRows as $pr) {
        $planById[(int) $pr['id']] = $pr;
        if (($pr['status'] ?? 'active') === 'active' && $firstActivePlan === null) {
            $firstActivePlan = $pr;
        }
    }

    // Örnek webhook yükü — "Test et" sonucunda kopyalanabilir gösterilir (rates kapsamı,
    // bu dış plan kodu + örnek oda kodu; dış ürün kodu eşleştirmeden alınır).
    $ext = '';
    $pmSt = $pdo->prepare('SELECT external_property_id FROM channel_property_mappings WHERE channel_connection_id=? AND property_id=? LIMIT 1');
    $pmSt->execute([$connId, $propId]);
    if ($pmSt->fetch()) $ext = (string) $pmSt->fetchColumn();
    $sample = [
        'scope' => 'rates',
        'external_property_id' => $ext !== '' ? $ext : 'KANAL-OTEL-KODU',
        'entries' => [
            ['external_room_id' => 'OTA-ROOM', 'external_rate_plan_id' => $code, 'date' => date('Y-m-d', strtotime('+30 days')), 'price' => 185.50],
            ['external_room_id' => 'OTA-ROOM', 'external_rate_plan_id' => $code, 'date' => date('Y-m-d', strtotime('+31 days')), 'price' => 195.00],
        ],
    ];
    $sampleText = json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    // Eşleştirme: bu dış plan kodu hangi NEXUS planına bağlı?
    $map = $pdo->prepare('SELECT rate_plan_id, status, suggestion_score, suggestion_count FROM channel_rate_plan_mappings WHERE channel_connection_id=? AND property_id=? AND external_rate_plan_id=?');
    $map->execute([$connId, $propId, $code]);
    $m = $map->fetch();
    $issues = [];

    if (!$m) {
        echo json_encode([
            'ok' => true,
            'mapped' => false,
            'ready' => false,
            'status' => 'unmapped',
            'message' => 'Bu dış plan kodu hiçbir NEXUS fiyat planına eşleştirilmedi. Webhook geldiğinde onay bekleyen öneri olarak kaydedilir ve veri YAZILMAZ — aşağıdaki formdan bir plana yazıp kaydedin veya öneriyi yukarıdan onaylayın.',
            'plan' => null,
            'issues' => $issues,
            'sample' => $sample,
            'sampleText' => $sampleText,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $status = (string) ($m['status'] ?? 'confirmed');
    if ($status === 'suggested') {
        $target = (int) $m['rate_plan_id'];
        $targetName = (string) ($planById[$target]['name'] ?? ('#' . $target));
        $score = $m['suggestion_score'] !== null ? (int) $m['suggestion_score'] : null;
        echo json_encode([
            'ok' => true,
            'mapped' => true,
            'ready' => false,
            'status' => 'suggested',
            'message' => 'Bu dış plan kodu "' . $code . '" → ' . $targetName . ' için onay bekleyen öneri durumunda' . ($score !== null ? ' (%' . $score . ' benzerlik)' : '') . ' — veri onaylanana kadar YAZILMAZ. Öneriyi onaylayın veya kodu başka bir plana yazıp kaydedin.',
            'plan' => isset($planById[$target]) ? ['id' => (int) $planById[$target]['id'], 'name' => (string) $planById[$target]['name'], 'currency' => strtoupper((string) ($planById[$target]['currency'] ?? 'EUR'))] : null,
            'issues' => $issues,
            'sample' => $sample,
            'sampleText' => $sampleText,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // confirmed eşleşme: hedef planı doğrula.
    $planId = $m['rate_plan_id'] !== null ? (int) $m['rate_plan_id'] : 0;
    $plan = $planId > 0 ? ($planById[$planId] ?? null) : null;
    if ($planId > 0 && !$plan) {
        $issues[] = 'Eşleştirmedeki fiyat planı (#' . $planId . ') silinmiş — plan seçimini yeniden yapın.';
    } elseif ($plan && ($plan['status'] ?? 'active') !== 'active') {
        $issues[] = 'Fiyat planı "' . $plan['name'] . '" durumu: ' . $plan['status'] . ' (aktif değil).';
    }

    $ready = $issues === [];
    echo json_encode([
        'ok' => true,
        'mapped' => true,
        'ready' => $ready,
        'status' => 'confirmed',
        'message' => $ready
            ? '✓ Dış plan kodu "' . $code . '" → NEXUS planı "' . htmlspecialchars((string) ($plan['name'] ?? '#')) . '" (' . strtoupper((string) ($plan['currency'] ?? 'EUR')) . '). Webhook fiyat/kontenjanı doğru plana yazılır.'
            : '⚠ ' . implode(' ', $issues),
        'plan' => $plan ? ['id' => (int) $plan['id'], 'name' => (string) $plan['name'], 'currency' => strtoupper((string) ($plan['currency'] ?? 'EUR'))] : null,
        'issues' => $issues,
        'sample' => $sample,
        'sampleText' => $sampleText,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => 'Test sırasında hata: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
