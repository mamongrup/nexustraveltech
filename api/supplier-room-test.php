<?php
declare(strict_types=1);

// Dağıtım merkezi → oda eşleştirmesi → "Test et" butonu.
// Seçili kanal dış oda kodu için eşleştirme zincirini webhook işleyicisiyle
// aynı mantıkla doğrular: eşleşme var mı, hedef oda tipi/plan aktif mi,
// kur dönüşümü gerekiyorsa kur mevcut mu? Sonuç anında JSON döner.

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

    // İlanın aktif oda tipleri ve fiyat planları (webhook işleyicisiyle aynı kaynak).
    $rooms = $pdo->prepare("SELECT id, name, status FROM room_types WHERE property_id=? ORDER BY id");
    $rooms->execute([$propId]);
    $roomList = $rooms->fetchAll();
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
    $roomById = [];
    $firstActiveRoom = null;
    foreach ($roomList as $rl) {
        $roomById[(int) $rl['id']] = $rl;
        if (($rl['status'] ?? 'active') === 'active' && $firstActiveRoom === null) {
            $firstActiveRoom = $rl;
        }
    }

    // Örnek webhook yükü — "Test et" sonucunda kopyalanabilir gösterilir (rates kapsamı,
    // bu dış kod + iki örnek tarih; dış ürün kodu eşleştirmeden, birim seçiciden alınır).
    $ext = '';
    $pmSt = $pdo->prepare('SELECT external_property_id FROM channel_property_mappings WHERE channel_connection_id=? AND property_id=? LIMIT 1');
    $pmSt->execute([$connId, $propId]);
    if ($pmSt->fetch()) $ext = (string) $pmSt->fetchColumn();
    $sampleCur = strtoupper(trim((string) ($_GET['currency'] ?? '')));
    $sample = [
        'scope' => 'rates',
        'external_property_id' => $ext !== '' ? $ext : 'KANAL-OTEL-KODU',
        'entries' => [
            ['external_room_id' => $code, 'date' => date('Y-m-d', strtotime('+30 days')), 'price' => 185.50],
            ['external_room_id' => $code, 'date' => date('Y-m-d', strtotime('+31 days')), 'price' => 195.00],
        ],
    ];
    if (preg_match('/^[A-Z]{3}$/', $sampleCur)) $sample['currency'] = $sampleCur;
    $sampleText = json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    // Eşleştirme: bu dış kod hangi NEXUS oda tipine + plana bağlı?
    $map = $pdo->prepare('SELECT room_type_id, rate_plan_id, status, suggestion_score, suggestion_count FROM channel_room_mappings WHERE channel_connection_id=? AND property_id=? AND external_room_id=?');
    $map->execute([$connId, $propId, $code]);
    $m = $map->fetch();
    $issues = [];

    if (!$m) {
        // Kod hiç eşleştirilmemiş: ayar açıksa öneri oluşur (veri yazılmaz), kapalıysa ilk aktif oda tipine yazılır.
        $autoMap = (bool) platform_setting('channel_webhook_auto_map', true);
        if (!$roomList) {
            echo json_encode(['ok' => false, 'message' => 'İlanda aktif oda/birim tipi yok — önce ilan düzenleyiciden ekleyin.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($autoMap) {
            echo json_encode([
                'ok' => true,
                'mapped' => false,
                'ready' => false,
                'status' => 'unmapped',
                'message' => 'Bu kod henüz hiçbir oda tipine eşleştirilmedi. Webhook geldiğinde onay bekleyen öneri olarak kaydedilir ve veri YAZILMAZ — aşağıdaki formdan bir oda tipine yazıp kaydedin veya öneriyi yukarıdan onaylayın.',
                'room' => null,
                'plan' => null,
                'sample' => $sample,
                'sampleText' => $sampleText,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // Ayar kapalı (eski davranış): ilk aktif oda tipine yazılır, kalıcı eşleştirme yok.
        echo json_encode([
            'ok' => true,
            'mapped' => false,
            'ready' => true,
            'status' => 'fallback',
            'message' => 'Eşleştirme yok; kontrol merkezi ayarı kapalı olduğu için webhook veriyi ilk aktif oda tipine ("' . htmlspecialchars((string) ($firstActiveRoom['name'] ?? '#')) . '") yazar. Kalıcı kontrol için bu kod bir oda tipine eşleştirin.',
            'room' => ['id' => (int) ($firstActiveRoom['id'] ?? 0), 'name' => (string) ($firstActiveRoom['name'] ?? '')],
            'plan' => $firstActivePlan ? ['id' => (int) $firstActivePlan['id'], 'name' => (string) $firstActivePlan['name'], 'currency' => (string) $firstActivePlan['currency']] : null,
            'sample' => $sample,
            'sampleText' => $sampleText,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $status = (string) ($m['status'] ?? 'confirmed');
    if ($status === 'suggested') {
        $target = (int) $m['room_type_id'];
        $targetName = (string) ($roomById[$target]['name'] ?? ('#' . $target));
        $score = $m['suggestion_score'] !== null ? (int) $m['suggestion_score'] : null;
        echo json_encode([
            'ok' => true,
            'mapped' => true,
            'ready' => false,
            'status' => 'suggested',
            'message' => 'Bu kod ' . $targetName . ' için onay bekleyen öneri durumunda' . ($score !== null ? ' (%' . $score . ' benzerlik)' : '') . ' — veri onaylanana kadar YAZILMAZ. Öneriyi onaylayın veya kodu başka oda tipine yazıp kaydedin.',
            'room' => ['id' => $target, 'name' => $targetName],
            'plan' => null,
            'sample' => $sample,
            'sampleText' => $sampleText,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // confirmed eşleşme: hedef oda tipi ve plan doğrula.
    $roomId = (int) $m['room_type_id'];
    $room = $roomById[$roomId] ?? null;
    if (!$room) {
        $issues[] = 'Hedef oda tipi (#'.$roomId.') silinmiş — eşleştirmeyi yeniden kurun.';
    } elseif (($room['status'] ?? 'active') !== 'active') {
        $issues[] = 'Hedef oda tipi "' . $room['name'] . '" durumu: ' . $room['status'] . ' (aktif değil).';
    }

    $planId = $m['rate_plan_id'] !== null ? (int) $m['rate_plan_id'] : 0;
    $plan = $planId > 0 ? ($planById[$planId] ?? null) : $firstActivePlan;
    if ($planId > 0 && !$plan) {
        $issues[] = 'Eşleştirmedeki fiyat planı (#'.$planId.') silinmiş — plan seçimini yeniden yapın.';
    } elseif ($plan && ($plan['status'] ?? 'active') !== 'active') {
        $issues[] = 'Fiyat planı "' . $plan['name'] . '" durumu: ' . $plan['status'] . ' (aktif değil).';
    }

    // Kur kontrolü: webhook fiyatı farklı birimde gelirse dönüşüm gerekir.
    $payloadCur = strtoupper(trim((string) ($_GET['currency'] ?? '')));
    $planCur = $plan ? strtoupper((string) $plan['currency']) : '';
    $fxNote = null;
    if ($plan && preg_match('/^[A-Z]{3}$/', $planCur) && $payloadCur !== '' && $payloadCur !== $planCur) {
        $fxq = $pdo->prepare('SELECT rate, rate_date FROM fx_rates WHERE base_currency=? AND quote_currency=? ORDER BY rate_date DESC LIMIT 1');
        $fxq->execute([$payloadCur, $planCur]);
        $fxRow = $fxq->fetch();
        if (!$fxRow) {
            $issues[] = 'Kur yok: ' . $payloadCur . '→' . $planCur . ' (webhook ' . $payloadCur . ' fiyatı gönderirse bu satır yazılmaz — Kur yönetimi → hızlı giriş).';
            $fxNote = 'eksik: ' . $payloadCur . '→' . $planCur;
        } else {
            $fxNote = $payloadCur . '→' . $planCur . ' @ ' . rtrim(rtrim(number_format((float) $fxRow['rate'], 6, '.', ''), '0'), '.') . ' (' . $fxRow['rate_date'] . ')';
        }
    }

    $ready = $issues === [];
    echo json_encode([
        'ok' => true,
        'mapped' => true,
        'ready' => $ready,
        'status' => 'confirmed',
        'message' => $ready
            ? '✓ Eşleşme hazır: "' . $code . '" → ' . htmlspecialchars((string) ($room['name'] ?? '#')) . ' (' . ($plan ? htmlspecialchars((string) $plan['name']) . ' · ' . $planCur : 'ilk aktif plan') . '). Webhook verisi doğru oda tipine ve plana yazılır.' . ($fxNote ? ' Kur: ' . $fxNote . '.' : '')
            : '⚠ ' . implode(' ', $issues),
        'room' => $room ? ['id' => (int) $room['id'], 'name' => (string) $room['name']] : null,
        'plan' => $plan ? ['id' => (int) $plan['id'], 'name' => (string) $plan['name'], 'currency' => $planCur] : null,
        'fx' => $fxNote,
        'issues' => $issues,
        'sample' => $sample,
        'sampleText' => $sampleText,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => 'Test sırasında hata: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
