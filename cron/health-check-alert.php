<?php
declare(strict_types=1);

// Günlük sağlık kontrolü — scripts/health-check.php ile aynı mantığı çalıştırır,
// sorun varsa admin_alert_email'e özet e-postası gönderir.
//
// - Sağlık kontrolü eksik migration'ları da idempotent uygular (aynı mantık).
// - Yalnızca SORUN varken e-posta gider; temizse yalnızca konsol çıktısı.
// - admin_alert_email tanımsızsa e-posta atlanır (görev yine de çalışır).
//
// Zamanlayıcı: nexus-health-check (varsayılan: her gün 06:45).

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/health.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/platform_settings.php';

$adminEmail = trim((string) platform_setting('admin_alert_email', ''));
// Günlük görevde onarım modu: eksik tablo/kolonu düzelt, yetim eşleştirmeleri temizle.
// --yes: onay istemeden otomatik temizlik.
$orphanBefore = 0;
try {
    $orphanBefore = (int) db()->query("SELECT COUNT(*) FROM channel_room_mappings m LEFT JOIN room_types rt ON rt.id=m.room_type_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id WHERE m.room_type_id>0 AND (rt.id IS NULL OR c.id IS NULL OR rt.property_id<>m.property_id OR (m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)))")->fetchColumn()
        + (int) db()->query("SELECT COUNT(*) FROM channel_rate_plan_mappings m LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id WHERE (m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)) OR c.id IS NULL")->fetchColumn()
        + (int) db()->query("SELECT COUNT(*) FROM channel_property_mappings m LEFT JOIN properties p ON p.id=m.property_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id WHERE p.id IS NULL OR c.id IS NULL")->fetchColumn()
        + (int) db()->query("SELECT COUNT(*) FROM ical_connections c LEFT JOIN properties p ON p.id=c.property_id WHERE p.id IS NULL")->fetchColumn();
} catch (Throwable $e) {}
$result = health_check_run(false, true); // repair=true: onarım + yetim temizliği
// Yetim temizliği sonrası sayım — fark e-postada gösterilir.
$orphanAfter = 0;
try {
    $orphanAfter = (int) db()->query("SELECT COUNT(*) FROM channel_room_mappings m LEFT JOIN room_types rt ON rt.id=m.room_type_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id WHERE m.room_type_id>0 AND (rt.id IS NULL OR c.id IS NULL OR rt.property_id<>m.property_id OR (m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)))")->fetchColumn()
        + (int) db()->query("SELECT COUNT(*) FROM channel_rate_plan_mappings m LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id WHERE (m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)) OR c.id IS NULL")->fetchColumn()
        + (int) db()->query("SELECT COUNT(*) FROM channel_property_mappings m LEFT JOIN properties p ON p.id=m.property_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id WHERE p.id IS NULL OR c.id IS NULL")->fetchColumn()
        + (int) db()->query("SELECT COUNT(*) FROM ical_connections c LEFT JOIN properties p ON p.id=c.property_id WHERE p.id IS NULL")->fetchColumn();
} catch (Throwable $e) {}
$orphanCleaned = max(0, $orphanBefore - $orphanAfter);

// Son 24 saatte uygulanan migration'lar — deploy günü "o gün ne uygulandı" görünürlüğü.
// Migration başarıyla uygulanıp başka sorun yoksa bile e-posta gider (sessiz deploy onayı).
$migApplied = [];
try {
    $migQ = db()->query("SELECT file, applied_at, commit_hash FROM schema_migrations WHERE applied_at >= now() - interval '24 hours' ORDER BY applied_at DESC");
    $migApplied = $migQ ? $migQ->fetchAll() : [];
} catch (Throwable $e) {
    $migApplied = [];
}

echo $result['output'];
if ($result['ok'] && $migApplied === []) {
    exit(0);
}

if ($result['errors'] !== []) {
    echo "\n" . count($result['errors']) . ' sorun tespit edildi.';
}
if ($migApplied !== []) {
    echo "\n" . count($migApplied) . ' migration son 24 saatte uygulandı.';
}
if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    $rowsHtml = '';
    foreach (array_slice($result['errors'], 0, 30) as $err) {
        $rowsHtml .= '<tr><td style="padding:7px 12px;border:1px solid #e1e5de;color:#8e2410">' . htmlspecialchars($err) . '</td></tr>';
    }
    $extra = count($result['errors']) > 30
        ? '<tr><td style="padding:7px 12px;border:1px solid #e1e5de;color:#64716d">… ve ' . (count($result['errors']) - 30) . ' sorun daha (tamamı konsol çıktısında).</td></tr>'
        : '';
    // Son 7 günün çalıştırma özeti — zamanlayıcı geçmişinden (scheduled_job_runs) gün bazında mini liste.
    // Sağlık görevi (nexus-health-check) geçmişte başarılı da olsa her çalışma kaydedilir; bu tablo
    // sorunun bugün mü yoksa tekrarlayan mı olduğunu tek bakışta gösterir.
    $runsBlock = '';
    try {
        $runQ = db()->prepare("SELECT to_char(r.created_at, 'YYYY-MM-DD') AS day, COUNT(*) AS runs, COUNT(*) FILTER (WHERE r.status = 'failed') AS fails, COALESCE(ROUND(AVG(r.duration_ms)), 0) AS avg_ms FROM scheduled_job_runs r JOIN scheduled_jobs j ON j.id = r.job_id WHERE j.code = 'nexus-health-check' AND r.created_at >= now() - interval '7 days' GROUP BY day ORDER BY day DESC");
        $runQ->execute();
        $runs = $runQ->fetchAll();
        // Günün son koşusunun çıktısı — 'SONUÇ: N sorun' satırından günün gerçek sonucu (temiz / N sorun) ayrıştırılır.
        $lastOut = [];
        try {
            $loQ = db()->prepare("SELECT DISTINCT ON (to_char(r.created_at,'YYYY-MM-DD')) to_char(r.created_at,'YYYY-MM-DD') AS day, r.output AS last_output FROM scheduled_job_runs r JOIN scheduled_jobs j ON j.id = r.job_id WHERE j.code = 'nexus-health-check' AND r.created_at >= now() - interval '7 days' ORDER BY to_char(r.created_at,'YYYY-MM-DD'), r.created_at DESC");
            $loQ->execute();
            foreach ($loQ->fetchAll() as $lo) $lastOut[(string) $lo['day']] = (string) ($lo['last_output'] ?? '');
        } catch (Throwable $e) {}
        if ($runs) {
            $totalRuns = (int) array_sum(array_column($runs, 'runs'));
            $totalFails = (int) array_sum(array_column($runs, 'fails'));
            $problemDays = 0;
            $runsBlock = '<h3 style="margin:18px 0 4px;font-size:14px">📅 Son 7 gün — sağlık kontrolü çalıştırmaları: ' . $totalRuns . ' çalıştırma, ' . $totalFails . ' hatalı' . '</h3>'
                . '<table style="border-collapse:collapse;width:100%;max-width:640px;font-size:12px">'
                . '<tr><th style="text-align:left;padding:6px 12px;border:1px solid #e1e5de;background:#f4f6f1">Gün</th><th style="padding:6px 12px;border:1px solid #e1e5de;background:#f4f6f1;text-align:center">Çalışma</th><th style="padding:6px 12px;border:1px solid #e1e5de;background:#f4f6f1;text-align:center">Hata</th><th style="padding:6px 12px;border:1px solid #e1e5de;background:#f4f6f1;text-align:center">Ort. süre</th><th style="padding:6px 12px;border:1px solid #e1e5de;background:#f4f6f1;text-align:center">Sonuç</th><th style="padding:6px 12px;border:1px solid #e1e5de;background:#f4f6f1;text-align:center">Durum</th></tr>';
            foreach ($runs as $rd) {
                $fails = (int) $rd['fails'];
                $out = $lastOut[(string) $rd['day']] ?? '';
                $probs = -1;
                if (preg_match('/SONUÇ:\s*(\d+)\s+sorun/i', $out, $m)) $probs = (int) $m[1];
                elseif (mb_stripos($out, 'Tüm kontroller başarılı') !== false) $probs = 0;
                if ($probs > 0) $problemDays++;
                $resCell = $probs < 0
                    ? '<span style="color:#64716d">—</span>'
                    : ($probs > 0 ? '<b style="color:#8e2410">' . $probs . ' sorun</b>' : '<span style="color:#2e7d32">temiz</span>');
                $runsBlock .= '<tr><td style="padding:6px 12px;border:1px solid #e1e5de">' . htmlspecialchars((string) $rd['day']) . '</td>'
                    . '<td style="padding:6px 12px;border:1px solid #e1e5de;text-align:center">' . (int) $rd['runs'] . '</td>'
                    . '<td style="padding:6px 12px;border:1px solid #e1e5de;text-align:center">' . ($fails > 0 ? '<b style="color:#8e2410">' . $fails . '</b>' : '<span style="color:#2e7d32">0</span>') . '</td>'
                    . '<td style="padding:6px 12px;border:1px solid #e1e5de;text-align:center">' . (int) $rd['avg_ms'] . ' ms</td>'
                    . '<td style="padding:6px 12px;border:1px solid #e1e5de;text-align:center">' . $resCell . '</td>'
                    . '<td style="padding:6px 12px;border:1px solid #e1e5de;text-align:center">' . ($fails > 0 ? '✗' : '✓') . '</td></tr>';
            }
            $runsBlock .= '</table>';
            $runsBlock .= $problemDays > 0
                ? '<p style="margin:6px 0 0;font-size:12px;color:#8e2410"><b>' . $problemDays . '/7 gün sorunlu</b> — çıktıdaki SONUÇ satırına göre.</p>'
                : '<p style="margin:6px 0 0;font-size:12px;color:#2e7d32">Son 7 günün tamamı temiz — çıktıdaki SONUÇ satırına göre.</p>';
        } else {
            $runsBlock = '<p style="margin-top:16px;color:#64716d;font-size:12px">📅 Son 7 günde kayıtlı sağlık kontrolü çalıştırması yok (zamanlayıcı geçmişi boş).</p>';
        }
    } catch (Throwable $e) {
        $runsBlock = '<p style="margin-top:16px;color:#64716d;font-size:12px">📅 Çalıştırma geçmişi okunamadı (' . htmlspecialchars($e->getMessage()) . ').</p>';
    }
    // Operasyonel metrikler — bekleyen webhook/retry kuyruğu, iCal hata sayısı, e-posta
    // kuyruğu ve hata logu. Her sorgu ayrı korumalı: biri başarısız olursa satır '—' gösterir,
    // e-postayı düşürmez. Eşik aşan kalemler kırmızı kalın gösterilir ve başlığa uyarı eklenir.
    $opsBlock = '';
    try {
        $maxRetries = max(2, min(10, (int) platform_setting('channel_webhook_max_retries', 3)));
        // Uyarı eşikleri kontrol merkezinden (health_warn_* ayarları) — health-check çıktısıyla tutarlı.
        $warnWebhook = max(1, (int) platform_setting('health_warn_webhook_fail', 10));
        $warnIcal = max(1, (int) platform_setting('health_warn_ical_fail', 3));
        $warnEmail = max(1, (int) platform_setting('health_warn_email_queue', 50));
        $warnLog = max(1, (int) platform_setting('health_warn_error_logs', 20));
        $fetchCount = function (string $sql) use ($pdo): ?int {
            try { return (int) $pdo->query($sql)->fetchColumn(); }
            catch (Throwable $e) { return null; }
        };
        $pendingWebhook = $fetchCount("SELECT COUNT(*) FROM channel_sync_logs WHERE direction='pull' AND status='queued'");
        $retryable      = $fetchCount("SELECT COUNT(*) FROM channel_sync_logs WHERE direction='pull' AND status='failed' AND attempt_count < {$maxRetries} AND scope IN ('availability','rates','restrictions','reservations')");
        $exhausted      = $fetchCount("SELECT COUNT(*) FROM channel_sync_logs WHERE direction='pull' AND status='failed' AND attempt_count >= {$maxRetries} AND completed_at >= now() - interval '24 hours'");
        $icalOk         = $fetchCount("SELECT COUNT(*) FROM ical_sync_logs WHERE status='success' AND created_at >= now() - interval '24 hours'");
        $icalFail       = $fetchCount("SELECT COUNT(*) FROM ical_sync_logs WHERE status='failed' AND created_at >= now() - interval '24 hours'");
        $emailQ         = $fetchCount("SELECT COUNT(*) FROM email_outbox WHERE status='queued'");
        $errLog         = $fetchCount("SELECT COUNT(*) FROM error_logs WHERE level IN ('error','critical') AND status='new' AND created_at >= now() - interval '24 hours'");
        $renderVal = static function (?int $v, bool $warn): string {
            if ($v === null) return '<span style="color:#9aa5a0">—</span>';
            return '<span style="color:' . ($warn ? '#8e2410' : '#2e7d32') . ($warn ? ';font-weight:bold' : '') . '">' . $v . '</span>';
        };
        $rowsHtml2 = '';
        $warned = 0;
        $addRow = function (string $label, ?int $v, bool $warn) use (&$rowsHtml2, &$warned, $renderVal): void {
            if ($warn) $warned++;
            $rowsHtml2 .= '<tr><td style="padding:6px 12px;border:1px solid #e1e5de">' . $label . '</td>'
                . '<td style="padding:6px 12px;border:1px solid #e1e5de;text-align:center">' . $renderVal($v, $warn) . '</td></tr>';
        };
        $addRow('⏳ Bekleyen kanal webhook işi (kuyrukta)', $pendingWebhook, ($pendingWebhook ?? 0) > 0);
        $addRow('🔁 Yeniden denemeyi bekleyen (max ' . $maxRetries . ' deneme)', $retryable, ($retryable ?? 0) > $warnWebhook);
        $addRow('✗ Denemesi tükenen kanal yükü (son 24s)', $exhausted, ($exhausted ?? 0) > 0);
        $addRow('📅 iCal senkron — başarılı (son 24s)', $icalOk, false);
        $addRow('📅 iCal senkron — hata (son 24s)', $icalFail, ($icalFail ?? 0) > $warnIcal);
        $addRow('📧 Bekleyen e-posta kuyruğu', $emailQ, ($emailQ ?? 0) > $warnEmail);
        $addRow('🐞 Yeni hata logu (son 24s)', $errLog, ($errLog ?? 0) > $warnLog);
        $opsBlock = '<h3 style="margin:18px 0 4px;font-size:14px">📊 Operasyonel metrikler (şu an)' . ($warned > 0 ? ' — <span style="color:#8e2410">' . $warned . ' kalem dikkat gerektiriyor</span>' : '') . '</h3>'
            . '<table style="border-collapse:collapse;width:100%;max-width:640px;font-size:12px">'
            . '<tr><th style="text-align:left;padding:6px 12px;border:1px solid #e1e5de;background:#f4f6f1">Metrik</th><th style="padding:6px 12px;border:1px solid #e1e5de;background:#f4f6f1;text-align:center">Değer</th></tr>'
            . $rowsHtml2 . '</table>';
    } catch (Throwable $e) {
        $opsBlock = '<p style="margin-top:16px;color:#64716d;font-size:12px">📊 Operasyonel metrikler okunamadı (' . htmlspecialchars($e->getMessage()) . ').</p>';
    }
    // Son 24 saatte uygulanan migration listesi (sadece varsa) — dosya, tarih, commit.
    $migBlock = '';
    if ($migApplied !== []) {
        $rowsM = '';
        foreach ($migApplied as $m) {
            $rowsM .= '<tr><td style="padding:6px 12px;border:1px solid #e1e5de"><code>' . htmlspecialchars((string) $m['file']) . '</code></td>'
                . '<td style="padding:6px 12px;border:1px solid #e1e5de;white-space:nowrap">' . htmlspecialchars(mb_substr((string) $m['applied_at'], 0, 19)) . '</td>'
                . '<td style="padding:6px 12px;border:1px solid #e1e5de"><code>' . (((string) ($m['commit_hash'] ?? '')) !== '' ? substr((string) $m['commit_hash'], 0, 7) : '—') . '</code></td></tr>';
        }
        $migBlock = '<h3 style="margin:18px 0 4px;font-size:14px">🆕 Son 24 saatte uygulanan migration' . "'lar: " . count($migApplied) . '</h3>'
            . '<table style="border-collapse:collapse;width:100%;max-width:640px;font-size:12px">'
            . '<tr><th style="text-align:left;padding:6px 12px;border:1px solid #e1e5de;background:#f4f6f1">Dosya</th><th style="text-align:left;padding:6px 12px;border:1px solid #e1e5de;background:#f4f6f1">Uygulanma</th><th style="text-align:left;padding:6px 12px;border:1px solid #e1e5de;background:#f4f6f1">Commit</th></tr>'
            . $rowsM . '</table>';
    }
    // Yetim eşleştirme temizleme bağlantısı — yalnızca temizlenecek yetim varsa üretilir.
    // Token platform ayarında 3 gün saklanır; admin/approve-orphan-cleanup.php iki adımlı
    // onayla (önce özet, sonra POST) health_orphan_cleanup() ile satırları siler.
    $orphanBlock = '';
    try {
        $orphanCount = 0;
        try {
            $oc = db()->query("SELECT COUNT(*) FROM channel_room_mappings m LEFT JOIN room_types rt ON rt.id=m.room_type_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id WHERE m.room_type_id>0 AND (rt.id IS NULL OR c.id IS NULL OR rt.property_id<>m.property_id OR (m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)))")->fetchColumn();
            $orphanCount += (int) ($oc ?: 0);
        } catch (Throwable $e) {}
        try {
            $oc2 = db()->query("SELECT COUNT(*) FROM channel_rate_plan_mappings m LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id WHERE (m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)) OR c.id IS NULL")->fetchColumn();
            $orphanCount += (int) ($oc2 ?: 0);
        } catch (Throwable $e) {}
        try {
            $oc3 = db()->query("SELECT COUNT(*) FROM channel_property_mappings m LEFT JOIN properties p ON p.id=m.property_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id WHERE p.id IS NULL OR c.id IS NULL")->fetchColumn();
            $orphanCount += (int) ($oc3 ?: 0);
        } catch (Throwable $e) {}
        if ($orphanCount > 0) {
            $approveToken = bin2hex(random_bytes(32));
            save_platform_setting('orphan_cleanup_approve', ['token' => $approveToken, 'expires_at' => date('Y-m-d H:i:s', time() + 3 * 86400)]);
            $approveLink = 'https://nexustraveltech.com/admin/approve-orphan-cleanup.php?token=' . $approveToken;
            $orphanBlock = '<p style="background:#fff3cd;border:1px solid #e0c9a3;border-radius:8px;padding:10px 12px;margin-top:14px"><a href="' . $approveLink . '" style="color:#8a6100;font-weight:bold;font-size:15px;text-decoration:none">🧹 ' . $orphanCount . ' yetim eşleştirmeyi temizle →</a><br><span style="color:#6b7774;font-size:12px">Silinmiş oda tipi / fiyat planı / kanal / ürüne işaret eden satırlar; onay sayfası önce listeyi gösterir, tek tıkla temizler (3 gün geçerli, tek kullanımlık).</span></p>';
        }
    } catch (Throwable $e) {
        $orphanBlock = '';
    }
    // Yetim ayrıntı listesi — mevcut yetimlerin dağılımı ve son 7 günün temizlik geçmişi.
    $orphanDetailBlock = '';
    try {
        $pdo = db();
        // Mevcut yetim dağılımı
        $orphanParts = [];
        // 1) channel_room_mappings
        $rc = $pdo->query("SELECT COUNT(*) FROM channel_room_mappings m LEFT JOIN room_types rt ON rt.id=m.room_type_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id WHERE m.room_type_id>0 AND (rt.id IS NULL OR c.id IS NULL OR rt.property_id<>m.property_id OR (m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)))");
        $n = $rc ? (int) $rc->fetchColumn() : 0;
        if ($n > 0) $orphanParts[] = ['label' => 'Oda eşleştirmesi', 'count' => $n, 'table' => 'channel_room_mappings'];
        // 2) channel_rate_plan_mappings
        $rc = $pdo->query("SELECT COUNT(*) FROM channel_rate_plan_mappings m LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id WHERE (m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)) OR c.id IS NULL");
        $n = $rc ? (int) $rc->fetchColumn() : 0;
        if ($n > 0) $orphanParts[] = ['label' => 'Fiyat planı eşleştirmesi', 'count' => $n, 'table' => 'channel_rate_plan_mappings'];
        // 3) channel_property_mappings
        $rc = $pdo->query("SELECT COUNT(*) FROM channel_property_mappings m LEFT JOIN properties p ON p.id=m.property_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id WHERE p.id IS NULL OR c.id IS NULL");
        $n = $rc ? (int) $rc->fetchColumn() : 0;
        if ($n > 0) $orphanParts[] = ['label' => 'Ürün eşleştirmesi', 'count' => $n, 'table' => 'channel_property_mappings'];
        // 4) ical_connections
        $rc = $pdo->query("SELECT COUNT(*) FROM ical_connections c LEFT JOIN properties p ON p.id=c.property_id WHERE p.id IS NULL");
        $n = $rc ? (int) $rc->fetchColumn() : 0;
        if ($n > 0) $orphanParts[] = ['label' => 'iCal bağlantısı', 'count' => $n, 'table' => 'ical_connections'];
        // 5) ical_events
        $rc = $pdo->query("SELECT COUNT(*) FROM ical_events e LEFT JOIN ical_connections c ON c.id=e.ical_connection_id WHERE c.id IS NULL");
        $n = $rc ? (int) $rc->fetchColumn() : 0;
        if ($n > 0) $orphanParts[] = ['label' => 'iCal olayı', 'count' => $n, 'table' => 'ical_events'];
        // 6) ical_sync_logs
        $rc = $pdo->query("SELECT COUNT(*) FROM ical_sync_logs l LEFT JOIN ical_connections c ON c.id=l.ical_connection_id WHERE c.id IS NULL");
        $n = $rc ? (int) $rc->fetchColumn() : 0;
        if ($n > 0) $orphanParts[] = ['label' => 'iCal senkron kaydı', 'count' => $n, 'table' => 'ical_sync_logs'];
        // 7) channel_property_mappings (ürün eşleştirmesi — ürün yok)
        // 8) pending_trash_purges
        $rc = $pdo->query("SELECT COUNT(*) FROM pending_trash_purges p LEFT JOIN feature_delete_backups b ON b.feature_id=p.feature_id WHERE b.id IS NULL");
        $n = $rc ? (int) $rc->fetchColumn() : 0;
        if ($n > 0) $orphanParts[] = ['label' => 'Silme onayı (yedeği yok)', 'count' => $n, 'table' => 'pending_trash_purges'];

        // Son 7 günün yetim temizlik geçmişi
        $historyRows = [];
        try {
            $histQ = $pdo->query("SELECT details, created_at FROM admin_audit_logs WHERE action='health.repair_orphan_cleanup' AND created_at >= now() - interval '7 days' ORDER BY created_at DESC");
            $historyRows = $histQ ? $histQ->fetchAll() : [];
        } catch (Throwable $e) {}

        if ($orphanParts !== [] || $historyRows !== []) {
            $orphanDetailBlock = '<div style="background:#f9f8f4;border:1px solid #e1e5de;border-radius:8px;padding:12px 14px;margin-top:14px">';
            $orphanDetailBlock .= '<b style="color:#10211f;font-size:14px">🧹 Yetim eşleştirme özeti</b>';
            // Dağılım tablosu
            if ($orphanParts !== []) {
                $orphanDetailBlock .= '<table style="border-collapse:collapse;width:100%;font-size:12px;margin-top:8px">';
                $orphanDetailBlock .= '<tr><th style="text-align:left;padding:4px 8px;border:1px solid #e1e5de;background:#f4f6f1">Tür</th><th style="text-align:right;padding:4px 8px;border:1px solid #e1e5de;background:#f4f6f1">Sayı</th></tr>';
                $totalOrphan = 0;
                foreach ($orphanParts as $op) {
                    $totalOrphan += $op['count'];
                    $orphanDetailBlock .= '<tr><td style="padding:4px 8px;border:1px solid #e1e5de">' . htmlspecialchars($op['label']) . '</td><td style="padding:4px 8px;border:1px solid #e1e5de;text-align:right"><b>' . $op['count'] . '</b></td></tr>';
                }
                $orphanDetailBlock .= '<tr><td style="padding:4px 8px;border:1px solid #e1e5de;background:#f4f6f1"><b>Toplam</b></td><td style="padding:4px 8px;border:1px solid #e1e5de;background:#f4f6f1;text-align:right"><b>' . $totalOrphan . '</b></td></tr>';
                $orphanDetailBlock .= '</table>';
            }
            // Son 7 gün temizlik geçmişi
            if ($historyRows !== []) {
                $orphanDetailBlock .= '<p style="margin:10px 0 4px;color:#64716d;font-size:12px">Son 7 günün temizlik çalışmaları:</p>';
                $orphanDetailBlock .= '<table style="border-collapse:collapse;width:100%;font-size:12px">';
                $orphanDetailBlock .= '<tr><th style="text-align:left;padding:4px 8px;border:1px solid #e1e5de;background:#f4f6f1">Tarih</th><th style="text-align:left;padding:4px 8px;border:1px solid #e1e5de;background:#f4f6f1">Detay</th></tr>';
                foreach (array_slice($historyRows, 0, 10) as $hr) {
                    $det = $hr['details'] ?? '';
                    // details JSON ise summary alanı var
                    if (is_string($det) && str_starts_with(trim($det), '{')) {
                        $jd = json_decode($det, true);
                        $det = $jd['summary'] ?? ($jd['removed'] ?? '') . ' satır temizlendi';
                    }
                    $orphanDetailBlock .= '<tr><td style="padding:4px 8px;border:1px solid #e1e5de;white-space:nowrap">' . htmlspecialchars(date('d.m', strtotime((string) $hr['created_at']))) . '</td><td style="padding:4px 8px;border:1px solid #e1e5de">' . htmlspecialchars(mb_substr((string) $det, 0, 80)) . '</td></tr>';
                }
                $orphanDetailBlock .= '</table>';
            }
            $orphanDetailBlock .= '</div>';
        }
    } catch (Throwable $e) {
        $orphanDetailBlock = '';
    }
    $body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
        . ($result['errors'] !== []
            ? '<h2 style="margin:0 0 6px">⚠ Platform sağlık kontrolü: ' . count($result['errors']) . ' sorun</h2>'
            : '<h2 style="margin:0 0 6px">✅ Platform sağlık kontrolü: ' . count($migApplied) . ' migration uygulandı</h2>')
        . ($result['errors'] !== []
            ? '<p style="color:#64716d;margin:0 0 10px">Günlük sağlık taraması sorun tespit etti. Eksik tablo/kolon, başarısız migration veya ortam eksikliği olabilir; ayrıntılar aşağıdadır.</p>'
            : '<p style="color:#64716d;margin:0 0 10px">Günlük sağlık taraması bekleyen migration' . "'ları uyguladı; başka sorun yok. Ayrıntılar aşağıdadır.</p>")
        . ($result['errors'] !== [] ? '<table style="border-collapse:collapse;width:100%;max-width:640px;font-size:13px">'
            . '<tr><th style="text-align:left;padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1">Sorun</th></tr>'
            . $rowsHtml . $extra
            . '</table>' : '')
        . ($orphanCleaned > 0
            ? '<p style="background:#e6f8c7;border:1px solid #bcd98a;border-radius:8px;padding:10px 12px;margin-top:14px"><b style="color:#2e7d32">🧹 Yetim temizliği:</b> ' . $orphanCleaned . ' satır otomatik temizlendi' . ($orphanAfter > 0 ? ' · kalan: ' . $orphanAfter : ' · tüm yetimler temizlendi') . '</p>'
            : ($orphanBefore > 0 ? '<p style="background:#fff3cd;border:1px solid #e0c9a3;border-radius:8px;padding:10px 12px;margin-top:14px">🧹 <b>' . $orphanBefore . ' yetim eşleştirme</b> mevcut (otomatik temizlenmedi — onay gerekli).</p>' : ''))
        . $orphanBlock
        . $migBlock
        . $runsBlock
        . $opsBlock
        . '<p style="margin-top:18px">Tam çıktı için sunucuda: <code style="background:#f2f4ef;padding:2px 5px">/opt/plesk/php/8.5/bin/php scripts/health-check.php</code></p>'
        . $orphanDetailBlock
        . '</div>';
    $orphanNote = $orphanCleaned > 0 ? ' · ' . $orphanCleaned . ' yetim temizlendi' : '';
$subject = $result['errors'] !== []
        ? '⚠ Sağlık kontrolü: ' . count($result['errors']) . ' sorun' . $orphanNote
        : '✅ Sağlık kontrolü: ' . count($migApplied) . ' migration uygulandı' . $orphanNote;
    queue_email($adminEmail, $subject, $body, 'health_check_alert');
    echo " Admin e-postası kuyruğa eklendi.\n";
} else {
    echo " admin_alert_email tanımsız — e-posta atlanıyor.\n";
}
exit(1);
