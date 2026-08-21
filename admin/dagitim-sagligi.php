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
require_once __DIR__ . '/layout.php';
admin_layout_start('Dağıtım Sağlığı İzleme', 'dagitim-sagligi');
?>

<div class="sui-card">
    <div class="sui-card-header">
        <div>
            <h2 class="sui-card-title">📈 Dağıtım Sağlığı & Senkronizasyon Durumu (<?= count($problems) ?>)</h2>
            <p style="color:var(--sui-muted);font-size:13px;margin:4px 0 0 0">
                iCal (villa/yat) ve OTA Kanal Bağlantılarındaki (otel) gecikme veya kopuklukları anlık denetler.
            </p>
        </div>
        <div style="display:flex;gap:8px">
            <a href="?download=pdf" class="sui-btn sui-btn-outline sui-btn-sm"><i class="fas fa-file-pdf"></i> PDF İndir</a>
            <a href="?download=csv" class="sui-btn sui-btn-outline sui-btn-sm"><i class="fas fa-file-csv"></i> CSV İndir</a>
        </div>
    </div>

    <?php if (!$problems): ?>
        <div class="sui-alert sui-alert-success">
            ✓ Harika! Dağıtımda sorunlu bir kayıt bulunamadı — tüm kanal ve iCal bağlantıları aktif ve güncel.
        </div>
    <?php else: ?>
        <div style="overflow-x:auto">
            <table class="sui-table">
                <thead>
                    <tr>
                        <th>Tesis / Tedarikçi</th>
                        <th>Kanal Türü</th>
                        <th>Durum</th>
                        <th>Son Senkronizasyon</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($problems as $p): 
                        $lastTxt = $p['age'] !== null ? $p['age'] . ' gün önce' : '—'; 
                        $isRed = $p['cls'] === '#b0301a';
                    ?>
                        <tr>
                            <td>
                                <b><?= htmlspecialchars($p['name']) ?></b>
                                <div style="font-size:11px;color:var(--sui-muted)"><?= htmlspecialchars($p['company']) ?></div>
                            </td>
                            <td>
                                <span class="sui-badge <?= $p['cat'] === 'Kanal' ? 'sui-badge-primary' : 'sui-badge-info' ?>">
                                    <?= htmlspecialchars($p['cat']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="sui-badge <?= $isRed ? 'sui-badge-danger' : 'sui-badge-warning' ?>">
                                    <?= htmlspecialchars($p['status']) ?>
                                </span>
                            </td>
                            <td style="font-size:13px;color:var(--sui-muted)">
                                <?= $lastTxt ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>

