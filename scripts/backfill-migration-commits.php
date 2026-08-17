<?php
declare(strict_types=1);

// Geriye dönük commit doldurma — eski schema_migrations satırlarına commit_hash'i git
// geçmişinden tahmin ederek yazar. (Migration sayfasındaki "Commit" sütununu eski
// kayıtlar için de doldurur.)
//
// Mantık: her satırın applied_at tarihi vardır; migration dosyasına dokunan git commit'leri
// (en yeni → en eski) sıralanır ve applied_at'ten ÖNCEKİ en yeni commit seçilir — migration
// o commit çekildiğinde uygulandığı varsayılır. Hiçbir commit applied_at'ten önce değilse
// (dosya sonradan eklenmiş / tarih kayması) dosyayı İLK tanıtan en eski commit kullanılır.
//
// Kullanım (sunucuda, repo kökünde):
//   php scripts/backfill-migration-commits.php            → doldurur
//   php scripts/backfill-migration-commits.php --dry-run  → yalnızca önizleme, yazmaz

require_once __DIR__ . '/../config/database.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$pdo = db();

// Repo kökü — bu dosyanın iki üst dizini (sunucuda httpdocs).
$repo = dirname(__DIR__);
$migDir = 'database/migrations';

try {
    $rows = $pdo->query("SELECT id, file, applied_at FROM schema_migrations WHERE commit_hash IS NULL OR commit_hash = '' ORDER BY id")->fetchAll();
} catch (Throwable $e) {
    echo "schema_migrations tablosu okunamadı: " . $e->getMessage() . " (migration'lar henüz uygulanmamış olabilir)\n";
    exit(0);
}

if (!$rows) {
    echo "Doldurulacak satır yok — tüm schema_migrations kayıtları commit_hash içeriyor.\n";
    exit(0);
}
echo count($rows) . " satır commit_hash bekliyor.\n\n";

$updated = 0;
$skipped = 0;
$usedFallback = 0;

foreach ($rows as $row) {
    $id = (int) $row['id'];
    $file = (string) $row['file'];
    $appliedTs = (int) strtotime((string) $row['applied_at']);

    $path = $migDir . '/' . $file;
    // %x09 = tab ayracı (boşluk/backslash platform sorunlarına karşı); dosya yoksa stderr'e
    // düşen hata $out'u doldurmaz, aşağıda "bulunamadı" yolu tetiklenir.
    $cmd = 'git -C ' . escapeshellarg($repo) . ' log --format=%H%x09%ct -- ' . escapeshellarg($path);
    $out = [];
    $exit = 0;
    exec($cmd, $out, $exit);
    if ($exit !== 0 || $out === []) {
        $skipped++;
        echo "· #{$id} {$file} — git geçmişinde bulunamadı (dosya yok / force-push), atlandı\n";
        continue;
    }

    $commits = [];
    foreach ($out as $line) {
        $parts = preg_split('/\s+/', trim($line), 2);
        if (count($parts) !== 2) continue;
        $commits[] = ['hash' => $parts[0], 'ts' => (int) $parts[1]];
    }
    if ($commits === []) {
        $skipped++;
        echo "· #{$id} {$file} — commit satırı ayrıştırılamadı, atlandı\n";
        continue;
    }

    // applied_at'ten önceki EN YENİ commit; yoksa dosyayı tanıtan en eski commit (fallback).
    $pick = null;
    foreach ($commits as $c) {
        if ($c['ts'] <= $appliedTs) {
            $pick = $c;
            break;
        }
    }
    if ($pick === null) {
        $pick = $commits[count($commits) - 1];
        $usedFallback++;
    }

    $when = date('Y-m-d H:i', $pick['ts']);
    if ($dryRun) {
        echo "→ #{$id} {$file}: {$pick['hash']} ({$when})" . ($usedFallback ? ' [fallback: dosyayı tanıtan en eski]' : '') . "\n";
        continue;
    }
    $pdo->prepare('UPDATE schema_migrations SET commit_hash=? WHERE id=?')->execute([$pick['hash'], $id]);
    $updated++;
    echo "✓ #{$id} {$file}: {$pick['hash']} ({$when})" . ($usedFallback ? ' [fallback: dosyayı tanıtan en eski]' : '') . "\n";
}

echo "\nÖzet: " . ($dryRun ? '[dry-run] ' : '') . $updated . " güncellendi, " . $skipped . " atlandı (git geçmişinde yok)" . ($usedFallback > 0 ? ', ' . $usedFallback . ' fallback (en eski commit) kullanıldı' : '') . ".\n";
echo "Doğrulama: SELECT file, commit_hash FROM schema_migrations WHERE commit_hash IS NOT NULL AND commit_hash <> '' ORDER BY id;\n";
exit($dryRun ? 0 : ($updated > 0 || $skipped === 0 ? 0 : 1));
