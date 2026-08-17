<?php
declare(strict_types=1);

// Kanal senkron kuyruğu başarısızlık taraması — channel_sync_jobs tablosunda son 24 saatte
// status='failed' olan (veya çok uzun süredir 'running' takılı kalan) işleri bulur ve
// bağlantının sahibi tedarikçiye panel bildirimi + admin_alert_email'e e-posta gönderir.
// Aynı tedarikçi için 24 saatte bir kez (notifications tipi channel_job_failed_{tedarikçiId}).
//
// Zamanlayıcı: nexus-channel-sync-job-alerts (varsayılan: 15 dakikada bir).

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/notifications.php';

$pdo = db();
$adminEmail = trim((string) platform_setting('admin_alert_email', ''));

// Son 24 saatte başarısız olan veya 30 dakikadan uzun 'running' takılı kalmış işler.
$jobs = $pdo->query("
    SELECT j.id, j.job_type, j.status, j.error_message, j.created_at, j.processed_at,
           c.id connection_id, c.display_name channel_name, c.channel_code,
           c.supplier_id, s.company_name
    FROM channel_sync_jobs j
    JOIN channel_connections c ON c.id = j.channel_connection_id
    JOIN suppliers s ON s.id = c.supplier_id
    WHERE j.status = 'failed'
       OR (j.status = 'running' AND j.processed_at < now() - interval '30 minutes')
    ORDER BY j.id DESC
")->fetchAll();

// Yalnızca son 24 saatte oluşan/başarısızlaşan kayıtları bildir (eski çöp sayılmasın).
$recent = array_filter($jobs, function ($j) {
    $ts = $j['status'] === 'failed' ? (string) $j['processed_at'] : (string) $j['created_at'];
    if ($ts === '') return false;
    return strtotime($ts) >= time() - 24 * 3600;
});

$bySupplier = [];
foreach ($recent as $j) {
    $sid = (int) $j['supplier_id'];
    if (!isset($bySupplier[$sid])) {
        $bySupplier[$sid] = ['company' => (string) $j['company_name'], 'jobs' => []];
    }
    $bySupplier[$sid]['jobs'][] = $j;
}

$recentCheck = $pdo->prepare("SELECT id FROM notifications WHERE user_type='supplier' AND user_id IN (SELECT id FROM supplier_users WHERE supplier_id=?) AND type=? AND created_at > now() - interval '24 hours' LIMIT 1");

$notified = 0;
$emailed = 0;

foreach ($bySupplier as $sid => $data) {
    $typeKey = 'channel_job_failed_' . $sid; // notifications.type 40 karakterle sınırlıdır.
    $recentCheck->execute([$sid, $typeKey]);
    if ($recentCheck->fetch()) {
        continue; // Son 24 saatte bu tedarikçi için bildirim gitti — gürültü yapma.
    }

    $jobs = array_slice($data['jobs'], 0, 5);
    $total = count($data['jobs']);
    $lines = [];
    foreach ($jobs as $j) {
        $err = trim((string) ($j['error_message'] ?? ''));
        $lines[] = '• ' . $j['channel_name'] . ' / ' . $j['job_type']
            . ($err !== '' ? ' — ' . mb_substr($err, 0, 120) : '')
            . ' (' . (string) $j['processed_at'] . ')';
    }
    if ($total > 5) $lines[] = '… ve ' . ($total - 5) . ' iş daha.';

    // 1) Tedarikçi panel bildirimi (tüm kullanıcılarına).
    $msg = 'Senkron uyarısı: son 24 saatte ' . $total . ' kanal senkron işi başarısız oldu.'
        . ' ' . implode(' | ', $lines);
    notify_supplier_users($sid, $typeKey, mb_substr($msg, 0, 500), '/nexustraveltech/tedarikci/dagitim-merkezi');
    $notified++;

    // 2) Admin e-postası (yalnızca admin_alert_email tanımlıysa).
    if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $rows = '';
        foreach ($lines as $line) {
            $rows .= '<tr><td style="padding:7px 12px;border-bottom:1px solid #e1e5de">' . htmlspecialchars($line) . '</td></tr>';
        }
        $body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
            . '<h2 style="margin:0 0 6px">⚠ Kanal senkron başarısızlığı: ' . htmlspecialchars($data['company']) . '</h2>'
            . '<p style="color:#64716d;margin:0 0 10px">Son 24 saatte <b style="color:#b0301a">' . $total . '</b> senkron işi başarısız oldu veya takılı kaldı.</p>'
            . '<table style="border-collapse:collapse;width:100%;max-width:600px">' . $rows . '</table>'
            . '<p style="margin-top:18px"><a href="https://nexustraveltech.com/admin/tedarikci-onaylari" style="color:#b0301a">Tedarikçi yönetimi →</a></p>'
            . '</div>';
        queue_email($adminEmail, 'Kanal senkron başarısızlığı: ' . htmlspecialchars($data['company']) . ' (' . $total . ' iş)', $body, 'channel_sync_job_failed', $sid);
        $emailed++;
    }

    echo 'Senkron hata uyarısı eklendi: ' . $data['company'] . ' (tedarikçi #' . $sid . ', ' . $total . " iş)\n";
}

if ($notified === 0) {
    echo "Son 24 saatte başarısız/takılı kanal senkron işi yok.\n";
} else {
    echo "Özet: {$notified} tedarikçi bildirildi, {$emailed} admin e-postası kuyruğa eklendi.\n";
}
