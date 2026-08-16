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
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sohbet raporu | NEXUS Admin</title><style>body{margin:0;background:#f7f7f2;color:#10211f;font-family:Arial,sans-serif}.wrap{width:min(1180px,calc(100% - 32px));margin:40px auto}.top{display:flex;justify-content:space-between;gap:20px;align-items:center}.brand{font-size:28px;font-weight:800}.brand span{color:#e85f42}.back{color:#10211f}.nav{display:flex;gap:10px;align-items:center;margin:16px 0;flex-wrap:wrap}.nav a,.nav form{background:#fff;border:1px solid #e1e5de;padding:8px 12px;color:#10211f;text-decoration:none;font-size:13px}.nav input{padding:8px;border:1px solid #d8ded8;font:inherit}.stats{display:flex;gap:10px;flex-wrap:wrap;margin:14px 0}.stat{background:#fff;border:1px solid #e1e5de;padding:12px 16px;font-size:12px;color:#64716d;min-width:130px}.stat b{font-size:22px;display:block;color:#10211f;margin-top:3px}.stat.warn b{color:#a86026}.stat.danger b{color:#b0301a}.panel{background:#fff;border:1px solid #e1e5de;padding:16px;margin:16px 0}.panel h2{margin:0 0 10px;font-size:16px}table{width:100%;border-collapse:collapse}th,td{text-align:left;border-bottom:1px solid #e1e5de;padding:9px 11px;font-size:13px;vertical-align:top}th{font-size:11px;text-transform:uppercase;color:#64716d}td.num,th.num{text-align:center}.muted{color:#64716d}.bar{display:flex;gap:2px;align-items:flex-end;height:44px;margin-top:8px;max-width:480px}.bar i{flex:1;background:#10211f;border-radius:2px 2px 0 0}.rate-line{font-size:14px;font-weight:700}.rate-ok{color:#0d7a4a}.rate-warn{color:#a86026}.rate-bad{color:#b0301a}</style></head><body><main class="wrap"><div class="top"><div><div class="brand">N<span>∿</span>XUS Admin</div><p>Önyüz AI asistan — aylık sohbet raporu</p></div><a class="back" href="/nexustraveltech/admin/ziyaretci-sohbet">← Sohbet kayıtları</a></div>
<div class="nav"><a href="/nexustraveltech/admin/sohbet-raporu?ay=<?=htmlspecialchars($prev)?>">← Önceki ay</a><form method="get" action="/nexustraveltech/admin/sohbet-raporu" style="display:flex;gap:6px;align-items:center;border:0;background:none;padding:0"><input type="month" name="ay" value="<?=htmlspecialchars($ay)?>"><button style="background:#10211f;color:#fff;border:0;padding:8px 12px;cursor:pointer">Göster</button></form><a href="/nexustraveltech/admin/sohbet-raporu?ay=<?=htmlspecialchars($next)?>">Sonraki ay →</a><span style="width:10px"></span><a href="?ay=<?=htmlspecialchars($ay)?>&export=csv">⬇ CSV</a><a href="?ay=<?=htmlspecialchars($ay)?>&export=pdf">⬇ PDF</a><span class="muted" style="margin-left:auto"><b style="color:#10211f"><?=htmlspecialchars($monthLabel)?></b></span></div>
<div class="stats"><div class="stat"><b><?= (int)$totalRows ?></b>Kayıtlı soru</div><div class="stat"><b><?= (int)$qualityRows ?></b>Kaliteli soru</div><div class="stat"><b><?= (int)$ips ?></b>Farklı IP</div><div class="stat warn"><b><?= (int)$redirected ?></b>Yönlendirildi</div><div class="stat danger"><b><?= (int)$denied ?></b>Reddedilen istek</div></div>
<section class="panel"><h2>Yanıtlanamayan / yönlendirme oranı</h2>
<p class="rate-line <?= $unansweredRate <= 30 ? 'rate-ok' : ($unansweredRate <= 60 ? 'rate-warn' : 'rate-bad') ?>">%<?= (int)$unansweredRate ?></p>
<p class="muted">AI doğrudan yanıt yerine sayfaya yönlendirdi (<?= (int)$redirected ?>) veya istek reddedildi (<?= (int)$denied ?> — hız sınırı / yasak kelime). Düşük oran, asistanın soruları doğrudan çözdüğünü gösterir.</p>
<div class="bar"><?php for ($i = 0; $i < 10; $i++): ?><i title="%<?= (int)$unansweredRate ?>" style="height:<?= $i < round($unansweredRate / 10) ? 100 : 12 ?>%"></i><?php endfor; ?></div>
</section>
<section class="panel"><h2>Gün bazında trafik</h2>
<table><tr><th>Gün</th><th class="num">Soru</th><th class="num">Yönlendirme</th><th class="num">Red</th></tr>
<?php foreach ($daily as $date => $v): ?>
<tr><td><?= htmlspecialchars((string) $date) ?></td><td class="num"><?= (int) $v['soru'] ?></td><td class="num"><?= (int) $v['yon'] ?></td><td class="num"><?= (int) $v['red'] ?></td></tr>
<?php endforeach; ?>
</table>
<p class="muted">Soru: kayıtlı tüm sorular · Yönlendirme: AI'nın sayfaya yönlendirdiği kaliteli yanıtlar · Red: hız sınırı + yasak kelime istekleri.</p>
</section>
<section class="panel"><h2>Konu bazında haftalık trend</h2>
<table><tr><th>Konu</th><?php for ($w = 1; $w <= 5; $w++): ?><th class="num">Hafta <?= $w ?></th><?php endfor; ?><th class="num">Toplam</th></tr>
<?php foreach ($topicTopKeys as $t): ?>
<tr><td><?=htmlspecialchars($t)?></td><?php for ($w = 1; $w <= 5; $w++): ?><td class="num"><?= (int)$topicWeek[$t][$w] ?></td><?php endfor; ?><td class="num"><b><?= (int)$topicTotal[$t] ?></b></td></tr>
<?php endforeach; ?>
</table></section>
<section class="panel"><h2>En çok sorulan 10 soru</h2>
<?php if (!$topQuestions): ?><p class="muted">Bu ay kaliteli soru kaydı yok.</p><?php else: ?>
<table><tr><th>#</th><th>Soru</th><th class="num">Tekrar</th></tr>
<?php foreach ($topQuestions as $i => $row): ?>
<tr><td class="num"><?= $i + 1 ?></td><td><?=htmlspecialchars((string) $row['q'])?></td><td class="num"><?= (int)$row['c'] ?></td></tr>
<?php endforeach; ?>
</table><?php endif; ?>
</section>
</main><?php require_once __DIR__.'/../config/ai_widget.php'; ai_widget('/nexustraveltech/admin/ai-chat','admin_csrf'); ?></body></html>
