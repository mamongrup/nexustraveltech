<?php
declare(strict_types=1);

// Migration durumu — database/migrations/*-postgres.sql dosyalarının schema_migrations
// kayıtlarıyla eşleşmesini tablo bazında gösterir: dosya, durum, uygulanma tarihi, commit.
// "Bekleyenleri uygula" butonu health_check_run(false) ile idempotent uygular (aynı mantık
// scripts/health-check.php ile paylaşılır); sonuç aynı sayfada gösterilir.

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/health.php';

require_admin();
if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));

$pdo = db();

// schema_migrations güvencesi — health.php 3. bölümüyle aynı (tablo + commit_hash kolonu).
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, file VARCHAR(190) NOT NULL UNIQUE, applied_at TIMESTAMPTZ NOT NULL DEFAULT now())');
$hasCommit = (bool) $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='schema_migrations' AND column_name='commit_hash'")->fetchColumn();
if (!$hasCommit) {
    $pdo->exec('ALTER TABLE schema_migrations ADD COLUMN commit_hash CHAR(40)');
}

$message = '';
$error = '';
$applyOutput = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['admin_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Güvenlik doğrulaması geçersiz.';
    } else {
        $result = health_check_run(false);
        $applyOutput = $result['output'];
        $message = $result['errors'] === []
            ? "Bekleyen migration'lar uygulandı — sorun yok."
            : 'Uygulama tamamlandı ama ' . count($result['errors']) . ' sorun kaldı: ' . implode('; ', array_slice($result['errors'], 0, 5)) . '.';
    }
}

$migrationFiles = glob(__DIR__ . '/../database/migrations/*-postgres.sql');
sort($migrationFiles);
$legacyFiles = glob(__DIR__ . '/../database/migrations/[0-9][0-9][0-9]-*.sql');
$legacyCount = count(array_filter($legacyFiles, fn($f) => !str_contains($f, '-postgres')));

$appliedMap = [];
foreach ($pdo->query('SELECT file, applied_at, commit_hash FROM schema_migrations')->fetchAll() as $r) {
    $appliedMap[(string) $r['file']] = $r;
}

$rows = [];
$pending = 0;
foreach ($migrationFiles as $file) {
    $base = basename($file);
    if (isset($appliedMap[$base])) {
        $rows[] = ['file' => $base, 'status' => 'ok', 'applied_at' => (string) $appliedMap[$base]['applied_at'], 'commit' => (string) ($appliedMap[$base]['commit_hash'] ?? '')];
    } else {
        $rows[] = ['file' => $base, 'status' => 'pending', 'applied_at' => '', 'commit' => ''];
        $pending++;
    }
}
$total = count($rows);
$applied = $total - $pending;
?>
<?php
require_once __DIR__ . '/layout.php';
admin_layout_start('Veritabanı Migration Durumu', 'migration-durumu');
?>

<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:14px;margin-bottom:24px">
    <div class="sui-card" style="padding:16px">
        <div style="font-size:12px;color:var(--sui-muted);font-weight:600">Toplam Migration</div>
        <div style="font-size:24px;font-weight:800;margin-top:4px"><?= (int)$total ?></div>
    </div>
    <div class="sui-card" style="padding:16px">
        <div style="font-size:12px;color:var(--sui-success);font-weight:600">Uygulanan</div>
        <div style="font-size:24px;font-weight:800;color:var(--sui-success);margin-top:4px"><?= (int)$applied ?></div>
    </div>
    <div class="sui-card" style="padding:16px">
        <div style="font-size:12px;color:var(--sui-warning);font-weight:600">Bekleyen</div>
        <div style="font-size:24px;font-weight:800;color:var(--sui-warning);margin-top:4px"><?= (int)$pending ?></div>
    </div>
    <div class="sui-card" style="padding:16px">
        <div style="font-size:12px;color:var(--sui-muted);font-weight:600">Legacy Atlanan</div>
        <div style="font-size:24px;font-weight:800;margin-top:4px"><?= (int)$legacyCount ?></div>
    </div>
</div>

<?php if ($message): ?><div class="sui-alert sui-alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="sui-alert sui-alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="sui-card" style="margin-bottom:24px">
    <div class="sui-card-header">
        <div>
            <h2 class="sui-card-title">🐘 PostgreSQL Migration Listesi (<?= (int)$total ?>)</h2>
            <p style="color:var(--sui-muted);font-size:13px;margin:4px 0 0 0">
                PostgreSQL uyumlu SQL dosyalarının <code>schema_migrations</code> üzerindeki kayıtları.
            </p>
        </div>
        <form method="post" style="margin:0">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
            <button class="sui-btn <?= $pending > 0 ? 'sui-btn-success' : 'sui-btn-outline' ?>" <?= $pending === 0 ? 'disabled style="opacity:.6;cursor:not-allowed"' : '' ?>>
                ▶ Bekleyenleri Uygula <?= $pending > 0 ? "($pending)" : '' ?>
            </button>
        </form>
    </div>

    <div style="overflow-x:auto">
        <table class="sui-table">
            <thead>
                <tr>
                    <th>Durum</th>
                    <th>Migration Dosyası</th>
                    <th>Uygulanma Zamanı</th>
                    <th>Git Commit</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr style="<?= $r['status'] === 'pending' ? 'background:#fff8e1' : '' ?>">
                        <td>
                            <span class="sui-badge <?= $r['status'] === 'ok' ? 'sui-badge-success' : 'sui-badge-warning' ?>">
                                <?= $r['status'] === 'ok' ? '✓ Uygulandı' : '⏳ Bekliyor' ?>
                            </span>
                        </td>
                        <td><code style="font-size:12px"><?= htmlspecialchars($r['file']) ?></code></td>
                        <td style="font-size:12px;color:var(--sui-muted);white-space:nowrap">
                            <?= $r['applied_at'] !== '' ? htmlspecialchars(mb_substr($r['applied_at'], 0, 19)) : '—' ?>
                        </td>
                        <td>
                            <?php if ($r['commit'] !== ''): ?>
                                <code><?= substr($r['commit'], 0, 7) ?></code>
                            <?php else: ?>
                                <span style="color:var(--sui-muted)">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($applyOutput !== ''): ?>
    <div class="sui-card">
        <div class="sui-card-header">
            <h2 class="sui-card-title">🖥️ Uygulama Çıktısı</h2>
        </div>
        <pre style="background:var(--sui-bg);padding:12px;border-radius:6px;font-size:12px;white-space:pre-wrap;max-height:300px;overflow-y:auto;border:1px solid var(--sui-border)"><?= htmlspecialchars(mb_substr($applyOutput, -6000)) ?></pre>
    </div>
<?php endif; ?>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>

