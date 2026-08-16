<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/agency_auth.php';
require_once __DIR__ . '/../config/chat_report.php';
require_once __DIR__ . '/../config/pdf.php';
$u = require_agency();

$ay = trim((string) ($_GET['ay'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $ay)) $ay = date('Y-m');
$d = panel_chat_report_data('agency', (int) $u['agency_id'], $ay);
$prev = date('Y-m', strtotime($d['start'] . ' -1 day'));
$next = date('Y-m', strtotime($d['start'] . ' +1 month'));

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="panel-sohbet-raporu-' . $ay . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['PANEL SOHBET RAPORU', $d['monthLabel']]);
    fputcsv($out, ['Dönem', $d['start'] . ' – ' . $d['end']]);
    fputcsv($out, ['Toplam mesaj', $d['totalRows']]);
    fputcsv($out, ['Kaliteli mesaj', $d['qualityRows']]);
    fputcsv($out, ['Aktif gün', $d['activeDays']]);
    fputcsv($out, []);
    fputcsv($out, ['KONU TRENDİ']);
    $head = ['Konu'];
    for ($w = 1; $w <= 5; $w++) $head[] = 'Hafta ' . $w;
    $head[] = 'Toplam';
    fputcsv($out, $head);
    foreach ($d['topicTopKeys'] as $t) {
        $row = [$t];
        for ($w = 1; $w <= 5; $w++) $row[] = $d['topicWeek'][$t][$w];
        $row[] = $d['topicTotal'][$t];
        fputcsv($out, $row);
    }
    fputcsv($out, []);
    fputcsv($out, ['EN ÇOK SORULAN 5 SORU']);
    fputcsv($out, ['Soru', 'Tekrar']);
    foreach ($d['topQuestions'] as $row) fputcsv($out, [(string) $row['q'], (int) $row['c']]);
    fclose($out);
    exit;
}

if (($_GET['export'] ?? '') === 'pdf') {
    pdf_download(panel_chat_report_html($d), 'panel-sohbet-raporu-' . $ay);
}
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sohbet raporu | NEXUS Acenta</title><style>body{margin:0;font-family:Arial;background:#f7f7f2;color:#10211f}.w{width:min(980px,calc(100% - 32px));margin:35px auto}.top{display:flex;gap:10px;align-items:center;flex-wrap:wrap;background:#fff;border:1px solid #ddd;padding:12px 14px}.top a,.top form{color:#10211f;font-size:13px;text-decoration:none;font-weight:600}.top input{border:1px solid #d8ded8;padding:8px;font:inherit}.top button{background:#10211f;color:#fff;border:0;padding:8px 12px;cursor:pointer;font:inherit;font-weight:700}.stats{display:flex;gap:10px;flex-wrap:wrap;margin:14px 0}.stat{background:#fff;border:1px solid #ddd;padding:12px 16px;min-width:140px}.stat span{font-size:11px;text-transform:uppercase;color:#64716d}.stat b{display:block;font-size:22px;margin-top:4px}.panel{background:#fff;border:1px solid #ddd;padding:16px;margin:14px 0}.panel h2{margin:0 0 10px;font-size:15px}table{width:100%;border-collapse:collapse}th,td{text-align:left;border-bottom:1px solid #e1e5de;padding:9px 11px;font-size:13px}th{font-size:11px;text-transform:uppercase;color:#64716d}.num{text-align:center}.muted{color:#64716d;font-size:13px}@media(max-width:640px){.stats{flex-direction:column}}</style></head><body><main class="w">
<div class="top"><a href="/nexustraveltech/acente/">← Panel</a><a href="?ay=<?=htmlspecialchars($prev)?>">← Önceki ay</a><form method="get" action="/nexustraveltech/acente/sohbet-raporu" style="display:flex;gap:6px;align-items:center"><input type="month" name="ay" value="<?=htmlspecialchars($ay)?>"><button>Göster</button></form><a href="?ay=<?=htmlspecialchars($next)?>">Sonraki ay →</a><a href="?ay=<?=htmlspecialchars($ay)?>&export=csv">⬇ CSV</a><a href="?ay=<?=htmlspecialchars($ay)?>&export=pdf">⬇ PDF</a><b style="margin-left:auto"><?=htmlspecialchars($d['monthLabel'])?></b></div>
<div class="stats"><div class="stat"><span>Toplam mesaj</span><b><?= (int)$d['totalRows'] ?></b></div><div class="stat"><span>Kaliteli mesaj</span><b><?= (int)$d['qualityRows'] ?></b></div><div class="stat"><span>Aktif gün</span><b><?= (int)$d['activeDays'] ?></b></div></div>
<section class="panel"><h2>Konu bazında haftalık trend</h2>
<?php if ($d['totalRows'] === 0): ?><p class="muted">Bu ay panel asistanı kullanımı yok. Sağ alttaki NEXUS AI butonuyla soru sordukça bura dolar.</p>
<?php else: ?>
<table><tr><th>Konu</th><?php for ($w = 1; $w <= 5; $w++): ?><th class="num">Hafta <?= $w ?></th><?php endfor; ?><th class="num">Toplam</th></tr>
<?php foreach ($d['topicTopKeys'] as $t): ?><tr><td><?=htmlspecialchars($t)?></td><?php for ($w = 1; $w <= 5; $w++): ?><td class="num"><?= (int)$d['topicWeek'][$t][$w] ?></td><?php endfor; ?><td class="num"><b><?= (int)$d['topicTotal'][$t] ?></b></td></tr><?php endforeach; ?>
</table><?php endif; ?></section>
<section class="panel"><h2>En çok sorulan 5 soru</h2>
<?php if (!$d['topQuestions']): ?><p class="muted">Bu ay kaliteli soru kaydı yok.</p>
<?php else: ?>
<table><tr><th>#</th><th>Soru</th><th class="num">Tekrar</th></tr>
<?php foreach ($d['topQuestions'] as $i => $row): ?><tr><td class="num"><?= $i + 1 ?></td><td><?=htmlspecialchars((string) $row['q'])?></td><td class="num"><?= (int)$row['c'] ?></td></tr><?php endforeach; ?>
</table><?php endif; ?></section>
</main><?php require_once __DIR__.'/../config/ai_widget.php'; ai_widget('/nexustraveltech/acente/ai-chat','agency_csrf'); ?></body></html>
