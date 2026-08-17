<?php
declare(strict_types=1);

// scripts/verify-all.php — sunucu güncellemesi sonrası uçtan uca doğrulama betiği.
//
// Tek komutla şunları test eder:
//   1) YAPI   — scripts/health-check.php (migration'ları uygular + raporlar) ve
//               scripts/verify-platform.php (tablo/kolon/migration durumu)
//   2) ŞEMA   — yeni tablolar/kolonlar (fx_audit_daily, channel_room_mappings,
//               channel_rate_plan_mappings, channel_sync_logs.fx_audit, step_targets)
//   3) GÖREVLER — config/scheduler.php varsayılan görevlerinin tamamı kayıtlı mı,
//               komut dosyaları diskte mi; --run-jobs ile güvenli tanı görevleri de çalıştırılır
//   4) WEBHOOK AKIŞI — kanal + ürün seçimi, fx kapsama kontrolü, tanınmayan kod → öneri
//               akışı (auto_map), --deep ile geçici onaylı eşleştirmeyle gerçek fiyat yazma
//               + fx_audit kaydı, --http ile canlı HTTP ucu üzerinden kuyruğa alma.
//
// Test verisi işlem içi testlerde tek transaction içinde yürütülüp rollback edilir
// (hiçbir kalıntı kalmaz); --http kuyruk satırı ise hemen silinir.
//
// Kullanım:
//   php scripts/verify-all.php                      # yapı + şema + görev kaydı + webhook (salt)
//   php scripts/verify-all.php --run-jobs           # güvenli tanı görevlerini de çalıştırır (e-posta kuyruğuna yazabilir)
//   php scripts/verify-all.php --deep               # geçici onaylı eşleştirmeyle fiyat yazma + fx testi
//   php scripts/verify-all.php --http               # webhook'u canlı HTTP ucu üzerinden de dener
// Çıkış kodu: 0 = tüm kontroller geçti, 1 = en az bir hata.

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/fx.php';
require_once __DIR__ . '/../config/channel_webhook.php';
require_once __DIR__ . '/../config/scheduler.php';

$runJobs = in_array('--run-jobs', $argv, true);
$deep = in_array('--deep', $argv, true);
$http = in_array('--http', $argv, true);

$failures = 0;
$checks = 0;

function vok(string $msg): void { global $checks; $checks++; echo "  ✓ $msg\n"; }
function vbad(string $msg): void { global $checks, $failures; $checks++; $failures++; echo "  ✗ $msg\n"; }
function vnote(string $msg): void { echo "  · $msg\n"; }
function vsection(string $t): void { echo "\n=== $t ===\n"; }

function vproc(array $cmd): array
{
    $cmdStr = implode(' ', array_map('escapeshellarg', $cmd));
    $out = [];
    exec($cmdStr . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

function vtable_exists(PDO $pdo, string $table): bool
{
    $r = $pdo->prepare("SELECT to_regclass('public.' || ?)");
    $r->execute([$table]);
    $v = $r->fetchColumn();
    return $v !== null && $v !== false;
}

function vcolumn_exists(PDO $pdo, string $table, string $column): bool
{
    $q = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name=? AND column_name=?");
    $q->execute([$table, $column]);
    return (bool) $q->fetchColumn();
}

echo "NEXUS Uçtan Uca Doğrulama — " . date('Y-m-d H:i:s') . ' · PHP ' . PHP_VERSION . "\n";

try {
    $pdo = db();

    // ───────────────────────────────────────────── 1) YAPI ─────────────────────────────────────────────
    vsection('1) YAPI — health-check + verify-platform');
    [$hc, $hcOut] = vproc([PHP_BINARY, __DIR__ . '/health-check.php']);
    $hcLines = explode("\n", $hcOut);
    $hcTail = trim((string) end($hcLines));
    if ($hc === 0) {
        vok('health-check: temiz (çıkış 0)');
    } else {
        vbad('health-check hata verdi (çıkış ' . $hc . '): ' . $hcTail);
    }
    vnote('health-check son satır: ' . $hcTail);
    if (preg_match('/\d+ migration bekliyor/', $hcOut, $m)) {
        vnote($m[0]);
    }

    [$vp, $vpOut] = vproc([PHP_BINARY, __DIR__ . '/verify-platform.php']);
    $vpLines = explode("\n", $vpOut);
    $vpTail = trim((string) end($vpLines));
    if ($vp === 0) {
        vok('verify-platform: tüm tablolar/kolonlar/migration dosyaları hazır (çıkış 0)');
    } else {
        vbad('verify-platform hata verdi (çıkış ' . $vp . ')');
    }
    if (str_contains($vpOut, 'Migration durumu')) {
        foreach (explode("\n", $vpOut) as $line) {
            if (str_contains($line, 'Migration durumu') || str_contains($line, 'bekliyor')) {
                vnote(trim($line));
            }
        }
    }

    // ───────────────────────────────────────────── 2) ŞEMA ─────────────────────────────────────────────
    vsection('2) ŞEMA — yeni tablolar ve kritik kolonlar');
    foreach (['fx_audit_daily', 'channel_room_mappings', 'channel_rate_plan_mappings'] as $t) {
        vtable_exists($pdo, $t) ? vok("tablo $t var") : vbad("tablo $t YOK");
    }
    $colChecks = [
        ['channel_room_mappings', ['channel_connection_id', 'property_id', 'room_type_id', 'external_room_id', 'rate_plan_id', 'status', 'suggested_at', 'suggestion_count', 'suggestion_score']],
        ['channel_rate_plan_mappings', ['channel_connection_id', 'property_id', 'external_rate_plan_id', 'rate_plan_id', 'status']],
        ['channel_sync_logs', ['fx_audit']],
        ['fx_audit_daily', ['audit_date', 'missing_count', 'stale_count', 'details']],
        ['product_type_catalog', ['step_targets']],
    ];
    foreach ($colChecks as [$tbl, $cols]) {
        if (!vtable_exists($pdo, $tbl)) {
            vbad("tablo $tbl yok — kolon kontrolü atlandı");
            continue;
        }
        $missing = array_values(array_filter($cols, fn($c) => !vcolumn_exists($pdo, $tbl, $c)));
        $missing === [] ? vok("$tbl kolonları tam (" . count($cols) . ')') : vbad("$tbl eksik kolon: " . implode(', ', $missing));
    }
    // Migration durumu (schema_migrations + glob karşılaştırması — sağlık kontrolüyle aynı yöntem).
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, file VARCHAR(190) NOT NULL UNIQUE, applied_at TIMESTAMPTZ NOT NULL DEFAULT now())');
        $applied = array_flip($pdo->query('SELECT file FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
        $files = glob(__DIR__ . '/../database/migrations/[0-9][0-9][0-9]-*.sql');
        sort($files);
        $pending = array_values(array_filter($files, fn($f) => !isset($applied[basename($f)])));
        $pending === [] ? vok('migration durumu: ' . count($files) . ' dosyanın tamamı uygulanmış') : vbad(count($pending) . ' migration bekliyor: ' . implode(', ', array_map('basename', array_slice($pending, 0, 8))));
    } catch (Throwable $e) {
        vnote('schema_migrations okunamadı: ' . $e->getMessage());
    }

    // ───────────────────────────────────────────── 3) GÖREVLER ─────────────────────────────────────────────
    vsection('3) GÖREVLER — zamanlayıcı kayıtları');
    $expectedJobs = [
        'nexus-sync-ical', 'nexus-revenue-rec', 'nexus-netgsm-sms', 'nexus-process-emails',
        'nexus-process-webhooks', 'nexus-channel-webhook-process', 'nexus-welcome-emails',
        'nexus-notification-digest', 'nexus-expire-group-options', 'nexus-chat-digest',
        'nexus-chat-weekly', 'nexus-flag-abusive-ips', 'nexus-monthly-report',
        'nexus-job-fail-alerts', 'nexus-ical-inactive-alerts', 'nexus-channel-inactive-alerts',
        'nexus-channel-sync-job-alerts', 'nexus-channel-webhook-loop-alerts',
        'nexus-distribution-health-digest', 'nexus-job-status-digest', 'nexus-feature-trash-purge',
        'nexus-fx-missing-audit', 'nexus-ical-repeat-alerts', 'nexus-channel-webhook-retry',
        'nexus-health-check', 'nexus-admin-alert-test', 'nexus-room-mapping-audit',
        'nexus-suggestion-cleanup',
    ];
    scheduler_seed_defaults();
    $registered = [];
    foreach ($pdo->query('SELECT code, command, enabled FROM scheduled_jobs') as $row) {
        $registered[$row['code']] = $row;
    }
    foreach ($expectedJobs as $code) {
        if (!isset($registered[$code])) {
            vbad("görev kayıtlı değil: $code");
            continue;
        }
        $file = $registered[$code]['command'];
        if (!file_exists(__DIR__ . '/../' . ltrim((string) $file, '/'))) {
            vbad("görev komutu diskte yok: $file ($code)");
            continue;
        }
        $enabled = (string) $registered[$code]['enabled'] === 't' || (bool) $registered[$code]['enabled'];
        if (!$enabled) {
            vnote("görev kapalı: $code");
        }
    }
    vok(count($expectedJobs) . ' görev beklentisi tarandı; ' . count($registered) . ' kayıt var');

    if ($runJobs) {
        $safeJobs = [
            'nexus-fx-missing-audit', 'nexus-channel-webhook-process', 'nexus-channel-webhook-loop-alerts',
            'nexus-channel-inactive-alerts', 'nexus-ical-inactive-alerts', 'nexus-ical-repeat-alerts',
            'nexus-channel-sync-job-alerts', 'nexus-room-mapping-audit', 'nexus-feature-trash-purge',
            'nexus-channel-webhook-retry',
        ];
        foreach ($safeJobs as $code) {
            if (!isset($registered[$code])) {
                vbad("çalıştırılamadı (kayıt yok): $code");
                continue;
            }
            $job = $pdo->prepare('SELECT * FROM scheduled_jobs WHERE code=?');
            $job->execute([$code]);
            $row = $job->fetch();
            if (!$row) continue;
            echo '  · çalıştırılıyor: ' . $code . ' …';
            $res = scheduler_run_job($row);
            $outTail = trim((string) $res['output']);
            if ($res['status'] === 'ok') {
                vok($code . ' → ' . (strlen($outTail) > 150 ? mb_substr($outTail, 0, 150) . '…' : $outTail));
            } else {
                vbad($code . ' → ' . (strlen($outTail) > 250 ? mb_substr($outTail, 0, 250) . '…' : $outTail));
            }
        }
    } else {
        vnote('Görevleri çalıştırmak için: --run-jobs (güvenli tanı görevleri; e-posta kuyruğuna yazabilir)');
    }

    // ───────────────────────────────────────────── 4) WEBHOOK AKIŞI ─────────────────────────────────────────────
    vsection('4) WEBHOOK AKIŞI — kanal + öneri + fx');
    $autoMap = (bool) platform_setting('channel_webhook_auto_map', true);
    vnote('channel_webhook_auto_map: ' . ($autoMap ? 'açık (tanınmayan kod → öneri)' : 'kapalı (ilk aktif oda tipine yazar)'));

    // Fx kapsama kontrolü (salt) — aktif plan hedefleri + görülen gelen birimler arası eksikler.
    $tg = $pdo->query("SELECT DISTINCT UPPER(currency) AS c FROM rate_plans WHERE status='active' AND currency IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    $tg = array_values(array_filter($tg, fn($c) => preg_match('/^[A-Z]{3}$/', (string) $c)));
    $inc = [];
    $dflt = strtoupper((string) platform_setting('channel_webhook_default_currency', 'EUR'));
    if (preg_match('/^[A-Z]{3}$/', $dflt)) $inc[$dflt] = true;
    $pr = $pdo->query("SELECT request_payload FROM channel_sync_logs WHERE direction='pull' AND request_payload IS NOT NULL AND created_at >= now() - interval '30 days'")->fetchAll();
    foreach ($pr as $cr) {
        $dec = json_decode((string) $cr['request_payload'], true);
        if (!is_array($dec)) continue;
        foreach ((array) ($dec['entries'] ?? []) as $en) {
            if (is_array($en) && isset($en['currency']) && is_string($en['currency'])) {
                $c = strtoupper($en['currency']);
                if (preg_match('/^[A-Z]{3}$/', $c)) $inc[$c] = true;
            }
        }
    }
    $fxMissing = [];
    foreach (array_keys($inc) as $from) {
        foreach ($tg as $to) {
            if ($from === $to) continue;
            if (fx_rate($from, $to) <= 0) $fxMissing[$from . '->' . $to] = true;
        }
    }
    $fxMissing === [] ? vok('fx kapsama: gerekli tüm çiftler mevcut') : vnote('fx eksik çiftler: ' . implode(', ', array_keys($fxMissing)) . ' — kur-yonetimi sayfasindaki eksik doldurma butonu ile kapatilabilir');

    $conn = $pdo->query("SELECT id, supplier_id, access_token, display_name, status FROM channel_connections WHERE status='active' ORDER BY id LIMIT 1")->fetch();
    if (!$conn) {
        vnote('Aktif kanal bağlantısı yok — webhook akışı atlandı.');
    } else {
        $prop = $pdo->prepare('SELECT id FROM properties WHERE supplier_id=? ORDER BY id LIMIT 1');
        $prop->execute([(int) $conn['supplier_id']]);
        $propId = $prop->fetchColumn();
        if (!$propId) {
            $propId = $pdo->query('SELECT id FROM properties ORDER BY id LIMIT 1')->fetchColumn();
        }
        if (!$propId) {
            vnote('Ürün (ilan) yok — webhook akışı atlandı.');
        } else {
            $propId = (int) $propId;
            $testDate = date('Y-m-d', strtotime('+60 days'));

            // Test A — tanınmayan kod → öneri akışı (tek transaction, rollback).
            $codeA = 'VER-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
            $pdo->beginTransaction();
            try {
                $logA = ['channel_connection_id' => (int) $conn['id'], 'property_id' => $propId];
                $payloadA = ['scope' => 'rates', 'currency' => 'USD', 'entries' => [['external_room_id' => $codeA, 'date' => $testDate, 'price' => 100.0]]];
                $resA = channel_webhook_apply($logA, $payloadA);
                $sug = $pdo->prepare('SELECT status FROM channel_room_mappings WHERE channel_connection_id=? AND external_room_id=?');
                $sug->execute([(int) $conn['id'], $codeA]);
                $sugRow = $sug->fetch();
                if ($autoMap) {
                    ($resA['ok'] && $sugRow && $sugRow['status'] === 'suggested' && (int) ($resA['applied'] ?? 0) === 0)
                        ? vok("öneri akışı: '$codeA' → suggested kaydı oluştu, satır yazılmadı")
                        : vbad("öneri akışı: '$codeA' beklenen suggested kaydı oluşmadı (ok=" . var_export($resA['ok'], true) . ', applied=' . (int) ($resA['applied'] ?? 0) . ')');
                } else {
                    ((int) ($resA['applied'] ?? 0) >= 1)
                        ? vok("auto_map kapalı: '$codeA' ilk aktif oda tipine yazıldı (applied=" . (int) ($resA['applied'] ?? 0) . ')')
                        : vbad("auto_map kapalı: '$codeA' beklenen yazma olmadı (applied=" . (int) ($resA['applied'] ?? 0) . ')');
                }
            } finally {
                $pdo->rollBack();
            }

            // Test B (--deep) — geçici onaylı eşleştirmeyle gerçek fiyat yazma + fx_audit (rollback).
            if ($deep) {
                $room = $pdo->prepare("SELECT id FROM room_types WHERE property_id=? AND status='active' ORDER BY id LIMIT 1");
                $room->execute([$propId]);
                $roomId = $room->fetchColumn();
                $plan = $pdo->prepare("SELECT id, currency FROM rate_plans WHERE property_id=? AND status='active' ORDER BY id LIMIT 1");
                $plan->execute([$propId]);
                $planRow = $plan->fetch();
                if (!$roomId || !$planRow) {
                    vnote('--deep: aktif oda tipi / fiyat planı yok — derin test atlandı.');
                } else {
                    $planCur = strtoupper((string) ($planRow['currency'] ?: 'EUR'));
                    if (!preg_match('/^[A-Z]{3}$/', $planCur)) $planCur = 'EUR';
                    $inCur = 'EUR';
                    foreach (['USD', 'EUR', 'GBP'] as $cand) {
                        if ($cand !== $planCur) { $inCur = $cand; break; }
                    }
                    $codeB = 'VER-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
                    $price = 123.45;
                    $rate = fx_rate($inCur, $planCur, $testDate);
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare("INSERT INTO channel_room_mappings(channel_connection_id, property_id, room_type_id, rate_plan_id, external_room_id, status) VALUES(?,?,?,?,?,'confirmed')")
                            ->execute([(int) $conn['id'], $propId, (int) $roomId, (int) $planRow['id'], $codeB]);
                        $logB = ['channel_connection_id' => (int) $conn['id'], 'property_id' => $propId];
                        $payloadB = ['scope' => 'rates', 'currency' => $inCur, 'entries' => [['external_room_id' => $codeB, 'date' => $testDate, 'price' => $price]]];
                        $resB = channel_webhook_apply($logB, $payloadB);
                        if ($rate <= 0) {
                            (!$resB['ok'] && in_array('fx_rate_missing:' . $inCur . '->' . $planCur . ':' . $testDate, (array) ($resB['errors'] ?? []), true))
                                ? vok("fx koruması çalışıyor: $inCur→$planCur kuru eksik → satır yazılmadı (beklenen davranış)")
                                : vbad("fx koruması beklenen hatayı üretmedi (ok=" . var_export($resB['ok'], true) . ')');
                        } else {
                            $expected = round($price * $rate, 2);
                            $inv = $pdo->prepare('SELECT base_price FROM inventory_calendar WHERE room_type_id=? AND rate_plan_id=? AND stay_date=?');
                            $inv->execute([(int) $roomId, (int) $planRow['id'], $testDate]);
                            $basePrice = $inv->fetchColumn();
                            $fxFound = false;
                            foreach ((array) ($resB['fx_audit'] ?? []) as $fx) {
                                if (($fx['from'] ?? '') === $inCur && ($fx['to'] ?? '') === $planCur) $fxFound = true;
                            }
                            $okWrite = $basePrice !== false && abs((float) $basePrice - $expected) < 0.01;
                            $okWrite ? vok("derin yazma: '$codeB' → $price $inCur = $expected $planCur (kur $rate) yazıldı") : vbad("derin yazma: '$codeB' beklenen fiyat bulunamadı (beklenen $expected, bulunan " . var_export($basePrice, true) . ')');
                            $fxFound ? vok("fx_audit kaydı: $inCur→$planCur dönüşüm işlenmiş") : vbad('fx_audit kaydı bulunamadı');
                        }
                    } finally {
                        $pdo->rollBack();
                    }
                }
            }

            // Test C (--http) — canlı HTTP ucu üzerinden kuyruğa alma + temizlik.
            if ($http) {
                $ext = '';
                $em = $pdo->prepare('SELECT external_property_id FROM channel_property_mappings WHERE channel_connection_id=? ORDER BY id LIMIT 1');
                $em->execute([(int) $conn['id']]);
                $ext = (string) ($em->fetchColumn() ?: '');
                $codeC = 'VER-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
                $body = json_encode(['scope' => 'rates', 'external_property_id' => $ext, 'currency' => 'USD', 'entries' => [['external_room_id' => $codeC, 'date' => $testDate, 'price' => 100.0]]]);
                $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $body, 'ignore_errors' => true, 'timeout' => 15]]);
                $resp = @file_get_contents('https://nexustraveltech.com/api/channel-webhook?token=' . (string) $conn['access_token'], false, $ctx);
                $dec = is_string($resp) ? json_decode($resp, true) : null;
                if (is_array($dec) && ($dec['ok'] ?? false) && ($dec['queued'] ?? false)) {
                    vok("HTTP webhook: kuyruğa alındı (scope=" . ($dec['scope'] ?? '?') . ')');
                } else {
                    vbad('HTTP webhook başarısız: ' . (is_string($resp) ? mb_substr($resp, 0, 160) : 'yanıt yok'));
                }
                // Kuyruk satırını ve (işleyici araya girerse) oluşabilecek öneriyi temizle.
                $del = $pdo->prepare("DELETE FROM channel_sync_logs WHERE channel_connection_id=? AND request_payload::text LIKE ? AND created_at > now() - interval '10 minutes'");
                $del->execute([(int) $conn['id'], '%' . $codeC . '%']);
                $delM = $pdo->prepare('DELETE FROM channel_room_mappings WHERE channel_connection_id=? AND external_room_id=?');
                $delM->execute([(int) $conn['id'], $codeC]);
                vnote("HTTP test kalıntıları temizlendi ('$codeC')");
            }
        }
    }

    // ───────────────────────────────────────────── SONUÇ ─────────────────────────────────────────────
    vsection('SONUÇ');
    echo $failures === 0
        ? "  ✓ Tüm kontroller geçti: $checks kontrol, 0 hata.\n"
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
