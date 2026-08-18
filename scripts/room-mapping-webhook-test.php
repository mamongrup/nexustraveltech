<?php
declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// scripts/room-mapping-webhook-test.php — sunucuda TEK KOMUTLA çalışır,
// oda eşleştirme + webhook akışını uçtan uca adım adım test eder ve her adımı
// PASS/FAIL raporlar.
//
//   php scripts/room-mapping-webhook-test.php                # standart koşu
//   php scripts/room-mapping-webhook-test.php --no-http      # HTTP ucu yerine doğrudan kuyruğa ekler
//   php scripts/room-mapping-webhook-test.php --keep         # test satırlarını temizleme
//   php scripts/room-mapping-webhook-test.php --json         # her adımı makinece okunabilir JSON döner
//
// ADIMLAR:
//   1) ÖN KOŞULLAR — tablolar, aktif kanal + geçerli token, ürün eşleştirmesi,
//      aktif oda tipi, aktif fiyat planı, kur kapsaması, zamanlayıcı kilidi.
//   2) EŞLEŞTİRME KUR — benzersiz dış kod için geçici confirmed eşleştirme.
//   3) YÜK GÖNDER — rates kapsamında JSON yükü webhook ucuna POST edilir
//      (veya --no-http ile doğrudan kuyruğa INSERT).
//   4) İŞLE — kuyruk satırı işleyiciyle aynı kodla uygulanır (cron araya girmediyse).
//   5) RAPOR — log durumu, inventory_calendar fiyatı, fx dönüşümü/fx_audit.
//   6) ÖNERİ AKIŞI — tanınmayan dış kod → suggested öneri oluşur, satır yazılmaz.
//   7) ÖNERİ ONAYI — öneri confirmed yapılır; yük yeniden gönderilir → satır yazılır.
//   8) TEMİZLİK — test kodu/oda/plan satırları silinir (--keep hariç).
//
// Çıkış kodu: 0 = tüm adımlar PASS, 1 = en az bir FAIL.
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/channel_webhook.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/fx.php';

$noHttp = in_array('--no-http', $argv, true);
$keep   = in_array('--keep', $argv, true);
$json   = in_array('--json', $argv, true);
$baseUrl = 'https://nexustraveltech.com';
foreach ($argv as $i => $a) {
    if ($a === '--url' && isset($argv[$i + 1])) $baseUrl = rtrim((string) $argv[$i + 1], '/');
}
$baseUrl = preg_replace('#/$#', '', (string) $baseUrl);

$failures = 0;
$checks = 0;
$steps = []; // --json için: [['name'=>..,'status'=>'pass'|'fail','detail'=>..], ...]

function vok(string $msg): void { global $checks, $steps; $checks++; echo "  [PASS] $msg\n"; $steps[] = ['name' => $msg, 'status' => 'pass', 'detail' => '']; }
function vbad(string $msg): void { global $checks, $failures, $steps; $checks++; $failures++; echo "  [FAIL] $msg\n"; $steps[] = ['name' => $msg, 'status' => 'fail', 'detail' => '']; }
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

    $needed = ['channel_connections', 'channel_property_mappings', 'channel_sync_logs', 'channel_room_mappings', 'inventory_calendar', 'room_types', 'rate_plans', 'fx_rates', 'channel_rate_plan_mappings', 'channel_mapping_blacklist'];
    $missingTables = [];
    foreach ($needed as $t) {
        if (!vtable_exists($pdo, $t)) $missingTables[] = $t;
    }
    $missingTables === [] ? vok('gerekli tablolar mevcut (' . count($needed) . '/' . count($needed) . ')') : vbad('eksik tablo: ' . implode(', ', $missingTables) . ' — önce scripts/health-check.php ile migrationları uygulayın');

    $hasFxAudit = vcolumn_exists($pdo, 'channel_sync_logs', 'fx_audit');
    $hasFxAudit ? vok('channel_sync_logs.fx_audit kolonu mevcut (migration 048)') : vnote('channel_sync_logs.fx_audit eksik (migration 048 bekliyor) — dönüşüm raporu atlanır');

    $hasApproval = vcolumn_exists($pdo, 'channel_room_mappings', 'approved_by_type');
    $hasApproval ? vok('channel_room_mappings onay kolonları mevcut (migration 053)') : vnote('approved_by_* kolonları eksik — onay denetimi atlanır (053 bekliyor)');

    // Zamanlayıcı kilidi takılı mı? (tick.php görevleri çalıştırmıyorsa akış etkilenir)
    $lockHeld = (bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_locks WHERE locktype='advisory' AND (classid=424242 OR objid=424242) AND granted)")->fetchColumn();
    $lockHeld
        ? vbad('zamanlayıcı advisory kilidi (424242) takılı — cron/tick.php hiçbir görevi çalıştırmıyor. pg_terminate_backend ile kilidi tutan oturumu sonlandırın')
        : vok('zamanlayıcı kilidi serbest (tick.php çalışabilir)');

    $conn = $pdo->query("SELECT id, supplier_id, access_token, display_name, channel_code, status FROM channel_connections WHERE status='active' AND access_token IS NOT NULL AND access_token <> '' ORDER BY id LIMIT 1")->fetch();
    if ($missingTables !== [] || !vcolumn_exists($pdo, 'channel_connections', 'access_token')) {
        vbad('ön koşullar eksik — webhook akışı atlandı (önce sahiplik devri + health-check migrationları)');
    } elseif (!$conn) {
        vbad('aktif kanal bağlantısı (tokenli) yok — dağıtım merkezi bölüm 1\'den bir kanal etkinleştirin');
    } elseif (!preg_match('/^[a-f0-9]{64}$/', (string) $conn['access_token'])) {
        vbad('aktif kanalın tokenı geçersiz biçim (64 hex değil) — health-check --fix ile yenileyin');
    } else {
        vok('aktif kanal: ' . (string) $conn['display_name'] . ' (' . (string) $conn['channel_code'] . ', id=' . (int) $conn['id'] . ', token 64 hex)');

        $prop = $pdo->prepare('SELECT m.property_id, m.external_property_id, p.name, p.property_type FROM channel_property_mappings m JOIN properties p ON p.id=m.property_id WHERE m.channel_connection_id=? ORDER BY m.id LIMIT 1');
        $prop->execute([(int) $conn['id']]);
        $propRow = $prop->fetch();
        if (!$propRow) {
            vbad('ürün eşleştirmesi yok — dağıtım merkezi bölüm 2\'den dış ürün kodu ile eşleştirin');
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

            $testDate = date('Y-m-d', strtotime('+60 days'));

            if ($roomRow && $planRow) {
                $planCur = strtoupper((string) ($planRow['currency'] ?: 'EUR'));
                if (!preg_match('/^[A-Z]{3}$/', $planCur)) $planCur = 'EUR';
                $inCur = 'USD';
                foreach (['USD', 'EUR', 'GBP', 'TRY'] as $cand) {
                    if ($cand !== $planCur) { $inCur = $cand; break; }
                }
                $rate = fx_rate($inCur, $planCur, $testDate);
                $rate > 0
                    ? vok("kur kapsaması: $inCur→$planCur mevcut (kur " . number_format($rate, 4, '.', '') . ')')
                    : vnote("kur eksik: $inCur→$planCur — fiyat yazımı fx korumasıyla engellenir (beklenen davranış; kur ekleyince adım 5 geçer)");

                $ext = (string) ($propRow['external_property_id'] ?? '');
                if ($ext === '') vnote('ürün eşleştirmesinde dış ürün kodu boş — yük external_property_id olmadan gönderilir');

                // ─────────────── 2) EŞLEŞTİRME KUR (geçici confirmed) ───────────────
                vsection('2) EŞLEŞTİRME KUR (geçici confirmed)');
                $code = 'RMT-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
                $price = 123.45;
                try {
                    $pdo->prepare("INSERT INTO channel_room_mappings(channel_connection_id, property_id, room_type_id, rate_plan_id, external_room_id, status, approved_by_type, approved_by_name, approved_at) VALUES(?,?,?,?,'confirmed','auto','room-mapping-webhook-test',now()) ON CONFLICT(channel_connection_id, external_room_id) DO UPDATE SET room_type_id=EXCLUDED.room_type_id, rate_plan_id=EXCLUDED.rate_plan_id, property_id=EXCLUDED.property_id, status='confirmed', approved_by_type='auto', approved_by_name='room-mapping-webhook-test', approved_at=now()")
                        ->execute([(int) $conn['id'], $propId, (int) $roomRow['id'], (int) $planRow['id'], $code]);
                    $ok = (int) $pdo->query("SELECT COUNT(*) FROM channel_room_mappings WHERE channel_connection_id=" . (int) $conn['id'] . " AND external_room_id='" . $code . "' AND status='confirmed'")->fetchColumn() === 1;
                    $ok ? vok("confirmed eşleştirme kuruldu: $code → " . (string) $roomRow['name'] . ' · plan ' . (string) $planRow['name']) : vbad('confirmed eşleştirme yazılamadı');
                } catch (Throwable $e) {
                    vbad('eşleştirme kurma hatası: ' . $e->getMessage());
                }

                // ─────────────────────────────── 3) YÜK GÖNDER ───────────────────────────────
                vsection('3) YÜK GÖNDER (rates)');
                $payload = [
                    'scope' => 'rates',
                    'external_property_id' => $ext,
                    'currency' => $inCur,
                    'entries' => [['external_room_id' => $code, 'date' => $testDate, 'price' => $price]],
                ];
                $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
                $queued = false;
                if ($noHttp) {
                    try {
                        $pdo->prepare("INSERT INTO channel_sync_logs(channel_connection_id, property_id, direction, scope, status, request_payload, response_payload) VALUES(?,?, 'pull', ?, 'queued', ?::jsonb, ?::jsonb)")
                            ->execute([(int) $conn['id'], $propId, 'rates', $body, json_encode(['received_at' => gmdate('c')])]);
                        $queued = true;
                        vok('kuyruğa eklendi (--no-http: doğrudan INSERT, kod ' . $code . ')');
                    } catch (Throwable $e) {
                        vbad('kuyruk INSERT hatası: ' . $e->getMessage());
                    }
                } else {
                    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $body, 'ignore_errors' => true, 'timeout' => 20]]);
                    $url = $baseUrl . '/api/channel-webhook?token=' . (string) $conn['access_token'];
                    $resp = @file_get_contents($url, false, $ctx);
                    $dec = is_string($resp) ? json_decode($resp, true) : null;
                    if (is_array($dec) && ($dec['ok'] ?? false) && ($dec['queued'] ?? false)) {
                        $queued = true;
                        vok("HTTP POST kabul edildi — kuyruğa alındı (scope=" . ($dec['scope'] ?? '?') . ', kod ' . $code . ')');
                    } else {
                        vbad('HTTP POST başarısız: ' . (is_string($resp) ? mb_substr($resp, 0, 200) : 'yanıt yok / ağ hatası (--no-http ile deneyin)'));
                    }
                }

                // ─────────────────────────────── 4) İŞLE ───────────────────────────────
                vsection('4) İŞLE (işleyici kodu)');
                $logId = 0;
                $qrow = $pdo->prepare("SELECT id, status, attempt_count FROM channel_sync_logs WHERE channel_connection_id=? AND request_payload::text LIKE ? AND created_at > now() - interval '10 minutes' ORDER BY id DESC LIMIT 1");
                $qrow->execute([(int) $conn['id'], '%' . $code . '%']);
                $log = $qrow->fetch();
                if (!$log) {
                    vbad('kuyruk satırı bulunamadı (kod ' . $code . ')');
                } else {
                    $logId = (int) $log['id'];
                    if ($log['status'] === 'queued') {
                        try {
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
                                vok('uygulandı: ' . (string) $result['message']);
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
                        } catch (Throwable $e) {
                            vbad('işleme hatası: ' . $e->getMessage());
                        }
                    } else {
                        vnote('satır zaten işlenmiş (cron araya girdi): status=' . (string) $log['status'] . ' — saklanan sonuç raporlanır');
                    }
                }

                // ─────────────────────────────── 5) RAPOR ───────────────────────────────
                vsection('5) RAPOR (log + takvim + fx)');
                $fxCol = $hasFxAudit ? ', fx_audit' : '';
                $final = $pdo->prepare('SELECT id, status, attempt_count, error_message' . $fxCol . ' FROM channel_sync_logs WHERE id=?');
                $final->execute([$logId]);
                $fr = $final->fetch();
                $status = (string) ($fr['status'] ?? '?');
                $status === 'success' ? vok('log #' . $logId . ' → success (deneme ' . (int) ($fr['attempt_count'] ?? 1) . ')') : vbad('log #' . $logId . ' → ' . $status . ((string) ($fr['error_message'] ?? '') !== '' ? ' · ' . (string) $fr['error_message'] : ''));

                if ($logId > 0) {
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
                        ($basePrice === false)
                            ? vok('fx koruması doğrulandı: ' . $inCur . '→' . $planCur . ' kuru eksik, fiyat yazılmadı (beklenen davranış)')
                            : vbad('fx koruması beklenen davranışı üretmedi — kur eksikken fiyat yazıldı!');
                    }

                    if (!$hasFxAudit) {
                        vnote('fx_audit kolonu yok (migration 048 bekliyor) — dönüşüm raporu atlandı');
                    } else {
                        $fxList = json_decode((string) ($fr['fx_audit'] ?? '[]'), true);
                        $fxFound = false;
                        $rbd = false;
                        if (is_array($fxList)) {
                            foreach ($fxList as $fx) {
                                if (($fx['from'] ?? '') === $inCur && ($fx['to'] ?? '') === $planCur) {
                                    $fxFound = true;
                                    if (!empty($fx['rates_by_date'])) $rbd = true;
                                }
                            }
                        }
                        $inCur === $planCur
                            ? vnote('birimler aynı (' . $inCur . ') — dönüşüm gerekmedi, fx_audit beklenmez')
                            : ($fxFound
                                ? vok("fx_audit kaydı: $inCur→$planCur dönüşüm işlenmiş" . ($rbd ? ' + girdi bazlı kur (rates_by_date)' : ''))
                                : vbad("fx_audit kaydı bulunamadı ($inCur→$planCur)"));
                    }
                }

                // ─────────────────────── 6) ÖNERİ AKIŞI (tanınmayan kod) ───────────────────────
                vsection('6) ÖNERİ AKIŞI (tanınmayan dış kod)');
                $autoMap = (bool) platform_setting('channel_webhook_auto_map', true);
                $codeS = 'RMT-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
                try {
                    $pdo->beginTransaction();
                    $plS = ['scope' => 'rates', 'external_property_id' => $ext, 'currency' => $inCur, 'entries' => [['external_room_id' => $codeS, 'date' => $testDate, 'price' => 99.0]]];
                    $logS = ['channel_connection_id' => (int) $conn['id'], 'property_id' => $propId];
                    $resS = channel_webhook_apply($logS, $plS);
                    $sugQ = $pdo->prepare('SELECT status FROM channel_room_mappings WHERE channel_connection_id=? AND external_room_id=?');
                    $sugQ->execute([(int) $conn['id'], $codeS]);
                    $sugRow = $sugQ->fetch();
                    if ($autoMap) {
                        ($resS['ok'] && $sugRow && $sugRow['status'] === 'suggested' && (int) ($resS['applied'] ?? 0) === 0)
                            ? vok("öneri oluştu: '$codeS' → suggested, satır yazılmadı (applied=0)")
                            : vbad("öneri beklenen gibi oluşmadı (ok=" . var_export($resS['ok'], true) . ', applied=' . (int) ($resS['applied'] ?? 0) . ')');
                    } else {
                        ((int) ($resS['applied'] ?? 0) >= 1)
                            ? vok("auto_map kapalı: '$codeS' ilk aktif oda tipine yazıldı (applied=" . (int) ($resS['applied'] ?? 0) . ')')
                            : vbad("auto_map kapalı: beklenen yazma olmadı (applied=" . (int) ($resS['applied'] ?? 0) . ')');
                    }
                } catch (Throwable $e) {
                    vbad('öneri akışı hatası: ' . $e->getMessage());
                } finally {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                }

                // ─────────────────────── 7) ÖNERİ ONAYI → YAZMA ───────────────────────
                vsection('7) ÖNERİ ONAYI → YAZMA');
                try {
                    $pdo->beginTransaction();
                    $codeO = 'RMT-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
                    // 7a) Öneri oluştur (auto_map varsayılanı açık kabul edilir).
                    $plO1 = ['scope' => 'rates', 'external_property_id' => $ext, 'currency' => $inCur, 'entries' => [['external_room_id' => $codeO, 'date' => $testDate, 'price' => 88.0]]];
                    $logO = ['channel_connection_id' => (int) $conn['id'], 'property_id' => $propId];
                    $resO1 = channel_webhook_apply($logO, $plO1);
                    $sugQ2 = $pdo->prepare('SELECT id FROM channel_room_mappings WHERE channel_connection_id=? AND external_room_id=? AND status=\'suggested\'');
                    $sugQ2->execute([(int) $conn['id'], $codeO]);
                    $sugId = $sugQ2->fetchColumn();
                    if (!$sugId) {
                        // auto_map kapalıysa öneri oluşmaz — test doğrudan onay adımını atlar.
                        vnote("auto_map kapalı — '$codeO' için öneri oluşmadı, onay adımı atlandı");
                    } else {
                        // 7b) Tedarikçi onayını simüle et (confirmed + denetim alanları).
                        $pdo->prepare("UPDATE channel_room_mappings SET status='confirmed', suggested_at=NULL, approved_by_type='supplier', approved_by_name='room-mapping-webhook-test', approved_by_user_id=NULL, approved_at=now() WHERE id=?")->execute([(int) $sugId]);
                        $pdo->prepare('DELETE FROM channel_sync_logs WHERE channel_connection_id=? AND request_payload::text LIKE ? AND created_at > now() - interval \'10 minutes\'')->execute([(int) $conn['id'], '%' . $codeO . '%']);
                        // 7c) Yükü yeniden gönder → bu kez confirmed eşleşmeyle satır yazılır.
                        $resO2 = channel_webhook_apply($logO, $plO1);
                        $invO = $pdo->prepare('SELECT base_price FROM inventory_calendar WHERE room_type_id=? AND rate_plan_id=? AND stay_date=?');
                        $invO->execute([(int) $roomRow['id'], (int) $planRow['id'], $testDate]);
                        $priceO = $invO->fetchColumn();
                        $expectedO = $rate > 0 ? round(88.0 * $rate, 2) : null;
                        $okWrite = $expectedO !== null && $priceO !== false && abs((float) $priceO - $expectedO) < 0.01;
                        $okWrite
                            ? vok("onay sonrası yazma: '$codeO' → confirmed → fiyat " . number_format((float) $priceO, 2, '.', '') . ' ' . $planCur . ' yazıldı')
                            : vbad("onay sonrası yazma bulunamadı (beklenen " . var_export($expectedO, true) . ', bulunan ' . var_export($priceO, true) . ')');
                    }
                } catch (Throwable $e) {
                    vbad('öneri onayı hatası: ' . $e->getMessage());
                } finally {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                }

                // ─────────────────────────────── 8) TEMİZLİK ───────────────────────────────
                vsection('8) TEMİZLİK');
                if (!$keep) {
                    $delLogs = $pdo->prepare("DELETE FROM channel_sync_logs WHERE channel_connection_id=? AND request_payload::text LIKE ? AND created_at > now() - interval '10 minutes'");
                    $delLogs->execute([(int) $conn['id'], '%' . $code . '%']);
                    $delMap = $pdo->prepare('DELETE FROM channel_room_mappings WHERE channel_connection_id=? AND external_room_id=?');
                    $delMap->execute([(int) $conn['id'], $code]);
                    $delMap2 = $pdo->prepare('DELETE FROM channel_room_mappings WHERE channel_connection_id=? AND external_room_id=?');
                    $delMap2->execute([(int) $conn['id'], $codeS]);
                    $delMap3 = $pdo->prepare('DELETE FROM channel_room_mappings WHERE channel_connection_id=? AND external_room_id=?');
                    $delMap3->execute([(int) $conn['id'], $codeO]);
                    $delInv = $pdo->prepare('DELETE FROM inventory_calendar WHERE room_type_id=? AND rate_plan_id=? AND stay_date=?');
                    $delInv->execute([(int) $roomRow['id'], (int) $planRow['id'], $testDate]);
                    vok('temizlik tamam (loglar, eşleştirmeler ' . $code . '/' . $codeS . '/' . $codeO . ', takvim ' . $testDate . ')');
                } else {
                    vnote('--keep: test satırları bırakıldı (' . $code . '/' . $codeS . '/' . $codeO . ', takvim ' . $testDate . ')');
                }
            } else {
                vbad('aktif oda tipi veya fiyat planı yok — akış adım 2\'den devam edemedi');
            }
        }
    }

    // ─────────────────────────────── SONUÇ ───────────────────────────────
    vsection('SONUÇ');
    if ($json) {
        echo json_encode([
            'ok' => $failures === 0,
            'total' => $checks,
            'passed' => $checks - $failures,
            'failed' => $failures,
            'steps' => $steps,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        echo $failures === 0
            ? "  ✓ TÜM ADIMLAR PASS: $checks kontrol, 0 hata.\n"
            : "  ✗ $failures FAIL / $checks kontrol — yukarıdaki [FAIL] satırlarını inceleyin.\n";
    }
} catch (Throwable $e) {
    $failures++;
    echo "\n✗ Betik hatası: " . $e->getMessage() . "\n";
    if (isset($pdo) && $pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable $ignored) {}
    }
    echo "SONUÇ: 1 hata (betik tamamlanamadı)\n";
}

exit($failures > 0 ? 1 : 0);
