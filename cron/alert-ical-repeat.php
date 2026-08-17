<?php
declare(strict_types=1);

// iCal içe aktarma/senkron tekrar tespiti — webhook loop uyarısıyla aynı mantık.
// Aynı hata içeriği (error_hash = md5(error_message)) son 24 saatte belirli sayıda
// (varsayılan 3, platform ayarı ical_repeat_threshold — admin -> Kontrol merkezi)
// tekrarlarsa bağlantının sahibi tedarikçiye panel bildirimi + admin_alert_email'e
// e-posta gönderir. Aynı bağlantı + hata için 24 saatte bir kez bildirim
// (notifications tipi ical_loop_{bağlantı}_{hash}).
//
// Zamanlayıcı: nexus-ical-repeat-alerts (varsayılan: 15 dakikada bir).

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/notifications.php';

$pdo = db();
$adminEmail = trim((string) platform_setting('admin_alert_email', ''));
$threshold = max(3, (int) platform_setting('ical_repeat_threshold', 3));

// Aynı hata (error_hash) son 24 saatte kaç kez tekrarlandı.
$groups = $pdo->query(
    "SELECT l.ical_connection_id,
            l.error_hash,
            COUNT(*) AS attempt_count,
            MIN(l.created_at) AS first_at,
            MAX(l.created_at) AS last_at,
            (array_agg(l.error_message ORDER BY l.id DESC))[1] AS last_error,
            c.label, c.source_url, c.property_id, c.supplier_id,
            p.name AS property_name, s.company_name
     FROM ical_sync_logs l
     JOIN ical_connections c ON c.id = l.ical_connection_id
     JOIN suppliers s ON s.id = c.supplier_id
     LEFT JOIN properties p ON p.id = l.property_id
     WHERE l.status = 'failed'
       AND l.created_at >= now() - interval '24 hours'
       AND l.error_hash IS NOT NULL
     GROUP BY l.ical_connection_id, l.error_hash, c.label, c.source_url,
              c.property_id, c.supplier_id, p.name, s.company_name
     HAVING COUNT(*) >= {$threshold}
     ORDER BY attempt_count DESC"
)->fetchAll();

$dedup = $pdo->prepare("SELECT id FROM notifications WHERE user_type='supplier' AND user_id IN (SELECT id FROM supplier_users WHERE supplier_id=?) AND type=? AND created_at > now() - interval '24 hours' LIMIT 1");

$notified = 0;
$emailed = 0;

foreach ($groups as $g) {
    $sid = (int) $g['supplier_id'];
    $hash = substr((string) $g['error_hash'], 0, 8);
    $typeKey = 'ical_loop_' . (int) $g['ical_connection_id'] . '_' . $hash;
    $dedup->execute([$sid, $typeKey]);
    if ($dedup->fetch()) {
        continue; // Son 24 saatte bu bağlantı + hata için bildirim gitti.
    }

    $count = (int) $g['attempt_count'];
    $err = trim((string) ($g['last_error'] ?? ''));
    $propertyLabel = $g['property_name'] !== null ? ' · ' . $g['property_name'] : '';
    $link = '/nexustraveltech/tedarikci/ical-takvimler';

    // 1) Tedarikçi panel bildirimi (tüm kullanıcılarına).
    $msg = 'iCal tekrar uyarısı: ' . $g['label'] . ' bağlantısı son 24 saatte '
        . $count . ' kez aynı hata ile senkronize edilemedi' . $propertyLabel
        . ($err !== '' ? '. Son hata: ' . mb_substr($err, 0, 160) : '')
        . '. iCal takvimler sayfasından bağlantıyı kontrol edin.';
    notify_supplier_users($sid, $typeKey, mb_substr($msg, 0, 500), $link);
    $notified++;

    // 2) Admin e-postası (yalnızca admin_alert_email tanımlıysa).
    if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
            . '<h2 style="margin:0 0 6px">⚠ Tekrarlayan iCal senkron hatası: ' . htmlspecialchars($g['label']) . '</h2>'
            . '<p style="color:#64716d;margin:0 0 10px">' . htmlspecialchars($g['company_name'])
            . ' bağlantısında <b style="color:#b0301a">aynı hata</b> son 24 saatte <b style="color:#b0301a">' . $count . '</b> kez tekrarlandı'
            . htmlspecialchars($propertyLabel) . '.</p>'
            . '<table style="border-collapse:collapse;width:100%;max-width:620px;font-size:13px">'
            . '<tr><td style="padding:7px 12px;border:1px solid #e1e5de"><b>Bağlantı</b></td><td style="padding:7px 12px;border:1px solid #e1e5de">' . htmlspecialchars($g['label']) . '</td></tr>'
            . '<tr><td style="padding:7px 12px;border:1px solid #e1e5de"><b>Kaynak URL</b></td><td style="padding:7px 12px;border:1px solid #e1e5de"><code style="font-size:11px;word-break:break-all">' . htmlspecialchars((string) $g['source_url']) . '</code></td></tr>'
            . '<tr><td style="padding:7px 12px;border:1px solid #e1e5de"><b>İlk / son deneme</b></td><td style="padding:7px 12px;border:1px solid #e1e5de">' . htmlspecialchars((string) $g['first_at'] . ' → ' . $g['last_at']) . '</td></tr>'
            . '<tr><td style="padding:7px 12px;border:1px solid #e1e5de"><b>Son hata</b></td><td style="padding:7px 12px;border:1px solid #e1e5de;color:#b0301a">' . htmlspecialchars($err !== '' ? mb_substr($err, 0, 300) : '—') . '</td></tr>'
            . '</table>'
            . '<p style="margin-top:18px"><a href="https://nexustraveltech.com/admin/tedarikci-onaylari" style="color:#b0301a">Tedarikçi yönetimi →</a> · '
            . '<a href="https://nexustraveltech.com/tedarikci/ical-takvimler" style="color:#b0301a">iCal takvimler →</a></p>'
            . '</div>';
        queue_email($adminEmail, 'iCal tekrar hatası: ' . htmlspecialchars($g['label']) . ' (' . $count . ' deneme)', $body, 'ical_repeat', (int) $g['ical_connection_id']);
        $emailed++;
    }

    echo 'iCal tekrar uyarısı eklendi: ' . $g['company_name'] . ' → ' . $g['label'] . ' (aynı hata ' . $count . " kez)\\n";
}

if ($notified === 0) {
    echo "Son 24 saatte tekrar eden iCal senkron hatası yok (eşik: {$threshold}).\\n";
} else {
    echo "Özet: {$notified} tedarikçi bildirildi, {$emailed} admin e-postası kuyruğa eklendi.\\n";
}
