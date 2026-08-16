<?php
declare(strict_types=1);

/**
 * Günlük görev sağlık raporu — her görevin son 24 saatteki çalışma/hata durumunu
 * özetleyip admin_alert_email adresine gönderir (günde bir kez, idempotent).
 *
 * Vurgular: hata veren görevler, vadesi geldiği halde çalışmayan görevler ve
 * nabız (tick) sağlığı. Zamanlayıcı: nexus-job-status-digest (varsayılan: her gün 09:00).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/scheduler.php';

$to = trim((string) platform_setting('admin_alert_email', ''));
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Görev sağlık raporu gönderilmedi: admin_alert_email tanımlı değil (admin → Kontrol merkezi).\n");
    exit(0);
}

$dateKey = (int) date('Ymd');
$exists = db()->prepare("SELECT COUNT(*) FROM email_outbox WHERE related_type='job_status_digest' AND related_id=?");
$exists->execute([$dateKey]);
if ((int) $exists->fetchColumn() > 0) {
    echo "Bugün için görev sağlık raporu zaten kuyrukta; atlandı.\n";
    exit(0);
}

$pdo = db();
$rows = $pdo->query(
    "SELECT j.id,j.name,j.code,j.schedule,j.enabled,j.last_status,
            COUNT(r.id) FILTER (WHERE r.created_at >= now() - interval '24 hours') AS runs24,
            COUNT(r.id) FILTER (WHERE r.created_at >= now() - interval '24 hours' AND r.status='error') AS err24,
            MAX(r.created_at) FILTER (WHERE r.created_at >= now() - interval '24 hours') AS last24
       FROM scheduled_jobs j
       LEFT JOIN scheduled_job_runs r ON r.job_id = j.id
      GROUP BY j.id ORDER BY j.id"
)->fetchAll();

if ($rows === []) {
    echo "Zamanlayıcı görevi tanımlı değil; rapor gönderilmedi.\n";
    exit(0);
}

$dueIn24 = function (string $schedule): bool {
    for ($t = time() - 86400; $t <= time(); $t += 60) {
        if (cron_matches($schedule, $t)) return true;
    }
    return false;
};

$totalRuns24 = 0;
$errorJobs = 0;
$missedJobs = 0;
$tableRows = '';
foreach ($rows as $j) {
    $runs24 = (int) $j['runs24'];
    $err24 = (int) $j['err24'];
    $totalRuns24 += $runs24;
    $enabled = (bool) $j['enabled'];

    if ($err24 > 0) {
        $statusTxt = '⚠ ' . $err24 . ' hata / ' . $runs24 . ' çalışma';
        $statusColor = '#b0301a';
        $errorJobs++;
    } elseif ($runs24 > 0) {
        $statusTxt = '✓ ' . $runs24 . ' çalışma';
        $statusColor = '#0d7a4a';
    } elseif (!$enabled) {
        $statusTxt = 'kapalı';
        $statusColor = '#64716d';
    } elseif ($dueIn24((string) $j['schedule'])) {
        $statusTxt = '✗ vadesi geldi, çalışmadı';
        $statusColor = '#b0301a';
        $missedJobs++;
    } else {
        $statusTxt = 'bugün vadesi yok';
        $statusColor = '#64716d';
    }

    $tableRows .= '<tr>'
        . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de"><b>' . htmlspecialchars((string) $j['name']) . '</b><br><code style="font-size:11px;color:#64716d">' . htmlspecialchars((string) $j['code']) . '</code></td>'
        . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de;text-align:center"><code>' . htmlspecialchars((string) $j['schedule']) . '</code></td>'
        . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de;text-align:center;color:' . $statusColor . ';font-weight:700">' . $statusTxt . '</td>'
        . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de;text-align:center">' . ($j['last24'] !== null ? htmlspecialchars((string) $j['last24']) : '—') . '</td>'
        . '</tr>';
}

$warnBanner = '';
if ($errorJobs > 0 || $missedJobs > 0) {
    $warnBanner = '<p style="background:#ffe2de;border:1px solid #f3c1b8;padding:10px 12px;font-size:13px">⚠ <b>' . ($errorJobs + $missedJobs) . ' görevde sorun</b> — hata veren: ' . $errorJobs . ', vadesi geldiği halde çalışmayan: ' . $missedJobs . '. Detaylar aşağıda.</p>';
} elseif ($totalRuns24 === 0) {
    $warnBanner = '<p style="background:#fff4d6;border:1px solid #f0dcae;padding:10px 12px;font-size:13px">⚠ Son 24 saatte <b>hiç çalışma kaydı yok</b> — nabız (tick) çalışmıyor olabilir. Zamanlayıcılar sayfasındaki kurulumu kontrol edin.</p>';
} else {
    $warnBanner = '<p style="background:#e6f8c7;border:1px solid #cfe8a8;padding:10px 12px;font-size:13px">✅ Son 24 saatte <b>' . $totalRuns24 . ' çalışma, ' . count($rows) . ' görevin tamamı sorunsuz.</b></p>';
}

$body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
    . '<h2 style="margin:0 0 6px">Günlük görev sağlık raporu</h2>'
    . '<p style="color:#64716d;margin:0 0 14px">' . date('d.m.Y') . ' · ' . count($rows) . ' görev · son 24 saatte ' . $totalRuns24 . ' çalışma</p>'
    . $warnBanner
    . '<table style="border-collapse:collapse;width:100%;max-width:640px">'
    . '<tr><th style="text-align:left;padding:8px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Görev</th><th style="padding:8px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d;text-align:center">Zamanlama</th><th style="padding:8px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d;text-align:center">Son 24s</th><th style="padding:8px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d;text-align:center">Son çalışma</th></tr>'
    . $tableRows
    . '</table>'
    . '<p style="margin-top:18px"><a href="https://nexustraveltech.com/admin/zamanlayici-gecmisi" style="color:#0d7a4a">Çalışma geçmişini görüntüle →</a></p>'
    . '</div>';

queue_email($to, 'Görev sağlık raporu — ' . date('d.m.Y'), $body, 'job_status_digest', $dateKey);
echo 'Görev sağlık raporu kuyruğa eklendi: ' . $to . ' (' . count($rows) . " görev, {$totalRuns24} çalışma, {$errorJobs} hatalı).\n";
