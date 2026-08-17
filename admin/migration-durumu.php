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
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Migration durumu | NEXUS Admin</title>
  <style>
    body{margin:0;font-family:Arial;background:#f7f7f2;color:#10211f}.w{width:min(1080px,calc(100% - 32px));margin:35px auto}.c{background:#fff;border:1px solid #ddd;padding:18px;margin:15px 0}table{width:100%;border-collapse:collapse;margin-top:10px}th,td{border-bottom:1px solid #e1e5de;padding:9px 10px;text-align:left;vertical-align:top;font-size:13px}th{font-size:12px;text-transform:uppercase;color:#64716d}code{background:#f2f4ef;padding:2px 6px;font-size:12px}pre{background:#f2f4ef;padding:10px;font-size:12px;white-space:pre-wrap;margin:10px 0 0;max-height:380px;overflow:auto}.ok{color:#0d7a4a;font-weight:700}.er{color:#b0301a;font-weight:700}.pen{color:#a86026;font-weight:700}.stats{display:flex;gap:10px;flex-wrap:wrap}.stat{background:#fff;border:1px solid #ddd;padding:12px 16px;min-width:130px}.stat span{font-size:11px;text-transform:uppercase;color:#64716d}.stat b{display:block;font-size:20px;margin-top:3px}.stat.warn b{color:#a86026}.stat.danger b{color:#b0301a}.muted{color:#64716d;font-size:13px}.notice{background:#e6f8c7;padding:10px}.error{background:#ffe2de;padding:10px}.btn{background:#10211f;color:#fff;border:0;padding:11px 16px;font-weight:bold;font-size:13px;cursor:pointer}tr.pending{background:#fff6ef}tr.pending td{color:#7a4a12}
  </style>
</head>
<body>
<main class="w"><a href="/nexustraveltech/admin/">← Panel</a> &nbsp; <a href="/nexustraveltech/admin/zamanlayici-gecmisi">← Zamanlayıcı geçmişi</a>
<h1>Migration durumu</h1>
<p class="muted">Database migration dosyalarının (<code>*-postgres.sql</code>) uygulanma durumu. Kayıtlar <code>schema_migrations</code> tablosunda tutulur; commit sütunu migration'ın uygulandığı git commit'ini gösterir. Legacy MySQL dosyaları (<code>*-postgres.sql</code> olmayan) atlanır.</p>

<div class="stats">
  <div class="stat"><span>Toplam migration</span><b><?= (int)$total ?></b></div>
  <div class="stat"><span>Uygulanan</span><b style="color:#0d7a4a"><?= (int)$applied ?></b></div>
  <div class="stat <?= $pending > 0 ? 'warn' : '' ?>"><span>Bekleyen</span><b><?= (int)$pending ?></b></div>
  <div class="stat"><span>Legacy atlanan</span><b><?= (int)$legacyCount ?></b></div>
</div>

<?php if ($message): ?><p class="notice"><?= htmlspecialchars($message) ?></p><?php endif; ?>
<?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

<section class="c">
<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
<h2 style="margin:0">Migration listesi (<?= (int)$total ?>)</h2>
<form method="post" action="/nexustraveltech/admin/migration-durumu" style="display:inline">
  <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
  <button class="btn" <?= $pending === 0 ? 'disabled style="opacity:.5;cursor:not-allowed"' : '' ?>>▶ Bekleyenleri uygula<?= $pending > 0 ? ' (' . (int)$pending . ')' : '' ?></button>
</form>
</div>
<?php if ($pending > 0): ?><p class="muted" style="margin:8px 0 0">Bekleyen migration'lar sağlık kontrolü ile idempotent uygulanır (script'le aynı mantık). Sunucuda komut satırından da çalıştırabilirsiniz: <code>php scripts/health-check.php</code></p><?php endif; ?>
<table>
<tr><th>Durum</th><th>Dosya</th><th>Uygulanma</th><th>Commit</th></tr>
<?php foreach ($rows as $r): ?>
<tr class="<?= $r['status'] === 'pending' ? 'pending' : '' ?>">
  <td style="white-space:nowrap"><?= $r['status'] === 'ok' ? '<span class="ok">✓ Uygulandı</span>' : '<span class="pen">⏳ Bekliyor</span>' ?></td>
  <td><code><?= htmlspecialchars($r['file']) ?></code></td>
  <td style="white-space:nowrap"><?= $r['applied_at'] !== '' ? htmlspecialchars(mb_substr($r['applied_at'], 0, 19)) : '—' ?></td>
  <td><code><?= $r['commit'] !== '' ? substr($r['commit'], 0, 7) : '—' ?></code></td>
</tr>
<?php endforeach; ?>
</table>
</section>

<?php if ($applyOutput !== ''): ?>
<section class="c">
<h2 style="margin:0">Uygulama çıktısı</h2>
<pre><?= htmlspecialchars(mb_substr($applyOutput, -6000)) ?></pre>
</section>
<?php endif; ?>
</main>
<?php require_once __DIR__.'/../config/ai_widget.php'; ai_widget('/nexustraveltech/admin/ai-chat','admin_csrf'); ?></body>
</html>
