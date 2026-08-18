<?php
declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/scheduler.php';
require_once __DIR__ . '/../config/platform_settings.php';

require_admin();

$jobId = (int) ($_GET['job'] ?? 0);
$status = (string) ($_GET['status'] ?? '');
if (!in_array($status, ['', 'ok', 'error'], true)) $status = '';
$limit = (int) ($_GET['limit'] ?? 200);
if (!in_array($limit, [50, 200, 500], true)) $limit = 200;
$hours = (int) ($_GET['hours'] ?? 0);
if (!in_array($hours, [0, 24, 72, 168], true)) $hours = 0;
// Süre grafiğinin bağımsız görev seçici: belirtilmediyse üst filtreyi izler, seçilince ayrışır.
if (array_key_exists('chart_job', $_GET)) {
    $chartJob = (int) $_GET['chart_job'];
} else {
    $chartJob = $jobId > 0 ? $jobId : 0;
}

$pdo = db();

// Filtreleme + son kayıtlar.
$where = 'WHERE 1=1';
$params = [];
if ($jobId > 0) {
    $where .= ' AND r.job_id=?';
    $params[] = $jobId;
}
if ($status !== '') {
    $where .= ' AND r.status=?';
    $params[] = $status;
}
if ($hours > 0) {
    $where .= ' AND r.created_at >= now() - interval \'' . $hours . ' hours\'';
}
$q = $pdo->prepare(
    "SELECT r.id,r.status,r.output,r.duration_ms,r.triggered_by,r.created_at,j.name,j.code,j.command
       FROM scheduled_job_runs r JOIN scheduled_jobs j ON j.id=r.job_id
      $where ORDER BY r.id DESC LIMIT $limit"
);
$q->execute($params);
$runs = $q->fetchAll();

// Özet istatistikler.
$stats = [];
$stats['total7'] = (int) $pdo->query("SELECT COUNT(*) FROM scheduled_job_runs WHERE created_at >= now() - interval '7 days'")->fetchColumn();
$stats['err24'] = (int) $pdo->query("SELECT COUNT(*) FROM scheduled_job_runs WHERE status='error' AND created_at >= now() - interval '24 hours'")->fetchColumn();
$stats['err7'] = (int) $pdo->query("SELECT COUNT(*) FROM scheduled_job_runs WHERE status='error' AND created_at >= now() - interval '7 days'")->fetchColumn();
$stats['avgMs'] = (int) $pdo->query("SELECT COALESCE(AVG(duration_ms),0) FROM scheduled_job_runs WHERE created_at >= now() - interval '7 days'")->fetchColumn();
$stats['totalRuns'] = (int) $pdo->query('SELECT COUNT(*) FROM scheduled_job_runs')->fetchColumn();
try { $stats['pendingPurge'] = (int) $pdo->query("SELECT COUNT(*) FROM pending_trash_purges WHERE approved_at IS NULL AND expires_at > now()")->fetchColumn(); } catch (Throwable $e) { $stats['pendingPurge'] = 0; }

// Son 30 günün günlük ortalama süresi (grafiğin kendi görev seçimine göre).
$durWhere = $chartJob > 0 ? 'WHERE job_id=? AND created_at >= CURRENT_DATE - 29' : 'WHERE created_at >= CURRENT_DATE - 29';
$durParams = $chartJob > 0 ? [$chartJob] : [];
$durQ = $pdo->prepare("SELECT created_at::date d, COALESCE(AVG(duration_ms),0)::int avg_ms, COUNT(*) c, COUNT(*) FILTER (WHERE status='error') err FROM scheduled_job_runs $durWhere GROUP BY 1");
$durQ->execute($durParams);
$durMap = [];
foreach ($durQ->fetchAll() as $r) {
    $durMap[(string) $r['d']] = ['avg_ms' => (int) $r['avg_ms'], 'c' => (int) $r['c'], 'err' => (int) $r['err']];
}
$durChart = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', time() - $i * 86400);
    $durChart[$d] = $durMap[$d] ?? ['avg_ms' => 0, 'c' => 0, 'err' => 0];
}
$durMax = max(1, max(array_column($durChart, 'avg_ms')));
$durErrDays = count(array_filter($durChart, fn($v) => $v['err'] > 0));

// Operasyonel uyarı trendi — sağlık kontrolü 4. bölümündeki sayaçların günlük kırılımı.
// Eşikler kontrol merkezinden (health_warn_*) okunur; eşiği aşan gün ⚠ sayılır.
$opsDays = (int) ($_GET['ops_days'] ?? 14);
if (!in_array($opsDays, [7, 14, 30], true)) $opsDays = 14;
$opsThresholds = [
    'log'     => max(1, (int) platform_setting('health_warn_error_logs', 20)),
    'email'   => max(1, (int) platform_setting('health_warn_email_queue', 50)),
    'webhook' => max(1, (int) platform_setting('health_warn_webhook_fail', 10)),
    'ical'    => max(1, (int) platform_setting('health_warn_ical_fail', 3)),
];
$opsQuery = function (string $sql) use ($pdo): array {
    try {
        $q = $pdo->query($sql);
        return $q ? $q->fetchAll() : [];
    } catch (Throwable $e) {
        return [];
    }
};
$opsSince = 'CURRENT_DATE - ' . ($opsDays - 1);
$opsByDay = [
    'log'     => $opsQuery("SELECT created_at::date d, COUNT(*) c FROM error_logs WHERE level IN ('error','critical') AND created_at >= " . $opsSince . " GROUP BY 1"),
    'webhook' => $opsQuery("SELECT created_at::date d, COUNT(*) c FROM channel_sync_logs WHERE direction='pull' AND status='failed' AND created_at >= " . $opsSince . " GROUP BY 1"),
    'ical'    => $opsQuery("SELECT created_at::date d, COUNT(*) c FROM ical_sync_logs WHERE status='failed' AND created_at >= " . $opsSince . " GROUP BY 1"),
    'email'   => $opsQuery("SELECT created_at::date d, COUNT(*) c FROM email_outbox WHERE status='queued' AND created_at >= " . $opsSince . " GROUP BY 1"),
];
$opsMap = [];
foreach ($opsByDay as $metric => $rows) {
    foreach ($rows as $r) {
        $opsMap[$metric][(string) $r['d']] = (int) $r['c'];
    }
}
$opsRows = [];
$opsWarnDays = 0;
$opsMaxTotal = 1;
for ($i = $opsDays - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', time() - $i * 86400);
    $log = $opsMap['log'][$d] ?? 0;
    $web = $opsMap['webhook'][$d] ?? 0;
    $ic  = $opsMap['ical'][$d] ?? 0;
    $em  = $opsMap['email'][$d] ?? 0;
    $warn = $log > $opsThresholds['log'] || $web > $opsThresholds['webhook'] || $ic > $opsThresholds['ical'] || $em > $opsThresholds['email'];
    $total = $log + $web + $ic + $em;
    if ($warn) $opsWarnDays++;
    $opsMaxTotal = max($opsMaxTotal, $total);
    $opsRows[] = ['d' => $d, 'log' => $log, 'web' => $web, 'ic' => $ic, 'em' => $em, 'warn' => $warn, 'total' => $total];
}

$jobs = scheduler_jobs();

// Sağlık kontrolü trendi — nexus-health-check çalıştırmalarından günlük sorun sayısı.
// Sayı, her koşunun çıktısındaki 'SONUÇ: N sorun' satırından ayrıştırılır;
// 'Tüm kontroller başarılı' → 0 sorun; çıktı yoksa durum bilinmiyor (-1).
// Gün bazında o günün son koşusunun durumu esas alınır (kronolojik üzerine yazma).
$hcDays = (int) ($_GET['hc_days'] ?? 14);
if (!in_array($hcDays, [7, 14, 30], true)) $hcDays = 14;
$hcJobId = null;
foreach ($jobs as $j) if (($j['code'] ?? '') === 'nexus-health-check') { $hcJobId = (int) $j['id']; break; }
$hcRuns = $hcJobId !== null ? $opsQuery("SELECT sr.created_at::date d, sr.status, sr.output FROM scheduled_job_runs sr WHERE sr.job_id=$hcJobId AND sr.created_at >= CURRENT_DATE - " . ($hcDays - 1) . " ORDER BY sr.created_at") : [];
$hcByDay = [];
$hcRunCount = 0;
foreach ($hcRuns as $r) {
    $hcRunCount++;
    $d = (string) $r['d'];
    $out = (string) ($r['output'] ?? '');
    $probs = -1;
    if (preg_match('/SONUÇ:\s*(\d+)\s+sorun/i', $out, $m)) $probs = (int) $m[1];
    elseif (mb_stripos($out, 'Tüm kontroller başarılı') !== false) $probs = 0;
    if (!isset($hcByDay[$d])) $hcByDay[$d] = ['runs' => 0, 'failed' => 0, 'probs' => -1];
    $hcByDay[$d]['runs']++;
    if (in_array((string) ($r['status'] ?? ''), ['error', 'failed'], true)) $hcByDay[$d]['failed']++;
    if ($probs >= 0) $hcByDay[$d]['probs'] = $probs;
}
$hcRows = [];
$hcProblemDays = 0;
$hcMaxProbs = 1;
for ($i = $hcDays - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', time() - $i * 86400);
    $info = $hcByDay[$d] ?? null;
    $probs = $info !== null ? (int) $info['probs'] : -1;
    if ($probs > 0) $hcProblemDays++;
    $hcMaxProbs = max($hcMaxProbs, max(0, $probs));
    $hcRows[] = ['d' => $d, 'probs' => $probs, 'runs' => $info !== null ? (int) $info['runs'] : 0, 'failed' => $info !== null ? (int) $info['failed'] : 0];
}

// Kartlardan filtre bağlantıları üret (görev seçimi korunur).
$filterUrl = function (string $st, int $h) use ($jobId): string {
    $q = [];
    if ($jobId > 0) $q[] = 'job=' . $jobId;
    if ($st !== '') $q[] = 'status=' . $st;
    if ($h > 0) $q[] = 'hours=' . $h;
    $q[] = 'limit=200';
    return '?' . implode('&', $q);
};
$hasFilter = $status !== '' || $hours > 0;
$filterLabel = trim(($status === 'error' ? 'Hata' : ($status === 'ok' ? 'Başarılı' : '')) . ' ' . ($hours === 24 ? '· son 24 saat' : ($hours === 72 ? '· son 3 gün' : ($hours === 168 ? '· son 7 gün' : ''))));
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Zamanlayıcı geçmişi | NEXUS Admin</title>
  <style>
    body{margin:0;font-family:Arial;background:#f7f7f2;color:#10211f}.w{width:min(1080px,calc(100% - 32px));margin:35px auto}.c{background:#fff;border:1px solid #ddd;padding:18px;margin:15px 0}select,button{padding:9px;font:inherit;border:1px solid #d8ded8}table{width:100%;border-collapse:collapse;margin-top:10px}th,td{border-bottom:1px solid #e1e5de;padding:9px 10px;text-align:left;vertical-align:top;font-size:13px}th{font-size:12px;text-transform:uppercase;color:#64716d}code{background:#f2f4ef;padding:2px 6px;font-size:12px}pre{background:#f2f4ef;padding:8px;font-size:12px;white-space:pre-wrap;margin:4px 0 0}.ok{color:#0d7a4a;font-weight:700}.er{color:#b0301a;font-weight:700}.stats{display:flex;gap:10px;flex-wrap:wrap}.stat{background:#fff;border:1px solid #ddd;padding:12px 16px;min-width:130px;display:block;text-decoration:none;color:inherit}.stat span{font-size:11px;text-transform:uppercase;color:#64716d}.stat b{display:block;font-size:20px;margin-top:3px}.stat.warn b{color:#a86026}.stat.danger b{color:#b0301a}a.stat:hover{border-color:#10211f;box-shadow:0 2px 8px rgba(0,0,0,.08)}.filters{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.filters button{background:#10211f;color:#fff;border:0;cursor:pointer}.muted{color:#64716d;font-size:13px}summary{cursor:pointer;font-size:12px;color:#0d7a4a}
  </style>
</head>
<body>
<main class="w"><a href="/nexustraveltech/admin/">← Panel</a> &nbsp; <a href="/nexustraveltech/admin/timerlar">← Zamanlayıcılar</a>
<h1>Zamanlayıcı çalışma geçmişi</h1>
<p class="muted">Her çalıştırma (nabız, manuel, AI) ayrı satır olarak kaydedilir; kayıtlar 90 gün tutulur. <?= (int)$stats['totalRuns'] ?> toplam kayıt.</p>

<div class="stats">
  <a class="stat" href="<?=htmlspecialchars($filterUrl('', 168))?>"><span>Son 7 gün çalışma</span><b><?= (int)$stats['total7'] ?></b></a>
  <a class="stat <?= $stats['err24'] > 0 ? 'danger' : '' ?>" href="<?=htmlspecialchars($filterUrl('error', 24))?>"><span>Hata — son 24 saat</span><b><?= (int)$stats['err24'] ?></b></a>
  <a class="stat <?= $stats['err7'] > 0 ? 'warn' : '' ?>" href="<?=htmlspecialchars($filterUrl('error', 168))?>"><span>Hata — son 7 gün</span><b><?= (int)$stats['err7'] ?></b></a>
  <div class="stat"><span>Ort. süre (7 gün)</span><b><?= number_format((int)$stats['avgMs']) ?> ms</b></div>
  <a class="stat <?= ($stats['pendingPurge'] ?? 0) > 0 ? 'danger' : '' ?>" href="/nexustraveltech/admin/ozellik-listeleri#trash"><span>Onay bekleyen silme</span><b><?= (int)($stats['pendingPurge'] ?? 0) ?></b></a>
</div>
<?php if ($hasFilter): ?>
<p class="muted" style="margin:10px 0 0">Filtre: <b><?=htmlspecialchars($filterLabel)?></b> · <a href="?limit=200" style="color:#0d7a4a;font-weight:700">Temizle</a></p>
<?php endif; ?>

<section class="c"><div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><h2 style="margin:0">Çalışma süresi — son 30 gün <?= $chartJob > 0 ? '(görev bazında)' : '(tüm görevler)' ?></h2>
<form method="get" action="/nexustraveltech/admin/zamanlayici-gecmisi" style="display:flex;gap:6px;align-items:center"><?php if ($jobId > 0): ?><input type="hidden" name="job" value="<?= (int)$jobId ?>"><?php endif; ?><?php if ($status !== ''): ?><input type="hidden" name="status" value="<?=htmlspecialchars($status)?>"><?php endif; ?><?php if ($hours > 0): ?><input type="hidden" name="hours" value="<?= (int)$hours ?>"><?php endif; ?><input type="hidden" name="limit" value="<?= (int)$limit ?>"><select name="chart_job" onchange="this.form.submit()"><option value="0">Tüm görevler</option><?php foreach ($jobs as $j): ?><option value="<?= (int) $j['id'] ?>" <?= $chartJob === (int) $j['id'] ? 'selected' : '' ?>><?= htmlspecialchars($j['name']) ?></option><?php endforeach; ?></select></form></div>
<p class="muted" style="margin:6px 0 8px">Günlük <b>ortalama</b> süre (ms). Üzerine gelince gün, süre ve çalışma sayısı görünür; kırmızı nokta o gün hata olduğunu gösterir. Grafik seçicisi üst filtreden bağımsızdır.</p>
<div style="display:flex;gap:2px;align-items:flex-end;max-width:820px;margin-top:8px">
<?php foreach ($durChart as $d => $v): ?>
  <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:3px;min-width:4px">
    <i title="<?=htmlspecialchars($d)?>: <?= (int)$v['avg_ms'] ?> ms (<?= (int)$v['c'] ?> çalışma<?= (int)$v['err'] > 0 ? ', ' . (int)$v['err'] . ' hata' : '' ?>)" style="display:block;width:100%;background:<?= (int)$v['avg_ms'] > 0 ? '#10211f' : '#e1e5de' ?>;border-radius:2px 2px 0 0;height:<?= max(2, (int) round((int)$v['avg_ms'] / $durMax * 82)) ?>px"></i>
    <?php if ((int) $v['err'] > 0): ?><span title="<?=htmlspecialchars($d)?>: <?= (int)$v['err'] ?> hata" style="width:6px;height:6px;border-radius:50%;background:#b0301a"></span><?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<p class="muted" style="margin:6px 0 0">En yüksek gün: <?= number_format($durMax) ?> ms · <?= count(array_filter($durChart, fn($v) => $v['avg_ms'] > 0)) ?>/30 gün verili · <b style="color:#b0301a"><?= (int)$durErrDays ?> gün hatalı</b> <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#b0301a;vertical-align:middle"></span></p>
</section>

<section class="c"><div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><h2 style="margin:0">📊 Operasyonel uyarılar — günlük trend (son <?= (int)$opsDays ?> gün)</h2>
<form method="get" action="/nexustraveltech/admin/zamanlayici-gecmisi" style="display:flex;gap:6px;align-items:center"><?php if ($jobId > 0): ?><input type="hidden" name="job" value="<?= (int)$jobId ?>"><?php endif; ?><?php if ($status !== ''): ?><input type="hidden" name="status" value="<?=htmlspecialchars($status)?>"><?php endif; ?><?php if ($hours > 0): ?><input type="hidden" name="hours" value="<?= (int)$hours ?>"><?php endif; ?><input type="hidden" name="limit" value="<?= (int)$limit ?>"><input type="hidden" name="chart_job" value="<?= (int)$chartJob ?>"><select name="ops_days" onchange="this.form.submit()"><option value="7" <?= $opsDays === 7 ? 'selected' : '' ?>>7 gün</option><option value="14" <?= $opsDays === 14 ? 'selected' : '' ?>>14 gün</option><option value="30" <?= $opsDays === 30 ? 'selected' : '' ?>>30 gün</option></select></form></div>
<p class="muted" style="margin:6px 0 8px">Sağlık kontrolünün 4. bölümündeki sayaçların gün bazında kırılımı — eşiği aşan hücre <b style="color:#b0301a">kırmızı</b> kalın, gün durumu ⚠ olur. Eşikler <a href="/nexustraveltech/admin/kontrol-merkezi">kontrol merkezinden</a> ayarlanır: hata logu <?= (int)$opsThresholds['log'] ?> · webhook <?= (int)$opsThresholds['webhook'] ?> · iCal <?= (int)$opsThresholds['ical'] ?> · e-posta <?= (int)$opsThresholds['email'] ?>.</p>
<table>
<tr><th>Gün</th><th style="text-align:center">Hata logu</th><th style="text-align:center">Webhook fail</th><th style="text-align:center">iCal hata</th><th style="text-align:center">E-posta kuyruğu</th><th style="text-align:center">Toplam</th><th style="text-align:center">Durum</th></tr>
<?php foreach ($opsRows as $o): ?>
<tr style="background:<?= $o['warn'] ? '#fff6f2' : '' ?>">
  <td style="white-space:nowrap"><?= htmlspecialchars($o['d']) ?></td>
  <td style="text-align:center" class="<?= $o['log'] > $opsThresholds['log'] ? 'er' : '' ?>"><?= (int)$o['log'] ?></td>
  <td style="text-align:center" class="<?= $o['web'] > $opsThresholds['webhook'] ? 'er' : '' ?>"><?= (int)$o['web'] ?></td>
  <td style="text-align:center" class="<?= $o['ic'] > $opsThresholds['ical'] ? 'er' : '' ?>"><?= (int)$o['ic'] ?></td>
  <td style="text-align:center" class="<?= $o['em'] > $opsThresholds['email'] ? 'er' : '' ?>"><?= (int)$o['em'] ?></td>
  <td style="text-align:center"><?= (int)$o['total'] ?></td>
  <td style="text-align:center"><?= $o['warn'] ? '<span class="er">⚠</span>' : '<span class="ok">✓</span>' ?></td>
</tr>
<?php endforeach; ?>
</table>
<div style="display:flex;gap:2px;align-items:flex-end;max-width:820px;margin-top:10px">
<?php foreach ($opsRows as $o): ?>
  <div title="<?=htmlspecialchars($o['d'])?>: <?= (int)$o['total'] ?> sorun<?= $o['warn'] ? ' (⚠)' : '' ?>" style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:3px;min-width:4px">
    <i style="display:block;width:100%;background:<?= $o['warn'] ? '#b0301a' : '#10211f' ?>;border-radius:2px 2px 0 0;height:<?= max(2, (int) round((int)$o['total'] / $opsMaxTotal * 46)) ?>px"></i>
  </div>
<?php endforeach; ?>
</div>
<p class="muted" style="margin:6px 0 0">Alt çubuk: günün toplam sorun sayısı (en yüksek gün <?= (int)$opsMaxTotal ?>). <b><?= (int)$opsWarnDays ?>/<?= (int)$opsDays ?> gün en az bir eşiği aştı</b> <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#b0301a;vertical-align:middle"></span></p>
</section>

<section class="c"><div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><h2 style="margin:0">🩺 Sağlık kontrolü — günlük sorun trendi (son <?= (int)$hcDays ?> gün)</h2>
<form method="get" action="/nexustraveltech/admin/zamanlayici-gecmisi" style="display:flex;gap:6px;align-items:center"><?php if ($jobId > 0): ?><input type="hidden" name="job" value="<?= (int)$jobId ?>"><?php endif; ?><?php if ($status !== ''): ?><input type="hidden" name="status" value="<?=htmlspecialchars($status)?>"><?php endif; ?><?php if ($hours > 0): ?><input type="hidden" name="hours" value="<?= (int)$hours ?>"><?php endif; ?><input type="hidden" name="limit" value="<?= (int)$limit ?>"><input type="hidden" name="chart_job" value="<?= (int)$chartJob ?>"><input type="hidden" name="ops_days" value="<?= (int)$opsDays ?>"><select name="hc_days" onchange="this.form.submit()"><option value="7" <?= $hcDays === 7 ? 'selected' : '' ?>>7 gün</option><option value="14" <?= $hcDays === 14 ? 'selected' : '' ?>>14 gün</option><option value="30" <?= $hcDays === 30 ? 'selected' : '' ?>>30 gün</option></select></form></div>
<p class="muted" style="margin:6px 0 8px">Her koşunun çıktısındaki <code>SONUÇ: N sorun</code> satırından ayrıştırılan sorun sayısı — gün bazında; sorunlu günler <b style="color:#b0301a">kırmızı</b>, temiz günler <b style="color:#0d7a4a">yeşil</b>, gri nokta = o gün çalışma yok. Son koşunun durumu esas alınır. <a href="<?= $hcJobId ? '/nexustraveltech/admin/zamanlayici-gecmisi?job=' . $hcJobId . '&limit=100' : '/nexustraveltech/admin/zamanlayici-gecmisi' ?>">Kayıtları gör →</a></p>
<div style="display:flex;gap:2px;align-items:flex-end;max-width:820px;margin-top:10px">
<?php foreach ($hcRows as $h): ?>
  <div title="<?= htmlspecialchars($h['d']) . ': ' . ($h['probs'] >= 0 ? $h['probs'] . ' sorun · ' . $h['runs'] . ' çalıştırma' . ($h['failed'] > 0 ? ' (' . $h['failed'] . ' hata)' : '') : 'çalışma yok') ?>" style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:3px;min-width:4px">
    <i style="display:block;width:100%;background:<?= $h['probs'] < 0 ? '#d5dccf' : ($h['probs'] > 0 ? '#b0301a' : '#0d7a4a') ?>;border-radius:2px 2px 0 0;height:<?= $h['probs'] < 0 ? 3 : max(2, (int) round($h['probs'] / $hcMaxProbs * 46)) ?>px"></i>
  </div>
<?php endforeach; ?>
</div>
<p class="muted" style="margin:6px 0 0"><b><?= (int)$hcProblemDays ?>/<?= (int)$hcDays ?> gün sorunlu</b> · <?= (int)$hcRunCount ?> çalıştırma · en yüksek gün <?= (int)$hcMaxProbs ?> sorun <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#b0301a;vertical-align:middle"></span></p>
</section>

<section class="c">
<h2>Kayıtlar</h2>
<form method="get" class="filters" action="/nexustraveltech/admin/zamanlayici-gecmisi">
  <select name="job">
    <option value="0">Tüm görevler</option>
    <?php foreach ($jobs as $j): ?>
      <option value="<?= (int) $j['id'] ?>" <?= $jobId === (int) $j['id'] ? 'selected' : '' ?>><?= htmlspecialchars($j['name']) ?> (<?= htmlspecialchars($j['code']) ?>)</option>
    <?php endforeach; ?>
  </select>
  <select name="status">
    <option value="">Tüm durumlar</option>
    <option value="ok" <?= $status === 'ok' ? 'selected' : '' ?>>Başarılı</option>
    <option value="error" <?= $status === 'error' ? 'selected' : '' ?>>Hata</option>
  </select>
  <select name="limit">
    <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50 kayıt</option>
    <option value="200" <?= $limit === 200 ? 'selected' : '' ?>>200 kayıt</option>
    <option value="500" <?= $limit === 500 ? 'selected' : '' ?>>500 kayıt</option>
  </select>
  <?php if ($hours > 0): ?><input type="hidden" name="hours" value="<?= (int)$hours ?>"><?php endif; ?>
  <input type="hidden" name="chart_job" value="<?= (int)$chartJob ?>">
  <button>Filtrele</button>
</form>
<?php if (!$runs): ?>
  <p class="muted">Kayıt yok. Görevler çalıştıkça buraya düşer — <a href="/nexustraveltech/admin/timerlar">Zamanlayıcılar</a> sayfasından "Şimdi çalıştır" ile hemen test edebilirsiniz.</p>
<?php else: ?>
<table>
<tr><th>Zaman</th><th>Görev</th><th>Durum</th><th>Süre</th><th>Tetikleyen</th><th>Çıktı</th></tr>
<?php foreach ($runs as $r): ?>
<tr>
  <td style="white-space:nowrap"><?= htmlspecialchars((string) $r['created_at']) ?></td>
  <td><b><?= htmlspecialchars($r['name']) ?></b><br><code><?= htmlspecialchars($r['code']) ?></code></td>
  <td class="<?= $r['status'] === 'ok' ? 'ok' : 'er' ?>"><?= $r['status'] === 'ok' ? '✓ Başarılı' : '✗ Hata' ?></td>
  <td style="white-space:nowrap"><?= $r['duration_ms'] !== null ? ((int) $r['duration_ms']) . ' ms' : '—' ?></td>
  <td><?= $r['triggered_by'] === 'manual' ? '👆 Manuel' : ($r['triggered_by'] === 'ai' ? '🤖 AI' : '🕐 Nabız') ?></td>
  <td><?= $r['output'] !== null && trim((string) $r['output']) !== '' ? '<details><summary>Göster</summary><pre>' . htmlspecialchars(mb_substr((string) $r['output'], 0, 2000)) . '</pre></details>' : '—' ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>
</section>
</main>
<?php require_once __DIR__.'/../config/ai_widget.php'; ai_widget('/nexustraveltech/admin/ai-chat','admin_csrf'); ?></body>
</html>
