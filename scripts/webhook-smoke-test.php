<?php
declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// Webhook uçtan uca smoke testi — TEK KOMUTLA sunucuda çalıştırılır.
//
//   php scripts/webhook-smoke-test.php
//   php scripts/webhook-smoke-test.php --no-http     # HTTP ucu yerine doğrudan kuyruğa ekler
//   php scripts/webhook-smoke-test.php --url https://domain.com   # webhook taban adresi
//   php scripts/webhook-smoke-test.php --keep        # test satırlarını temizleme
//
// Akış:
//   1) ÖN KOŞULLAR — tablolar, aktif kanal + token, ürün eşleştirmesi, aktif oda tipi,
//      aktif fiyat planı, kur kapsaması, zamanlayıcı kilidi durumu.
//   2) YÜK GÖNDER — tanınmayan benzersiz dış oda kodu için onaylı eşleştirme kurulur,
//      rates kapsamında JSON yükü webhook ucuna POST edilir (veya doğrudan kuyruğa eklenir).
//   3) İŞLE — kuyruktaki satır (cron araya girmediyse) işleyiciyle aynı kodla uygulanır.
//   4) RAPOR — log durumu, uygulanan satır, inventory_calendar fiyatı, kur dönüşümü/fx_audit.
//   5) TEMİZLİK — test koduna ait log/eşleştirme/takvim satırları silinir (--keep hariç).
//
// Çıkış kodu 0 = tüm kontroller geçti, 1 = en az bir hata (cron/CI'ya bağlanabilir).
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/channel_webhook.php';
require_once __DIR__ . '/../config/platform_settings.php';

$noHttp = in_array('--no-http', $argv, true);
$keep   = in_array('--keep', $argv, true);
$baseUrl = 'https://nexustraveltech.com';
foreach ($argv as $i => $a) {
    if ($a === '--url' && isset($argv[$i + 1])) $baseUrl = rtrim((string) $argv[$i + 1], '/');
}
$baseUrl = preg_replace('#/$#', '', (string) $baseUrl);

$failures = 0;
$checks = 0;
function vok(string $msg): void { global $checks; $checks++; echo "  ✓ $msg\n"; }
function vbad(string $msg): void { global $checks, $failures; $checks++; $failures++; echo "  ✗ $msg\n"; }
function vnote(string $msg): void { echo "  · $msg\n"; }
function vsection(string $t): void { echo "\n=== $t ===\n"; }
function vtable_exists(PDO $pdo, string $table): bool {
    $s = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name=?");
    $s->execute([$table]);
    return (bool) $s->fetchColumn();
}
function vcolumn_exists(PDO $pdo, string $table, string $column): bool {
    $s = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name=? AND column_name=?");
    $s->execute([$table, $column]);
    return (bool) $s->fetchColumn();
}

try {
    $pdo = db();

    // ─────────────────────────────── 1) ÖN KOŞULLAR ───────────────────────────────
    vsection('1) ÖN KOŞULLAR');

    $needed = ['channel_connections', 'channel_property_mappings', 'channel_sync_logs', 'channel_room_mappings', 'inventory_calendar', 'room_types', 'rate_plans', 'fx_rates'];
    $missingTables = [];
    foreach ($needed as $t) {
        if (!vtable_exists($pdo, $t)) $missingTables[] = $t;
    }
    $missingTables === [] ? vok('gerekli tablolar mevcut (8/8)') : vbad('eksik tablo: ' . implode(', ', $missingTables) . ' — önce scripts/health-check.php ile migrationları uygulayın');

    $hasFxAudit = vcolumn_exists($pdo, 'channel_sync_logs', 'fx_audit');
    $hasFxAudit ? vok('channel_sync_logs.fx_audit kolonu mevcut (migration 048)') : vnote('channel_sync_logs.fx_audit eksik (migration 048 bekliyor) — dönüşüm raporu atlanır');

    $tokenOk = vcolumn_exists($pdo, 'channel_connections', 'access_token');
    $tokenOk ? vok('channel_connections.access_token kolonu mevcut (migration 044)') : vbad('channel_connections.access_token eksik — migration 044 uygulanmalı');

    // Zamanlayıcı kilidi takılı mı? (tick.php görevleri çalıştırmıyorsa akış etkilenir)
    $lockHeld = (bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_locks WHERE locktype='advisory' AND (classid=424242 OR objid=424242) AND granted)")->fetchColumn();
    $lockHeld
        ? vbad('zamanlayıcı advisory kilidi (424242) takılı — cron/tick.php hiçbir görevi çalıştırmıyor. Kilidi tutan oturumu sonlandırın (pg_terminate_backend)')
        : vok('zamanlayıcı kilidi serbest (tick.php çalışabilir)');

    $conn = $pdo->query("SELECT id, supplier_id, access_token, display_name, channel_code, status FROM channel_connections WHERE status='active' AND access_token IS NOT NULL AND access_token <> '' ORDER BY id LIMIT 1")->fetch();
    if ($missingTables !== [] || !$tokenOk) {
        vnote('ön koşullar eksik — webhook akışı atlandı (önce sahiplik devri + scripts/health-check.php ile migrationları uygulayın)');
    } elseif (!$conn) {
        vbad('aktif kanal bağlantısı (tokenli) yok — dağıtım merkezi bölüm 1den bir kanal etkinleştirin');
    } elseif (!preg_match('/^[a-f0-9]{64}$/', (string) $conn['access_token'])) {
        vbad('aktif kanalın tokenı geçersiz biçim (64 hex değil) — health-check --fix ile yenileyin');
    } else {
        vok('aktif kanal: ' . (string) $conn['display_name'] . ' (' . (string) $conn['channel_code'] . ', id=' . (int) $conn['id'] . ', token 64 hex)');

        $prop = $pdo->prepare('SELECT m.property_id, m.external_property_id, p.name, p.property_type FROM channel_property_mappings m JOIN properties p ON p.id=m.property_id WHERE m.channel_connection_id=? ORDER BY m.id LIMIT 1');
        $prop->execute([(int) $conn['id']]);
        $propRow = $prop->fetch();
        if (!$propRow) {
            vbad('ürün eşleştirmesi yok — dağıtım merkezi bölüm 2den dış ürün kodu ile eşleştirin');
        } else {
            $propId = (int) $propRow['property_id'];
            vok('ürün eşleştirmesi: ' . (string) $propRow['name'] . ' (' . (string) $propRow['property_type'] . ', id=' . $propId . ')');

            $room = $pdo->prepare("SELECT id, name FROM room_types WHERE property_id=? AND status='active' ORDER BY id LIMIT 1");
            $room->execute([$propId]);
            $roomRow = $room->fetch();
            $roomRow ? vok('aktif oda tipi: ' . (string) $roomRow['name']) : vbad('aktif oda/birim tipi yok — ilan düzenleyiciden ekleyin');

            $plan = $pdo->prepare("SELECT id, name, currency FROM rate_plans WHERE property_id=? AND status='active' ORDER BY id LIMIT 1");
            $plan->execute([$propId]);
            $planRow = $plan->fetch();
            $planRow ? vok('aktif fiyat planı: ' . (string) $planRow['name'] . ' · ' . (string) $planRow['currency']) : vbad('aktif fiyat planı yok — fiyat planı ekleyin');

            if ($roomRow && $planRow) {
                $planCur = strtoupper((string) ($planRow['currency'] ?: 'EUR'));
                if (!preg_match('/^[A-Z]{3}$/', $planCur)) $planCur = 'EUR';
                $inCur = 'USD';
                foreach (['USD', 'EUR', 'GBP', 'TRY'] as $cand) {
                    if ($cand !== $planCur) { $inCur = $cand; break; }
                }
                $testDate = date('Y-m-d', strtotime('+60 days'));
                $rate = fx_rate($inCur, $planCur, $testDate);
                $rate > 0
                    ? vok("kur kapsaması: $inCur→$planCur mevcut (kur " . number_format($rate, 4, '.', '') . ') — dönüşüm test edilecek')
                    : vnote("kur eksik: $inCur→$planCur — fiyat yazımı fx korumasıyla engellenir (beklenen davranış, kur eklenince test geçer)");

                // ─────────────────────────────── 2) YÜK GÖNDER ───────────────────────────────
                vsection('2) YÜK GÖNDER');
                $code = 'SMK-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
                $price = 123.45;
                $ext = (string) ($propRow['external_property_id'] ?? '');
                if ($ext === '') {
                    vnote('ürün eşleştirmesinde dış ürün kodu boş — yük external_property_id olmadan gönderilecek, eşleşme property_id üzerinden çözülür');
                }

                // Test kodu için ONAYLI eşleştirme kur (deterministik yazma; temizlikte silinir).
                $pdo->prepare("INSERT INTO channel_room_mappings(channel_connection_id, property_id, room_type_id, rate_plan_id, external_room_id, status, approved_by_type, approved_by_name, approved_at) VALUES(?,?,?,?,'confirmed','auto','webhook-smoke-test',now()) ON CONFLICT(channel_connection_id, external_room_id) DO UPDATE SET room_type_id=EXCLUDED.room_type_id, rate_plan_id=EXCLUDED.rate_plan_id, property_id=EXCLUDED.property_id, status='confirmed', approved_by_type='auto', approved_by_name='webhook-smoke-test', approved_at=now()")
                    ->execute([(int) $conn['id'], $propId, (int) $roomRow['id'], (int) $planRow['id'], $code]);

                $payload = [
                    'scope' => 'rates',
                    'external_property_id' => $ext,
                    'currency' => $inCur,
                    'entries' => [['external_room_id' => $code, 'date' => $testDate, 'price' => $price]],
                ];
                $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

                if ($noHttp) {
                    // Doğrudan kuyruğa ekle (HTTP ucu simülasyonu — api/channel-webhook ile aynı INSERT).
                    $pdo->prepare("INSERT INTO channel_sync_logs(channel_connection_id, property_id, direction, scope, status, request_payload, response_payload) VALUES(?,?, 'pull', ?, 'queued', ?::jsonb, ?::jsonb)")
                        ->execute([(int) $conn['id'], $propId, 'rates', $body, json_encode(['received_at' => gmdate('c')])]);
                    vok('kuyruğa eklendi (--no-http: HTTP yerine doğrudan INSERT, kod ' . $code . ')');
                } else {
                    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $body, 'ignore_errors' => true, 'timeout' => 20]]);
                    $url = $baseUrl . '/api/channel-webhook?token=' . (string) $conn['access_token'];
                    $resp = @file_get_contents($url, false, $ctx);
                    $dec = is_string($resp) ? json_decode($resp, true) : null;
                    if (is_array($dec) && ($dec['ok'] ?? false) && ($dec['queued'] ?? false)) {
                        vok("HTTP POST kabul edildi — kuyruğa alındı (scope=" . ($dec['scope'] ?? '?') . ', kod ' . $code . ')');
                    } else {
                        vbad('HTTP POST başarısız: ' . (is_string($resp) ? mb_substr($resp, 0, 200) : 'yanıt yok / ağ hatası (sunucu DNS veya SSL? --no-http ile deneyin)'));
                    }
                }

                // ─────────────────────────────── 3) İŞLE ───────────────────────────────
                vsection('3) İŞLE');
                $qrow = $pdo->prepare("SELECT id, status, attempt_count FROM channel_sync_logs WHERE channel_connection_id=? AND request_payload::text LIKE ? AND created_at > now() - interval '10 minutes' ORDER BY id DESC LIMIT 1");
                $qrow->execute([(int) $conn['id'], '%' . $code . '%']);
                $log = $qrow->fetch();
                if (!$log) {
                    vbad('kuyruk satırı bulunamadı (kod ' . $code . ')');
                } else {
                    $logId = (int) $log['id'];
                    if ($log['status'] === 'queued') {
                        // Cron henüz araya girmedi — işleyiciyle AYNI kodla uygula.
                        $pdo->prepare("UPDATE channel_sync_logs SET status='running', attempt_count=attempt_count+1 WHERE id=?")->execute([$logId]);
                        $job = $pdo->prepare('SELECT * FROM channel_sync_logs WHERE id=?');
                        $job->execute([$logId]);
                        $jobRow = $job->fetch();
                        $pl = json_decode((string) ($jobRow['request_payload'] ?? '{}'), true);
                        if (!is_array($pl)) $pl = [];
                        $result = channel_webhook_apply($jobRow, $pl);
                        if ($result['ok']) {
                            $upd = $hasFxAudit
                                ? "UPDATE channel_sync_logs SET status='success', response_payload=?::jsonb, fx_audit=?::jsonb, error_message=NULL, completed_at=now() WHERE id=?"
                                : "UPDATE channel_sync_logs SET status='success', response_payload=?::jsonb, error_message=NULL, completed_at=now() WHERE id=?";
                            $args = $hasFxAudit
                                ? [json_encode(['applied' => $result['applied'], 'message' => $result['message']]), json_encode($result['fx_audit'] ?? []), $logId]
                                : [json_encode(['applied' => $result['applied'], 'message' => $result['message']]), $logId];
                            $pdo->prepare($upd)->execute($args);
                            vok('uygulandı (satır içi işleme): ' . (string) $result['message']);
                        } else {
                            $errMsg = (string) $result['message'] . (isset($result['errors']) ? ' [' . implode(',', array_slice($result['errors'], 0, 4)) . ']' : '');
                            $upd = $hasFxAudit
                                ? "UPDATE channel_sync_logs SET status='failed', error_message=?, response_payload=?::jsonb, fx_audit=?::jsonb, completed_at=now() WHERE id=?"
                                : "UPDATE channel_sync_logs SET status='failed', error_message=?, response_payload=?::jsonb, completed_at=now() WHERE id=?";
                            $args = $hasFxAudit
                                ? [mb_substr($errMsg, 0, 1000), json_encode(['message' => $result['message']]), json_encode($result['fx_audit'] ?? []), $logId]
                                : [mb_substr($errMsg, 0, 1000), json_encode(['message' => $result['message']]), $logId];
                            $pdo->prepare($upd)->execute($args);
                            vbad('uygulama başarısız: ' . $errMsg);
                        }
                    } else {
                        vnote('satır zaten işlenmiş (cron araya girdi): status=' . (string) $log['status'] . ' — saklanan sonuç raporlanır');
                    }

                    // ─────────────────────────────── 4) RAPOR ───────────────────────────────
                    vsection('4) RAPOR');
                    $fxCol = $hasFxAudit ? ', fx_audit' : '';
                    $final = $pdo->prepare('SELECT id, status, attempt_count, error_message' . $fxCol . ' FROM channel_sync_logs WHERE id=?');
                    $final->execute([$logId]);
                    $fr = $final->fetch();
                    $status = (string) ($fr['status'] ?? '?');
                    $status === 'success' ? vok('log #' . $logId . ' → success (deneme ' . (int) ($fr['attempt_count'] ?? 1) . ')') : vbad('log #' . $logId . ' → ' . $status . ((string) ($fr['error_message'] ?? '') !== '' ? ' · ' . (string) $fr['error_message'] : ''));

                    $inv = $pdo->prepare('SELECT base_price FROM inventory_calendar WHERE room_type_id=? AND rate_plan_id=? AND stay_date=?');
                    $inv->execute([(int) $roomRow['id'], (int) $planRow['id'], $testDate]);
                    $basePrice = $inv->fetchColumn();
                    if ($rate > 0) {
                        $expected = round($price * $rate, 2);
                        if ($basePrice !== false && abs((float) $basePrice - $expected) < 0.01) {
                            vok('takvim yazımı: ' . $price . ' ' . $inCur . ' → ' . number_format((float) $basePrice, 2, '.', '') . ' ' . $planCur . ' (beklenen ' . $expected . ', kur ' . number_format($rate, 4, '.', '') . ')');
                        } elseif ($basePrice === false) {
                            vbad('takvim yazımı bulunamadı — beklenen ' . $expected . ' ' . $planCur);
                        } else {
                            vbad('takvim fiyatı uyuşmuyor: bulunan ' . number_format((float) $basePrice, 2, '.', '') . ' vs beklenen ' . $expected . ' ' . $planCur);
                        }
                    } else {
                        // Kur yok — fx koruması beklenir: satır yazılmamış olmalı.
                        ($basePrice === false)
                            ? vok('fx koruması doğrulandı: ' . $inCur . '→' . $planCur . ' kuru eksik, fiyat yazılmadı (beklenen davranış — kur ekleyince testi tekrar çalıştırın)')
                            : vbad('fx koruması beklenen davranışı üretmedi — kur eksikken fiyat yazıldı!');
                    }

                    if (!$hasFxAudit) {
                        vnote('fx_audit kolonu yok (migration 048 bekliyor) — dönüşüm raporu atlandı');
                    } else {
                        $fxList = json_decode((string) ($fr['fx_audit'] ?? '[]'), true);
                        $fxFound = false;
                        if (is_array($fxList)) {
                            foreach ($fxList as $fx) {
                                if (($fx['from'] ?? '') === $inCur && ($fx['to'] ?? '') === $planCur) $fxFound = true;
                            }
                        }
                        $inCur === $planCur
                            ? vnote('birimler aynı (' . $inCur . ') — dönüşüm gerekmedi, fx_audit beklenmez')
                            : ($fxFound ? vok("fx_audit kaydı: $inCur→$planCur dönüşüm işlenmiş") : vbad("fx_audit kaydı bulunamadı ($inCur→$planCur)"));
                    }

                    // ─────────────────────────────── 5) TEMİZLİK ───────────────────────────────
                    if (!$keep) {
                        $pdo->prepare('DELETE FROM channel_sync_logs WHERE id=?')->execute([$logId]);
                        $pdo->prepare('DELETE FROM channel_room_mappings WHERE channel_connection_id=? AND external_room_id=?')->execute([(int) $conn['id'], $code]);
                        $pdo->prepare('DELETE FROM inventory_calendar WHERE room_type_id=? AND rate_plan_id=? AND stay_date=?')->execute([(int) $roomRow['id'], (int) $planRow['id'], $testDate]);
                        vnote("temizlik tamam (log #$logId, eşleştirme '$code', takvim $testDate)");
                    } else {
                        vnote('--keep: test satırları bırakıldı (log #' . $logId . ', kod ' . $code . ')');
                    }
                }

                // ─────────────────── 2b) KANAL ÖZGÜ KAPSAMLAR ───────────────────
                vsection('2b) KAPSAM TESTLERİ: availability / restrictions / reservations');
                // Rates akışının temizliği eşleştirmeyi sildi — kapsam testleri için yeniden kur.
                $pdo->prepare("INSERT INTO channel_room_mappings(channel_connection_id, property_id, room_type_id, rate_plan_id, external_room_id, status, approved_by_type, approved_by_name, approved_at) VALUES(?,?,?,?,'confirmed','auto','webhook-smoke-test',now()) ON CONFLICT(channel_connection_id, external_room_id) DO UPDATE SET room_type_id=EXCLUDED.room_type_id, rate_plan_id=EXCLUDED.rate_plan_id, property_id=EXCLUDED.property_id, status='confirmed', approved_by_type='auto', approved_by_name='webhook-smoke-test', approved_at=now()")
                    ->execute([(int) $conn['id'], $propId, (int) $roomRow['id'], (int) $planRow['id'], $code]);
                $scopeSpecs = [
                    ['scope' => 'availability', 'date' => date('Y-m-d', strtotime('+61 days')), 'entry' => ['allotment' => 5], 'label' => 'kontenjan (allotment=5)'],
                    ['scope' => 'restrictions', 'date' => date('Y-m-d', strtotime('+62 days')), 'entry' => ['stop_sale' => true, 'min_stay' => 2, 'max_stay' => 7], 'label' => 'stop_sale + min/max konaklama'],
                    ['scope' => 'reservations', 'date' => date('Y-m-d', strtotime('+63 days')), 'entry' => ['qty' => 3], 'label' => 'rezervasyon (sold +3)'],
                ];
                foreach ($scopeSpecs as $spec) {
                    $scope = (string) $spec['scope'];
                    $scopeDate = (string) $spec['date'];
                    if ($scope === 'reservations') {
                        // reservations, inventory_calendar'da UPDATE sold yapar — satırın önce var olması gerekir.
                        $prePayload = ['scope' => 'availability', 'external_property_id' => $ext, 'entries' => [['external_room_id' => $code, 'date' => $scopeDate, 'allotment' => 8]]];
                        if ($noHttp) {
                            $pdo->prepare("INSERT INTO channel_sync_logs(channel_connection_id, property_id, direction, scope, status, request_payload, response_payload) VALUES(?,?, 'pull', ?, 'queued', ?::jsonb, ?::jsonb)")
                                ->execute([(int) $conn['id'], $propId, 'availability', json_encode($prePayload, JSON_UNESCAPED_UNICODE), json_encode(['received_at' => gmdate('c')])]);
                        } else {
                            $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode($prePayload, JSON_UNESCAPED_UNICODE), 'ignore_errors' => true, 'timeout' => 20]]);
                            @file_get_contents($baseUrl . '/api/channel-webhook?token=' . (string) $conn['access_token'], false, $ctx);
                        }
                        $preRow = $pdo->query("SELECT id FROM channel_sync_logs WHERE channel_connection_id=" . (int) $conn['id'] . " AND request_payload::text LIKE '%" . $code . "%' AND created_at > now() - interval '10 minutes' ORDER BY id DESC LIMIT 1")->fetchColumn();
                        if ($preRow) {
                            $pdo->prepare("UPDATE channel_sync_logs SET status='running', attempt_count=attempt_count+1 WHERE id=?")->execute([(int) $preRow]);
                            $preJob = $pdo->query("SELECT * FROM channel_sync_logs WHERE id=" . (int) $preRow)->fetch();
                            $prePl = json_decode((string) ($preJob['request_payload'] ?? '{}'), true);
                            if (!is_array($prePl)) $prePl = [];
                            $preRes = channel_webhook_apply($preJob, $prePl);
                            if ($preRes['ok']) {
                                $pdo->prepare("UPDATE channel_sync_logs SET status='success', response_payload=?::jsonb, error_message=NULL, completed_at=now() WHERE id=?")->execute([json_encode(['applied' => $preRes['applied']]), (int) $preRow]);
                            } else {
                                $pdo->prepare("UPDATE channel_sync_logs SET status='failed', error_message=?, completed_at=now() WHERE id=?")->execute([mb_substr((string) $preRes['message'], 0, 1000), (int) $preRow]);
                            }
                        }
                    }
                    $entry = array_merge(['external_room_id' => $code, 'date' => $scopeDate], (array) $spec['entry']);
                    $payload = ['scope' => $scope, 'external_property_id' => $ext, 'entries' => [$entry]];
                    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
                    if ($noHttp) {
                        $pdo->prepare("INSERT INTO channel_sync_logs(channel_connection_id, property_id, direction, scope, status, request_payload, response_payload) VALUES(?,?, 'pull', ?, 'queued', ?::jsonb, ?::jsonb)")
                            ->execute([(int) $conn['id'], $propId, $scope, $body, json_encode(['received_at' => gmdate('c')])]);
                    } else {
                        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $body, 'ignore_errors' => true, 'timeout' => 20]]);
                        $resp = @file_get_contents($baseUrl . '/api/channel-webhook?token=' . (string) $conn['access_token'], false, $ctx);
                        $dec = is_string($resp) ? json_decode($resp, true) : null;
                        if (!(is_array($dec) && ($dec['ok'] ?? false) && ($dec['queued'] ?? false))) {
                            vbad($scope . ' gönderimi başarısız: ' . (is_string($resp) ? mb_substr($resp, 0, 200) : 'yanıt yok'));
                            continue;
                        }
                    }
                    $qrow2 = $pdo->prepare("SELECT id, status, attempt_count FROM channel_sync_logs WHERE channel_connection_id=? AND request_payload::text LIKE ? AND created_at > now() - interval '10 minutes' ORDER BY id DESC LIMIT 1");
                    $qrow2->execute([(int) $conn['id'], '%' . $code . '%']);
                    $log2 = $qrow2->fetch();
                    if (!$log2) { vbad($scope . ': kuyruk satırı bulunamadı'); continue; }
                    $log2Id = (int) $log2['id'];
                    if ($log2['status'] === 'queued') {
                        $pdo->prepare("UPDATE channel_sync_logs SET status='running', attempt_count=attempt_count+1 WHERE id=?")->execute([$log2Id]);
                        $job2 = $pdo->query("SELECT * FROM channel_sync_logs WHERE id=" . $log2Id)->fetch();
                        $pl2 = json_decode((string) ($job2['request_payload'] ?? '{}'), true);
                        if (!is_array($pl2)) $pl2 = [];
                        $res2 = channel_webhook_apply($job2, $pl2);
                        if ($res2['ok']) {
                            $pdo->prepare("UPDATE channel_sync_logs SET status='success', response_payload=?::jsonb, error_message=NULL, completed_at=now() WHERE id=?")->execute([json_encode(['applied' => $res2['applied'], 'message' => $res2['message']]), $log2Id]);
                        } else {
                            $pdo->prepare("UPDATE channel_sync_logs SET status='failed', error_message=?, completed_at=now() WHERE id=?")->execute([mb_substr((string) $res2['message'], 0, 1000), $log2Id]);
                        }
                    }
                    $stat2 = $pdo->query("SELECT status FROM channel_sync_logs WHERE id=" . $log2Id)->fetchColumn();
                    if ($stat2 !== 'success') {
                        vbad($scope . ' işlenemedi (status=' . (string) $stat2 . ') — ' . $spec['label']);
                        continue;
                    }
                    $inv2 = $pdo->query("SELECT allotment, sold, min_stay, max_stay, stop_sale FROM inventory_calendar WHERE room_type_id=" . (int) $roomRow['id'] . " AND rate_plan_id=" . (int) $planRow['id'] . " AND stay_date='" . $scopeDate . "'")->fetch();
                    if ($scope === 'availability') {
                        ((int) ($inv2['allotment'] ?? -1) === 5)
                            ? vok('availability: kontenjan yazıldı (allotment=5 · ' . $scopeDate . ')')
                            : vbad('availability: allotment beklenen 5 değil (bulunan ' . var_export($inv2['allotment'] ?? null, true) . ')');
                    } elseif ($scope === 'restrictions') {
                        ((bool) ($inv2['stop_sale'] ?? false) === true && (int) ($inv2['min_stay'] ?? 0) === 2 && (int) ($inv2['max_stay'] ?? 0) === 7)
                            ? vok('restrictions: stop_sale + min 2 / max 7 yazıldı (' . $scopeDate . ')')
                            : vbad('restrictions: beklenen değerler bulunamadı (' . var_export($inv2, true) . ')');
                    } else {
                        ((int) ($inv2['sold'] ?? -1) === 3 && (int) ($inv2['allotment'] ?? -1) === 8)
                            ? vok('reservations: sold +3 işlendi, allotment 8 korundu (' . $scopeDate . ')')
                            : vbad('reservations: sold beklenen 3 değil (bulunan sold=' . var_export($inv2['sold'] ?? null, true) . ', allotment=' . var_export($inv2['allotment'] ?? null, true) . ')');
                    }
                }
                // Kapsam testi temizliği — tüm kapsam logları (ön yazım dahil) + takvim + eşleştirme.
                if (!$keep) {
                    $pdo->prepare("DELETE FROM channel_sync_logs WHERE channel_connection_id=? AND request_payload::text LIKE ? AND created_at > now() - interval '10 minutes'")->execute([(int) $conn['id'], '%' . $code . '%']);
                    $pdo->prepare("DELETE FROM inventory_calendar WHERE room_type_id=? AND rate_plan_id=? AND stay_date IN (?,?,?)")->execute([(int) $roomRow['id'], (int) $planRow['id'], date('Y-m-d', strtotime('+61 days')), date('Y-m-d', strtotime('+62 days')), date('Y-m-d', strtotime('+63 days'))]);
                    $pdo->prepare('DELETE FROM channel_room_mappings WHERE channel_connection_id=? AND external_room_id=?')->execute([(int) $conn['id'], $code]);
                    vnote("kapsam testi temizliği tamam (loglar, takvim +61/+62/+63, eşleştirme '$code')");
                } else {
                    vnote('--keep: kapsam testi satırları bırakıldı (kod ' . $code . ')');
                }

                // ─────────── 2d) TANINMAYAN KOD AKIŞI: öneri → onay → yazma ───────────
                // Aynı komut dizisinin gösterimi: tanınmayan dış kod önce suggested öneri
                // oluşturur (satır yazılmaz); öneri onaylanınca aynı yük fiyatı yazar.
                // Tek transaction + rollback — gösterim kalıntı bırakmaz; kanala gerçek
                // webhook POST'u da bu bölümün sonundaki curl örnekleriyle verilir.
                vsection('2d) TANINMAYAN KOD AKIŞI (öneri → onay → yazma)');
                $autoMap = (bool) platform_setting('channel_webhook_auto_map', true);
                $codeU = 'RMT-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
                $pdo->beginTransaction();
                try {
                    // 2d-1) Tanınmayan kod ile yük — auto_map açıksa suggested öneri oluşur, satır yazılmaz.
                    $logU = ['channel_connection_id' => (int) $conn['id'], 'property_id' => $propId];
                    $payloadU = ['scope' => 'rates', 'external_property_id' => $ext, 'currency' => $inCur, 'entries' => [['external_room_id' => $codeU, 'date' => $testDate, 'price' => 150.0]]];
                    $resU = channel_webhook_apply($logU, $payloadU);
                    $sugU = $pdo->prepare('SELECT status, suggestion_count FROM channel_room_mappings WHERE channel_connection_id=? AND external_room_id=?');
                    $sugU->execute([(int) $conn['id'], $codeU]);
                    $sugURow = $sugU->fetch();
                    if ($autoMap) {
                        ($resU['ok'] && $sugURow && $sugURow['status'] === 'suggested' && (int) ($resU['applied'] ?? 0) === 0)
                            ? vok("öneri oluştu: '$codeU' → suggested (uygulanan satır: 0) — tanınmayan kod satır yazdırmıyor")
                            : vbad("öneri akışı: '$codeU' beklenen suggested kaydı oluşmadı (ok=" . var_export($resU['ok'], true) . ', applied=' . (int) ($resU['applied'] ?? 0) . ')');
                    } else {
                        vnote("auto_map kapalı — '$codeU' ilk aktif oda tipine yazılır (öneri beklenmez); akış atlanıyor");
                    }
                    // 2d-2) Öneriyi onayla (tedarikçi onayı simülasyonu) → aynı yük fiyatı yazar.
                    if ($autoMap && $sugURow) {
                        $pdo->prepare("UPDATE channel_room_mappings SET status='confirmed', suggested_at=NULL, approved_by_type='supplier', approved_by_name='webhook-smoke-test', approved_by_user_id=NULL, approved_at=now() WHERE channel_connection_id=? AND external_room_id=?")
                            ->execute([(int) $conn['id'], $codeU]);
                        $resU2 = channel_webhook_apply($logU, $payloadU);
                        $invU = $pdo->prepare('SELECT base_price FROM inventory_calendar WHERE room_type_id=? AND rate_plan_id=? AND stay_date=?');
                        $invU->execute([(int) $roomRow['id'], (int) $planRow['id'], $testDate]);
                        $priceU = $invU->fetchColumn();
                        $expectedU = $rate > 0 ? round(150.0 * $rate, 2) : null;
                        if ($expectedU !== null && $priceU !== false && abs((float) $priceU - $expectedU) < 0.01) {
                            vok("onay sonrası yazma: '$codeU' → confirmed → fiyat " . number_format((float) $priceU, 2, '.', '') . ' ' . $planCur . ' yazıldı (beklenen ' . $expectedU . ', kur ' . number_format($rate, 4, '.', '') . ')');
                        } elseif ($expectedU === null) {
                            vnote("onay sonrası yazma: kur eksik ($inCur→$planCur) — fiyat yazımı fx korumasıyla engellendi (beklenen davranış)");
                        } else {
                            vbad("onay sonrası yazma bulunamadı (beklenen " . var_export($expectedU, true) . ', bulunan ' . var_export($priceU, true) . ')');
                        }
                    }
                } catch (Throwable $e) {
                    vbad('tanınmayan kod akışı hatası: ' . $e->getMessage());
                } finally {
                    $pdo->rollBack();
                    vnote('gösterim temizlendi (rollback — öneri + takvim satırı kaldırıldı)');
                }

                // ─────────────────── 2c) CURL ÖRNEKLERİ ───────────────────
                vsection('CURL ÖRNEKLERİ (kopyala-yapıştır)');
                echo "  Not: '" . $code . "' bu çalıştırmanın geçici test kodu — kendi eşleştirilmiş oda kodunuzla değiştirin.\n";
                $curlBase = $baseUrl . '/api/channel-webhook?token=' . (string) $conn['access_token'];
                echo "  # 1) availability — kontenjan\n";
                echo "  curl -s -X POST \"$curlBase\" -H \"Content-Type: application/json\" -d '{\"scope\":\"availability\",\"external_property_id\":\"$ext\",\"entries\":[{\"external_room_id\":\"$code\",\"date\":\"$testDate\",\"allotment\":5}]}'\n";
                echo "  # 2) rates — fiyat (kur varsa otomatik $planCur birimine çevrilir)\n";
                echo "  curl -s -X POST \"$curlBase\" -H \"Content-Type: application/json\" -d '{\"scope\":\"rates\",\"external_property_id\":\"$ext\",\"currency\":\"$inCur\",\"entries\":[{\"external_room_id\":\"$code\",\"date\":\"$testDate\",\"price\":123.45}]}'\n";
                echo "  # 3) restrictions — stop_sale + min/max konaklama\n";
                echo "  curl -s -X POST \"$curlBase\" -H \"Content-Type: application/json\" -d '{\"scope\":\"restrictions\",\"external_property_id\":\"$ext\",\"entries\":[{\"external_room_id\":\"$code\",\"date\":\"$testDate\",\"stop_sale\":false,\"min_stay\":2,\"max_stay\":7}]}'\n";
                echo "  # 4) reservations — satış (sold = sold + qty; takvim satırı önce var olmalı)\n";
                echo "  curl -s -X POST \"$curlBase\" -H \"Content-Type: application/json\" -d '{\"scope\":\"reservations\",\"external_property_id\":\"$ext\",\"entries\":[{\"external_room_id\":\"$code\",\"date\":\"$testDate\",\"qty\":1}]}'\n";            }
        }
    }

    // ─────────────────────────────── SONUÇ ───────────────────────────────
    vsection('SONUÇ');
    echo $failures === 0
        ? "  ✓ Webhook smoke testi geçti: $checks kontrol, 0 hata.\n"
        : "  ✗ $failures hata / $checks kontrol — yukarıdaki ✗ satırlarını inceleyin.\n";
} catch (Throwable $e) {
    $failures++;
    echo "\n✗ Betik hatası: " . $e->getMessage() . "\n";
    if (isset($pdo) && $pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable $ignored) {}
    }
    echo "SONUÇ: 1 hata (betik tamamlanamadı)\n";
}

// Sonucu platform ayarina kaydet (dagitim sagligi haftalik ozetinde gorunur).
try {
    save_platform_setting('last_webhook_smoke_test', [
        'date' => date('c'),
        'status' => $failures === 0 ? 'pass' : 'fail',
        'failures' => (int) $failures,
        'checks' => (int) $checks,
    ]);
} catch (Throwable $ignored) {}

exit($failures > 0 ? 1 : 0);
