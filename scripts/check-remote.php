<?php
/**
 * Yerel sunucu ile GitHub (origin/main) arasındaki commit farkını raporlar.
 *
 * Kullanım:
 *   php scripts/check-remote.php
 *   php scripts/check-remote.php --json
 *   php scripts/check-remote.php --pull   # fetch + reset --hard yapar
 */

declare(strict_types=1);

$jsonMode = in_array('--json', $argv, true);
$doPull = in_array('--pull', $argv, true);

function run(string $cmd): string {
    $out = [];
    $rc = 0;
    exec($cmd . ' 2>&1', $out, $rc);
    return implode("\n", $out);
}

function out(string $msg): void {
    global $jsonMode;
    if (!$jsonMode) echo $msg . "\n";
}

// ─── 0) Git var mı? ───
$gitDir = run('git rev-parse --show-toplevel 2>/dev/null');
if (strpos($gitDir, 'fatal') !== false || $gitDir === '') {
    if ($jsonMode) {
        echo json_encode(['error' => 'Git repository bulunamadı']) . "\n";
    } else {
        echo "✗ Git repository bulunamadı\n";
    }
    exit(1);
}
out("═══ Remote durum kontrolü ═══");
out("📁 Repo: " . trim($gitDir));
out("");

// ─── 1) Fetch ───
out("── 1) origin/main fetch ──");
$fetchOut = run('git fetch origin main --prune 2>&1');
out("  " . str_replace("\n", "\n  ", trim($fetchOut)));
out("");

// ─── 2) Local vs Remote ───
out("── 2) Local vs origin/main ──");

$localHash = trim(run('git rev-parse HEAD'));
$remoteHash = trim(run('git rev-parse origin/main'));
$baseHash = trim(run('git merge-base HEAD origin/main 2>/dev/null'));

$localShort = substr($localHash, 0, 7);
$remoteShort = substr($remoteHash, 0, 7);

out("  Local:      {$localShort} " . trim(run('git log --oneline -1 HEAD')));
out("  Origin/main: {$remoteShort} " . trim(run('git log --oneline -1 origin/main')));
out("");

// ─── 3) Fark analizi ───
$localAhead = 0;
$remoteAhead = 0;
$localOnly = [];
$remoteOnly = [];

if ($localHash === $remoteHash) {
    out("  ✓ Local ve origin/main aynı noktada");
} else {
    // Local'de olup remote'ta olmayan (push edilmemiş)
    $localAhead = (int) trim(run("git rev-list --count origin/main..HEAD 2>/dev/null"));
    if ($localAhead > 0) {
        $localOnly = explode("\n", trim(run("git log --oneline origin/main..HEAD")));
        out("  ⬆ Local'de {$localAhead} commit (push edilmemiş):");
        foreach ($localOnly as $c) out("    + {$c}");
    }

    // Remote'ta olup local'de olmayan (çekilmemiş)
    $remoteAhead = (int) trim(run("git rev-list --count HEAD..origin/main 2>/dev/null"));
    if ($remoteAhead > 0) {
        $remoteOnly = explode("\n", trim(run("git log --oneline HEAD..origin/main")));
        out("  ⬇ Origin'de {$remoteAhead} commit (çekilmemiş):");
        foreach ($remoteOnly as $c) out("    - {$c}");
    }

    if ($localAhead === 0 && $remoteAhead === 0) {
        out("  ⚠ Fark var ama biri diğerinin atası (diverged?)");
    }
}
out("");

// ─── 4) Son 5 commit karşılaştırma ───
out("── 3) Son 5 commit (her iki taraf) ──");
out("  LOCAL:");
$localLog = explode("\n", trim(run('git log --oneline -5 HEAD')));
foreach ($localLog as $c) out("    {$c}");
out("  REMOTE:");
$remoteLog = explode("\n", trim(run('git log --oneline -5 origin/main')));
foreach ($remoteLog as $c) out("    {$c}");
out("");

// ─── 5) Branch durumu ───
out("── 4) Branch durumu ──");
$currentBranch = trim(run('git branch --show-current'));
out("  Branch: {$currentBranch}");
$upstream = trim(run("git rev-parse --abbrev-ref @{upstream} 2>/dev/null"));
if ($upstream) {
    out("  Upstream: {$upstream}");
} else {
    out("  Upstream: ayarlanmamış");
}
out("");

// ─── 6) Working tree ───
out("── 5) Working tree ──");
$status = trim(run('git status --porcelain'));
if ($status === '') {
    out("  ✓ Working tree temiz (değişiklik yok)");
} else {
    $dirty = explode("\n", $status);
    out("  ⚠ " . count($dirty) . " değişiklik:");
    foreach (array_slice($dirty, 0, 10) as $d) out("    {$d}");
    if (count($dirty) > 10) out("    … ve " . (count($dirty) - 10) . " daha");
}
out("");

// ─── 7) Öneri ───
out("── 6) Öneri ──");
if ($localHash === $remoteHash) {
    out("  ✅ Sunucu güncel — change yok");
} elseif ($remoteAhead > 0 && $localAhead === 0) {
    out("  ⚠ Sunucu geride — \"git pull\" önerilir");
    out("    Komut: git fetch origin main && git reset --hard origin/main");
} elseif ($localAhead > 0 && $remoteAhead === 0) {
    out("  ⚠ Local'de push edilmemiş commit var — \"git push\" önerilir");
} else {
    out("  ⚠ Farklı dallar — manuel çözüm gerekebilir");
}
out("");

// ─── SONUÇ ───
$result = [
    'local_hash' => $localShort,
    'remote_hash' => $remoteShort,
    'local_ahead' => $localAhead,
    'remote_ahead' => $remoteAhead,
    'same' => $localHash === $remoteHash,
    'branch' => $currentBranch,
    'dirty' => $status !== '',
    'local_commits' => $localOnly,
    'remote_commits' => $remoteOnly,
];

if ($jsonMode) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    out("═══════════════════════════════════════");
    if ($result['same']) {
        out(" ✅ GÜNCEL — local ve origin/main aynı");
    } else {
        $total = $localAhead + $remoteAhead;
        out(" 📊 FARK — local +{$localAhead} / remote +{$remoteAhead} (toplam {$total} commit)");
    }
    out("═══════════════════════════════════════");
}

// ─── --pull modu ───
if ($doPull && $remoteAhead > 0) {
    out("\n── --pull: origin/main'e eşitleniyor ──");
    $pullOut = run('git fetch origin main && git reset --hard origin/main 2>&1');
    out($pullOut);
    $newLocal = substr(trim(run('git rev-parse HEAD')), 0, 7);
    out("  ✅ Şimdi: {$newLocal}");
}

exit($remoteAhead > 0 ? 1 : 0);
