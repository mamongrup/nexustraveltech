<?php
declare(strict_types=1);

// iCal sağlık haftalık özeti — yayındaki villa/yat ilanlarından son senkronu 7 günden eski
// (veya hiç senkron yok / aktif bağlantısı yok) olanları tek e-postayla admin_alert_email'e bildirir.
// Haftada bir kez idempotent (platform ayarı ical_health_digest_week = ISO yıl-hafta).
//
// Zamanlayıcı: nexus-ical-health-digest (varsayılan: pazartesi 08:00).

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/platform_settings.php';

$to = trim((string) platform_setting('admin_alert_email', ''));
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "iCal sağlık özeti gönderilmedi: admin_alert_email tanımlı değil (admin → Kontrol merkezi).\n");
    exit(0);
}

$week = date('o-W');
if (platform_setting('ical_health_digest_week', '') === $week) {
    echo "Bu hafta ($week) iCal sağlık özeti zaten kuyruğa eklendi; atlandı.\n";
    exit(0);
}

$pdo = db();
$rows = $pdo->query("
    SELECT p.id, p.name, p.property_type, p.supplier_id, s.company_name,
      (SELECT COUNT(*) FROM ical_connections c WHERE c.property_id=p.id AND c.status='active') active_con,
      (SELECT MAX(c.last_sync_at) FROM ical_connections c WHERE c.property_id=p.id AND c.status='active') last_sync_at
    FROM properties p JOIN suppliers s ON s.id=p.supplier_id
    WHERE p.status='active' AND p.property_type IN ('villa','yacht')
    ORDER BY s.company_name, p.name
")->fetchAll();

$totalPublished = count($rows);
$problems = [];
foreach ($rows as $r) {
    $active = (int) $r['active_con'];
    $lastSync = $r['last_sync_at'] !== null ? strtotime((string) $r['last_sync_at']) : 0;
    $ageDays = $lastSync > 0 ? (int) floor((time() - $lastSync) / 86400) : null;
    if ($active === 0) {
        $problems[] = ['name' => $r['name'], 'type' => $r['property_type'], 'company' => $r['company_name'], 'status' => 'Pasif bağlantı', 'age' => null, 'cls' => '#b0301a'];
    } elseif ($lastSync === 0) {
        $problems[] = ['name' => $r['name'], 'type' => $r['property_type'], 'company' => $r['company_name'], 'status' => 'Kırmızı · hiç senkron yok', 'age' => null, 'cls' => '#b0301a'];
    } elseif ($ageDays >= 30) {
        $problems[] = ['name' => $r['name'], 'type' => $r['property_type'], 'company' => $r['company_name'], 'status' => "Kırmızı · {$ageDays} gün", 'age' => $ageDays, 'cls' => '#b0301a'];
    } elseif ($ageDays >= 7) {
        $problems[] = ['name' => $r['name'], 'type' => $r['property_type'], 'company' => $r['company_name'], 'status' => "Sarı · {$ageDays} gün", 'age' => $ageDays, 'cls' => '#8a6100'];
    }
}

if ($problems === []) {
    save_platform_setting('ical_health_digest_week', $week);
    echo "Sorunlu yayınlanmış villa/yat yok — özet gönderilmedi. ($totalPublished yayında)\n";
    exit(0);
}

$bodyRows = '';
foreach ($problems as $p) {
    $lastTxt = $p['age'] !== null ? $p['age'] . ' gün önce' : '—';
    $bodyRows .= '<tr>'
        . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de"><b>' . htmlspecialchars($p['name']) . '</b><br><small style="color:#64716d">' . ($p['type'] === 'yacht' ? 'Yat' : 'Villa') . ' · ' . htmlspecialchars($p['company']) . '</small></td>'
        . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de;color:' . $p['cls'] . '"><b>' . htmlspecialchars($p['status']) . '</b></td>'
        . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de">' . $lastTxt . '</td>'
        . '</tr>';
}

$body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
    . '<h2 style="margin:0 0 6px">📅 iCal sağlık haftalık özeti</h2>'
    . '<p style="color:#64716d;margin:0 0 10px">Yayındaki <b>' . $totalPublished . '</b> villa/yat ilanından <b style="color:#b0301a">' . count($problems) . '</b> tanesinde senkron sorunu var (7+ gün eski veya hiç yok).</p>'
    . '<table style="border-collapse:collapse;width:100%;max-width:640px">'
    . '<tr><th style="text-align:left;padding:7px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">İlan</th><th style="text-align:left;padding:7px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Durum</th><th style="text-align:left;padding:7px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Son senkron</th></tr>'
    . $bodyRows
    . '</table>'
    . '<p style="margin-top:18px"><a href="https://nexustraveltech.com/admin/tedarikci-onaylari" style="color:#0d7a4a">Tedarikçi yönetimi →</a></p>'
    . '</div>';

queue_email($to, 'iCal sağlık özeti: ' . count($problems) . ' villa/yat senkron sorunu', $body, 'ical_health_digest');
save_platform_setting('ical_health_digest_week', $week);
echo "iCal sağlık özeti kuyruğa eklendi: " . count($problems) . " sorunlu ilan (toplam {$totalPublished} yayında).\n";
