<?php
declare(strict_types=1);
/**
 * webhook-e2e-verify.php — Uçtan uca webhook doğrulama betiği.
 *
 * Tek komutla 3 tabloyu ilişkilendirir:
 *   1. Webhook yükü oluştur → channel_sync_logs'a yaz
 *   2. channel_webhook_apply() ile işle
 *   3. inventory_calendar'daki değişikliği doğrula
 *   4. channel_room_mappings eşleştirmesini doğrula
 *   5. fx_audit (kur dönüşümü) varsa onu da doğrula
 *
 * Kullanım:
 *   php scripts/webhook-e2e-verify.php
 *   php scripts/webhook-e2e-verify.php --connection=1 --property=2 --code=OTA-STD --price=185.50 --currency=EUR
 *   php scripts/webhook-e2e-verify.php --json   (makinece okunabilir çıktı)
 *   php scripts/webhook-e2e-verify.php --dry-run (yükü göndermeden eşleştirme zincirini doğrula)
 *
 * Ön koşullar:
 *   - channel_connections tablosunda aktif bir bağlantı olmalı
 *   - channel_property_mappings tablosunda ürün eşleştirmesi olmalı
 *   - channel_room_mappings tablosunda oda eşleştirmesi olmalı (veya --dry-run)
 *   - Geçerli bir ileri tarih (bugün+1 .. bugün+90)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/channel_webhook.php';
require_once __DIR__ . '/../config/platform_settings.php';
// CLI modunda supplier_auth atlanır (session gerekmez).

// Parametreleri ayrıştır.
$isJson = in_array('--json', $argv, true);
$isDryRun = in_array('--dry-run', $argv, true);
$connId = 0;
$propId = 0;
$roomCode = '';
$price = 185.50;
$currency = 'EUR';
$date = date('Y-m-d', strtotime('+30 days'));
$supplierId = 0;
$isCLI = php_sapi_name() === 'cli';

foreach ($argv as $arg) {
    if (preg_match('/^--connection=(\d+)$/', $arg, $m)) $connId = (int) $m[1];
    if (preg_match('/^--property=(\d+)$/', $arg, $m)) $propId = (int) $m[1];
    if (preg_match('/^--code=(.+)$/', $arg, $m)) $roomCode = $m[1];
    if (preg_match('/^--price=(\d+\.?\d*)$/', $arg, $m)) $price = (float) $m[1];
    if (preg_match('/^--currency=([A-Z]{3})$/', $arg, $m)) $currency = $m[1];
    if (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) $date = $m[1];
}

// Otomatik algılama: parametre verilmemişse ilk aktif bağlantıyı bul.
try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($connId <= 0) {
        $first = $pdo->query("SELECT c.id, c.supplier_id, c.display_name, c.channel_code FROM channel_connections c WHERE c.status='active' ORDER BY c.id LIMIT 1")->fetch();
        if (!$first) {
            $json ? fwrite(STDERR, json_encode(['ok' => false, 'error' => 'Aktif kanal bağlantısı yok'], JSON_UNESCAPED_UNICODE) . "\n") : fwrite(STDERR, "✗ Aktif kanal bağlantısı yok — önce dağıtım merkezinde bir bağlantı ekleyin.\n");
            exit(1);
        }
        $connId = (int) $first['id'];
        $supplierId = (int) $first['supplier_id'];
    }
    if ($propId <= 0) {
        $pm = $pdo->prepare("SELECT property_id FROM channel_property_mappings WHERE channel_connection_id=? ORDER BY id LIMIT 1");
        $pm->execute([$connId]);
        $propId = (int) ($pm->fetchColumn() ?: 0);
    }
    if ($propId <= 0) {
        // Ürün eşleştirmesi yoksa ilk üründen dene.
        $firstProp = $pdo->query("SELECT id FROM properties WHERE supplier_id=(SELECT supplier_id FROM channel_connections WHERE id=$connId) ORDER BY id LIMIT 1")->fetch();
        $propId = $firstProp ? (int) $firstProp['id'] : 0;
    }
    if ($supplierId <= 0) {
        $sid = $pdo->query("SELECT supplier_id FROM channel_connections WHERE id=$connId")->fetchColumn();
        $supplierId = (int) ($sid ?: 0);
    }
    if ($roomCode === '') {
        $rm = $pdo->prepare("SELECT external_room_id FROM channel_room_mappings WHERE channel_connection_id=? AND property_id=? AND status='confirmed' ORDER BY id LIMIT 1");
        $rm->execute([$connId, $propId]);
        $roomCode = (string) ($rm->fetchColumn() ?: '');
    }
    // Oda kodu hâlâ boşsa ilk webhook kaydından dene.
    if ($roomCode === '') {
        $lr = $pdo->prepare("SELECT request_payload FROM channel_sync_logs WHERE channel_connection_id=? AND direction='pull' AND request_payload IS NOT NULL ORDER BY id DESC LIMIT 1");
        $lr->execute([$connId]);
        $lp = json_decode((string) ($lr->fetchColumn() ?: '{}'), true);
        if (isset($lp['entries'][0]['external_room_id'])) $roomCode = (string) $lp['entries'][0]['external_room_id'];
    }
} catch (Throwable $e) {
    $json ? fwrite(STDERR, json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n") : fwrite(STDERR, "✗ Hata: " . $e->getMessage() . "\n");
    exit(1);
}

if ($connId <= 0 || $propId <= 0 || $roomCode === '') {
    $json ? fwrite(STDERR, json_encode(['ok' => false, 'error' => 'Parametre eksik: --connection --property --code gereklidir veya otomatik algılama başarısız'], JSON_UNESCAPED_UNICODE) . "\n") : fwrite(STDERR, "✗ Parametre eksik: --connection --property --code gereklidir.\n");
    exit(1);
}

// Adım numaraları ve sonuçları.
$steps = [];
$allPass = true;
$connName = '';
$propName = '';
$roomName = '';
$planName = '';

function step(string $id, string $label, bool $pass, string $detail, array $extra = []): void
{
    global $steps, $allPass;
    if (!$pass) $allPass = false;
    $steps[] = array_merge(['id' => $id, 'label' => $label, 'pass' => $pass, 'detail' => $detail], $extra);
}

// ═══════════════════════════════════════════════════════════════
// ÖN KONTROLLER
// ═══════════════════════════════════════════════════════════════
$preErrors = [];

// Tablo varlığı.
$tables = ['channel_connections', 'channel_property_mappings', 'channel_room_mappings', 'channel_sync_logs', 'inventory_calendar'];
$existingTables = [];
foreach ($tables as $t) {
    $has = (bool) $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='$t'")->fetchColumn();
    $existingTables[$t] = $has;
    if (!$has) $preErrors[] = "Tablo yok: $t";
}
step('pre-tables', 'Tablo varlığı', $preErrors === [], $preErrors === [] ? implode(', ', $tables) : implode('; ', $preErrors));

// Bağlantı bilgisi.
try {
    $ci = $pdo->query("SELECT display_name, channel_code FROM channel_connections WHERE id=$connId")->fetch();
    $connName = $ci ? (string) $ci['display_name'] : "#$connId";
} catch (Throwable $e) { $connName = "#$connId"; }
try {
    $pi = $pdo->query("SELECT name FROM properties WHERE id=$propId")->fetch();
    $propName = $pi ? (string) $pi['name'] : "#$propId";
} catch (Throwable $e) { $propName = "#$propId"; }

step('pre-context', 'Bağlantı bilgisi', true, "Kanal: $connName (#$connId) · Ürün: $propName (#$propId) · Kod: $roomCode");

// ═══════════════════════════════════════════════════════════════
// ADIM 1: EŞLEŞTİRME ZİNCİRİNİ DOĞRULA
// ═══════════════════════════════════════════════════════════════
try {
    // channel_property_mappings
    $pmQ = $pdo->prepare("SELECT external_property_id FROM channel_property_mappings WHERE channel_connection_id=? AND property_id=?");
    $pmQ->execute([$connId, $propId]);
    $extProp = (string) ($pmQ->fetchColumn() ?: '');
    $step1a = $extProp !== '';
    step('map-property', 'Ürün eşleştirmesi', $step1a, $step1a ? "external_property_id: $extProp" : 'Ürün eşleştirmesi yok — ürün kodu tanımsız');

    // channel_room_mappings
    $rmQ = $pdo->prepare("SELECT m.room_type_id, m.rate_plan_id, m.status, rt.name room_name FROM channel_room_mappings m LEFT JOIN room_types rt ON rt.id=m.room_type_id WHERE m.channel_connection_id=? AND m.property_id=? AND m.external_room_id=?");
    $rmQ->execute([$connId, $propId, $roomCode]);
    $rm = $rmQ->fetch();
    if (!$rm) {
        step('map-room', 'Oda eşleştirmesi', false, "\"$roomCode\" hiçbir oda tipine eşleştirilmemiş — eşleştirme tablosunda kayıp");
    } else {
        $roomName = (string) ($rm['room_name'] ?? ('#' . $rm['room_type_id']));
        $roomStatus = (string) ($rm['status'] ?? 'unknown');
        $planId = $rm['rate_plan_id'] !== null ? (int) $rm['rate_plan_id'] : 0;
        $step1b = $roomStatus === 'confirmed';
        step('map-room', 'Oda eşleştirmesi', $step1b, "\"$roomCode\" → $roomName (durum: $roomStatus" . ($step1b ? '' : ' — veri yazılmaz!') . ')');

        // Fiyat planı eşleştirmesi.
        if ($planId > 0) {
            $plQ = $pdo->prepare("SELECT name, currency FROM rate_plans WHERE id=?");
            $plQ->execute([$planId]);
            $pl = $plQ->fetch();
            $planName = $pl ? (string) $pl['name'] : "#$planId";
            $planCur = $pl ? strtoupper((string) $pl['currency']) : '';
            step('map-plan', 'Fiyat planı eşleştirmesi', true, "Hedef plan: $planName ($planCur)");
        } else {
            $fallback = $pdo->prepare("SELECT name, currency FROM rate_plans WHERE property_id=? AND status='active' ORDER BY id LIMIT 1");
            $fallback->execute([$propId]);
            $fb = $fallback->fetch();
            $planName = $fb ? (string) $fb['name'] : 'yok';
            $planCur = $fb ? strtoupper((string) $fb['currency']) : '';
            step('map-plan', 'Fiyat planı (varsayılan)', true, "Eşleştirmede plan yok — ilk aktif plan kullanılacak: $planName ($planCur)");
        }

        // Kur kontrolü.
        if (strtoupper($currency) !== '' && strtoupper($currency) !== $planCur && $planCur !== '') {
            $fxQ = $pdo->prepare("SELECT rate, rate_date FROM fx_rates WHERE base_currency=? AND quote_currency=? ORDER BY rate_date DESC LIMIT 1");
            $fxQ->execute([strtoupper($currency), $planCur]);
            $fx = $fxQ->fetch();
            $step1c = (bool) $fx;
            $fxDetail = $fx ? strtoupper($currency) . '→' . $planCur . ' @ ' . number_format((float) $fx['rate'], 4) . ' (' . $fx['rate_date'] . ')' : strtoupper($currency) . '→' . $planCur . ' KURU YOK — fiyat dönüşümsüz yazılır';
            step('map-fx', 'Kur dönüşümü', $step1c, $fxDetail);
        } elseif (strtoupper($currency) === $planCur || $planCur === '') {
            step('map-fx', 'Kur dönüşümü', true, 'Birimler eşleşiyor veya plan birimi tanımsız — dönüşüm gerekmiyor');
        }
    }
} catch (Throwable $e) {
    step('map-chain', 'Eşleştirme zinciri', false, 'Hata: ' . $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════
// ADIM 2: WEBHOOK YÜKÜ GÖNDER (dry-run değilse)
// ═══════════════════════════════════════════════════════════════
$logId = 0;
$res = null;
if (!$isDryRun && $existingTables['channel_sync_logs']) {
    try {
        $payload = [
            'scope' => 'rates',
            'currency' => strtoupper($currency),
            'entries' => [
                ['external_room_id' => $roomCode, 'date' => $date, 'price' => $price],
                ['external_room_id' => $roomCode, 'date' => date('Y-m-d', strtotime($date . ' +1 day')), 'price' => $price * 1.05],
            ],
        ];

        // Sync log'a yaz.
        $ins = $pdo->prepare("INSERT INTO channel_sync_logs(channel_connection_id, property_id, direction, scope, status, request_payload, response_payload) VALUES(?, ?, 'pull', 'rates', 'queued', ?::jsonb, ?::jsonb)");
        $ins->execute([$connId, $propId, json_encode($payload, JSON_UNESCAPED_UNICODE), json_encode(['received_at' => gmdate('c'), 'source' => 'e2e-verify'], JSON_UNESCAPED_UNICODE)]);
        $logId = (int) $pdo->lastInsertId();

        // İşle.
        $pdo->prepare("UPDATE channel_sync_logs SET status='running', attempt_count=attempt_count+1 WHERE id=?")->execute([$logId]);
        $jobRow = $pdo->query("SELECT * FROM channel_sync_logs WHERE id=$logId")->fetch();
        $res = channel_webhook_apply($jobRow, $payload);

        // Sonucu güncelle.
        if ($res['ok']) {
            $hasFxCol = (bool) $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='channel_sync_logs' AND column_name='fx_audit'")->fetchColumn();
            if ($hasFxCol) {
                $pdo->prepare("UPDATE channel_sync_logs SET status='success', response_payload=?::jsonb, fx_audit=?::jsonb, failure_category=NULL, error_message=NULL, completed_at=now() WHERE id=?")
                    ->execute([json_encode(['applied' => $res['applied'], 'message' => $res['message']], JSON_UNESCAPED_UNICODE), json_encode($res['fx_audit'] ?? [], JSON_UNESCAPED_UNICODE), $logId]);
            } else {
                $pdo->prepare("UPDATE channel_sync_logs SET status='success', response_payload=?::jsonb, failure_category=NULL, error_message=NULL, completed_at=now() WHERE id=?")
                    ->execute([json_encode(['applied' => $res['applied'], 'message' => $res['message']], JSON_UNESCAPED_UNICODE), $logId]);
            }
        } else {
            $errMsg = (string) $res['message'] . (isset($res['errors']) ? ' [' . implode(',', array_slice($res['errors'], 0, 4)) . ']' : '');
            $failCat = (string) ($res['failure_category'] ?? 'transient');
            $pdo->prepare("UPDATE channel_sync_logs SET status='failed', failure_category=?, error_message=?, response_payload=?::jsonb, completed_at=now() WHERE id=?")
                ->execute([$failCat, mb_substr($errMsg, 0, 1000), json_encode(['message' => $res['message']], JSON_UNESCAPED_UNICODE), $logId]);
        }

        step('webhook-send', 'Webhook yüklendi', true, "#$logId · " . ($res['ok'] ? 'başarılı' : 'başarısız') . ' · ' . ($res['message'] ?? ''));
        step('webhook-result', 'Webhook işlendi', $res['ok'], (int) $res['applied'] . ' satır uygulandı — ' . ($res['message'] ?? ''));
    } catch (Throwable $e) {
        step('webhook-send', 'Webhook yükleme', false, 'Hata: ' . $e->getMessage());
    }
} elseif ($isDryRun) {
    step('webhook-send', 'Webhook (dry-run)', true, 'Atlandı — dry-run modunda');
    step('webhook-result', 'Webhook sonucu (dry-run)', true, 'Atlandı');
}

// ═══════════════════════════════════════════════════════════════
// ADIM 3: CHANNEL_SYNC_LOGS DOĞRULA
// ═══════════════════════════════════════════════════════════════
if ($logId > 0 && $existingTables['channel_sync_logs']) {
    try {
        $logQ = $pdo->query("SELECT id, status, scope, direction, applied_rows, error_message, completed_at, created_at FROM channel_sync_logs WHERE id=$logId");
        $logRow = $logQ->fetch();
        $logOk = $logRow && (string) $logRow['status'] === 'success';
        $logDetail = $logRow
            ? "durum: {$logRow['status']} · kapsam: {$logRow['scope']} · yön: {$logRow['direction']}" .
              ($logRow['applied_rows'] !== null ? ' · uygulanan: ' . (int) $logRow['applied_rows'] : '') .
              ($logRow['error_message'] ? ' · hata: ' . (string) $logRow['error_message'] : '') .
              ' · tarih: ' . (string) $logRow['created_at']
            : 'Kayıt bulunamadı!';
        step('verify-sync-log', 'channel_sync_logs doğrulama', $logOk, $logDetail);
        // failure_category kontrolü.
        $hasFcCol = (bool) $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='channel_sync_logs' AND column_name='failure_category'")->fetchColumn();
        if ($hasFcCol && $logRow) {
            $fc = (string) ($logRow['failure_category'] ?? '');
            if ((string) $logRow['status'] === 'success') {
                step('verify-fc', 'failure_category (başarılı)', $fc === '' || $fc === 'NULL', 'durum: success → failure_category=' . ($fc ?: 'NULL (beklenen)'));
            } elseif ($fc !== '') {
                $fcIcons = ['expected' => '⏳ beklenen', 'permanent' => '🔴 kalıcı', 'transient' => '🟡 geçici'];
                $fcLabel = $fcIcons[$fc] ?? $fc;
                $stepFcPass = $fc === 'expected'; // eşlenmemiş kod testi beklenen hata üretmeli
                step('verify-fc', 'failure_category (sınıflandırma)', $stepFcPass, "durum: failed → kategori: $fcLabel ($fc)");
            }
        }
    } catch (Throwable $e) {
        step('verify-sync-log', 'channel_sync_logs', false, 'Hata: ' . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════════════════
// ADIM 4: INVENTORY_CALENDAR DOĞRULA
// ═══════════════════════════════════════════════════════════════
if ($res && $res['ok'] && $existingTables['inventory_calendar']) {
    try {
        $calQ = $pdo->prepare("SELECT i.stay_date, i.base_price, i.currency, i.available, i.room_type_id, i.rate_plan_id, rt.name room_name, rp.name plan_name FROM inventory_calendar i JOIN room_types rt ON rt.id=i.room_type_id JOIN rate_plans rp ON rp.id=i.rate_plan_id WHERE rt.property_id=? AND i.stay_date IN (?, ?) ORDER BY i.stay_date");
        $calQ->execute([$propId, $date, date('Y-m-d', strtotime($date . ' +1 day'))]);
        $calRows = $calQ->fetchAll();
        $calCount = count($calRows);
        $calOk = $calCount > 0;

        if ($calOk) {
            $details = [];
            foreach ($calRows as $cr) {
                $details[] = (string) $cr['stay_date'] . ': ' . ($cr['base_price'] ?? 'null') . ' ' . ($cr['currency'] ?? '') . ' · ' . ($cr['room_name'] ?? '') . ' · ' . ($cr['plan_name'] ?? '');
            }
            step('verify-calendar', 'inventory_calendar doğrulama', true, $calCount . ' satır yazıldı — ' . implode(' | ', $details));
        } else {
            // Fiyat farklı birimdeyse takvim farklı sütuna yazılmış olabilir — daha geniş bak.
            $calWide = $pdo->prepare("SELECT COUNT(*) FROM inventory_calendar i JOIN room_types rt ON rt.id=i.room_type_id WHERE rt.property_id=? AND i.stay_date BETWEEN ? AND ?");
            $calWide->execute([$propId, $date, date('Y-m-d', strtotime($date . ' +1 day'))]);
            $wideCount = (int) $calWide->fetchColumn();
            step('verify-calendar', 'inventory_calendar doğrulama', false, "Tarih aralığında $wideCount satır bulundu — beklenen tarih/plan/oda eşleşmedi. Farklı bir birim veya tarih aralığı deneyin.");
        }
    } catch (Throwable $e) {
        step('verify-calendar', 'inventory_calendar', false, 'Hata: ' . $e->getMessage());
    }
} elseif ($isDryRun) {
    step('verify-calendar', 'inventory_calendar (dry-run)', true, 'Atlandı');
}

// ═══════════════════════════════════════════════════════════════
// ADIM 5: FX_AUDIT DOĞRULA (eğer kur dönüşümü yapıldıysa)
// ═══════════════════════════════════════════════════════════════
if ($logId > 0 && $existingTables['channel_sync_logs']) {
    try {
        $hasFxCol = (bool) $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='channel_sync_logs' AND column_name='fx_audit'")->fetchColumn();
        if ($hasFxCol) {
            $fxAudit = $pdo->query("SELECT fx_audit FROM channel_sync_logs WHERE id=$logId")->fetchColumn();
            $fxData = json_decode((string) ($fxAudit ?: '[]'), true);
            if (!empty($fxData) && is_array($fxData)) {
                $fxDetails = [];
                foreach ($fxData as $fx) {
                    $fxDetails[] = ($fx['from'] ?? '?') . '→' . ($fx['to'] ?? '?') . ' @ ' . ($fx['rate'] ?? '?') .
                        ($fx['count'] ?? 0) > 1 ? ' (' . $fx['count'] . ' fiyat)' : '' .
                        ' · ' . ($fx['original_total'] ?? '?') . ' ' . ($fx['from'] ?? '') . ' → ' . ($fx['converted_total'] ?? '?') . ' ' . ($fx['to'] ?? '');
                }
                step('verify-fx-audit', 'fx_audit doğrulama', true, count($fxData) . ' dönüşüm — ' . implode(' | ', $fxDetails));
            } else {
                $planCur2 = strtoupper($currency) === $planCur ? 'aynı' : strtoupper($currency) . '→' . $planCur;
                step('verify-fx-audit', 'fx_audit', true, 'Kur dönüşümü yapılmadı (' . $planCur2 . ') — bu beklenen bir durum');
            }
        }
    } catch (Throwable $e) {
        step('verify-fx-audit', 'fx_audit', false, 'Hata: ' . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════════════════
// SONUÇ
// ═══════════════════════════════════════════════════════════════
$total = count($steps);
$passed = count(array_filter($steps, fn($s) => $s['pass']));
$failed = $total - $passed;

if ($isJson) {
    echo json_encode([
        'ok' => $allPass,
        'total' => $total,
        'passed' => $passed,
        'failed' => $failed,
        'context' => ['connection' => $connId, 'connection_name' => $connName, 'property' => $propId, 'property_name' => $propName, 'code' => $roomCode, 'price' => $price, 'currency' => $currency, 'date' => $date],
        'log_id' => $logId,
        'webhook_result' => $res ? ['ok' => $res['ok'], 'applied' => (int) $res['applied'], 'message' => $res['message'] ?? ''] : null,
        'steps' => $steps,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} else {
    echo "\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    echo "  🔬 WEBHOOK E2E DOĞRULAMA\n";
    echo "  Kanal: $connName · Ürün: $propName · Kod: $roomCode\n";
    echo "  Fiyat: $price $currency · Tarih: $date" . ($isDryRun ? ' · DRY-RUN' : '') . "\n";
    echo "═══════════════════════════════════════════════════════════════\n\n";

    foreach ($steps as $i => $s) {
        $icon = $s['pass'] ? '✅' : '✗ ';
        $num = str_pad((string) ($i + 1), 2, ' ', STR_PAD_LEFT);
        echo "  $num. $icon {$s['label']}\n";
        echo "      {$s['detail']}\n\n";
    }

    echo "───────────────────────────────────────────────────────────────\n";
    if ($allPass) {
        echo "  ✅ TÜM KONTROLLER BAŞARILI ($passed/$total)\n";
    } else {
        echo "  ✗ $failed/$total BAŞARISIZ — düzeltme gerekli\n";
    }
    if ($logId > 0) {
        echo "  📋 Webhook log: #$logId\n";
    }
    echo "═══════════════════════════════════════════════════════════════\n\n";
}

exit($allPass ? 0 : 1);
