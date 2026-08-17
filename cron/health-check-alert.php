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
    $body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
        . '<h2 style="margin:0 0 6px">⚠ Platform sağlık kontrolü: ' . count($result['errors']) . ' sorun</h2>'
        . '<p style="color:#64716d;margin:0 0 10px">Günlük sağlık taraması sorun tespit etti. Eksik tablo/kolon, başarısız migration veya ortam eksikliği olabilir; ayrıntılar aşağıdadır.</p>'
        . '<table style="border-collapse:collapse;width:100%;max-width:640px;font-size:13px">'
        . '<tr><th style="text-align:left;padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1">Sorun</th></tr>'
        . $rowsHtml . $extra
        . '</table>'
        . '<p style="margin-top:18px">Tam çıktı için sunucuda: <code style="background:#f2f4ef;padding:2px 5px">/opt/plesk/php/8.5/bin/php scripts/health-check.php</code></p>'
        . '</div>';
    queue_email($adminEmail, '⚠ Sağlık kontrolü: ' . count($result['errors']) . ' sorun', $body, 'health_check_alert');
    echo " Admin e-postası kuyruğa eklendi.\n";
} else {
    echo " admin_alert_email tanımsız — e-posta atlanıyor.\n";
}
exit(1);
