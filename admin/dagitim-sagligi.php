<?php
declare(strict_types=1);
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/pdf.php';
require_admin();

// Dağıtım sağlığı — iCal (villa/yat) + kanal (otel) sorunlarını tek tabloda gösterir;
// PDF/CSV indirmeyi destekler. Haftalık e-postadaki (nexus-distribution-health-digest)
// tabloyla aynı mantığı kullanır.

$pdo = db();
$icalRows = $pdo->query("
    SELECT p.id, p.name, p.property_type, p.supplier_id, s.company_name,
      (SELECT COUNT(*) FROM ical_connections c WHERE c.property_id=p.id) total_con,
      (SELECT COUNT(*) FROM ical_connections c WHERE c.property_id=p.id AND c.status='active') active_con,
      (SELECT MAX(c.last_sync_at) FROM ical_connections c WHERE c.property_id=p.id AND c.status='active') last_sync_at
    FROM properties p JOIN suppliers s ON s.id=p.supplier_id
    WHERE p.status='active' AND p.property_type IN ('villa','yacht')
    ORDER BY s.company_name, p.name
")->fetchAll();

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
foreach ($icalRows as $r) {
    $total = (int) $r['total_con'];
    $active = (int) $r['active_con'];
    $lastSync = $r['last_sync_at'] !== null ? strtotime((string) $r['last_sync_at']) : 0;
    $ageDays = $lastSync > 0 ? (int) floor((time() - $lastSync) / 86400) : null;
    $typeLabel = $r['property_type'] === 'yacht' ? 'Yat' : 'Villa';
    if ($total > 0 && $active === 0) {
        $problems[] = ['cat' => 'iCal', 'name' => $r['name'] . ' (' . $typeLabel . ')', 'company' => $r['company_name'], 'status' => 'Pasif bağlantı', 'age' => null, 'cls' => '#b0301a'];
    } elseif ($total === 0) {
        $problems[] = ['cat' => 'iCal', 'name' => $r['name'] . ' (' . $typeLabel . ')', 'company' => $r['company_name'], 'status' => 'Bağlantı yok', 'age' => null, 'cls' => '#b0301a'];
    } elseif ($lastSync === 0) {
        $problems[] = ['cat' => 'iCal', 'name' => $r['name'] . ' (' . $typeLabel . ')', 'company' => $r['company_name'], 'status' => 'Kırmızı · hiç senkron yok', 'age' => null, 'cls' => '#b0301a'];
    } elseif ($ageDays >= 30) {
        $problems[] = ['cat' => 'iCal', 'name' => $r['name'] . ' (' . $typeLabel . ')', 'company' => $r['company_name'], 'status' => "Kırmızı · {$ageDays} gün", 'age' => $ageDays, 'cls' => '#b0301a'];
    } elseif ($ageDays >= 7) {
        $problems[] = ['cat' => 'iCal', 'name' => $r['name'] . ' (' . $typeLabel . ')', 'company' => $r['company_name'], 'status' => "Sarı · {$ageDays} gün", 'age' => $ageDays, 'cls' => '#8a6100'];
    }
}
foreach ($channelRows as $r) {
    $total = (int) $r['total_con'];
    $active = (int) $r['active_con'];
    $lastTxt = $r['last_sync_at'] !== null ? (string) $r['last_sync_at'] : '';
    if ($total > 0 && $active === 0) {
        $problems[] = ['cat' => 'Kanal', 'name' => $r['company_name'] . ' (dağıtım)', 'company' => $r['company_name'] . ' · ' . $r['active_hotels'] . ' otel yayında', 'status' => 'Pasif bağlantı', 'age' => null, 'cls' => '#b0301a'];
    } elseif ($total === 0) {
        $problems[] = ['cat' => 'Kanal', 'name' => $r['company_name'] . ' (dağıtım)', 'company' => $r['company_name'] . ' · ' . $r['active_hotels'] . ' otel yayında', 'status' => 'Kanal bağlantısı yok', 'age' => null, 'cls' => '#b0301a'];
    } elseif ($lastTxt !== '') {
        $lastSync = strtotime($lastTxt);
        $ageDays = $lastSync > 0 ? (int) floor((time() - $lastSync) / 86400) : null;
        if ($ageDays >= 30) {
            $problems[] = ['cat' => 'Kanal', 'name' => $r['company_name'] . ' (dağıtım)', 'company' => $r['company_name'], 'status' => "Kırmızı · {$ageDays} gün", 'age' => $ageDays, 'cls' => '#b0301a'];
        } elseif ($ageDays >= 7) {
            $problems[] = ['cat' => 'Kanal', 'name' => $r['company_name'] . ' (dağıtım)', 'company' => $r['company_name'], 'status' => "Sarı · {$ageDays} gün", 'age' => $ageDays, 'cls' => '#8a6100'];
        }
    }
}
usort($problems, function ($a, $b) {
    $o = strcmp($a['cat'], $b['cat']);
    if ($o !== 0) return $o;
    return $a['cls'] === '#b0301a' ? -1 : 1;
});

// İndirme: PDF (TCPDF) veya CSV.
if (($_GET['download'] ?? '') !== '') {
    $ts = date('Y-m-d');
    if ($_GET['download'] === 'pdf') {
        $pdfRows = '';
        foreach ($problems as $p) {
            $lastTxt = $p['age'] !== null ? $p['age'] . ' gün önce' : '—';
            $pdfRows .= '<tr><td>' . htmlspecialchars($p['name']) . '<br><small>' . htmlspecialchars($p['company']) . '</small></td><td>' . htmlspecialchars($p['cat']) . '</td><td>' . htmlspecialchars($p['status']) . '</td><td>' . $lastTxt . '</td></tr>';
        }
        $html = '<h2>Dağıtım sağlığı — ' . $ts . '</h2>'
            . '<p style="color:#64716d">' . count($problems) . ' sorun — iCal ' . count(array_filter($problems, fn($p) => $p['cat'] === 'iCal')) . ', kanal ' . count(array_filter($problems, fn($p) => $p['cat'] === 'Kanal')) . '</p>'
            . '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;font-family:Arial,sans-serif;font-size:10px">'
            . '<tr style="background:#f2f4ef"><th align="left">İlan / Tedarikçi</th><th align="left">Tip</th><th align="left">Durum</th><th align="left">Son senkron</th></tr>'
            . $pdfRows . '</table>';
        pdf_download($html, 'dagitim-sagligi-' . $ts);
    }
    if ($_GET['download'] === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="dagitim-sagligi-' . $ts . '.csv"');
        echo "\xEF\xBB\xBF"; // BOM — Excel Türkçe karakterler için.
        $out = fopen('php://output', 'w');
        fputcsv($out, ['İlan / Tedarikçi', 'Tip', 'Durum', 'Son senkron']);
        foreach ($problems as $p) {
            fputcsv($out, [$p['name'] . ' (' . $p['company'] . ')', $p['cat'], $p['status'], $p['age'] !== null ? $p['age'] . ' gün önce' : '—']);
        }
        fclose($out);
        exit;
    }
}

$typeLabel = fn($t) => ['hotel' => 'Otel', 'villa' => 'Villa', 'yacht' => 'Yat'][$t] ?? $t;
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dağıtım sağlığı | NEXUS Admin</title><style>body{margin:0;background:#f7f7f2;color:#10211f;font-family:Arial,sans-serif}.wrap{width:min(1100px,calc(100% - 32px));margin:40px auto}.top{display:flex;justify-content:space-between;gap:20px;align-items:center}.brand{font-size:28px;font-weight:800}.brand span{color:#e85f42}.muted{color:#64716d}.back{color:#10211f}.card{background:#fff;border:1px solid #e1e5de;padding:24px;margin-top:24px}.actions{display:flex;gap:10px;margin-top:14px}.actions a{border:1px solid #d8ded8;background:#fff;padding:9px 14px;color:#10211f;text-decoration:none;font-weight:700}table{width:100%;border-collapse:collapse;margin-top:12px}th{text-align:left;padding:8px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d;border-bottom:1px solid #e1e5de}td{padding:8px 12px;border-bottom:1px solid #eef0ea;font-size:13px}td small{color:#64716d}.chip{background:#eef1ea;border-radius:4px;padding:2px 7px;font-size:11px}.red{color:#b0301a;font-weight:700}.yellow{color:#8a6100;font-weight:700}.green{color:#0d7a4a;font-weight:700}.okbox{background:#e6f8c7;padding:10px;border-radius:5px}</style></head><body><main class="wrap"><div class="top"><div><div class="brand">N<span>∿</span>XUS Admin</div><p class="muted">Dağıtım sağlığı — iCal (villa/yat) + kanal (otel) senkron sorunları</p></div><a class="back" href="/nexustraveltech/admin/">← Panele dön</a></div>
<section class="card"><div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap"><h2 style="margin:0">Sorunlu dağıtım kayıtları <small style="color:#6b7774;font-weight:normal">(<?= count($problems) ?>)</small></h2><div class="actions" style="margin:0"><a href="?download=pdf">PDF indir</a><a href="?download=csv">CSV indir</a></div></div>
<p class="muted">Kırmızı: pasif bağlantı / 30+ gün eski senkron / hiç senkron yok. Sarı: 7–30 gün eski senkron. Bu tablo haftalık özet e-postasıyla aynı veriyi kullanır.</p>
<?php if (!$problems): ?><p class="okbox">✓ Sorunlu dağıtım kaydı yok — tüm bağlantılar güncel.</p><?php else: ?>
<table><tr><th>İlan / Tedarikçi</th><th>Tip</th><th>Durum</th><th>Son senkron</th></tr>
<?php foreach ($problems as $p): $lastTxt = $p['age'] !== null ? $p['age'] . ' gün önce' : '—'; $cls = $p['cls'] === '#b0301a' ? 'red' : 'yellow'; ?>
<tr><td><b><?= htmlspecialchars($p['name']) ?></b><br><small><?= htmlspecialchars($p['company']) ?></small></td><td><span class="chip"><?= htmlspecialchars($p['cat']) ?></span></td><td class="<?= $cls ?>"><?= htmlspecialchars($p['status']) ?></td><td><?= $lastTxt ?></td></tr>
<?php endforeach; ?>
</table><?php endif; ?>
</section></main><?php require_once __DIR__ . '/../config/ai_widget.php'; ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); ?></body></html>
