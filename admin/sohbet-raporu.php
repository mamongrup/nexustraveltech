<?php

declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/chat_report.php';
require __DIR__ . '/../config/pdf.php';

require_admin();

$ay = trim((string) ($_GET['ay'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $ay)) $ay = date('Y-m');

// Veri tek kaynaktan: config/chat_report.php — cron ile aynı fonksiyon.
$d = chat_report_data($ay);
extract($d, EXTR_SKIP);
$prev = date('Y-m', strtotime($d['start'] . ' -1 day'));
$next = date('Y-m', strtotime($d['start'] . ' +1 month'));

// CSV dışa aktarma: özet + konu trendi + en çok sorulan 10 soru.
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sohbet-raporu-' . $ay . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['AYLIK SOHBET RAPORU', $monthLabel]);
    fputcsv($out, ['Dönem', $start . ' – ' . $end]);
    fputcsv($out, ['Kayıtlı soru', $totalRows]);
    fputcsv($out, ['Kaliteli soru', $qualityRows]);
    fputcsv($out, ['Farklı IP', $ips]);
    fputcsv($out, ['Yönlendirildi', $redirected]);
    fputcsv($out, ['Reddedilen istek', $denied]);
    fputcsv($out, ['Yanıtlanamayan/Yönlendirme oranı', '%' . $unansweredRate]);
    fputcsv($out, []);
    fputcsv($out, ['GÜN BAZINDA TRAFİK']);
    fputcsv($out, ['Gün', 'Soru', 'Yönlendirme', 'Red']);
    foreach ($daily as $date => $v) fputcsv($out, [substr((string) $date, 8, 2), $v['soru'], $v['yon'], $v['red']]);
    fputcsv($out, []);
    fputcsv($out, ['KONU TRENDİ']);
    $head = ['Konu'];
    for ($w = 1; $w <= 5; $w++) $head[] = 'Hafta ' . $w;
    $head[] = 'Toplam';
    fputcsv($out, $head);
    foreach ($topicTopKeys as $t) {
        $row = [$t];
        for ($w = 1; $w <= 5; $w++) $row[] = $topicWeek[$t][$w];
        $row[] = $topicTotal[$t];
        fputcsv($out, $row);
    }
    fputcsv($out, []);
    fputcsv($out, ['EN ÇOK SORULAN 10 SORU']);
    fputcsv($out, ['Soru', 'Tekrar']);
    foreach ($topQuestions as $row) fputcsv($out, [(string) $row['q'], (int) $row['c']]);
    fclose($out);
    exit;
}

// PDF dışa aktarma (TCPDF yoksa yazdırılabilir HTML indirir) — ortak HTML üretici.
if (($_GET['export'] ?? '') === 'pdf') {
    pdf_download(chat_report_html($d), 'sohbet-raporu-' . $ay);
}
?>
<?php
require_once __DIR__ . '/layout.php';
admin_layout_start('Aylık Ziyaretçi AI Sohbet Raporu', 'ziyaretci-sohbet');
?>

<!-- Gezinme ve Filtre Kartı -->
<div class="sui-card" style="margin-bottom:24px">
    <div class="sui-card-header">
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <a href="?ay=<?= htmlspecialchars($prev) ?>" class="sui-btn sui-btn-outline sui-btn-sm">← Önceki Ay</a>
            <form method="get" style="display:flex;gap:8px;align-items:center;margin:0">
                <input type="month" name="ay" value="<?= htmlspecialchars($ay) ?>" class="sui-input" style="padding:6px 12px;width:auto">
                <button class="sui-btn sui-btn-primary sui-btn-sm" type="submit">Göster</button>
            </form>
            <a href="?ay=<?= htmlspecialchars($next) ?>" class="sui-btn sui-btn-outline sui-btn-sm">Sonraki Ay →</a>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <span style="font-weight:700;font-size:14px;margin-right:8px"><?= htmlspecialchars($monthLabel) ?></span>
            <a href="?ay=<?= htmlspecialchars($ay) ?>&export=csv" class="sui-btn sui-btn-outline sui-btn-sm">⬇ CSV</a>
            <a href="?ay=<?= htmlspecialchars($ay) ?>&export=pdf" class="sui-btn sui-btn-success sui-btn-sm">⬇ PDF</a>
            <a href="ziyaretci-sohbet" class="sui-btn sui-btn-outline sui-btn-sm">← Canlı Sohbet Kayıtları</a>
        </div>
    </div>
</div>

<!-- KPI Kartları -->
<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:14px;margin-bottom:24px">
    <div class="sui-card" style="padding:16px">
        <div style="font-size:12px;color:var(--sui-muted);font-weight:600">Kayıtlı Soru</div>
        <div style="font-size:24px;font-weight:800;margin-top:4px"><?= (int)$totalRows ?></div>
    </div>
    <div class="sui-card" style="padding:16px">
        <div style="font-size:12px;color:var(--sui-primary);font-weight:600">Kaliteli Soru</div>
        <div style="font-size:24px;font-weight:800;color:var(--sui-primary);margin-top:4px"><?= (int)$qualityRows ?></div>
    </div>
    <div class="sui-card" style="padding:16px">
        <div style="font-size:12px;color:var(--sui-muted);font-weight:600">Farklı IP Sayısı</div>
        <div style="font-size:24px;font-weight:800;margin-top:4px"><?= (int)$ips ?></div>
    </div>
    <div class="sui-card" style="padding:16px">
        <div style="font-size:12px;color:var(--sui-warning);font-weight:600">Yönlendirildi</div>
        <div style="font-size:24px;font-weight:800;color:var(--sui-warning);margin-top:4px"><?= (int)$redirected ?></div>
    </div>
    <div class="sui-card" style="padding:16px">
        <div style="font-size:12px;color:var(--sui-danger);font-weight:600">Reddedilen İstek</div>
        <div style="font-size:24px;font-weight:800;color:var(--sui-danger);margin-top:4px"><?= (int)$denied ?></div>
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(400px, 1fr));gap:20px;margin-bottom:24px">
    <!-- Gün Bazında Trafik -->
    <div class="sui-card">
        <div class="sui-card-header">
            <h2 class="sui-card-title">📅 Gün Bazında Trafik Dağılımı</h2>
        </div>
        <div style="overflow-x:auto;max-height:360px">
            <table class="sui-table">
                <thead>
                    <tr>
                        <th>Gün</th>
                        <th style="text-align:center">Soru</th>
                        <th style="text-align:center">Yönlendirme</th>
                        <th style="text-align:center">Red</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($daily as $date => $v): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $date) ?></td>
                            <td style="text-align:center;font-weight:700"><?= (int) $v['soru'] ?></td>
                            <td style="text-align:center;color:var(--sui-warning)"><?= (int) $v['yon'] ?></td>
                            <td style="text-align:center;color:var(--sui-danger)"><?= (int) $v['red'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- En Çok Sorulan Sorular -->
    <div class="sui-card">
        <div class="sui-card-header">
            <h2 class="sui-card-title">🔥 En Çok Sorulan 10 Soru</h2>
        </div>
        <?php if (!$topQuestions): ?>
            <p style="color:var(--sui-muted);padding:20px;text-align:center">Bu ay henüz kayıtlı soru yok.</p>
        <?php else: ?>
            <div style="overflow-x:auto">
                <table class="sui-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Soru</th>
                            <th style="text-align:center">Tekrar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topQuestions as $i => $row): ?>
                            <tr>
                                <td><span class="sui-badge sui-badge-info"><?= $i + 1 ?></span></td>
                                <td style="font-weight:600"><?= htmlspecialchars((string) $row['q']) ?></td>
                                <td style="text-align:center;font-weight:700"><?= (int)$row['c'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>

