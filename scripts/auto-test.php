<?php
declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// NEXUS otomatik modül testi — TEK KOMUTLA tüm modüller.
//
//   /opt/plesk/php/8.5/bin/php scripts/auto-test.php            → tüm modüller (salt okunur)
//   /opt/plesk/php/8.5/bin/php scripts/auto-test.php --verbose  → her kontrolü göster
//   /opt/plesk/php/8.5/bin/php scripts/auto-test.php --e2e      → + gerçek webhook uçtan uca (curl POST + uygulama + doğrulama)
//   /opt/plesk/php/8.5/bin/php scripts/auto-test.php --e2e --keep  → e2e test satırları silinmez
//
// Modüller: veritabani · migration · zamanlayici · kanal-webhook · kur · ical · eposta · e2e-webhook
// Her kontrol OK/WARN/FAIL üretir; sonunda modül özeti ve genel sonuç + çıkış kodu.
// E2e modülü: ilk aktif kanal bağlantısını bulur, test oda kodu için ONAYLI eşleştirme
// kurar, kapsam başına (rates/availability/restrictions/reservations) gerçek HTTP POST
// gönderir (cURL varsa cURL, yoksa akış), işleyiciyle aynı kodu satır içi uygular,
// channel_sync_logs + inventory_calendar + fx_audit'i doğrular ve temizler.
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/channel_webhook.php';
require_once __DIR__ . '/../config/fx.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/mailer.php';

$VERBOSE = in_array('--verbose', $argv ?? [], true);
$E2E     = in_array('--e2e', $argv ?? [], true);
$KEEP    = in_array('--keep', $argv ?? [], true);

try {
    $pdo = db();
} catch (Throwable $e) {
    fwrite(STDERR, "Veritabanına bağlanılamadı: " . $e->getMessage() . "\nSunucuda çalıştırın: /opt/plesk/php/8.5/bin/php scripts/auto-test.php\n");
    exit(1);
}
$results = [];   // [modul => [ [ad, durum(ok/warn/fail), detay], ... ] ]
$moduleOrder = [];
$fails = 0; $warns = 0; $oks = 0;

function rec(string $mod, string $name, string $status, string $detail = ''): void {
    global $results, $moduleOrder, $fails, $warns, $oks, $VERBOSE;
    if (!isset($results[$mod])) { $results[$mod] = []; $moduleOrder[] = $mod; }
    $results[$mod][] = [$name, $status, $detail];
    if ($status === 'fail') $fails++;
    elseif ($status === 'warn') $warns++;
    else $oks++;
    if ($VERBOSE) {
        $icon = $status === 'ok' ? '✓' : ($status === 'warn' ? '⚠' : '✗');
        echo "  $icon $name" . ($detail !== '' ? ' — ' . $detail : '') . "\n";
    }
}

function tbl(string $t): bool {
    global $pdo;
    return (bool) $pdo->query("SELECT 1 FROM pg_tables WHERE schemaname='public' AND tablename='" . $t . "'")->fetchColumn();
}

// ─────────────────────────── 1) VERİTABANI ───────────────────────────
$mod = 'veritabani';
$requiredTables = ['suppliers','supplier_users','properties','supplier_bookings','inventory_calendar','channel_connections','channel_property_mappings','ical_connections','ical_events','physical_rooms','booking_folios','folio_transactions','payment_records','payment_allocations','hotel_invoices','night_audit_runs','hotel_staff','hotel_roles','loyalty_tiers','guest_loyalty_accounts','revenue_recommendations','guest_service_requests','login_throttle','guest_reviews','agency_booking_requests','email_outbox','webhook_subscriptions','webhook_deliveries','error_logs','admin_audit_logs','payment_links','fx_rates','booking_groups','notifications','agencies','agency_users','email_templates','admin_2fa','scheduled_jobs','public_chat_messages','blocked_ips','panel_chat_messages','scheduled_job_runs','property_feature_catalog','channel_room_mappings','channel_rate_plan_mappings','feature_delete_backups','channel_sync_logs','ical_sync_logs','pending_trash_purges','fx_audit_daily','channel_mapping_blacklist'];
$missing = [];
foreach ($requiredTables as $t) { if (!tbl($t)) $missing[] = $t; }
rec($mod, count($requiredTables) . ' gerekli tablo', $missing === [] ? 'ok' : 'fail', $missing === [] ? 'tümü mevcut' : 'eksik: ' . implode(', ', array_slice($missing, 0, 6)) . (count($missing) > 6 ? ' … +' . (count($missing) - 6) : ''));

$keyCols = [
    'channel_room_mappings' => ['channel_connection_id','property_id','room_type_id','external_room_id','rate_plan_id','status','suggested_at','suggestion_count','suggestion_score'],
    'channel_sync_logs' => ['channel_connection_id','direction','scope','status','fx_audit','source','external_ref'],
    'channel_property_mappings' => ['channel_connection_id','property_id','external_property_id','status'],
    'ical_sync_logs' => ['ical_connection_id','status','error_message'],
    'scheduled_jobs' => ['code','command','schedule','enabled','last_status'],
];
foreach ($keyCols as $table => $cols) {
    if (!tbl($table)) { rec($mod, $table . ' kolonları', 'fail', 'tablo yok'); continue; }
    $stmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name=?");
    $stmt->execute([$table]);
    $have = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
    $mc = array_values(array_diff($cols, array_keys($have)));
    rec($mod, $table . ' kritik kolonlar', $mc === [] ? 'ok' : 'fail', $mc === [] ? 'tümü mevcut' : 'eksik: ' . implode(', ', $mc));
}

// ─────────────────────────── 2) MIGRATION ───────────────────────────
$mod = 'migration';
try {
    $applied = $pdo->query('SELECT file FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $files = glob(__DIR__ . '/../database/migrations/*.sql') ?: [];
    $fileBase = array_map(fn($f) => basename($f), $files);
    $pending = array_values(array_diff($fileBase, $applied));
    rec($mod, 'schema_migrations kayıtları', 'ok', count($applied) . ' uygulanmış · ' . count($fileBase) . ' dosya');
    rec($mod, 'bekleyen migration', $pending === [] ? 'ok' : 'warn', $pending === [] ? 'yok — tümü uygulanmış' : implode(', ', array_slice($pending, 0, 5)) . (count($pending) > 5 ? ' … +' . (count($pending) - 5) : ''));
} catch (Throwable $e) {
    rec($mod, 'migration durumu', 'fail', 'schema_migrations okunamadı: ' . $e->getMessage());
}

// ─────────────────────────── 3) ZAMANLAYICI ───────────────────────────
$mod = 'zamanlayici';
$keyJobs = ['nexus-process-emails','nexus-channel-webhook-process','nexus-channel-webhook-retry','nexus-health-check','nexus-room-mapping-audit','nexus-distribution-health-digest','nexus-alert-test-delivery','nexus-fx-missing-audit'];
try {
    $haveJobs = array_flip($pdo->query('SELECT code FROM scheduled_jobs')->fetchAll(PDO::FETCH_COLUMN));
    $missingJobs = array_values(array_diff($keyJobs, array_keys($haveJobs)));
    rec($mod, 'kritik görev kayıtları', $missingJobs === [] ? 'ok' : 'fail', $missingJobs === [] ? count($keyJobs) . '/' . count($keyJobs) . ' kayıtlı' : 'eksik: ' . implode(', ', $missingJobs));
    // Advisory kilit boşta mı? (424242 — tick ile aynı anahtar; alıp hemen bırak)
    $locked = $pdo->query('SELECT pg_try_advisory_lock(424242)')->fetchColumn() === 't';
    if ($locked) $pdo->query('SELECT pg_advisory_unlock(424242)');
    rec($mod, 'zamanlayıcı kilidi', $locked ? 'ok' : 'fail', $locked ? 'boşta (takılı değil)' : 'TAKILI — pg_terminate_backend ile bırakın (KULLANIM.md §4)');
} catch (Throwable $e) {
    rec($mod, 'zamanlayıcı', 'fail', $e->getMessage());
}

// ─────────────────────────── 4) KANAL / WEBHOOK ───────────────────────────
$mod = 'kanal-webhook';
try {
    $conns = $pdo->query('SELECT id, channel_code, status, access_token FROM channel_connections ORDER BY id')->fetchAll();
    $active = count(array_filter($conns, fn($c) => $c['status'] === 'active'));
    rec($mod, 'kanal bağlantıları', $active > 0 ? 'ok' : 'warn', count($conns) . ' toplam · ' . $active . ' aktif' . (count($conns) === 0 ? ' — önce Dağıtım merkezi bölüm 1' : ''));
    $badTokens = [];
    foreach ($conns as $c) { if (!preg_match('/^[a-f0-9]{64}$/', (string) $c['access_token'])) $badTokens[] = '#' . (int) $c['id']; }
    rec($mod, 'access_token biçimi (64 hex)', $badTokens === [] ? 'ok' : 'fail', $badTokens === [] ? 'tümü geçerli' : 'bozuk: ' . implode(', ', $badTokens));
    if (tbl('channel_property_mappings')) {
        $pm = (int) $pdo->query('SELECT COUNT(*) FROM channel_property_mappings')->fetchColumn();
        rec($mod, 'ürün eşleştirmeleri', $pm > 0 ? 'ok' : 'warn', $pm . ' kayıt' . ($pm === 0 ? ' — bölüm 2' : ''));
    }
    if (tbl('channel_room_mappings')) {
        $rm = $pdo->query("SELECT COUNT(*) FILTER (WHERE status='confirmed') confirmed, COUNT(*) FILTER (WHERE status='suggested') suggested FROM channel_room_mappings")->fetch();
        rec($mod, 'oda eşleştirmeleri', (int) ($rm['confirmed'] ?? 0) > 0 ? 'ok' : 'warn', (int) ($rm['confirmed'] ?? 0) . ' confirmed · ' . (int) ($rm['suggested'] ?? 0) . ' öneri bekliyor');
        // Yetim sayısı (health-check ile aynı koşullar)
        $orphan = (int) $pdo->query("SELECT COUNT(*) FROM channel_room_mappings m LEFT JOIN room_types rt ON rt.id=m.room_type_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id WHERE m.room_type_id>0 AND (rt.id IS NULL OR c.id IS NULL OR rt.property_id<>m.property_id OR (m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)))")->fetchColumn();
        rec($mod, 'yetim eşleştirmeler', $orphan === 0 ? 'ok' : 'warn', $orphan . ' kayıt' . ($orphan > 0 ? ' — health-check --repair --yes' : ''));
    }
    if (tbl('channel_sync_logs')) {
        $f24 = (int) $pdo->query("SELECT COUNT(*) FROM channel_sync_logs WHERE status='failed' AND created_at > now() - interval '24 hours'")->fetchColumn();
        $q = (int) $pdo->query("SELECT COUNT(*) FROM channel_sync_logs WHERE status='queued'")->fetchColumn();
        rec($mod, 'webhook işleri (son 24s)', $f24 === 0 ? 'ok' : 'warn', $q . ' bekleyen · ' . $f24 . ' başarısız');
    }
} catch (Throwable $e) {
    rec($mod, 'kanal durumu', 'fail', $e->getMessage());
}

// ─────────────────────────── 5) KUR ───────────────────────────
$mod = 'kur';
try {
    $pairs = (int) $pdo->query('SELECT COUNT(DISTINCT (base_currency || quote_currency)) FROM fx_rates')->fetchColumn();
    rec($mod, 'kur çiftleri', $pairs > 0 ? 'ok' : 'warn', $pairs . ' benzersiz çift');
    if (tbl('fx_audit_daily')) {
        $last = $pdo->query('SELECT audit_date, missing_count, stale_count FROM fx_audit_daily ORDER BY audit_date DESC LIMIT 1')->fetch();
        if ($last) {
            $n = (int) $last['missing_count'] + (int) $last['stale_count'];
            rec($mod, 'son kur denetimi (' . $last['audit_date'] . ')', $n === 0 ? 'ok' : 'warn', $n . ' sorun (eksik ' . (int) $last['missing_count'] . ' · bayat ' . (int) $last['stale_count'] . ')');
        } else {
            rec($mod, 'kur denetimi geçmişi', 'warn', 'kayıt yok — nexus-fx-missing-audit henüz koşmadı');
        }
    }
} catch (Throwable $e) {
    rec($mod, 'kur durumu', 'fail', $e->getMessage());
}

// ─────────────────────────── 6) iCAL ───────────────────────────
$mod = 'ical';
try {
    if (tbl('ical_connections')) {
        $ic = $pdo->query("SELECT COUNT(*) total, COUNT(*) FILTER (WHERE status='active') active FROM ical_connections")->fetch();
        rec($mod, 'iCal bağlantıları', (int) ($ic['total'] ?? 0) > 0 ? 'ok' : 'warn', (int) ($ic['total'] ?? 0) . ' toplam · ' . (int) ($ic['active'] ?? 0) . ' aktif');
    }
    if (tbl('ical_sync_logs')) {
        $f24 = (int) $pdo->query("SELECT COUNT(*) FROM ical_sync_logs WHERE status='failed' AND created_at > now() - interval '24 hours'")->fetchColumn();
        rec($mod, 'iCal senkron (son 24s)', $f24 === 0 ? 'ok' : 'warn', $f24 . ' başarısız');
    }
} catch (Throwable $e) {
    rec($mod, 'ical durumu', 'fail', $e->getMessage());
}

// ─────────────────────────── 7) E-POSTA ───────────────────────────
$mod = 'eposta';
$adminEmail = trim((string) platform_setting('admin_alert_email', ''));
rec($mod, 'admin_alert_email', $adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL) ? 'ok' : 'fail', $adminEmail !== '' ? $adminEmail : 'tanımsız — Kontrol merkezi');
try {
    $pending = (int) $pdo->query("SELECT COUNT(*) FROM email_outbox WHERE status IN ('queued','pending')")->fetchColumn();
    $failed = (int) $pdo->query("SELECT COUNT(*) FROM email_outbox WHERE status='failed'")->fetchColumn();
    rec($mod, 'e-posta kuyruğu', $failed === 0 ? 'ok' : 'warn', $pending . ' bekleyen · ' . $failed . ' başarısız');
} catch (Throwable $e) { /* outbox yoksa geç */ }
$testCode = (string) platform_setting('last_alert_test_code', '');
$testStatus = (string) platform_setting('last_alert_test_status', '');
if ($testCode !== '') {
    rec($mod, 'son uyarı testi', $testStatus === 'delivered' ? 'ok' : ($testStatus === 'missed' ? 'fail' : 'warn'), $testCode . ' · ' . ($testStatus !== '' ? $testStatus : 'bekleniyor'));
} else {
    rec($mod, 'uyarı testi', 'warn', 'henüz çalıştırılmadı — cron/test-admin-alerts.php --send');
}

// ─────────────────────────── 8) E2E WEBHOOK (--e2e) ───────────────────────────
if ($E2E) {
    $mod = 'e2e-webhook';
    echo "\n── E2E webhook testi (--e2e) ──\n";
    try {
        $conn = $pdo->query("SELECT * FROM channel_connections WHERE status='active' ORDER BY id LIMIT 1")->fetch();
        if (!$conn) {
            rec($mod, 'aktif kanal', 'fail', 'aktif kanal bağlantısı yok — Dağıtım merkezi bölüm 1');
        } else {
            $baseUrl = 'https://nexustraveltech.com';
            $propRow = $pdo->prepare('SELECT * FROM channel_property_mappings WHERE channel_connection_id=? ORDER BY id LIMIT 1');
            $propRow->execute([(int) $conn['id']]);
            $pm = $propRow->fetch();
            if (!$pm) {
                rec($mod, 'ürün eşleştirmesi', 'fail', 'kanal #' . (int) $conn['id'] . ' için bölüm 2 eşleştirmesi yok');
            } else {
                $propId = (int) $pm['property_id'];
                $roomRow = $pdo->prepare("SELECT id, name FROM room_types WHERE property_id=? AND status='active' ORDER BY id LIMIT 1");
                $roomRow->execute([$propId]);
                $room = $roomRow->fetch();
                $planRow = $pdo->prepare("SELECT id, name, currency FROM rate_plans WHERE property_id=? AND status='active' ORDER BY id LIMIT 1");
                $planRow->execute([$propId]);
                $plan = $planRow->fetch();
                if (!$room || !$plan) {
                    rec($mod, 'oda tipi / fiyat planı', 'fail', 'ilan #' . $propId . ' için aktif oda tipi veya plan yok');
                } else {
                    $planCur = strtoupper((string) ($plan['currency'] ?: 'TRY'));
                    if (!preg_match('/^[A-Z]{3}$/', $planCur)) $planCur = 'TRY';
                    $inCur = 'EUR';
                    if ($inCur === $planCur) $inCur = 'USD';
                    $testDate = date('Y-m-d', strtotime('+60 days'));
                    $rate = fx_rate($inCur, $planCur, $testDate);
                    $code = 'AT-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
                    $ext = (string) ($pm['external_property_id'] ?? '');

                    // Test kodu için onaylı eşleştirme kur
                    $pdo->prepare("INSERT INTO channel_room_mappings(channel_connection_id, property_id, room_type_id, rate_plan_id, external_room_id, status, approved_by_type, approved_by_name, approved_at) VALUES(?,?,?,?,'confirmed','auto','auto-test',now()) ON CONFLICT(channel_connection_id, external_room_id) DO UPDATE SET room_type_id=EXCLUDED.room_type_id, rate_plan_id=EXCLUDED.rate_plan_id, property_id=EXCLUDED.property_id, status='confirmed', approved_by_type='auto', approved_by_name='auto-test', approved_at=now()")
                        ->execute([(int) $conn['id'], $propId, (int) $room['id'], (int) $plan['id'], $code]);

                    $scopeSpecs = [
                        ['rates',         ['price' => 123.45, 'currency' => $inCur], date('Y-m-d', strtotime('+60 days'))],
                        ['availability',  ['allotment' => 5],                         date('Y-m-d', strtotime('+61 days'))],
                        ['restrictions',  ['stop_sale' => true, 'min_stay' => 2, 'max_stay' => 7], date('Y-m-d', strtotime('+62 days'))],
                    ];
                    foreach ($scopeSpecs as [$scope, $extra, $date]) {
                        $payload = ['scope' => $scope, 'external_property_id' => $ext, 'entries' => [array_merge(['external_room_id' => $code, 'date' => $date], $extra)]];
                        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
                        $url = $baseUrl . '/api/channel-webhook?token=' . (string) $conn['access_token'];
                        // cURL varsa kullan (Plesk'te kurulu), yoksa akış POST'u.
                        $resp = null; $used = 'curl';
                        if (function_exists('curl_init')) {
                            $ch = curl_init($url);
                            curl_setopt_array($ch, [
                                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
                                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
                                CURLOPT_SSL_VERIFYPEER => false,
                            ]);
                            $resp = curl_exec($ch);
                            curl_close($ch);
                        } else {
                            $used = 'stream';
                            $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $body, 'ignore_errors' => true, 'timeout' => 20]]);
                            $resp = @file_get_contents($url, false, $ctx);
                        }
                        $dec = is_string($resp) ? json_decode($resp, true) : null;
                        $posted = is_array($dec) && ($dec['ok'] ?? false) && ($dec['queued'] ?? false);
                        if (!$posted) {
                            rec($mod, "POST $scope ($used)", 'fail', is_string($resp) ? mb_substr($resp, 0, 160) : 'yanıt yok');
                            continue;
                        }
                        // Kuyruğa alındı → işleyiciyle aynı kodu satır içi uygula
                        $q = $pdo->prepare("SELECT id, status FROM channel_sync_logs WHERE channel_connection_id=? AND request_payload::text LIKE ? AND created_at > now() - interval '10 minutes' ORDER BY id DESC LIMIT 1");
                        $q->execute([(int) $conn['id'], '%' . $code . '%']);
                        $log = $q->fetch();
                        if (!$log) { rec($mod, "uygula $scope", 'fail', 'kuyruk satırı bulunamadı'); continue; }
                        $logId = (int) $log['id'];
                        $pdo->prepare("UPDATE channel_sync_logs SET status='running', attempt_count=attempt_count+1 WHERE id=?")->execute([$logId]);
                        $job = $pdo->prepare('SELECT * FROM channel_sync_logs WHERE id=?');
                        $job->execute([$logId]);
                        $jobRow = $job->fetch();
                        $pl = json_decode((string) ($jobRow['request_payload'] ?? '{}'), true);
                        if (!is_array($pl)) $pl = [];
                        $res = channel_webhook_apply($jobRow, $pl);
                        if ($res['ok']) {
                            $pdo->prepare("UPDATE channel_sync_logs SET status='success', response_payload=?::jsonb, fx_audit=?::jsonb, error_message=NULL, completed_at=now() WHERE id=?")
                                ->execute([json_encode(['applied' => $res['applied']]), json_encode($res['fx_audit'] ?? []), $logId]);
                            $fxTxt = '';
                            if ($scope === 'rates' && $res['fx_audit'] ?? []) {
                                $fxTxt = ' · fx_audit:' . count($res['fx_audit']) . ' dönüşüm';
                            }
                            rec($mod, "uygula $scope", 'ok', 'log #' . $logId . ' success · ' . $res['applied'] . ' gün' . $fxTxt);
                        } else {
                            $pdo->prepare("UPDATE channel_sync_logs SET status='failed', error_message=?, response_payload=?::jsonb, fx_audit=?::jsonb, completed_at=now() WHERE id=?")
                                ->execute([mb_substr((string) $res['message'], 0, 1000), json_encode(['message' => $res['message']]), json_encode($res['fx_audit'] ?? []), $logId]);
                            rec($mod, "uygula $scope", 'fail', (string) $res['message']);
                        }
                    }
                    // rates yazımını takvimde doğrula
                    if ($rate > 0) {
                        $inv = $pdo->prepare('SELECT base_price FROM inventory_calendar WHERE room_type_id=? AND rate_plan_id=? AND stay_date=?');
                        $inv->execute([(int) $room['id'], (int) $plan['id'], date('Y-m-d', strtotime('+60 days'))]);
                        $bp = $inv->fetchColumn();
                        $expected = round(123.45 * $rate, 2);
                        if ($bp !== false && abs((float) $bp - $expected) < 0.01) {
                            rec($mod, 'takvim yazımı', 'ok', '123.45 ' . $inCur . ' → ' . number_format((float) $bp, 2, '.', '') . ' ' . $planCur . ' (beklenen ' . $expected . ', kur ' . number_format($rate, 4, '.', '') . ')');
                        } elseif ($bp === false) {
                            rec($mod, 'takvim yazımı', 'fail', 'satır bulunamadı — beklenen ' . $expected . ' ' . $planCur);
                        } else {
                            rec($mod, 'takvim yazımı', 'fail', 'uyuşmuyor: ' . number_format((float) $bp, 2, '.', '') . ' vs ' . $expected);
                        }
                    } else {
                        rec($mod, 'takvim yazımı', 'warn', "kur yok ($inCur→$planCur) — fx koruması test edilemedi, kur ekleyin");
                    }
                    // Temizlik
                    if (!$KEEP) {
                        $pdo->prepare('DELETE FROM channel_room_mappings WHERE channel_connection_id=? AND external_room_id=?')->execute([(int) $conn['id'], $code]);
                        $pdo->prepare('DELETE FROM inventory_calendar WHERE room_type_id=? AND rate_plan_id=? AND stay_date>=?')->execute([(int) $room['id'], (int) $plan['id'], date('Y-m-d', strtotime('+60 days'))]);
                        $pdo->prepare('DELETE FROM channel_sync_logs WHERE channel_connection_id=? AND request_payload::text LIKE ?')->execute([(int) $conn['id'], '%' . $code . '%']);
                        rec($mod, 'temizlik', 'ok', 'test satırları silindi (kod ' . $code . ')');
                    } else {
                        rec($mod, 'temizlik', 'warn', '--keep: test satırları bırakıldı (kod ' . $code . ')');
                    }
                }
            }
        }
    } catch (Throwable $e) {
        rec($mod, 'e2e', 'fail', $e->getMessage());
    }
}

// ─────────────────────────── RAPOR ───────────────────────────
echo "\n" . str_repeat('═', 62) . "\n";
echo "OTOMATİK MODÜL TESTİ — SONUÇ\n";
echo str_repeat('═', 62) . "\n";
foreach ($moduleOrder as $m) {
    $modFails = count(array_filter($results[$m], fn($r) => $r[1] === 'fail'));
    $modWarns = count(array_filter($results[$m], fn($r) => $r[1] === 'warn'));
    $icon = $modFails > 0 ? '✗' : ($modWarns > 0 ? '⚠' : '✓');
    echo "  $icon $m — " . count($results[$m]) . ' kontrol (' . $modFails . ' hata · ' . $modWarns . ' uyarı)' . "\n";
    if (!$VERBOSE) {
        foreach ($results[$m] as [$name, $status, $detail]) {
            if ($status === 'fail' || $status === 'warn') {
                echo '      ' . ($status === 'fail' ? '✗' : '⚠') . ' ' . $name . ($detail !== '' ? ' — ' . $detail : '') . "\n";
            }
        }
    }
}
echo str_repeat('═', 62) . "\n";
echo "GENEL: $oks ✓ · $warns ⚠ · $fails ✗\n";
echo "Komut: " . ($E2E ? '--e2e' : '') . ($KEEP ? ' --keep' : '') . ($VERBOSE ? ' --verbose' : '') . "\n";
if ($E2E) echo "İpucu: e2e her koşuda ilk aktif kanalı kullanır; --keep ile test satırları kalır.\n";

// --e2e ile çalıştırıldıysa denetim kaydına yaz (modül durumları, süre, hatalar).
if ($E2E) {
    $elapsedMs = (int) round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)) * 1000);
    $modDetails = [];
    foreach ($moduleOrder as $m) {
        $mFails = count(array_filter($results[$m], fn($r) => $r[1] === 'fail'));
        $mWarns = count(array_filter($results[$m], fn($r) => $r[1] === 'warn'));
        $mOks = count($results[$m]) - $mFails - $mWarns;
        $modDetails[$m] = ['ok' => $mOks, 'warn' => $mWarns, 'fail' => $mFails];
    }
    // Hata satırlarını topla (en fazla 20).
    $errorList = [];
    foreach ($results as $mod => $items) {
        foreach ($items as [$name, $status, $detail]) {
            if ($status === 'fail' && count($errorList) < 20) {
                $errorList[] = ['module' => $mod, 'check' => $name, 'detail' => mb_substr($detail, 0, 200)];
            }
        }
    }
    try {
        require_once __DIR__ . '/../config/audit.php';
        audit_log(
            'auto_test.e2e',
            'auto_test',
            null,
            [
                'ok' => $oks,
                'warn' => $warns,
                'fail' => $fails,
                'elapsed_ms' => $elapsedMs,
                'modules' => $modDetails,
                'errors' => $errorList,
                'command' => 'auto-test.php --e2e' . ($KEEP ? ' --keep' : ''),
            ],
            'system'
        );
        echo "\nDenetim kaydı yazıldı: auto_test.e2e ({$oks}✓ {$warns}⚠ {$fails}✗, {$elapsedMs}ms)\n";
    } catch (Throwable $e) {
        echo "\nDenetim kaydı yazılamadı: " . $e->getMessage() . "\n";
    }
}

exit($fails > 0 ? 1 : 0);
