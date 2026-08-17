<?php
declare(strict_types=1);

// Dağıtım sağlığı haftalık özeti — iCal (villa/yat) ve kanal (otel) sağlığını tek
// e-postada birleştirir: pasif bağlantılar, 7+/30+ gün eski senkronlar, hiç senkron
// yapılmamış durumlar. Alıcı: admin_alert_email. Haftada bir idempotent
// (platform ayarı distribution_health_week = ISO yıl-hafta).
//
// Gerçek zamanlı uyarılar (nexus-ical-inactive-alerts / nexus-channel-inactive-alerts,
// 15 dakikada bir, tedarikçi panel bildirimi) ayrı çalışmaya devam eder.
//
// Zamanlayıcı: nexus-distribution-health-digest (varsayılan: pazartesi 08:00).

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/pdf.php';

$to = trim((string) platform_setting('admin_alert_email', ''));
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Dağıtım sağlık özeti gönderilmedi: admin_alert_email tanımlı değil (admin → Kontrol merkezi).\n");
    exit(0);
}

$week = date('o-W');
if (platform_setting('distribution_health_week', '') === $week) {
    echo "Bu hafta ($week) dağıtım sağlık özeti zaten kuyruğa eklendi; atlandı.\n";
    exit(0);
}

$pdo = db();

// --- 1) iCal sağlığı: yayındaki villa/yat ilanları ---
$icalRows = $pdo->query("
    SELECT p.id, p.name, p.property_type, p.supplier_id, p.city,
      COALESCE(NULLIF(p.product_details ->> 'home_port', ''), p.city, 'Bilinmeyen konum') AS location,
      s.company_name,
      (SELECT COUNT(*) FROM ical_connections c WHERE c.property_id=p.id) total_con,
      (SELECT COUNT(*) FROM ical_connections c WHERE c.property_id=p.id AND c.status='active') active_con,
      (SELECT MAX(c.last_sync_at) FROM ical_connections c WHERE c.property_id=p.id AND c.status='active') last_sync_at
    FROM properties p JOIN suppliers s ON s.id=p.supplier_id
    WHERE p.status='active' AND p.property_type IN ('villa','yacht')
    ORDER BY s.company_name, p.name
")->fetchAll();

// --- 2) Kanal sağlığı: yayındaki oteli olan tedarikçilerin kanal bağlantıları ---
$channelRows = $pdo->query("
    SELECT s.id supplier_id, s.company_name,
      (SELECT COUNT(*) FROM channel_connections c WHERE c.supplier_id=s.id) total_con,
      (SELECT COUNT(*) FROM channel_connections c WHERE c.supplier_id=s.id AND c.status='active') active_con,
      (SELECT MAX(c.last_sync_at) FROM channel_connections c WHERE c.supplier_id=s.id) last_sync_at,
      (SELECT COUNT(*) FROM properties p WHERE p.supplier_id=s.id AND p.status='active' AND p.property_type='hotel') active_hotels
    FROM suppliers s
    WHERE EXISTS (SELECT 1 FROM properties p WHERE p.supplier_id=s.id AND p.status='active' AND p.property_type='hotel')
    ORDER BY s.company_name
")->fetchAll();

$problems = [];

// iCal satırları: yalnızca sorunlu olanları topla.
foreach ($icalRows as $r) {
    $total = (int) $r['total_con'];
    $active = (int) $r['active_con'];
    $lastSync = $r['last_sync_at'] !== null ? strtotime((string) $r['last_sync_at']) : 0;
    $ageDays = $lastSync > 0 ? (int) floor((time() - $lastSync) / 86400) : null;
    $typeLabel = $r['property_type'] === 'yacht' ? 'Yat' : 'Villa';

    $loc = trim((string) ($r['location'] ?? '')) !== '' ? trim((string) $r['location']) : 'Bilinmeyen konum';
    if ($total > 0 && $active === 0) {
        $problems[] = ['cat' => 'iCal', 'name' => $r['name'] . ' (' . $typeLabel . ')', 'company' => $r['company_name'], 'location' => $loc, 'status' => 'Pasif bağlantı', 'age' => null, 'cls' => '#b0301a'];
    } elseif ($total === 0) {
        $problems[] = ['cat' => 'iCal', 'name' => $r['name'] . ' (' . $typeLabel . ')', 'company' => $r['company_name'], 'location' => $loc, 'status' => 'Bağlantı yok', 'age' => null, 'cls' => '#b0301a'];
    } elseif ($lastSync === 0) {
        $problems[] = ['cat' => 'iCal', 'name' => $r['name'] . ' (' . $typeLabel . ')', 'company' => $r['company_name'], 'location' => $loc, 'status' => 'Kırmızı · hiç senkron yok', 'age' => null, 'cls' => '#b0301a'];
    } elseif ($ageDays >= 30) {
        $problems[] = ['cat' => 'iCal', 'name' => $r['name'] . ' (' . $typeLabel . ')', 'company' => $r['company_name'], 'location' => $loc, 'status' => "Kırmızı · {$ageDays} gün", 'age' => $ageDays, 'cls' => '#b0301a'];
    } elseif ($ageDays >= 7) {
        $problems[] = ['cat' => 'iCal', 'name' => $r['name'] . ' (' . $typeLabel . ')', 'company' => $r['company_name'], 'location' => $loc, 'status' => "Sarı · {$ageDays} gün", 'age' => $ageDays, 'cls' => '#8a6100'];
    }
}

// Kanal satırları: yayındaki oteli olan ve bağlantısı tanımlı ama aktif olmayan tedarikçiler.
foreach ($channelRows as $r) {
    $total = (int) $r['total_con'];
    $active = (int) $r['active_con'];
    if ($total > 0 && $active === 0) {
        $problems[] = ['cat' => 'Kanal', 'name' => $r['company_name'] . ' (dağıtım)', 'company' => $r['company_name'] . ' · ' . $r['active_hotels'] . ' otel yayında', 'status' => 'Pasif bağlantı', 'age' => null, 'cls' => '#b0301a'];
    } elseif ($total === 0) {
        $problems[] = ['cat' => 'Kanal', 'name' => $r['company_name'] . ' (dağıtım)', 'company' => $r['company_name'] . ' · ' . $r['active_hotels'] . ' otel yayında', 'status' => 'Kanal bağlantısı yok', 'age' => null, 'cls' => '#b0301a'];
    } elseif ($r['last_sync_at'] !== null) {
        $lastSync = strtotime((string) $r['last_sync_at']);
        $ageDays = $lastSync > 0 ? (int) floor((time() - $lastSync) / 86400) : null;
        if ($ageDays >= 30) {
            $problems[] = ['cat' => 'Kanal', 'name' => $r['company_name'] . ' (dağıtım)', 'company' => $r['company_name'], 'status' => "Kırmızı · {$ageDays} gün", 'age' => $ageDays, 'cls' => '#b0301a'];
        } elseif ($ageDays >= 7) {
            $problems[] = ['cat' => 'Kanal', 'name' => $r['company_name'] . ' (dağıtım)', 'company' => $r['company_name'], 'status' => "Sarı · {$ageDays} gün", 'age' => $ageDays, 'cls' => '#8a6100'];
        }
    }
}

if ($problems === []) {
    save_platform_setting('distribution_health_week', $week);
    echo "Sorunlu dağıtım kaydı yok — özet gönderilmedi. (" . count($icalRows) . " villa/yat, " . count($channelRows) . " otel sahibi tedarikçi)\n";
    exit(0);
}

// Sorunları kategori + şiddete göre sırala (kırmızı önce).
usort($problems, function ($a, $b) {
    $o = strcmp($a['cat'], $b['cat']);
    if ($o !== 0) return $o;
    return $a['cls'] === '#b0301a' ? -1 : 1;
});

$icalCount = count(array_filter($problems, fn($p) => $p['cat'] === 'iCal'));
$channelCount = count(array_filter($problems, fn($p) => $p['cat'] === 'Kanal'));

$bodyRows = '';
$pdfRows = '';
foreach ($problems as $p) {
    $lastTxt = $p['age'] !== null ? $p['age'] . ' gün önce' : '—';
    $pdfRows .= '<tr><td>' . htmlspecialchars($p['name']) . '<br><small>' . htmlspecialchars($p['company']) . '</small></td><td>' . htmlspecialchars($p['cat']) . '</td><td>' . htmlspecialchars($p['status']) . '</td><td>' . $lastTxt . '</td></tr>';
    $bodyRows .= '<tr>'
        . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de"><b>' . htmlspecialchars($p['name']) . '</b><br><small style="color:#64716d">' . htmlspecialchars($p['company']) . '</small></td>'
        . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de"><span style="background:#eef1ea;border-radius:4px;padding:2px 7px;font-size:11px">' . htmlspecialchars($p['cat']) . '</span></td>'
        . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de;color:' . $p['cls'] . '"><b>' . htmlspecialchars($p['status']) . '</b></td>'
        . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de">' . $lastTxt . '</td>'
        . '</tr>';
}

// Konum bazlı kırılım: sorunlu iCal ilanlarını şehir/limana göre grupla (en çok sorun üstte).
$locGroups = [];
foreach ($problems as $p) {
    if ($p['cat'] !== 'iCal') continue;
    $loc = $p['location'] ?? 'Bilinmeyen konum';
    $locGroups[$loc][] = $p;
}
uksort($locGroups, fn($a, $b) => count($b) - count($a));
$locHtml = '';
if ($locGroups) {
    $locHtml .= '<h3 style="font-size:13px;margin:18px 0 6px;color:#10211f">📍 Konum bazlı kırılım</h3>';
    foreach ($locGroups as $loc => $locItems) {
        $locHtml .= '<p style="margin:4px 0;font-size:12px"><b>' . htmlspecialchars((string) $loc) . '</b> — ' . count($locItems) . ' sorunlu ilan<br>'
            . '<span style="color:#64716d">' . implode(' · ', array_map(fn($i) => htmlspecialchars((string) $i['name']) . ' (' . htmlspecialchars((string) $i['status']) . ')', array_slice($locItems, 0, 5))) . ($locItems > 5 ? ' … +' . (count($locItems) - 5) . ' daha' : '') . '</span></p>';
    }
}

$body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
    . '<h2 style="margin:0 0 6px">📡 Dağıtım sağlığı · haftalık özet</h2>'
    . '<p style="color:#64716d;margin:0 0 10px">Bu hafta <b>' . count($problems) . '</b> sorun tespit edildi — iCal <b style="color:#b0301a">' . $icalCount . '</b> · Kanal <b style="color:#b0301a">' . $channelCount . '</b>. (7+ gün eski senkron sarı, 30+ gün veya pasif bağlantı kırmızı.)</p>'
    . '<table style="border-collapse:collapse;width:100%;max-width:680px">'
    . '<tr><th style="text-align:left;padding:7px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">İlan / Tedarikçi</th><th style="text-align:left;padding:7px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Tip</th><th style="text-align:left;padding:7px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Durum</th><th style="text-align:left;padding:7px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Son senkron</th></tr>'
    . $bodyRows
    . '</table>'
    . $locHtml
    . '<p style="margin:14px 0 0;font-size:12px;color:#64716d">Gerçek zamanlı uyarılar (15 dk) tedarikçi panellerine ayrıca gider. Kırmızı durumlar için ilgili tedarikçiyle iletişime geçin veya iCal takvimler / Dağıtım & kanal merkezi sayfalarını denetleyin.</p>'
    . '<p style="margin-top:18px"><a href="https://nexustraveltech.com/admin/tedarikci-onaylari" style="color:#0d7a4a">Tedarikçi yönetimi →</a></p>'
    . '</div>';

// PDF eki (TCPDF kuruluysa): e-posta gövdesiyle aynı tablonun yazdırılabilir hali.
$attName = null;
$attBase64 = null;
$pdfLoc = '';
if ($locGroups) {
    $pdfLoc = '<h3>Konum bazlı kırılım</h3>';
    foreach ($locGroups as $loc => $locItems) {
        $pdfLoc .= '<p><b>' . htmlspecialchars((string) $loc) . '</b> — ' . count($locItems) . ' sorunlu ilan: '
            . htmlspecialchars(implode('; ', array_map(fn($i) => $i['name'] . ' (' . $i['status'] . ')', array_slice($locItems, 0, 8))))
            . (count($locItems) > 8 ? ' … +' . (count($locItems) - 8) . ' daha' : '') . '</p>';
    }
}
$pdf = pdf_build('<h2>Dağıtım sağlığı haftalık özeti — ' . date('d.m.Y') . '</h2>'
    . '<p style="color:#64716d">' . count($problems) . ' sorun — iCal ' . $icalCount . ', kanal ' . $channelCount . '</p>'
    . '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;font-family:Arial,sans-serif;font-size:10px">'
    . '<tr style="background:#f2f4ef"><th align="left">İlan / Tedarikçi</th><th align="left">Tip</th><th align="left">Durum</th><th align="left">Son senkron</th></tr>'
    . $pdfRows
    . '</table>'
    . $pdfLoc);
if ($pdf !== null) {
    $attName = 'dagitim-sagligi-' . date('Y-m-d') . '.pdf';
    $attBase64 = base64_encode($pdf);
}

queue_email($to, 'Dağıtım sağlığı özeti: ' . count($problems) . ' sorun (iCal ' . $icalCount . ' · kanal ' . $channelCount . ')', $body, 'distribution_health_digest', (int) str_replace('-', '', $week), $attName, $attBase64);
save_platform_setting('distribution_health_week', $week);
echo "Dağıtım sağlık özeti kuyruğa eklendi: " . count($problems) . " sorun (iCal {$icalCount}, kanal {$channelCount}" . ($attName ? ', PDF ekli' : ', PDF yok — TCPDF kurulu değil, HTML gövde gönderildi') . ").\n";
