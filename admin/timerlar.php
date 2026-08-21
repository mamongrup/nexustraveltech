<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/scheduler.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/../config/platform_settings.php';

require_admin();
if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$msg = '';
$err = '';
$pdo = db();

// Tabloların varlığını kesinleştir
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS scheduled_jobs (
            id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            code VARCHAR(80) NOT NULL UNIQUE,
            name VARCHAR(190) NOT NULL,
            command TEXT NOT NULL,
            schedule VARCHAR(60) NOT NULL,
            enabled BOOLEAN NOT NULL DEFAULT true,
            last_run_at TIMESTAMPTZ,
            last_status VARCHAR(40),
            last_output TEXT,
            run_count INTEGER NOT NULL DEFAULT 0,
            created_at TIMESTAMPTZ NOT NULL DEFAULT now()
        );

        CREATE TABLE IF NOT EXISTS scheduled_job_runs (
            id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            job_id BIGINT NOT NULL REFERENCES scheduled_jobs(id) ON DELETE CASCADE,
            status VARCHAR(40) NOT NULL,
            output TEXT,
            duration_ms INTEGER NOT NULL DEFAULT 0,
            trigger_type VARCHAR(20) NOT NULL DEFAULT 'cron',
            created_at TIMESTAMPTZ NOT NULL DEFAULT now()
        );

        CREATE TABLE IF NOT EXISTS email_templates (
            code VARCHAR(60) PRIMARY KEY,
            name VARCHAR(190) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body_html TEXT NOT NULL,
            is_active BOOLEAN NOT NULL DEFAULT true,
            created_at TIMESTAMPTZ NOT NULL DEFAULT now()
        );
    ");
} catch (Throwable $e) {
    $err = "Tablo oluşturma uyarısı: " . $e->getMessage();
}

// POST İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['admin_csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        $err = 'Güvenlik doğrulaması geçersiz.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        try {
            if ($action === 'toggle' && $id > 0) {
                $pdo->prepare('UPDATE scheduled_jobs SET enabled=NOT enabled WHERE id=?')->execute([$id]);
                audit_log('scheduler.toggle', 'job', $id);
                $msg = 'Görev durumu güncellendi.';
            }
            if ($action === 'edit' && $id > 0) {
                $schedule = trim((string)($_POST['schedule'] ?? ''));
                $name = trim((string)($_POST['name'] ?? ''));
                $parts = preg_split('/\s+/', $schedule);
                if (count($parts) !== 5) {
                    throw new RuntimeException('Zamanlama 5 alanlı cron ifadesi olmalı (örn. */5 * * * *).');
                }
                if ($name === '') {
                    throw new RuntimeException('Görev adı boş olamaz.');
                }
                $pdo->prepare('UPDATE scheduled_jobs SET schedule=?, name=? WHERE id=?')->execute([$schedule, mb_substr($name, 0, 190), $id]);
                audit_log('scheduler.edit', 'job', $id, ['schedule' => $schedule]);
                $msg = 'Zamanlama güncellendi.';
            }
            if ($action === 'run' && $id > 0) {
                $q = $pdo->prepare('SELECT * FROM scheduled_jobs WHERE id=?');
                $q->execute([$id]);
                $job = $q->fetch();
                if (!$job) {
                    throw new RuntimeException('Görev bulunamadı.');
                }
                $started = microtime(true);
                $res = scheduler_run_job($job);
                $durationMs = (int)round((microtime(true) - $started) * 1000);
                $pdo->prepare('UPDATE scheduled_jobs SET last_run_at=now(), last_status=?, last_output=?, run_count=run_count+1 WHERE id=?')
                    ->execute([$res['status'], mb_substr((string)$res['output'], 0, 2000), $id]);
                scheduler_record_run($id, $res['status'], (string)$res['output'], $durationMs, 'manual');
                audit_log('scheduler.run', 'job', $id, ['status' => $res['status']]);
                $msg = 'Görev çalıştırıldı: ' . $res['status'] . ($res['output'] !== '' ? ' — ' . mb_substr($res['output'], 0, 300) : '');
            }
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

// Görevleri Yükle
$jobs = [];
$hcJob = null;
try {
    scheduler_seed_defaults();
    $jobs = $pdo->query('SELECT * FROM scheduled_jobs ORDER BY id')->fetchAll();
    foreach ($jobs as $j) {
        if (($j['code'] ?? '') === 'nexus-health-check') {
            $hcJob = $j;
            break;
        }
    }
} catch (Throwable $e) {
    $err = "Görevler yüklenirken uyarı: " . $e->getMessage();
}

require_once __DIR__ . '/layout.php';
admin_layout_start('Zamanlayıcılar & Otomatik Görevler', 'timerlar');
?>

<?php if ($msg): ?><div class="sui-alert sui-alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="sui-alert sui-alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- Sağlık & Bütünlük Kontrolü Kartı -->
<div class="sui-card" style="margin-bottom:24px;border-left:4px solid var(--sui-success)">
    <div class="sui-card-header">
        <div>
            <h2 class="sui-card-title"><i class="fa-solid fa-heart-pulse" style="color:#22c55e;margin-right:8px"></i> Sağlık & Bütünlük Kontrolü</h2>
            <p style="color:var(--sui-muted);font-size:13px;margin:4px 0 0 0">
                Veritabanı tabloları, eksik migration'lar ve platform kilitlerini denetler.
            </p>
        </div>
        <?php if ($hcJob): ?>
            <form method="post" style="margin:0">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                <input type="hidden" name="action" value="run">
                <input type="hidden" name="id" value="<?= (int)$hcJob['id'] ?>">
                <button class="sui-btn sui-btn-success sui-btn-sm"><i class="fa-solid fa-play"></i> Şimdi Çalıştır</button>
            </form>
        <?php endif; ?>
    </div>
    <?php if ($hcJob && !empty($hcJob['last_run_at'])): ?>
        <p style="font-size:12px;color:var(--sui-muted);margin:0">
            Son çalışma: <b><?= htmlspecialchars((string)$hcJob['last_run_at']) ?></b> · Durum: <b><?= htmlspecialchars((string)($hcJob['last_status'] ?? '—')) ?></b>
        </p>
    <?php endif; ?>
</div>

<!-- Görevler Tablosu -->
<div class="sui-card" style="margin-bottom:24px">
    <div class="sui-card-header">
        <div>
            <h2 class="sui-card-title"><i class="fa-solid fa-stopwatch" style="color:var(--sui-primary);margin-right:8px"></i> Otomatik Görevler (Scheduler)</h2>
            <p style="color:var(--sui-muted);font-size:13px;margin:4px 0 0 0">
                Tek nabız noktası (`cron/tick.php`) dakikada bir çalışarak zamanı gelen görevleri tetikler. Toplam <b><?= count($jobs) ?></b> görev kayıtlı.
            </p>
        </div>
        <div>
            <a href="zamanlayici-gecmisi" class="sui-btn sui-btn-outline sui-btn-sm">
                <i class="fa-solid fa-clock-rotate-left"></i> Çalışma Geçmişi →
            </a>
        </div>
    </div>

    <?php if (!$jobs): ?>
        <div style="padding:40px;text-align:center;color:var(--sui-muted)">
            <p>Kayıtlı zamanlayıcı görevi bulunamadı.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto">
            <table class="sui-table">
                <thead>
                    <tr>
                        <th>Görev Adı & Komut</th>
                        <th>Zamanlama (Cron)</th>
                        <th>Sonraki Çalışma</th>
                        <th>Durum</th>
                        <th>Son Çalışma</th>
                        <th>Adet</th>
                        <th style="text-align:right">İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobs as $j): 
                        $next = null;
                        try {
                            if (function_exists('scheduler_next_run')) {
                                $next = scheduler_next_run((string)$j['schedule']);
                            }
                        } catch (Throwable $e) {}
                    ?>
                        <tr>
                            <td>
                                <b><?= htmlspecialchars($j['name']) ?></b>
                                <div style="font-size:11px;color:var(--sui-muted);font-family:monospace"><?= htmlspecialchars($j['command']) ?></div>
                            </td>
                            <td>
                                <form method="post" style="display:flex;gap:4px;margin:0">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                                    <input type="hidden" name="action" value="edit">
                                    <input type="hidden" name="id" value="<?= (int)$j['id'] ?>">
                                    <input name="schedule" value="<?= htmlspecialchars($j['schedule']) ?>" class="sui-input" style="width:90px;padding:4px 8px;font-size:12px">
                                    <button class="sui-btn sui-btn-outline sui-btn-sm" style="padding:4px 8px">Kaydet</button>
                                </form>
                            </td>
                            <td style="font-size:12px;color:var(--sui-muted)"><?= $next !== null ? htmlspecialchars($next) : '—' ?></td>
                            <td>
                                <span class="sui-badge <?= $j['enabled'] ? 'sui-badge-success' : 'sui-badge-danger' ?>">
                                    <?= $j['enabled'] ? 'AÇIK' : 'KAPALI' ?>
                                </span>
                            </td>
                            <td style="font-size:12px">
                                <?= $j['last_run_at'] ? htmlspecialchars((string)$j['last_run_at']) : '—' ?>
                                <?php if ($j['last_status']): ?>
                                    <br><small style="color:var(--sui-muted)"><?= htmlspecialchars((string)$j['last_status']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= (int)$j['run_count'] ?></td>
                            <td style="text-align:right">
                                <div style="display:flex;gap:6px;justify-content:flex-end;align-items:center">
                                    <form method="post" style="margin:0">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int)$j['id'] ?>">
                                        <button class="sui-btn sui-btn-outline sui-btn-sm"><?= $j['enabled'] ? 'Kapat' : 'Aç' ?></button>
                                    </form>
                                    <form method="post" style="margin:0">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                                        <input type="hidden" name="action" value="run">
                                        <input type="hidden" name="id" value="<?= (int)$j['id'] ?>">
                                        <button class="sui-btn sui-btn-primary sui-btn-sm"><i class="fa-solid fa-play"></i></button>
                                    </form>
                                </div>
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
