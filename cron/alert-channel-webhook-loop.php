<?php
declare(strict_types=1);

// Kanal webhook tekrar deneme taraması — aynı yük (request_payload) son 24 saatte
// birçok kez başarısız olduysa bağlantının sahibi tedarikçiye panel bildirimi +
// admin_alert_email'e e-posta gönderir. Eşik kanal başına belirlenir:
// channel_connections.webhook_loop_threshold doluysa o değer, boşsa kontrol merkezindeki
// varsayılan (channel_webhook_loop_threshold, 3) kullanılır. Kanalın webhook'u yeniden
// göndermesi her seferinde yeni bir channel_sync_logs satırı oluşturduğu için aynı
// içerikteki yük gruplanarak sayılır. Aynı bağlantı + yük için 24 saatte bir kez bildirim
// (notifications tipi webhook_loop_{bağlantı}_{hash}).
//
// Zamanlayıcı: nexus-channel-webhook-loop-alerts (varsayılan: 15 dakikada bir).

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/notifications.php';

$pdo = db();
$adminEmail = trim((string) platform_setting('admin_alert_email', ''));
$threshold = max(3, (int) platform_setting('channel_webhook_loop_threshold', 3));

// Aynı yükün (md5 ile) son 24 saatte kaç kez başarısız olduğunu gruplar.
$groups = $pdo->query(
    "SELECT l.channel_connection_id,
            md5(l.request_payload::text) AS payload_hash,
            COUNT(*) AS attempt_count,
            MIN(l.created_at) AS first_at,
            MAX(l.created_at) AS last_at,
            (array_agg(l.error_message ORDER BY l.id DESC))[1] AS last_error,
            (array_agg(l.request_payload::text ORDER BY l.id DESC))[1] AS last_payload,
            c.display_name, c.channel_code, c.supplier_id, s.company_name,
            p.id AS property_id, p.name AS property_name
     FROM channel_sync_logs l
     JOIN channel_connections c ON c.id = l.channel_connection_id
     JOIN suppliers s ON s.id = c.supplier_id
     LEFT JOIN properties p ON p.id = l.property_id
     WHERE l.direction = 'pull' AND l.status = 'failed'
       AND l.created_at >= now() - interval '24 hours'
       AND l.request_payload IS NOT NULL
     GROUP BY l.channel_connection_id, payload_hash, c.display_name, c.channel_code,
              c.supplier_id, s.company_name, p.id, p.name, c.webhook_loop_threshold
     HAVING COUNT(*) >= COALESCE(c.webhook_loop_threshold, {$threshold})
     ORDER BY attempt_count DESC"
)->fetchAll();

$dedup = $pdo->prepare("SELECT id FROM notifications WHERE user_type='supplier' AND user_id IN (SELECT id FROM supplier_users WHERE supplier_id=?) AND type=? AND created_at > now() - interval '24 hours' LIMIT 1");

$notified = 0;
$emailed = 0;

foreach ($groups as $g) {
    $sid = (int) $g['supplier_id'];
    $hash = substr((string) $g['payload_hash'], 0, 8);
    $typeKey = 'webhook_loop_' . (int) $g['channel_connection_id'] . '_' . $hash; // notifications.type sınırına uygun.
    $dedup->execute([$sid, $typeKey]);
    if ($dedup->fetch()) {
        continue; // Son 24 saatte bu bağlantı + yük için bildirim gitti.
    }

    $count = (int) $g['attempt_count'];
    // Kanal özel eşiği varsa onu kullan, yoksa kontrol merkezi varsayılanı.
    $effThreshold = $g['webhook_loop_threshold'] !== null && (int) $g['webhook_loop_threshold'] >= 3
        ? (int) $g['webhook_loop_threshold']
        : $threshold;
    $err = trim((string) ($g['last_error'] ?? ''));
    $propertyLabel = $g['property_name'] !== null ? ' · ' . $g['property_name'] : '';
    $link = '/nexustraveltech/tedarikci/dagitim-merkezi';

    // 1) Tedarikçi panel bildirimi (tüm kullanıcılarına).
    $msg = 'Webhook döngü uyarısı: ' . $g['display_name'] . ' kanalına gönderilen aynı bildirim son 24 saatte '
        . $count . ' kez işlenemedi (kanal eşiği: ' . $effThreshold . ')' . $propertyLabel
        . ($err !== '' ? '. Son hata: ' . mb_substr($err, 0, 160) : '')
        . '. Dağıtım & kanal merkezi bölüm 4\'ten işlem günlüğünü inceleyin.';
    notify_supplier_users($sid, $typeKey, mb_substr($msg, 0, 500), $link);
    $notified++;

    // 2) Admin e-postası (yalnızca admin_alert_email tanımlıysa).
    if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $snippet = mb_substr((string) ($g['last_payload'] ?? ''), 0, 400);
        $body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
            . '<h2 style="margin:0 0 6px">⚠ Tekrarlayan webhook başarısızlığı: ' . htmlspecialchars($g['display_name']) . '</h2>'
            . '<p style="color:#64716d;margin:0 0 10px">' . htmlspecialchars($g['company_name'])
            . ' bağlantısında <b style="color:#b0301a">aynı yük</b> son 24 saatte <b style="color:#b0301a">' . $count . '</b> kez işlenemedi'
            . htmlspecialchars($propertyLabel) . '.</p>'
            . '<table style="border-collapse:collapse;width:100%;max-width:620px;font-size:13px">'
            . '<tr><td style="padding:7px 12px;border:1px solid #e1e5de"><b>Kanal</b></td><td style="padding:7px 12px;border:1px solid #e1e5de">' . htmlspecialchars($g['display_name'] . ' (' . $g['channel_code'] . ')') . '</td></tr>'
            . '<tr><td style="padding:7px 12px;border:1px solid #e1e5de"><b>İlk / son deneme</b></td><td style="padding:7px 12px;border:1px solid #e1e5de">' . htmlspecialchars((string) $g['first_at'] . ' → ' . $g['last_at']) . '</td></tr>'
            . '<tr><td style="padding:7px 12px;border:1px solid #e1e5de"><b>Son hata</b></td><td style="padding:7px 12px;border:1px solid #e1e5de;color:#b0301a">' . htmlspecialchars($err !== '' ? mb_substr($err, 0, 300) : '—') . '</td></tr>'
            . '<tr><td style="padding:7px 12px;border:1px solid #e1e5de"><b>Son yük (ilk 400 karakter)</b></td><td style="padding:7px 12px;border:1px solid #e1e5de"><code style="font-size:11px;word-break:break-all">' . htmlspecialchars($snippet) . '</code></td></tr>'
            . '</table>'
            . '<p style="margin-top:18px"><a href="https://nexustraveltech.com/admin/tedarikci-onaylari" style="color:#b0301a">Tedarikçi yönetimi →</a> · '
            . '<a href="https://nexustraveltech.com/tedarikci/dagitim-merkezi" style="color:#b0301a">Tedarikçi dağıtım merkezi →</a></p>'
            . '</div>';
        queue_email($adminEmail, 'Webhook tekrar başarısızlığı: ' . htmlspecialchars($g['display_name']) . ' (' . $count . ' deneme)', $body, 'channel_webhook_loop', (int) $g['channel_connection_id']);
        $emailed++;
    }

    echo 'Webhook döngü uyarısı eklendi: ' . $g['company_name'] . ' → ' . $g['display_name'] . ' (aynı yük ' . $count . " kez başarısız)\n";
}

if ($notified === 0) {
    echo "Son 24 saatte tekrar eden başarısız webhook yükü yok (eşik: {$threshold}).\n";
} else {
    echo "Özet: {$notified} tedarikçi bildirildi, {$emailed} admin e-postası kuyruğa eklendi.\n";
}
