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
    } else {
        vok('aktif kanal: ' . (string) $conn['display_name'] . ' (' . (string) $conn['channel_code'] . ', id=' . (int) $conn['id'] . ')');

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
                $pdo->prepare("INSERT INTO channel_room_mappings(channel_connection_id, property_id, room_type_id, rate_plan_id, external_room_id, status) VALUES(?,?,?,?,'confirmed') ON CONFLICT(channel_connection_id, external_room_id) DO UPDATE SET room_type_id=EXCLUDED.room_type_id, rate_plan_id=EXCLUDED.rate_plan_id, property_id=EXCLUDED.property_id, status='confirmed'")
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
            }
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

exit($failures > 0 ? 1 : 0);
