<?php

declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/platform_settings.php';
require __DIR__ . '/../config/chat_topics.php';
require __DIR__ . '/../config/pdf.php';

require_admin();

$ay = trim((string) ($_GET['ay'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $ay)) $ay = date('Y-m');
$start = $ay . '-01 00:00:00';
$end = date('Y-m-01 00:00:00', strtotime($start . ' +1 month'));

$minLen = max(1, (int) platform_setting('chat_min_length', 5));
$requireSpace = (bool) platform_setting('chat_require_space', true);
$quality = 'CHAR_LENGTH(BTRIM(user_message)) >= ' . $minLen . ($requireSpace ? " AND POSITION(' ' IN BTRIM(user_message)) > 0" : '');

// Genel sayaçlar.
$c = db()->prepare('SELECT COUNT(*) FROM public_chat_messages WHERE created_at>=? AND created_at<?');
$c->execute([$start, $end]);
$totalRows = (int) $c->fetchColumn();

$c2 = db()->prepare("SELECT COUNT(*) FROM public_chat_messages WHERE created_at>=? AND created_at<? AND $quality");
$c2->execute([$start, $end]);
$qualityRows = (int) $c2->fetchColumn();

$c3 = db()->prepare('SELECT COUNT(DISTINCT ip) FROM public_chat_messages WHERE created_at>=? AND created_at<?');
$c3->execute([$start, $end]);
$ips = (int) $c3->fetchColumn();

// Yönlendirme / red: kayıtlı yanıtlar içinde sayfa bağlantısı veya red cümlesi.
$c4 = db()->prepare("SELECT COUNT(*) FROM public_chat_messages WHERE created_at>=? AND created_at<? AND $quality AND (ai_reply LIKE '%nexustraveltech%' OR ai_reply LIKE '%Bu konuda size yardımcı olamıyorum%' OR ai_reply LIKE '%biraz daha açık yazabilir misiniz%')");
$c4->execute([$start, $end]);
$redirected = (int) $c4->fetchColumn();

// Reddedilen istekler (rate limit + yasak kelime) — error_logs'tan.
$c5 = db()->prepare("SELECT COUNT(*) FROM error_logs WHERE created_at>=? AND created_at<? AND (message LIKE 'AI sohbet hız sınırı aşıldı%' OR message LIKE 'AI sohbet engellenen kelime%')");
$c5->execute([$start, $end]);
$denied = (int) $c5->fetchColumn();

$redirectRate = $qualityRows > 0 ? round($redirected / $qualityRows * 100) : 0;
$unansweredRate = ($qualityRows + $denied) > 0 ? round(($redirected + $denied) / ($qualityRows + $denied) * 100) : 0;

// Konu bazında haftalık trend.
$topicWeek = [];
$topicTotal = array_fill_keys(array_keys(chat_topic_defs()), 0);
for ($w = 1; $w <= 5; $w++) {
    foreach (array_keys(chat_topic_defs()) as $t) $topicWeek[$t][$w] = 0;
}
$q = db()->prepare("SELECT user_message, created_at FROM public_chat_messages WHERE created_at>=? AND created_at<? AND $quality LIMIT 20000");
$q->execute([$start, $end]);
foreach ($q->fetchAll() as $r) {
    $w = min(5, intdiv((int) date('j', strtotime((string) $r['created_at'])) - 1, 7) + 1);
    foreach (chat_classify((string) $r['user_message']) as $t) {
        $topicWeek[$t][$w]++;
        $topicTotal[$t]++;
    }
}
arsort($topicTotal);
$topicTopKeys = array_slice(array_keys($topicTotal), 0, 8, true);

// En çok sorulan 10 soru (normalize edilmiş).
$topQ = db()->prepare("SELECT LOWER(TRIM(user_message)) q, COUNT(*) c FROM public_chat_messages WHERE created_at>=? AND created_at<? AND $quality GROUP BY 1 ORDER BY c DESC, MAX(created_at) DESC LIMIT 10");
$topQ->execute([$start, $end]);
$topQuestions = $topQ->fetchAll();

$prev = date('Y-m', strtotime($start . ' -1 day'));
$next = date('Y-m', strtotime($start . ' +1 month'));
$monthLabel = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'][(int) substr($ay, 5, 2)] . ' ' . substr($ay, 0, 4);

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

// PDF dışa aktarma (TCPDF yoksa yazdırılabilir HTML indirir).
if (($_GET['export'] ?? '') === 'pdf') {
    $topicRows = '';
    foreach ($topicTopKeys as $t) {
        $topicRows .= '<tr><td style="border:1px solid #ccc;padding:5px">' . htmlspecialchars($t) . '</td>';
        for ($w = 1; $w <= 5; $w++) $topicRows .= '<td style="border:1px solid #ccc;padding:5px;text-align:center">' . $topicWeek[$t][$w] . '</td>';
        $topicRows .= '<td style="border:1px solid #ccc;padding:5px;text-align:center"><b>' . $topicTotal[$t] . '</b></td></tr>';
    }
    $qRows = '';
    foreach ($topQuestions as $i => $row) {
        $qRows .= '<tr><td style="border:1px solid #ccc;padding:5px;text-align:center">' . ($i + 1) . '</td>'
            . '<td style="border:1px solid #ccc;padding:5px">' . htmlspecialchars((string) $row['q']) . '</td>'
            . '<td style="border:1px solid #ccc;padding:5px;text-align:center">' . (int) $row['c'] . '</td></tr>';
    }
    $weekHead = '<th style="border:1px solid #ccc;padding:5px">Konu</th>';
    for ($w = 1; $w <= 5; $w++) $weekHead .= '<th style="border:1px solid #ccc;padding:5px">Hafta ' . $w . '</th>';
    $weekHead .= '<th style="border:1px solid #ccc;padding:5px">Toplam</th>';
    $html = '<h1>Sohbet raporu — ' . htmlspecialchars($monthLabel) . '</h1>'
        . '<p>Dönem: ' . htmlspecialchars($start) . ' – ' . htmlspecialchars($end) . '</p>'
        . '<h2>Özet</h2><table style="border-collapse:collapse">'
        . '<tr><td style="border:1px solid #ccc;padding:5px">Kayıtlı soru</td><td style="border:1px solid #ccc;padding:5px">' . $totalRows . '</td></tr>'
        . '<tr><td style="border:1px solid #ccc;padding:5px">Kaliteli soru</td><td style="border:1px solid #ccc;padding:5px">' . $qualityRows . '</td></tr>'
        . '<tr><td style="border:1px solid #ccc;padding:5px">Farklı IP</td><td style="border:1px solid #ccc;padding:5px">' . $ips . '</td></tr>'
        . '<tr><td style="border:1px solid #ccc;padding:5px">Yönlendirildi</td><td style="border:1px solid #ccc;padding:5px">' . $redirected . '</td></tr>'
        . '<tr><td style="border:1px solid #ccc;padding:5px">Reddedilen istek</td><td style="border:1px solid #ccc;padding:5px">' . $denied . '</td></tr>'
        . '</table>'
        . '<h2>Yanıtlanamayan / yönlendirme oranı: %' . $unansweredRate . '</h2>'
        . '<h2>Konu trendi</h2><table style="border-collapse:collapse"><tr>' . $weekHead . '</tr>' . $topicRows . '</table>'
        . ($qRows !== '' ? '<h2>En çok sorulan 10 soru</h2><table style="border-collapse:collapse"><tr><th style="border:1px solid #ccc;padding:5px">#</th><th style="border:1px solid #ccc;padding:5px">Soru</th><th style="border:1px solid #ccc;padding:5px">Tekrar</th></tr>' . $qRows . '</table>' : '');
    pdf_download($html, 'sohbet-raporu-' . $ay);
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
