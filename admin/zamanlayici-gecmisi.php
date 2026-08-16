<?php
declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/scheduler.php';

require_admin();

$jobId = (int) ($_GET['job'] ?? 0);
$status = (string) ($_GET['status'] ?? '');
if (!in_array($status, ['', 'ok', 'error'], true)) $status = '';
$limit = (int) ($_GET['limit'] ?? 200);
if (!in_array($limit, [50, 200, 500], true)) $limit = 200;

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

$jobs = scheduler_jobs();
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Zamanlayıcı geçmişi | NEXUS Admin</title>
  <style>
    body{margin:0;font-family:Arial;background:#f7f7f2;color:#10211f}.w{width:min(1080px,calc(100% - 32px));margin:35px auto}.c{background:#fff;border:1px solid #ddd;padding:18px;margin:15px 0}select,button{padding:9px;font:inherit;border:1px solid #d8ded8}table{width:100%;border-collapse:collapse;margin-top:10px}th,td{border-bottom:1px solid #e1e5de;padding:9px 10px;text-align:left;vertical-align:top;font-size:13px}th{font-size:12px;text-transform:uppercase;color:#64716d}code{background:#f2f4ef;padding:2px 6px;font-size:12px}pre{background:#f2f4ef;padding:8px;font-size:12px;white-space:pre-wrap;margin:4px 0 0}.ok{color:#0d7a4a;font-weight:700}.er{color:#b0301a;font-weight:700}.stats{display:flex;gap:10px;flex-wrap:wrap}.stat{background:#fff;border:1px solid #ddd;padding:12px 16px;min-width:130px}.stat span{font-size:11px;text-transform:uppercase;color:#64716d}.stat b{display:block;font-size:20px;margin-top:3px}.stat.warn b{color:#a86026}.stat.danger b{color:#b0301a}.filters{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.filters button{background:#10211f;color:#fff;border:0;cursor:pointer}.muted{color:#64716d;font-size:13px}summary{cursor:pointer;font-size:12px;color:#0d7a4a}
  </style>
</head>
<body>
<main class="w"><a href="/nexustraveltech/admin/">← Panel</a> &nbsp; <a href="/nexustraveltech/admin/timerlar">← Zamanlayıcılar</a>
<h1>Zamanlayıcı çalışma geçmişi</h1>
<p class="muted">Her çalıştırma (nabız, manuel, AI) ayrı satır olarak kaydedilir; kayıtlar 90 gün tutulur. <?= (int)$stats['totalRuns'] ?> toplam kayıt.</p>

<div class="stats">
  <div class="stat"><span>Son 7 gün çalışma</span><b><?= (int)$stats['total7'] ?></b></div>
  <div class="stat <?= $stats['err24'] > 0 ? 'danger' : '' ?>"><span>Hata — son 24 saat</span><b><?= (int)$stats['err24'] ?></b></div>
  <div class="stat <?= $stats['err7'] > 0 ? 'warn' : '' ?>"><span>Hata — son 7 gün</span><b><?= (int)$stats['err7'] ?></b></div>
  <div class="stat"><span>Ort. süre (7 gün)</span><b><?= number_format((int)$stats['avgMs']) ?> ms</b></div>
</div>

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
