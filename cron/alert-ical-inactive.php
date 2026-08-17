<?php
declare(strict_types=1);

// iCal pasif uyarıları — yayındaki (status='active') villa/yat ilanlarında en az bir
// iCal bağlantısı tanımlı ama hiçbiri aktif değilse tedarikçiye panel bildirimi ve
// admin_alert_email'e e-posta gönderir. Aynı ilan için 24 saatte bir kez
// (notifications tipi ical_inactive_{ilanId} ile kuyruklanır).
//
// Zamanlayıcı: nexus-ical-inactive-alerts (varsayılan: 15 dakikada bir).

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/notifications.php';

$pdo = db();
$adminEmail = trim((string) platform_setting('admin_alert_email', ''));

$properties = $pdo->query("
    SELECT p.id, p.supplier_id, p.name, p.property_type, s.company_name,
      (SELECT COUNT(*) FROM ical_connections c WHERE c.property_id=p.id) total_con,
      (SELECT MAX(c.last_sync_at) FROM ical_connections c WHERE c.property_id=p.id) last_sync_at,
      (SELECT string_agg(DISTINCT c.last_error, ' | ') FROM ical_connections c WHERE c.property_id=p.id AND c.status='error' AND COALESCE(c.last_error,'')<>'') last_errors
    FROM properties p JOIN suppliers s ON s.id=p.supplier_id
    WHERE p.status='active' AND p.property_type IN ('villa','yacht')
      AND (SELECT COUNT(*) FROM ical_connections c WHERE c.property_id=p.id) > 0
      AND (SELECT COUNT(*) FROM ical_connections c WHERE c.property_id=p.id AND c.status='active') = 0
")->fetchAll();

$recentCheck = $pdo->prepare("SELECT id FROM notifications WHERE user_type='supplier' AND user_id IN (SELECT id FROM supplier_users WHERE supplier_id=?) AND type=? AND created_at > now() - interval '24 hours' LIMIT 1");

$notified = 0;
$emailed = 0;

foreach ($properties as $prop) {
    $propId = (int) $prop['id'];
    $supplierId = (int) $prop['supplier_id'];
    $typeKey = 'ical_inactive_' . $propId; // notifications.type 40 karakterle sınırlıdır.
    $recentCheck->execute([$supplierId, $typeKey]);
    if ($recentCheck->fetch()) {
        continue; // Son 24 saatte bu ilan için bildirim gitti — gürültü yapma.
    }

    $total = (int) $prop['total_con'];
    $lastSync = (string) ($prop['last_sync_at'] ?? '');
    $lastErrors = trim((string) ($prop['last_errors'] ?? ''));
    $typeLabel = $prop['property_type'] === 'yacht' ? 'Yat' : 'Villa';

    // 1) Tedarikçi panel bildirimi (tüm kullanıcılarına).
    $msg = 'iCal uyarısı: "' . $prop['name'] . '" ilanının ' . $total . ' bağlantısından hiçbiri aktif değil.'
        . ($lastErrors !== '' ? ' Son hata: ' . $lastErrors : '');
    notify_supplier_users($supplierId, $typeKey, mb_substr($msg, 0, 500), '/nexustraveltech/tedarikci/ical-takvimler');
    $notified++;

    // 2) Admin e-postası (yalnızca admin_alert_email tanımlıysa).
    if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $rows = '';
        foreach ([['İlan', '<b>' . htmlspecialchars((string) $prop['name']) . '</b> (' . $typeLabel . ')'], ['Tedarikçi', htmlspecialchars((string) $prop['company_name'])], ['Bağlantı sayısı', $total], ['Son senkron', $lastSync !== '' ? $lastSync : 'henüz yok']] as $r) {
            $rows .= '<tr><td style="padding:7px 12px;border-bottom:1px solid #e1e5de;color:#64716d">' . $r[0] . '</td><td style="padding:7px 12px;border-bottom:1px solid #e1e5de">' . $r[1] . '</td></tr>';
        }
        $body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
            . '<h2 style="margin:0 0 6px">⚠ iCal bağlantısı pasif: ' . htmlspecialchars((string) $prop['name']) . '</h2>'
            . '<p style="color:#64716d;margin:0 0 10px">Yayındaki villa/yat ilanının ' . $total . ' iCal bağlantısından hiçbiri aktif değil — ilan müsaitlik kanallarıyla senkronize edilemiyor.</p>'
            . '<table style="border-collapse:collapse;width:100%;max-width:560px">' . $rows . '</table>'
            . ($lastErrors !== '' ? '<p style="margin:14px 0 4px;color:#64716d">Son hata(lar):</p><pre style="background:#f7f7f2;border:1px solid #e1e5de;padding:10px;font-size:12px;white-space:pre-wrap">' . htmlspecialchars(mb_substr($lastErrors, 0, 800)) . '</pre>' : '')
            . '<p style="margin-top:18px"><a href="https://nexustraveltech.com/admin/tedarikci-onaylari" style="color:#b0301a">Tedarikçi yönetimi →</a></p>'
            . '</div>';
        queue_email($adminEmail, 'iCal bağlantısı pasif: ' . htmlspecialchars((string) $prop['name']), $body, 'ical_inactive', $propId);
        $emailed++;
    }

    echo 'iCal uyarısı eklendi: ' . $prop['name'] . ' (tedarikçi #' . $supplierId . ")\n";
}

if ($notified === 0) {
    echo "Pasif iCal bağlantısı olan yayınlanmış villa/yat yok.\n";
} else {
    echo "Özet: {$notified} ilan bildirildi, {$emailed} admin e-postası kuyruğa eklendi.\n";
}
