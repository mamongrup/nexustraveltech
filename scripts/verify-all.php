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
//               + fx_audit kaydı, --http ile canlı HTTP ucu üzerinden kuyruğa alma
//               (gerçek curl ikilisi önceliklidir, yoksa PHP file_get_contents yedeği)
//   5) KİLİT  — zamanlayıcı advisory kilidi (424242) serbest mi; --run-jobs ile tick.php
//               gerçekten çalıştırılıp {"locked":false,"ran":[...]} doğrulanır
//
// Test verisi işlem içi testlerde tek transaction içinde yürütülüp rollback edilir
// (hiçbir kalıntı kalmaz); --http kuyruk satırı ise hemen silinir.
//
// Kullanım:
//   php scripts/verify-all.php                      # yapı + şema + görev kaydı + webhook (salt)
//   php scripts/verify-all.php --run-jobs           # güvenli tanı görevleri + tick + kilit doğrulaması
//   php scripts/verify-all.php --deep               # geçici onaylı eşleştirmeyle fiyat yazma + fx testi + plan ipucu (oda+plan önerisi) + kısıt-plan eşleştirmesi
//   php scripts/verify-all.php --http               # webhook'u canlı HTTP ucu üzerinden (curl) dener
//   php scripts/verify-all.php --run-jobs --deep --http   # tam uçtan uca koşu
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
    // Yeni entegrasyon kolonları özeti — verify-platform ile aynı 11 kolon (047-055/061).
    // Hepsi tam değilse sonuçta HATA sayılır (günlük verify-all görevi e-posta üretir).
    $newCols = [
        'channel_sync_logs.fx_audit' => '048',
        'channel_room_mappings.status' => '047',
        'channel_room_mappings.suggested_at' => '047',
        'channel_room_mappings.suggestion_count' => '047',
        'channel_room_mappings.rate_plan_id' => '049',
        'channel_room_mappings.suggestion_score' => '052',
        'product_type_catalog.step_targets' => '053',
        'channel_rate_plan_mappings.external_rate_plan_id' => '054',
        'fx_audit_daily.audit_date' => '055',
        'channel_room_mappings.approved_by_type' => '061',
        'channel_room_mappings.approved_at' => '061',
    ];
    $newMissing = [];
    foreach ($newCols as $colPath => $mig) {
        [$nt, $nc] = explode('.', $colPath, 2);
        if (!vtable_exists($pdo, $nt) || !vcolumn_exists($pdo, $nt, $nc)) {
            $newMissing[] = $colPath . ' (migration ' . $mig . ') ';
        }
    }
    if ($newMissing === []) {
        vok('yeni entegrasyon kolonları (047-055/061): ' . count($newCols) . '/' . count($newCols) . ' hazır');
    } else {
        vbad((count($newCols) - count($newMissing)) . '/' . count($newCols) . ' entegrasyon kolonu hazır — eksik: ' . implode(', ', $newMissing) . ' (scripts/health-check.php migration 047-055/061 uygular)');
    }
    // Migration durumu (schema_migrations + glob karşılaştırması — sağlık kontrolüyle aynı yöntem).
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, file VARCHAR(190) NOT NULL UNIQUE, applied_at TIMESTAMPTZ NOT NULL DEFAULT now())');
        $applied = array_flip($pdo->query('SELECT file FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
        // Yalnızca *-postgres.sql dosyaları sayılır — legacy MySQL dosyaları (002-008 vb.)
        // health-check/verify-platform ile aynı şekilde atlanır; aksi halde her gün yanlış
        // 'N migration bekliyor' hatası + e-posta üretilir.
        $files = glob(__DIR__ . '/../database/migrations/*-postgres.sql');
        sort($files);
        $legacyCount = count(array_filter(glob(__DIR__ . '/../database/migrations/[0-9][0-9][0-9]-*.sql'), fn($f) => !str_contains($f, '-postgres')));
        $pending = array_values(array_filter($files, fn($f) => !isset($applied[basename($f)])));
        $pending === [] ? vok('migration durumu: ' . count($files) . ' postgres dosyasının tamamı uygulanmış (' . $legacyCount . ' legacy atlandı)') : vbad(count($pending) . ' postgres migration bekliyor: ' . implode(', ', array_map('basename', array_slice($pending, 0, 8))));
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
        'nexus-fx-missing-audit', 'nexus-ical-repeat-alerts',        'nexus-channel-webhook-retry',
        'nexus-health-check', 'nexus-verify-all', 'nexus-admin-alert-test', 'nexus-room-mapping-audit',
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

            // Test A2 (--deep) — plan ipucu çözümü: tanınmayan ODA + FİYAT PLANI kodlu webhook,
            // oda önerisiyle birlikte fiyat planı önerisi de oluşturmalı; oda önerisinin planı
            // ipucuyla (external_rate_plan_id) çözülen planla aynı olmalı (tek transaction, rollback).
            // Oda/plan kodları gerçek adlardan türetilir (benzerlik ~1.0, eşiği garantili aşar)
            // ama zaten eşleştirilmemiş olmaları garanti edilir — öneri akışı tetiklenir.
            if ($deep) {
                $rSel = $pdo->prepare("SELECT id, name FROM room_types WHERE property_id=? AND status='active' ORDER BY id LIMIT 1");
                $rSel->execute([$propId]);
                $rRow = $rSel->fetch();
                $pSel = $pdo->prepare("SELECT id, name FROM rate_plans WHERE property_id=? AND status='active' ORDER BY id LIMIT 1");
                $pSel->execute([$propId]);
                $pRow = $pSel->fetch();
                if (!$rRow || !$pRow) {
                    vnote('--deep (plan ipucu): aktif oda tipi / fiyat planı yok — test atlandı.');
                } elseif (!$autoMap) {
                    vnote('--deep (plan ipucu): channel_webhook_auto_map kapalı — öneri akışı atlandı.');
                } else {
                    $codeBase = function (string $name): string { return trim(strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name)), '-'); };
                    $codeA2 = $codeBase((string) $rRow['name']);
                    $occupied = $pdo->prepare('SELECT 1 FROM channel_room_mappings WHERE channel_connection_id=? AND external_room_id=? LIMIT 1');
                    $occPlan = $pdo->prepare('SELECT 1 FROM channel_rate_plan_mappings WHERE channel_connection_id=? AND external_rate_plan_id=? LIMIT 1');
                    $tries = 0;
                    do {
                        $occupied->execute([(int) $conn['id'], $codeA2]);
                        if (!$occupied->fetchColumn()) break;
                        $codeA2 .= '-' . strtoupper(substr(bin2hex(random_bytes(1)), 0, 2));
                        $tries++;
                    } while ($tries < 5);
                    $planCodeA2 = $codeBase((string) $pRow['name']);
                    $tries = 0;
                    do {
                        $occPlan->execute([(int) $conn['id'], $planCodeA2]);
                        if (!$occPlan->fetchColumn()) break;
                        $planCodeA2 .= '-' . strtoupper(substr(bin2hex(random_bytes(1)), 0, 2));
                        $tries++;
                    } while ($tries < 5);
                    $pdo->beginTransaction();
                    try {
                        $logA2 = ['channel_connection_id' => (int) $conn['id'], 'property_id' => $propId];
                        $payloadA2 = ['scope' => 'rates', 'currency' => 'USD', 'entries' => [['external_room_id' => $codeA2, 'external_rate_plan_id' => $planCodeA2, 'date' => $testDate, 'price' => 100.0]]];
                        $resA2 = channel_webhook_apply($logA2, $payloadA2);
                        $sug2 = $pdo->prepare('SELECT status, rate_plan_id FROM channel_room_mappings WHERE channel_connection_id=? AND external_room_id=?');
                        $sug2->execute([(int) $conn['id'], $codeA2]);
                        $sug2Row = $sug2->fetch();
                        $psug2 = $pdo->prepare('SELECT status, rate_plan_id FROM channel_rate_plan_mappings WHERE channel_connection_id=? AND external_rate_plan_id=?');
                        $psug2->execute([(int) $conn['id'], $planCodeA2]);
                        $psug2Row = $psug2->fetch();
                        $roomOk2 = $resA2['ok'] && $sug2Row && $sug2Row['status'] === 'suggested' && (int) ($resA2['applied'] ?? 0) === 0;
                        $roomOk2
                            ? vok("plan ipucu: '$codeA2' oda önerisi oluştu (applied=0)")
                            : vbad("plan ipucu: '$codeA2' oda önerisi oluşmadı (ok=" . var_export($resA2['ok'], true) . ', applied=' . (int) ($resA2['applied'] ?? 0) . ')');
                        $planOk2 = $psug2Row && $psug2Row['status'] === 'suggested' && (int) $psug2Row['rate_plan_id'] > 0;
                        $planOk2
                            ? vok("plan ipucu: '$planCodeA2' → plan #" . (int) ($psug2Row['rate_plan_id'] ?? 0) . ' önerisi oluştu (onay bekliyor)')
                            : vbad("plan ipucu: '$planCodeA2' beklenen plan önerisi oluşmadı (status=" . var_export($psug2Row['status'] ?? null, true) . ', rate_plan_id=' . var_export($psug2Row['rate_plan_id'] ?? null, true) . ')');
                        $hintOk2 = $sug2Row && $psug2Row && (int) ($sug2Row['rate_plan_id'] ?? 0) > 0 && (int) $sug2Row['rate_plan_id'] === (int) $psug2Row['rate_plan_id'];
                        $hintOk2
                            ? vok('plan ipucu çözümü: oda önerisi plan #' . (int) ($sug2Row['rate_plan_id'] ?? 0) . ' ipucuyla çözüldü — plan önerisiyle aynı')
                            : vbad('plan ipucu çözümü: oda önerisi planı plan önerisiyle eşleşmedi');
                    } finally {
                        $pdo->rollBack();
                    }
                }
            }

            // Test A3 (--deep) — kısıt (restrictions) kapsamında plan eşleştirmesi: dış fiyat planı
            // kodu ipucu, oda eşleştirmesindeki plandan ÖNCE gelir — kısıt satırı ipucuyla çözülen
            // planın satırına yazılır, oda eşleştirmesinin planına sızmaz (tek transaction, rollback).
            if ($deep) {
                $plansX = $pdo->prepare("SELECT id FROM rate_plans WHERE property_id=? AND status='active' ORDER BY id LIMIT 2");
                $plansX->execute([$propId]);
                $planIdsX = $plansX->fetchAll(PDO::FETCH_COLUMN);
                $roomX = $pdo->prepare("SELECT id FROM room_types WHERE property_id=? AND status='active' ORDER BY id LIMIT 1");
                $roomX->execute([$propId]);
                $roomIdX = (int) $roomX->fetchColumn();
                if (count($planIdsX) < 2 || $roomIdX <= 0) {
                    vnote('--deep (kısıt-plan): en az 2 aktif fiyat planı + 1 aktif oda tipi gerekli — test atlandı.');
                } else {
                    $planA = (int) $planIdsX[0]; // oda eşleştirmesinin planı
                    $planB = (int) $planIdsX[1]; // dış plan kodu ipucunun çözüleceği plan
                    $codeX = 'RES-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
                    $planCodeX = 'RPLAN-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare("INSERT INTO channel_room_mappings(channel_connection_id, property_id, room_type_id, rate_plan_id, external_room_id, status) VALUES(?,?,?,?,?,'confirmed')")
                            ->execute([(int) $conn['id'], $propId, $roomIdX, $planA, $codeX]);
                        $pdo->prepare("INSERT INTO channel_rate_plan_mappings(channel_connection_id, property_id, rate_plan_id, external_rate_plan_id, status) VALUES(?,?,?,?,'confirmed')")
                            ->execute([(int) $conn['id'], $propId, $planB, $planCodeX]);
                        $logX = ['channel_connection_id' => (int) $conn['id'], 'property_id' => $propId];
                        $payloadX = ['scope' => 'restrictions', 'entries' => [['external_room_id' => $codeX, 'external_rate_plan_id' => $planCodeX, 'date' => $testDate, 'stop_sale' => true, 'min_stay' => 3, 'max_stay' => 5]]];
                        $resX = channel_webhook_apply($logX, $payloadX);
                        $rowX = $pdo->prepare('SELECT min_stay, max_stay, stop_sale FROM inventory_calendar WHERE room_type_id=? AND rate_plan_id=? AND stay_date=?');
                        $rowX->execute([$roomIdX, $planB, $testDate]);
                        $gotX = $rowX->fetch();
                        $leakX = $pdo->prepare('SELECT 1 FROM inventory_calendar WHERE room_type_id=? AND rate_plan_id=? AND stay_date=? LIMIT 1');
                        $leakX->execute([$roomIdX, $planA, $testDate]);
                        $leakVal = (bool) $leakX->fetchColumn();
                        $stopOk = is_array($gotX) && in_array($gotX['stop_sale'], [true, 't', '1'], true);
                        $okRestr = $resX['ok'] && (int) ($resX['applied'] ?? 0) === 1 && is_array($gotX)
                            && (int) $gotX['min_stay'] === 3 && (int) $gotX['max_stay'] === 5 && $stopOk && !$leakVal;
                        $okRestr
                            ? vok("kısıt-plan: '$codeX' + plan '$planCodeX' → plan #$planB satırına yazıldı (min 3 / max 5 / stop_sale) — oda planı #$planA boş")
                            : vbad('kısıt-plan: kısıt satırı plan eşleştirmesine göre yazılmadı (ok=' . var_export($resX['ok'], true) . ', applied=' . (int) ($resX['applied'] ?? 0) . ', satır=' . var_export($gotX, true) . ', sızıntı=' . var_export($leakVal, true) . ')');
                    } finally {
                        $pdo->rollBack();
                    }
                }
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

            // Test B2 (--deep) — rezervasyon kapsamı: geçici onaylı eşleştirmeyle sold artışı
            // + supplier_bookings/booking_folios PMS kaydı (tek transaction, rollback).
            if ($deep) {
                $rdate = date('Y-m-d', strtotime('+64 days'));
                $codeR = 'VER-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
                $pdo->beginTransaction();
                try {
                    // Takvim satırı önceden var olmalı (sold artışı UPDATE yapar).
                    $pdo->prepare("INSERT INTO inventory_calendar(room_type_id, rate_plan_id, stay_date, allotment, sold, base_price, min_stay, max_stay, stop_sale) VALUES(?,?,?,10,0,0,1,1,false) ON CONFLICT(room_type_id, rate_plan_id, stay_date) DO UPDATE SET sold=0, allotment=10")
                        ->execute([(int) $roomId, (int) $planRow['id'], $rdate]);
                    $pdo->prepare("INSERT INTO channel_room_mappings(channel_connection_id, property_id, room_type_id, rate_plan_id, external_room_id, status) VALUES(?,?,?,?,'confirmed')")
                        ->execute([(int) $conn['id'], $propId, (int) $roomId, (int) $planRow['id'], $codeR]);
                    $logR = ['channel_connection_id' => (int) $conn['id'], 'property_id' => $propId];
                    $payloadR = ['scope' => 'reservations', 'entries' => [['external_room_id' => $codeR, 'date' => $rdate, 'qty' => 3]]];
                    $resR = channel_webhook_apply($logR, $payloadR);
                    // sold artışı doğrula
                    $soldQ = $pdo->prepare('SELECT sold FROM inventory_calendar WHERE room_type_id=? AND rate_plan_id=? AND stay_date=?');
                    $soldQ->execute([(int) $roomId, (int) $planRow['id'], $rdate]);
                    $soldAfter = (int) $soldQ->fetchColumn();
                    $okSold = ($resR['ok'] ?? false) && $soldAfter === 3;
                    $okSold ? vok("rezervasyon kapsamı: sold +3 (şu an {$soldAfter}) — qty=3 uygulandı") : vbad("rezervasyon kapsamı: sold beklenen 3 değil (şu an {$soldAfter}, ok=" . var_export($resR['ok'] ?? null, true) . ' · ' . (string) ($resR['message'] ?? '') . ')');
                    // booking_folios + supplier_bookings PMS kaydı doğrula
                    $folioQ = $pdo->prepare('SELECT COUNT(*) FROM booking_folios f JOIN supplier_bookings b ON b.id=f.booking_id WHERE b.property_id=? AND b.check_in=? AND b.status=\'confirmed\'');
                    $folioQ->execute([$propId, $rdate]);
                    $folioCount = (int) $folioQ->fetchColumn();
                    $okFolio = $folioCount >= 1;
                    $okFolio
                        ? vok("rezervasyon PMS kaydı: {$folioCount} supplier_bookings + booking_folios (check_in={$rdate}) — dönüş reservation_bookings=" . (int) ($resR['reservation_bookings'] ?? 0))
                        : vbad('rezervasyon PMS kaydı: supplier_bookings/booking_folios bulunamadı');
                } finally {
                    $pdo->rollBack();
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
                $url = 'https://nexustraveltech.com/api/channel-webhook?token=' . (string) $conn['access_token'];
                $curlBin = trim((string) @shell_exec('command -v curl 2>/dev/null'));
                if ($curlBin !== '') {
                    $tmp = tempnam(sys_get_temp_dir(), 'vhk');
                    file_put_contents($tmp, $body);
                    $resp = (string) shell_exec(escapeshellarg($curlBin) . ' -s -X POST -H "Content-Type: application/json" --data-binary @' . escapeshellarg($tmp) . ' ' . escapeshellarg($url) . ' 2>&1');
                    @unlink($tmp);
                    $transport = 'curl';
                } else {
                    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $body, 'ignore_errors' => true, 'timeout' => 15]]);
                    $resp = @file_get_contents($url, false, $ctx);
                    $transport = 'file_get_contents (curl bulunamadı)';
                }
                $dec = is_string($resp) ? json_decode($resp, true) : null;
                if (is_array($dec) && ($dec['ok'] ?? false) && ($dec['queued'] ?? false)) {
                    vok("HTTP webhook ($transport): kuyruğa alındı (scope=" . ($dec['scope'] ?? '?') . ')');
                } else {
                    vbad("HTTP webhook ($transport) başarısız: " . (is_string($resp) ? mb_substr($resp, 0, 160) : 'yanıt yok'));
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

    // ───────────────────────────────────────────── 5) ZAMANLAYICI KİLİDİ ─────────────────────────────────────────────
    vsection('5) ZAMANLAYICI KİLİDİ — advisory lock (424242) + tick');
    $lockRows = $pdo->query("SELECT pid, coalesce(to_char(now() - xact_start, 'HH24:MI:SS'), '?') AS age FROM pg_stat_activity WHERE pid IN (SELECT pid FROM pg_locks WHERE locktype='advisory' AND granted AND (classid=424242 OR objid=424242))")->fetchAll();
    if ($lockRows) {
        vbad('advisory kilit (424242) TAKILI: ' . implode(', ', array_map(fn($r) => 'pid=' . $r['pid'] . ' (' . $r['age'] . ')', $lockRows)) . ' — fix-server.sh veya pg_terminate_backend ile serbest bırakın');
    } else {
        vok('advisory kilit (424242) serbest — zamanlayıcı çalışabilir');
    }
    if ($runJobs) {
        [$tk, $tkOut] = vproc([PHP_BINARY, __DIR__ . '/../cron/tick.php']);
        $tkDec = json_decode(trim((string) $tkOut), true);
        if (is_array($tkDec) && ($tkDec['locked'] ?? true) === false) {
            $ran = (array) ($tkDec['ran'] ?? []);
            $ran === []
                ? vbad('tick.php kilit serbest ama hiç görev çalıştırmadı (ran boş) — zamanlayıcı kayıtlarını inceleyin')
                : vok('tick.php: ' . count($ran) . ' görev çalıştı: ' . implode(', ', array_slice($ran, 0, 5)) . (count($ran) > 5 ? ' …' : ''));
        } elseif (is_array($tkDec) && ($tkDec['locked'] ?? true) === true) {
            vbad('tick.php: kilit TAKILI — hiçbir görev koşmuyor');
        } else {
            vbad('tick.php beklenen JSON çıktısı değil: ' . mb_substr(trim((string) $tkOut), 0, 140));
        }
    } else {
        vnote('tick.php çalıştırmak için: --run-jobs (kilidin gerçekten serbest olduğunu uçtan uca doğrular)');
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
