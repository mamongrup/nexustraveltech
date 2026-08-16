<?php
declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/scheduler.php';
require __DIR__ . '/../config/audit.php';

require_admin();
if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['admin_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $err = 'Güvenlik doğrulaması geçersiz.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);
        try {
            if ($action === 'toggle') {
                db()->prepare('UPDATE scheduled_jobs SET enabled=NOT enabled WHERE id=?')->execute([$id]);
                audit_log('scheduler.toggle', 'job', $id);
                $msg = 'Görev durumu güncellendi.';
            }
            if ($action === 'edit') {
                $schedule = trim((string) ($_POST['schedule'] ?? ''));
                $name = trim((string) ($_POST['name'] ?? ''));
                $parts = preg_split('/\s+/', $schedule);
                if (count($parts) !== 5) throw new RuntimeException('Zamanlama 5 alanlı cron ifadesi olmalı (örn. */5 * * * *).');
                if ($name === '') throw new RuntimeException('Görev adı boş olamaz.');
                db()->prepare('UPDATE scheduled_jobs SET schedule=?,name=? WHERE id=?')->execute([$schedule, mb_substr($name, 0, 190), $id]);
                audit_log('scheduler.edit', 'job', $id, ['schedule' => $schedule]);
                $msg = 'Zamanlama güncellendi.';
            }
            if ($action === 'run') {
                $q = db()->prepare('SELECT * FROM scheduled_jobs WHERE id=?');
                $q->execute([$id]);
                $job = $q->fetch();
                if (!$job) throw new RuntimeException('Görev bulunamadı.');
                $res = scheduler_run_job($job);
                db()->prepare('UPDATE scheduled_jobs SET last_run_at=now(),last_status=?,last_output=?,run_count=run_count+1 WHERE id=?')
                    ->execute([$res['status'], mb_substr((string) $res['output'], 0, 2000), $id]);
                audit_log('scheduler.run', 'job', $id, ['status' => $res['status']]);
                $msg = 'Görev çalıştırıldı: ' . $res['status'] . ($res['output'] !== '' ? ' — ' . mb_substr($res['output'], 0, 300) : '');
            }
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

scheduler_seed_defaults();
scheduler_tick(); // sayfa açılışında da nabız — cron olmadan bile ilerleme sağlar
$jobs = scheduler_jobs();
$token = scheduler_tick_token();
$tickUrl = 'https://nexustraveltech.com/nexustraveltech/timer-tick.php?token=' . $token;
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Zamanlayıcılar | NEXUS Admin</title>
  <style>
    body{margin:0;font-family:Arial;background:#f7f7f2;color:#10211f}.w{width:min(1080px,calc(100% - 32px));margin:35px auto}.c{background:#fff;border:1px solid #ddd;padding:18px;margin:15px 0}input,button,select{padding:9px;font:inherit;border:1px solid #d8ded8}table{width:100%;border-collapse:collapse;margin-top:10px}th,td{border-bottom:1px solid #e1e5de;padding:10px;text-align:left;vertical-align:top;font-size:14px}th{font-size:12px;text-transform:uppercase;color:#64716d}.ok{background:#e6f8c7;padding:9px}.er{background:#ffe2de;padding:9px}button{background:#10211f;color:#fff;border:0;cursor:pointer;margin-top:6px}code{background:#f2f4ef;padding:2px 6px;font-size:12px}
  </style>
</head>
<body>
<main class="w"><a href="/nexustraveltech/admin/">← Panel</a><h1>Zamanlayıcılar</h1>
<p class="muted">Sistem cron'ları yerine panel yönetimli görevler. Tek nabız noktası vadesi gelen görevleri çalıştırır — 8 satır cron yerine <b>1 satır</b> yeterli.</p>
<?php if ($msg): ?><p class="ok"><?= htmlspecialchars($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="er"><?= htmlspecialchars($err) ?></p><?php endif; ?>

<section class="c"><h2>Nabız kurulumu (ikisinden biri)</h2>
<p><b>A) Sistem cron / Plesk Scheduled Tasks (komut):</b></p>
<code>* * * * * /opt/plesk/php/8.5/bin/php /var/www/vhosts/nexustraveltech.com/httpdocs/cron/tick.php &gt;/dev/null 2>&1</code>
<p><b>B) Plesk "Request a URL" görevi (token korumalı):</b></p>
<code><?= htmlspecialchars($tickUrl) ?></code>
<p class="muted">Her iki seçenek de dakikada bir çalışır; görevlerin kendisi bu sayfadan yönetilir. A sayfasındaki eski 8 görev kaldırılmalıdır (çift çalışmayı önler).</p>
</section>

<section class="c"><h2>Görevler</h2>
<table>
<tr><th>Görev</th><th>Zamanlama</th><th>Sonraki çalışma</th><th>Durum</th><th>Son çalışma</th><th>Çalışma</th><th>İşlem</th></tr>
<?php foreach ($jobs as $j): $next = scheduler_next_run((string) $j['schedule']); ?>
<tr>
  <td><b><?= htmlspecialchars($j['name']) ?></b><br><code><?= htmlspecialchars($j['command']) ?></code></td>
  <td>
    <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="<?= (int) $j['id'] ?>">
    <input name="schedule" value="<?= htmlspecialchars($j['schedule']) ?>" style="width:110px" title="dakika saat gün ay hafta"><br><button style="padding:4px 8px;font-size:12px">Kaydet</button></form>
  </td>
  <td><?= $next !== null ? htmlspecialchars($next) : '—' ?></td>
  <td><?= $j['enabled'] ? '<b style="color:#0d7a4a">AÇIK</b>' : '<b style="color:#8e2410">KAPALI</b>' ?></td>
  <td><?= $j['last_run_at'] ? htmlspecialchars((string) $j['last_run_at']) . '<br><b>' . htmlspecialchars((string) ($j['last_status'] ?? '—')) . '</b>' : '—' ?></td>
  <td><?= (int) $j['run_count'] ?></td>
  <td>
    <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $j['id'] ?>"><button style="padding:4px 8px;font-size:12px;background:#a86026"><?= $j['enabled'] ? 'Kapat' : 'Aç' ?></button></form>
    <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="run"><input type="hidden" name="id" value="<?= (int) $j['id'] ?>"><button style="padding:4px 8px;font-size:12px">Şimdi çalıştır</button></form>
    <?php if ($j['last_output']): ?><details><summary style="cursor:pointer;font-size:12px;margin-top:4px">Son çıktı</summary><pre style="background:#f2f4ef;padding:8px;font-size:12px;white-space:pre-wrap"><?= htmlspecialchars((string) $j['last_output']) ?></pre></details><?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</table>
<p class="muted">Zamanlama biçimi: <code>dakika saat gün ay hafta</code> — ör. <code>*/5 * * * *</code> (5 dakikada bir), <code>0 8 * * *</code> (her gün 08:00), <code>30 3 * * *</code> (her gün 03:30).</p>
</section>
</main>
</body>
</html>
