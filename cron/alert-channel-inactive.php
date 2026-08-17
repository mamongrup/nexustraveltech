<?php
declare(strict_types=1);

// Kanal dağıtımı pasif uyarıları — yayındaki (status='active') oteli olan ve en az bir
// channel_connections bağlantısı tanımlı ama hiçbiri aktif olmayan tedarikçilere panel bildirimi
// ve admin_alert_email'e e-posta gönderir. Aynı tedarikçi için 24 saatte bir kez
// (notifications tipi channel_inactive_{tedarikçiId} ile kuyruklanır).
//
// Zamanlayıcı: nexus-channel-inactive-alerts (varsayılan: 15 dakikada bir).

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/notifications.php';

$pdo = db();
$adminEmail = trim((string) platform_setting('admin_alert_email', ''));

$suppliers = $pdo->query("
    SELECT s.id, s.company_name,
      (SELECT COUNT(*) FROM channel_connections c WHERE c.supplier_id=s.id) total_con,
      (SELECT string_agg(DISTINCT c.display_name, ', ') FROM channel_connections c WHERE c.supplier_id=s.id) channels,
      (SELECT MAX(c.last_sync_at) FROM channel_connections c WHERE c.supplier_id=s.id) last_sync_at,
      (SELECT string_agg(DISTINCT c.last_error, ' | ') FROM channel_connections c WHERE c.supplier_id=s.id AND c.status='error' AND COALESCE(c.last_error,'')<>'') last_errors
    FROM suppliers s
    WHERE EXISTS (SELECT 1 FROM properties p WHERE p.supplier_id=s.id AND p.status='active' AND p.property_type='hotel')
      AND (SELECT COUNT(*) FROM channel_connections c WHERE c.supplier_id=s.id) > 0
      AND (SELECT COUNT(*) FROM channel_connections c WHERE c.supplier_id=s.id AND c.status='active') = 0
")->fetchAll();

$recentCheck = $pdo->prepare("SELECT id FROM notifications WHERE user_type='supplier' AND user_id IN (SELECT id FROM supplier_users WHERE supplier_id=?) AND type=? AND created_at > now() - interval '24 hours' LIMIT 1");

$notified = 0;
$emailed = 0;

foreach ($suppliers as $sup) {
    $supplierId = (int) $sup['id'];
    $typeKey = 'channel_inactive_' . $supplierId; // notifications.type 40 karakterle sınırlıdır.
    $recentCheck->execute([$supplierId, $typeKey]);
    if ($recentCheck->fetch()) {
        continue; // Son 24 saatte bu tedarikçi için bildirim gitti — gürültü yapma.
    }

    $total = (int) $sup['total_con'];
    $channels = trim((string) ($sup['channels'] ?? ''));
    $lastSync = (string) ($sup['last_sync_at'] ?? '');
    $lastErrors = trim((string) ($sup['last_errors'] ?? ''));

    // 1) Tedarikçi panel bildirimi (tüm kullanıcılarına).
    $msg = 'Dağıtım uyarısı: "' . ($channels !== '' ? $channels : $total . ' kanal') . '" bağlantınızın hiçbiri aktif değil. Yayındaki otelleriniz kanallara senkronize edilemiyor.'
        . ($lastErrors !== '' ? ' Son hata: ' . $lastErrors : '');
    notify_supplier_users($supplierId, $typeKey, mb_substr($msg, 0, 500), '/nexustraveltech/tedarikci/dagitim-merkezi');
    $notified++;

    // 2) Admin e-postası (yalnızca admin_alert_email tanımlıysa).
    if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $rows = '';
        foreach ([['Tedarikçi', '<b>' . htmlspecialchars((string) $sup['company_name']) . '</b>'], ['Kanallar', htmlspecialchars($channels)], ['Bağlantı sayısı', $total], ['Son senkron', $lastSync !== '' ? $lastSync : 'henüz yok']] as $r) {
            $rows .= '<tr><td style="padding:7px 12px;border-bottom:1px solid #e1e5de;color:#64716d">' . $r[0] . '</td><td style="padding:7px 12px;border-bottom:1px solid #e1e5de">' . $r[1] . '</td></tr>';
        }
        $body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
            . '<h2 style="margin:0 0 6px">⚠ Kanal dağıtımı pasif: ' . htmlspecialchars((string) $sup['company_name']) . '</h2>'
            . '<p style="color:#64716d;margin:0 0 10px">Yayındaki oteli olan tedarikçinin ' . $total . ' kanal bağlantısından hiçbiri aktif değil — oteller dağıtım kanallarına senkronize edilemiyor.</p>'
            . '<table style="border-collapse:collapse;width:100%;max-width:560px">' . $rows . '</table>'
            . ($lastErrors !== '' ? '<p style="margin:14px 0 4px;color:#64716d">Son hata(lar):</p><pre style="background:#f7f7f2;border:1px solid #e1e5de;padding:10px;font-size:12px;white-space:pre-wrap">' . htmlspecialchars(mb_substr($lastErrors, 0, 800)) . '</pre>' : '')
            . '<p style="margin-top:18px"><a href="https://nexustraveltech.com/admin/tedarikci-onaylari" style="color:#b0301a">Tedarikçi yönetimi →</a></p>'
            . '</div>';
        queue_email($adminEmail, 'Kanal dağıtımı pasif: ' . htmlspecialchars((string) $sup['company_name']), $body, 'channel_inactive', $supplierId);
        $emailed++;
    }

    echo 'Kanal uyarısı eklendi: ' . $sup['company_name'] . ' (tedarikçi #' . $supplierId . ")\n";
}

if ($notified === 0) {
    echo "Pasif kanal bağlantısı olan yayınlanmış otel sahibi tedarikçi yok.\n";
} else {
    echo "Özet: {$notified} tedarikçi bildirildi, {$emailed} admin e-postası kuyruğa eklendi.\n";
}
