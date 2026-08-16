<?php
declare(strict_types=1);

/**
 * Görev hata uyarıları — arka arkaya 3 kez hata veren zamanlayıcı görevlerini
 * bulur ve admin_alert_email'e tek uyarı gönderir (aynı hata serisi için bir kez;
 * araya giren başarı bayrağı sıfırlar, yeni seri tekrar uyarır).
 *
 * Zamanlayıcı: nexus-job-fail-alerts (varsayılan: 15 dakikada bir) — admin → Zamanlayıcılar.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/platform_settings.php';

$to = trim((string) platform_setting('admin_alert_email', ''));
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Görev hata uyarısı gönderilmedi: admin_alert_email tanımlı değil (admin → Kontrol merkezi).\n");
    exit(0);
}

$pdo = db();
$jobs = $pdo->query('SELECT id,code,name,command,schedule,last_fail_alert_at FROM scheduled_jobs WHERE enabled=true')->fetchAll();
$sent = 0;

// Son 24 saat hata özeti — uyarı e-postalarına bağlam olarak eklenir.
$totalErr24 = (int) $pdo->query("SELECT COUNT(*) FROM scheduled_job_runs WHERE status='error' AND created_at >= now() - interval '24 hours'")->fetchColumn();
$topErr = $pdo->query("SELECT j.name,j.code,COUNT(r.id) c FROM scheduled_job_runs r JOIN scheduled_jobs j ON j.id=r.job_id WHERE r.status='error' AND r.created_at >= now() - interval '24 hours' GROUP BY j.id,j.name,j.code ORDER BY c DESC LIMIT 3")->fetchAll();
$summaryRows = '';
foreach ($topErr as $te) {
    $summaryRows .= '<tr><td style="padding:7px 12px;border-bottom:1px solid #e1e5de"><b>' . htmlspecialchars((string) $te['name']) . '</b><br><code style="font-size:11px;color:#64716d">' . htmlspecialchars((string) $te['code']) . '</code></td>'
        . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de;text-align:center">' . (int) $te['c'] . '</td></tr>';
}
$summaryHtml = '<h2 style="font-family:Arial;color:#10211f;margin:22px 0 6px">Son 24 saat hata özeti</h2>'
    . '<p style="font-family:Arial;color:#64716d;margin:0 0 8px">Toplam hata: <b style="color:' . ($totalErr24 > 0 ? '#b0301a' : '#0d7a4a') . '">' . $totalErr24 . '</b></p>'
    . ($summaryRows !== '' ? '<table style="border-collapse:collapse;font-family:Arial;color:#10211f;width:100%;max-width:480px"><tr><th style="text-align:left;padding:7px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">En sık hata veren görevler</th><th style="padding:7px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d;text-align:center">Hata</th></tr>' . $summaryRows . '</table>' : '<p style="font-family:Arial;color:#64716d">Son 24 saatte başka hata kaydı yok.</p>');

foreach ($jobs as $job) {
    $q = $pdo->prepare('SELECT id,status,output,created_at FROM scheduled_job_runs WHERE job_id=? ORDER BY id DESC LIMIT 3');
    $q->execute([$job['id']]);
    $runs = $q->fetchAll();
    if (count($runs) < 3) continue;

    $newest = $runs[0];
    $oldest = $runs[2];

    // Güncellik: en son çalışma 24 saat içinde olmalı (eski/uzak hatalar uyarı üretmez).
    if (strtotime((string) $newest['created_at']) < time() - 86400) continue;

    $allError = true;
    foreach ($runs as $r) {
        if ($r['status'] !== 'error') {
            $allError = false;
            break;
        }
    }

    if (!$allError) {
        // Başarılı çalışma seriyi kırdı — bayrağı temizle (sonraki seri tekrar uyarabilir).
        if ($job['last_fail_alert_at'] !== null) {
            $pdo->prepare('UPDATE scheduled_jobs SET last_fail_alert_at=NULL WHERE id=?')->execute([$job['id']]);
        }
        continue;
    }

    // Aynı seri için daha önce uyarı gitti mi? (serinin en eski çalışmasından sonra)
    $already = $job['last_fail_alert_at'] !== null
        && strtotime((string) $job['last_fail_alert_at']) >= strtotime((string) $oldest['created_at']);
    if ($already) continue;

    $output = trim((string) ($newest['output'] ?? ''));
    $body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
        . '<h2 style="margin:0 0 6px">⚠ Zamanlayıcı görevi arka arkaya 3 kez hata verdi</h2>'
        . '<table style="border-collapse:collapse;width:100%;max-width:560px;margin-top:10px">'
        . '<tr><td style="padding:7px 12px;border-bottom:1px solid #e1e5de;color:#64716d">Görev</td><td style="padding:7px 12px;border-bottom:1px solid #e1e5de"><b>' . htmlspecialchars((string) $job['name']) . '</b></td></tr>'
        . '<tr><td style="padding:7px 12px;border-bottom:1px solid #e1e5de;color:#64716d">Kod</td><td style="padding:7px 12px;border-bottom:1px solid #e1e5de"><code>' . htmlspecialchars((string) $job['code']) . '</code></td></tr>'
        . '<tr><td style="padding:7px 12px;border-bottom:1px solid #e1e5de;color:#64716d">Komut</td><td style="padding:7px 12px;border-bottom:1px solid #e1e5de"><code>' . htmlspecialchars((string) $job['command']) . '</code></td></tr>'
        . '<tr><td style="padding:7px 12px;border-bottom:1px solid #e1e5de;color:#64716d">Zamanlama</td><td style="padding:7px 12px;border-bottom:1px solid #e1e5de"><code>' . htmlspecialchars((string) $job['schedule']) . '</code></td></tr>'
        . '<tr><td style="padding:7px 12px;border-bottom:1px solid #e1e5de;color:#64716d">Son hata zamanı</td><td style="padding:7px 12px;border-bottom:1px solid #e1e5de">' . htmlspecialchars((string) $newest['created_at']) . '</td></tr>'
        . '</table>'
        . ($output !== '' ? '<p style="margin:14px 0 4px;color:#64716d">Son çıktı:</p><pre style="background:#f7f7f2;border:1px solid #e1e5de;padding:10px;font-size:12px;white-space:pre-wrap">' . htmlspecialchars(mb_substr($output, 0, 800)) . '</pre>' : '')
        . '<p style="margin-top:18px"><a href="https://nexustraveltech.com/admin/zamanlayici-gecmisi?job=' . (int) $job['id'] . '&status=error" style="color:#0d7a4a">Çalışma geçmişini görüntüle →</a></p>'
        . '</div>';

    queue_email($to, 'Zamanlayıcı uyarısı: ' . htmlspecialchars((string) $job['name']) . ' 3 kez hata verdi', $body . $summaryHtml, 'job_fail_alert', (int) $job['id']);
    $pdo->prepare('UPDATE scheduled_jobs SET last_fail_alert_at=now() WHERE id=?')->execute([$job['id']]);
    $sent++;
    echo 'Uyarı kuyruğa eklendi: ' . $job['code'] . "\n";
}

echo $sent === 0 ? "Ardışık 3 hatası olan görev yok.\n" : "Toplam {$sent} uyarı kuyruğa eklendi.\n";
