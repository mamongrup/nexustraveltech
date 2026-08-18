<?php
declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/scheduler.php';
require __DIR__ . '/../config/audit.php';
require __DIR__ . '/../config/platform_settings.php';

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
            if ($action === 'send_test') {
                // Test e-postasını gerçekten gönder (--send).
                $testScript = __DIR__ . '/../cron/test-admin-alerts.php';
                if (!file_exists($testScript)) throw new RuntimeException('test-admin-alerts.php bulunamadı.');
                $output = '';
                $exitCode = 0;
                exec(PHP_BINARY . ' ' . escapeshellarg($testScript) . ' --send 2>&1', $output, $exitCode);
                $outputText = implode("\n", $output);
                audit_log('scheduler.send_test_email', 'scheduler', 0, ['exit_code' => $exitCode, 'output' => mb_substr($outputText, 0, 500)]);
                $msg = 'Test e-postası kuyruğa eklendi (' . ($exitCode === 0 ? 'başarılı' : 'hata') . ') — ' . mb_substr($outputText, 0, 300);
                // Last test bilgisini güncelle.
                save_platform_setting('last_alert_test_at', date('Y-m-d H:i:s'));
                save_platform_setting('last_alert_test_mode', 'send');
            }
            if ($action === 'run') {
                $q = db()->prepare('SELECT * FROM scheduled_jobs WHERE id=?');
                $q->execute([$id]);
                $job = $q->fetch();
                if (!$job) throw new RuntimeException('Görev bulunamadı.');
                $started = microtime(true);
                $res = scheduler_run_job($job);
                $durationMs = (int) round((microtime(true) - $started) * 1000);
                db()->prepare('UPDATE scheduled_jobs SET last_run_at=now(),last_status=?,last_output=?,run_count=run_count+1 WHERE id=?')
                    ->execute([$res['status'], mb_substr((string) $res['output'], 0, 2000), $id]);
                scheduler_record_run($id, $res['status'], (string) $res['output'], $durationMs, 'manual');
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
// Sağlık kontrolü için belirgin tek tık tetikleyici — görev satırındaki "Şimdi çalıştır" ile aynı `run` akışını kullanır.
$hcJob = null;
foreach ($jobs as $j) if (($j['code'] ?? '') === 'nexus-health-check') { $hcJob = $j; break; }
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

<?php if ($hcJob): ?><section class="c" style="border:2px solid #0d7a4a"><h2>🩺 Sağlık kontrolü</h2><p class="muted">Bekleyen migration'ları uygular, tablo/kolon/ortam/kilit durumunu denetler ve sorunları raporlar. Buton görevi anında çalıştırır; sonuç yukarıda ve görev geçmişinde görünür.</p><form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="run"><input type="hidden" name="id" value="<?= (int) $hcJob['id'] ?>"><button style="padding:10px 20px;background:#0d7a4a;font-size:14px">▶ Sağlık kontrolünü şimdi çalıştır</button> <span class="muted">son çalışma: <?= !empty($hcJob['last_run_at']) ? htmlspecialchars((string) $hcJob['last_run_at']) . ' · <b>' . htmlspecialchars((string) ($hcJob['last_status'] ?? '—')) . '</b>' : '—' ?></span></form><?php if (!empty($hcJob['last_output'])): ?><details style="margin-top:10px"><summary style="cursor:pointer;font-size:12px;color:#0d7a4a">Son çıktıyı göster</summary><pre style="background:#f2f4ef;padding:8px;font-size:12px;white-space:pre-wrap"><?= htmlspecialchars((string) $hcJob['last_output']) ?></pre></details><?php endif; ?></section><?php endif; ?>

<section class="c" style="border:1px solid #d8ded8"><h2>🧪 Uyarı e-postası testi</h2><?php $lastTestAt = (string) platform_setting('last_alert_test_at', ''); $lastTestCh = (int) platform_setting('last_alert_test_channels', 0); $lastTestMode = (string) platform_setting('last_alert_test_mode', ''); if ($lastTestAt === ''): ?><p class="muted">Henüz test çalıştırılmadı. Aşağıdaki <code>nexus-admin-alert-test</code> satırında "Şimdi çalıştır" ile kuru test yapın (kanallar hazır mı bakar, e-posta göndermez); gerçek gönderim sunucuda <code>php cron/test-admin-alerts.php --send</code> ile yapılır ve tüm kanallar tek özet e-postada tablo olarak gelir.</p><?php else: ?>  <p class="muted">Son test: <b><?= htmlspecialchars($lastTestAt) ?></b> · <b style="color:#0d7a4a"><?= (int) $lastTestCh ?> kanal ✓</b> · mod: <?= $lastTestMode === 'send' ? 'gerçek gönderim' : 'kuru' ?> · hedef: <?= htmlspecialchars((string) platform_setting('admin_alert_email', '')) ?: 'tanımsız' ?> · kod: <b><?= htmlspecialchars((string) platform_setting('last_alert_test_code', '')) ?: '—' ?></b> · teslim: <?php $delCode = (string) platform_setting('last_alert_test_delivered_code', ''); if ($delCode !== '' && $delCode === (string) platform_setting('last_alert_test_code', '')): ?><b style="color:#0d7a4a">✓ <?= htmlspecialchars((string) platform_setting('last_alert_test_delivered_at', '')) ?></b><?php else: ?><span style="color:#b06a00">bekleniyor</span><?php endif; ?></p>
  <form method="post" style="margin:10px 0;display:inline-flex;gap:10px;align-items:center;flex-wrap:wrap"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="send_test"><button style="padding:10px 20px;background:#0d7a4a;font-size:14px">📨 Test e-postası gönder — <?= htmlspecialchars((string) platform_setting('admin_alert_email', '')) ?: 'tanımsız' ?></button> <span class="muted">TÜM kanallar tek e-postada → kuyruk işleyicisi 5 dk içinde gönderir</span></form>
  <?php $verifyJob = null; foreach ($jobs as $vj) if (($vj['code'] ?? '') === 'nexus-alert-test-delivery') { $verifyJob = $vj; break; } ?>
  <?php if ($verifyJob): ?><form method="post" style="display:inline-flex;gap:10px;align-items:center;flex-wrap:wrap;margin:10px 0"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="run"><input type="hidden" name="id" value="<?= (int) $verifyJob['id'] ?>"><button style="padding:10px 20px;background:#a86026;font-size:14px">🔍 Teslimatı şimdi doğrula</button> <span class="muted">Son test kodunu kontrol eder (30 dk penceresi) — son çalışma: <?= $verifyJob['last_run_at'] ? htmlspecialchars((string) $verifyJob['last_run_at']) . ' · <b>' . htmlspecialchars((string) ($verifyJob['last_status'] ?? '—')) . '</b>' : '—' ?></span></form><?php endif; ?><?php endif; ?></section>

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
<p><a href="/nexustraveltech/admin/zamanlayici-gecmisi" style="font-weight:700;color:#0d7a4a">Çalışma geçmişi →</a></p>
</section>
</main>
<?php require_once __DIR__.'/../config/ai_widget.php'; ai_widget('/nexustraveltech/admin/ai-chat','admin_csrf'); ?></body>
</html>
