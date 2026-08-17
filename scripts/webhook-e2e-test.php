<?php
declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// Webhook uçtan uca ÖNERİ AKIŞI testi — TEK KOMUTLA sunucuda çalıştırılır.
//
//   php scripts/webhook-e2e-test.php
//   php scripts/webhook-e2e-test.php --no-http     # HTTP ucu yerine doğrudan kuyruğa ekler
//   php scripts/webhook-e2e-test.php --url https://domain.com   # webhook taban adresi
//   php scripts/webhook-e2e-test.php --keep        # test satırlarını temizleme
//   php scripts/webhook-e2e-test.php --plan-code OTA-PLAN1   # fiyat planı ipucu da gönder
//
// Adım 2-5 zinciri (kullanıcının elle yürüttüğü testin otomasyonu):
//   2) YÜK GÖNDER  — aktif kanal + token + ürün eşleştirmesi doğrulanır, tanınmayan
//      benzersiz dış oda koduyla rates yükü webhook ucuna POST edilir (veya --no-http).
//   3) ÖNERİ OLUŞUMU — işleyiciyle AYNI kod uygulanır; beklenti: channel_room_mappings'te
//      status='suggested' satır + notifications'ta channel_mapping_suggestion + veri YAZILMAZ.
//   4) ONAY + YAZIM — öneri confirmed'a çekilir (rate_plan_id dolu), aynı yük yeniden işlenir;
//      beklenti: inventory_calendar'a fiyat yazılır, kur varsa fx_audit dönüşümü kaydedilir.
//   5) RAPOR + TEMİZLİK — log durumu, takvim fiyatı, fx_audit; test satırları silinir (--keep hariç).
//
// Çıkış kodu 0 = tüm kontroller geçti, 1 = en az bir hata (cron/CI'ya bağlanabilir).
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/channel_webhook.php';
require_once __DIR__ . '/../config/platform_settings.php';

$noHttp  = in_array('--no-http', $argv, true);
$keep    = in_array('--keep', $argv, true);
$baseUrl = 'https://nexustraveltech.com';
$planCode = '';
foreach ($argv as $i => $a) {
    if ($a === '--url' && isset($argv[$i + 1])) $baseUrl = rtrim((string) $argv[$i + 1], '/');
    if ($a === '--plan-code' && isset($argv[$i + 1])) $planCode = (string) $argv[$i + 1];
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

    // ─────────────────────────── 1) ÖN KOŞULLAR ───────────────────────────
    vsection('1) ÖN KOŞULLAR');
    $needed = ['channel_connections', 'channel_property_mappings', 'channel_sync_logs', 'channel_room_mappings', 'channel_rate_plan_mappings', 'inventory_calendar', 'room_types', 'rate_plans', 'fx_rates', 'notifications'];
    $missingTables = [];
    foreach ($needed as $t) {
        if (!vtable_exists($pdo, $t)) $missingTables[] = $t;
    }
    $missingTables === [] ? vok('gerekli tablolar mevcut (' . count($needed) . '/' . count($needed) . ')') : vbad('eksik tablo: ' . implode(', ', $missingTables) . ' — önce scripts/health-check.php --repair ile onarın');

    $hasFxAudit = vcolumn_exists($pdo, 'channel_sync_logs', 'fx_audit');
    $hasFxAudit ? vok('channel_sync_logs.fx_audit mevcut (migration 048)') : vnote('fx_audit eksik — dönüşüm raporu atlanır');
    $hasSuggestion = vcolumn_exists($pdo, 'channel_room_mappings', 'suggestion_score');
    $hasSuggestion ? vok('channel_room_mappings.suggestion_score mevcut (migration 045+)') : vbad('öneri kolonları eksik — channel_room_mappings onarılmadan öneri akışı çalışmaz');

    $autoMap = (bool) platform_setting('channel_webhook_auto_map', true);
    $autoMap ? vok('channel_webhook_auto_map AÇIK (öneri akışı etkin)') : vbad('channel_webhook_auto_map KAPALI — tanınmayan kodlar öneri oluşturmaz; kontrol merkezinden açın');

    $conn = $pdo->query("SELECT id, supplier_id, access_token, display_name, channel_code, status FROM channel_connections WHERE status='active' AND access_token IS NOT NULL AND access_token <> '' ORDER BY id LIMIT 1")->fetch();
    if ($missingTables !== [] || !$hasSuggestion) {
        vnote('ön koşullar eksik — akış atlandı (önce sahiplik devri + health-check --repair)');
    } elseif (!$conn) {
        vbad('aktif kanal bağlantısı (tokenli) yok — dağıtım merkezi bölüm 1den kanal etkinleştirin');
    } elseif (!preg_match('/^[a-f0-9]{64}$/', (string) $conn['access_token'])) {
        vbad('aktif kanalın tokenı 64 hex değil — health-check --fix ile yenileyin');
    } else {
        $connId = (int) $conn['id'];
        vok('aktif kanal: ' . (string) $conn['display_name'] . ' (' . (string) $conn['channel_code'] . ', id=' . $connId . ', token 64 hex)');

        $prop = $pdo->prepare('SELECT m.property_id, m.external_property_id, p.name, p.property_type, p.supplier_id FROM channel_property_mappings m JOIN properties p ON p.id=m.property_id WHERE m.channel_connection_id=? ORDER BY m.id LIMIT 1');
        $prop->execute([$connId]);
        $propRow = $prop->fetch();
        if (!$propRow) {
            vbad('ürün eşleştirmesi yok — dağıtım merkezi bölüm 2den dış ürün kodu ile eşleştirin');
        } else {
            $propId = (int) $propRow['property_id'];
            $supplierId = (int) ($propRow['supplier_id'] ?? 0);
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
                $testDate = date('Y-m-d', strtotime('+75 days'));
                $rate = fx_rate($inCur, $planCur, $testDate);
                $rate > 0
                    ? vok("kur kapsaması: $inCur→$planCur mevcut (kur " . number_format($rate, 4, '.', '') . ')')
                    : vnote("kur eksik: $inCur→$planCur — dönüşüm adımı fx korumasıyla atlanır (beklenen davranış)");

                // ─────────────────────────── 2) YÜK GÖNDER ───────────────────────────
                vsection('2) YÜK GÖNDER — tanınmayan kod ile rates');
                // Benzersiz tanınmayan dış oda kodu: E2E öneki + rastgele. Eşleşme yok → öneri akışı tetiklenmeli.
                $code = 'E2E-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
                $price = 210.0;
                $ext = (string) ($propRow['external_property_id'] ?? '');
                $entry = ['external_room_id' => $code, 'date' => $testDate, 'price' => $price];
                if ($planCode !== '') $entry['external_rate_plan_id'] = $planCode;
                $payload = ['scope' => 'rates', 'external_property_id' => $ext, 'currency' => $inCur, 'entries' => [$entry]];
                $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

                // Kod önceden eşleşmemiş olmalı — testi tekrarlanabilir kılmak için önce temizle.
                $pdo->prepare('DELETE FROM channel_room_mappings WHERE channel_connection_id=? AND external_room_id=?')->execute([$connId, $code]);
                if ($planCode !== '') $pdo->prepare('DELETE FROM channel_rate_plan_mappings WHERE channel_connection_id=? AND external_rate_plan_id=?')->execute([$connId, $planCode]);

                if ($noHttp) {
                    $pdo->prepare("INSERT INTO channel_sync_logs(channel_connection_id, property_id, direction, scope, status, request_payload, response_payload) VALUES(?,?, 'pull', ?, 'queued', ?::jsonb, ?::jsonb)")
                        ->execute([$connId, $propId, 'rates', $body, json_encode(['received_at' => gmdate('c')])]);
                    vok('kuyruğa eklendi (--no-http, kod ' . $code . ')');
                } else {
                    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $body, 'ignore_errors' => true, 'timeout' => 20]]);
                    $url = $baseUrl . '/api/channel-webhook?token=' . (string) $conn['access_token'];
                    $resp = @file_get_contents($url, false, $ctx);
                    $dec = is_string($resp) ? json_decode($resp, true) : null;
                    if (is_array($dec) && ($dec['ok'] ?? false) && ($dec['queued'] ?? false)) {
                        vok("HTTP POST kabul edildi — kuyruğa alındı (scope=" . ($dec['scope'] ?? '?') . ', kod ' . $code . ')');
                    } else {
                        vbad('HTTP POST başarısız: ' . (is_string($resp) ? mb_substr($resp, 0, 200) : 'yanıt yok / ağ hatası (--no-http ile deneyin)'));
                    }
                }

                // ─────────────────────── 3) ÖNERİ OLUŞUMU ───────────────────────
                vsection('3) ÖNERİ OLUŞUMU — tanınmayan kod');
                $qrow = $pdo->prepare("SELECT id, status FROM channel_sync_logs WHERE channel_connection_id=? AND request_payload::text LIKE ? AND created_at > now() - interval '10 minutes' ORDER BY id DESC LIMIT 1");
                $qrow->execute([$connId, '%' . $code . '%']);
                $log = $qrow->fetch();
                if (!$log) {
                    vbad('kuyruk satırı bulunamadı (kod ' . $code . ')');
                } else {
                    $logId = (int) $log['id'];
                    if ($log['status'] === 'queued') {
                        $pdo->prepare("UPDATE channel_sync_logs SET status='running', attempt_count=attempt_count+1 WHERE id=?")->execute([$logId]);
                        $job = $pdo->query('SELECT * FROM channel_sync_logs WHERE id=' . $logId)->fetch();
                        $pl = json_decode((string) ($job['request_payload'] ?? '{}'), true);
                        if (!is_array($pl)) $pl = [];
                        $result = channel_webhook_apply($job, $pl);
                        $errMsg = (string) $result['message'] . (isset($result['errors']) ? ' [' . implode(',', array_slice($result['errors'], 0, 4)) . ']' : '');
                        $upd = $hasFxAudit
                            ? "UPDATE channel_sync_logs SET status=?, response_payload=?::jsonb, error_message=?, fx_audit=?::jsonb, completed_at=now() WHERE id=?"
                            : "UPDATE channel_sync_logs SET status=?, response_payload=?::jsonb, error_message=?, completed_at=now() WHERE id=?";
                        $args = $hasFxAudit
                            ? [$result['ok'] ? 'success' : 'failed', json_encode(['applied' => $result['applied'], 'message' => $result['message']]), $result['ok'] ? null : mb_substr($errMsg, 0, 1000), json_encode($result['fx_audit'] ?? []), $logId]
                            : [$result['ok'] ? 'success' : 'failed', json_encode(['applied' => $result['applied'], 'message' => $result['message']]), $result['ok'] ? null : mb_substr($errMsg, 0, 1000), $logId];
                        $pdo->prepare($upd)->execute($args);
                    } else {
                        vnote('satır zaten işlenmiş (cron araya girdi): status=' . (string) $log['status']);
                    }

                    // Öneri satırı oluştu mu?
                    $sug = $pdo->prepare("SELECT room_type_id, rate_plan_id, status, suggestion_count, suggestion_score FROM channel_room_mappings WHERE channel_connection_id=? AND external_room_id=?");
                    $sug->execute([$connId, $code]);
                    $sugRow = $sug->fetch();
                    if ($sugRow && ($sugRow['status'] ?? '') === 'suggested') {
                        vok('öneri oluştu: ' . (string) $code . ' → oda tipi #' . (int) $sugRow['room_type_id'] . ' (suggestion_count=' . (int) $sugRow['suggestion_count'] . ', skor %' . (int) ($sugRow['suggestion_score'] ?? 0) . ')');
                    } else {
                        vbad('suggested öneri bulunamadı — auto_map kapalı veya benzerlik eşiği aşılamadı');
                    }

                    // Bildirim düştü mü?
                    if ($supplierId > 0) {
                        $notif = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_type='supplier' AND type='channel_mapping_suggestion' AND message LIKE ? AND created_at > now() - interval '10 minutes'");
                        $notif->execute(['%' . $code . '%']);
                        $notifCount = (int) $notif->fetchColumn();
                        $notifCount > 0
                            ? vok('tedarikçi bildirimi oluştu (channel_mapping_suggestion, ' . $notifCount . ' kayıt)')
                            : vbad('tedarikçi bildirimi bulunamadı — notifications tablosunu kontrol edin');
                    } else {
                        vnote('tedarikçi id bulunamadı — bildirim kontrolü atlandı');
                    }

                    // Öneri oluştuğunda veri YAZILMAMIŞ olmalı (onay bekliyor).
                    $invBefore = $pdo->prepare('SELECT COUNT(*) FROM inventory_calendar WHERE room_type_id=? AND rate_plan_id=? AND stay_date=?');
                    $invBefore->execute([(int) ($sugRow['room_type_id'] ?? 0), (int) ($sugRow['rate_plan_id'] ?? 0), $testDate]);
                    ((int) $invBefore->fetchColumn() === 0)
                        ? vok('öneri beklerken takvime yazılmadı (beklenen güvenlik davranışı)')
                        : vbad('öneri beklerken takvime satır yazıldı — güvenlik kontrolü başarısız');

                    // ─────────────────────── 4) ONAY + YAZIM ───────────────────────
                    vsection('4) ONAY + YAZIM — suggested → confirmed → takvim');
                    $confirmRoom = (int) ($sugRow['room_type_id'] ?? 0);
                    $confirmPlan = (int) ($sugRow['rate_plan_id'] ?? 0);
                    if ($confirmPlan <= 0) $confirmPlan = (int) $planRow['id']; // plan ipucu yoksa ilk aktif plan
                    $pdo->prepare("UPDATE channel_room_mappings SET status='confirmed', suggested_at=NULL, rate_plan_id=? WHERE channel_connection_id=? AND external_room_id=?")
                        ->execute([$confirmPlan, $connId, $code]);
                    if ($planCode !== '') {
                        $pdo->prepare("UPDATE channel_rate_plan_mappings SET status='confirmed', suggested_at=NULL, rate_plan_id=? WHERE channel_connection_id=? AND external_rate_plan_id=?")
                            ->execute([$confirmPlan, $connId, $planCode]);
                    }
                    vok('öneri onaylandı (oda tipi #' . $confirmRoom . ' · plan #' . $confirmPlan . ($planCode !== '' ? ', plan ipucu ' . $planCode . ' dahil' : '') . ' — dağıtım merkezi bölüm 3teki onayın aynısı)');

                    // Onaylanan eşleşmeyle AYNI yükü yeniden işle → takvime yazılmalı.
                    $pdo->prepare("INSERT INTO channel_sync_logs(channel_connection_id, property_id, direction, scope, status, request_payload, response_payload) VALUES(?,?, 'pull', ?, 'queued', ?::jsonb, ?::jsonb)")
                        ->execute([$connId, $propId, 'rates', $body, json_encode(['received_at' => gmdate('c')])]);
                    $log2Id = (int) $pdo->lastInsertId();
                    $pdo->prepare("UPDATE channel_sync_logs SET status='running', attempt_count=attempt_count+1 WHERE id=?")->execute([$log2Id]);
                    $job2 = $pdo->query('SELECT * FROM channel_sync_logs WHERE id=' . $log2Id)->fetch();
                    $pl2 = json_decode((string) ($job2['request_payload'] ?? '{}'), true);
                    if (!is_array($pl2)) $pl2 = [];
                    $res2 = channel_webhook_apply($job2, $pl2);
                    $err2 = (string) $res2['message'] . (isset($res2['errors']) ? ' [' . implode(',', array_slice($res2['errors'], 0, 4)) . ']' : '');
                    $upd2 = $hasFxAudit
                        ? "UPDATE channel_sync_logs SET status=?, response_payload=?::jsonb, error_message=?, fx_audit=?::jsonb, completed_at=now() WHERE id=?"
                        : "UPDATE channel_sync_logs SET status=?, response_payload=?::jsonb, error_message=?, completed_at=now() WHERE id=?";
                    $args2 = $hasFxAudit
                        ? [$res2['ok'] ? 'success' : 'failed', json_encode(['applied' => $res2['applied'], 'message' => $res2['message']]), $res2['ok'] ? null : mb_substr($err2, 0, 1000), json_encode($res2['fx_audit'] ?? []), $log2Id]
                        : [$res2['ok'] ? 'success' : 'failed', json_encode(['applied' => $res2['applied'], 'message' => $res2['message']]), $res2['ok'] ? null : mb_substr($err2, 0, 1000), $log2Id];
                    $pdo->prepare($upd2)->execute($args2);

                    // Takvim yazımı kontrolü.
                    $inv = $pdo->prepare('SELECT base_price, allotment FROM inventory_calendar WHERE room_type_id=? AND rate_plan_id=? AND stay_date=?');
                    $inv->execute([$confirmRoom, $confirmPlan, $testDate]);
                    $invRow = $inv->fetch();
                    if ($rate > 0) {
                        $expected = round($price * $rate, 2);
                        if ($invRow && abs((float) $invRow['base_price'] - $expected) < 0.01) {
                            vok('takvim yazımı: ' . $price . ' ' . $inCur . ' → ' . number_format((float) $invRow['base_price'], 2, '.', '') . ' ' . $planCur . ' (beklenen ' . $expected . ', kur ' . number_format($rate, 4, '.', '') . ')');
                        } elseif (!$invRow) {
                            vbad('takvim yazımı bulunamadı — beklenen ' . $expected . ' ' . $planCur);
                        } else {
                            vbad('takvim fiyatı uyuşmuyor: ' . number_format((float) $invRow['base_price'], 2, '.', '') . ' vs beklenen ' . $expected);
                        }
                    } else {
                        (!$invRow)
                            ? vok('fx koruması: ' . $inCur . '→' . $planCur . ' kuru eksik, fiyat yazılmadı (beklenen — kur ekleyince testi tekrar çalıştırın)')
                            : vbad('fx koruması davranışı bozuk — kur eksikken fiyat yazıldı!');
                    }

                    // fx_audit kontrolü (kur varsa ve birimler farklıysa).
                    if ($hasFxAudit && $rate > 0 && $inCur !== $planCur) {
                        $fxList = json_decode((string) ($res2['fx_audit'] ?? '[]'), true);
                        $fxFound = false;
                        if (is_array($fxList)) {
                            foreach ($fxList as $fx) {
                                if (($fx['from'] ?? '') === $inCur && ($fx['to'] ?? '') === $planCur) $fxFound = true;
                            }
                        }
                        $fxFound
                            ? vok("fx_audit kaydı: $inCur→$planCur dönüşüm işlendi")
                            : vbad("fx_audit kaydı bulunamadı ($inCur→$planCur)");
                    } else {
                        vnote('fx_audit kontrolü atlandı (kur yok / birimler aynı / kolon eksik)');
                    }

                    // ─────────────────────── 5) TEMİZLİK ───────────────────────
                    vsection('5) TEMİZLİK');
                    if (!$keep) {
                        $pdo->prepare("DELETE FROM channel_sync_logs WHERE channel_connection_id=? AND request_payload::text LIKE ? AND created_at > now() - interval '10 minutes'")->execute([$connId, '%' . $code . '%']);
                        $pdo->prepare('DELETE FROM channel_room_mappings WHERE channel_connection_id=? AND external_room_id=?')->execute([$connId, $code]);
                        if ($planCode !== '') $pdo->prepare('DELETE FROM channel_rate_plan_mappings WHERE channel_connection_id=? AND external_rate_plan_id=?')->execute([$connId, $planCode]);
                        $pdo->prepare('DELETE FROM inventory_calendar WHERE room_type_id=? AND rate_plan_id=? AND stay_date=?')->execute([$confirmRoom, $confirmPlan, $testDate]);
                        $pdo->prepare("DELETE FROM notifications WHERE user_type='supplier' AND type='channel_mapping_suggestion' AND message LIKE ? AND created_at > now() - interval '10 minutes'")->execute(['%' . $code . '%']);
                        vnote("temizlik tamam (loglar, öneri '$code', takvim $testDate, bildirimler)");
                    } else {
                        vnote('--keep: test satırları bırakıldı (kod ' . $code . ')');
                    }
                }
            }
        }
    }

    // ─────────────────────────────── SONUÇ ───────────────────────────────
    vsection('SONUÇ');
    echo $failures === 0
        ? "  ✓ Webhook uçtan uca testi geçti: $checks kontrol, 0 hata.\n"
        : "  ✗ $failures hata / $checks kontrol — yukarıdaki ✗ satırlarını inceleyin.\n";
} catch (Throwable $e) {
    $failures++;
    echo "\n✗ Betik hatası: " . $e->getMessage() . "\n";
    if (isset($pdo) && $pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable $ignored) {}
    }
    echo "SONUÇ: 1 hata (betik tamamlanamadı)\n";
}

exit($failures > 0 ? 1 : 0);
