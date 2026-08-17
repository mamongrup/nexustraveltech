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
$result = health_check_run(false);

echo $result['output'];
if ($result['ok']) {
    exit(0);
}

echo "\n" . count($result['errors']) . ' sorun tespit edildi.';
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
        if ($runs) {
            $totalRuns = (int) array_sum(array_column($runs, 'runs'));
            $totalFails = (int) array_sum(array_column($runs, 'fails'));
            $runsBlock = '<h3 style="margin:18px 0 4px;font-size:14px">📅 Son 7 gün — sağlık kontrolü çalıştırmaları: ' . $totalRuns . ' çalıştırma, ' . $totalFails . ' hatalı' . '</h3>'
                . '<table style="border-collapse:collapse;width:100%;max-width:640px;font-size:12px">'
                . '<tr><th style="text-align:left;padding:6px 12px;border:1px solid #e1e5de;background:#f4f6f1">Gün</th><th style="padding:6px 12px;border:1px solid #e1e5de;background:#f4f6f1;text-align:center">Çalışma</th><th style="padding:6px 12px;border:1px solid #e1e5de;background:#f4f6f1;text-align:center">Hata</th><th style="padding:6px 12px;border:1px solid #e1e5de;background:#f4f6f1;text-align:center">Ort. süre</th><th style="padding:6px 12px;border:1px solid #e1e5de;background:#f4f6f1;text-align:center">Durum</th></tr>';
            foreach ($runs as $rd) {
                $fails = (int) $rd['fails'];
                $runsBlock .= '<tr><td style="padding:6px 12px;border:1px solid #e1e5de">' . htmlspecialchars((string) $rd['day']) . '</td>'
                    . '<td style="padding:6px 12px;border:1px solid #e1e5de;text-align:center">' . (int) $rd['runs'] . '</td>'
                    . '<td style="padding:6px 12px;border:1px solid #e1e5de;text-align:center">' . ($fails > 0 ? '<b style="color:#8e2410">' . $fails . '</b>' : '<span style="color:#2e7d32">0</span>') . '</td>'
                    . '<td style="padding:6px 12px;border:1px solid #e1e5de;text-align:center">' . (int) $rd['avg_ms'] . ' ms</td>'
                    . '<td style="padding:6px 12px;border:1px solid #e1e5de;text-align:center">' . ($fails > 0 ? '✗' : '✓') . '</td></tr>';
            }
            $runsBlock .= '</table>';
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
    $body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
        . '<h2 style="margin:0 0 6px">⚠ Platform sağlık kontrolü: ' . count($result['errors']) . ' sorun</h2>'
        . '<p style="color:#64716d;margin:0 0 10px">Günlük sağlık taraması sorun tespit etti. Eksik tablo/kolon, başarısız migration veya ortam eksikliği olabilir; ayrıntılar aşağıdadır.</p>'
        . '<table style="border-collapse:collapse;width:100%;max-width:640px;font-size:13px">'
        . '<tr><th style="text-align:left;padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1">Sorun</th></tr>'
        . $rowsHtml . $extra
        . '</table>'
        . $runsBlock
        . $opsBlock
        . '<p style="margin-top:18px">Tam çıktı için sunucuda: <code style="background:#f2f4ef;padding:2px 5px">/opt/plesk/php/8.5/bin/php scripts/health-check.php</code></p>'
        . '</div>';
    queue_email($adminEmail, '⚠ Sağlık kontrolü: ' . count($result['errors']) . ' sorun', $body, 'health_check_alert');
    echo " Admin e-postası kuyruğa eklendi.\n";
} else {
    echo " admin_alert_email tanımsız — e-posta atlanıyor.\n";
}
exit(1);
