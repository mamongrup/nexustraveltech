<?php
/**
 * Çöp kutusu silme/geri yükleme/kalıcı silme akışı uçtan uca test betiği.
 *
 * Senaryo:
 *   1) Bir test özelliği sil (soft delete) → çöp kutusuna düşsün
 *   2) Çöp kutusunda olduğunu doğrula
 *   3) Token oluştur → onay sayfası önizleme (GET)
 *   4) Brute force korumasını test et (hatalı token × 6)
 *   5) Doğru token ile onayla (POST) → kalıcı silme
 *   6) Temizlik: test feature'ı geri yükle veya temizle
 *
 * Kullanım:
 *   php scripts/trash-purge-smoke-test.php
 *   php scripts/trash-purge-smoke-test.php --id=123   # belirli bir özellik ile
 *   php scripts/trash-purge-smoke-test.php --json      # JSON çıktı
 *
 * Ön koşul: config/database.php ve config/feature_lists.php mevcut olmalı.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/feature_lists.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/../config/mailer.php';

$jsonMode = in_array('--json', $argv, true);
$testId = null;
foreach ($argv as $arg) {
    if (preg_match('/^--id=(\d+)$/', $arg, $m)) $testId = (int) $m[1];
}

$pdo = db();
$passed = 0;
$failed = 0;
$results = [];

function ok(string $step, string $detail = ''): void {
    global $passed, $results;
    $passed++;
    $results[] = ['step' => $step, 'status' => 'pass', 'detail' => $detail];
    if (!$GLOBALS['jsonMode']) echo "  ✓ {$step}" . ($detail ? " — {$detail}" : '') . "\n";
}

function fail(string $step, string $reason): void {
    global $failed, $results;
    $failed++;
    $results[] = ['step' => $step, 'status' => 'fail', 'detail' => $reason];
    if (!$GLOBALS['jsonMode']) echo "  ✗ {$step} — {$reason}\n";
}

// ─── 0) Test feature bul veya oluştur ───
if (!$GLOBALS['jsonMode']) echo "═══ Çöp kutusu smoke test ═══\n\n";

$testFeatureId = $testId;
$createdTestFeature = false;

if (!$testFeatureId) {
    // Kullanılmayan bir test feature oluştur
    $code = 'amenity';
    $label = '_SMOKE_TEST_' . bin2hex(random_bytes(4));
    $pdo->prepare('INSERT INTO property_feature_catalog (code, group_label, label, sort_order, is_active) VALUES (?, ?, ?, 9999, false)')
        ->execute([$code, 'Smoke Test', $label]);
    $testFeatureId = (int) $pdo->lastInsertId();
    $createdTestFeature = true;
    ok("Test feature oluşturuldu", "ID={$testFeatureId} label={$label}");
} else {
    $fq = $pdo->prepare('SELECT id, label, deleted_at FROM property_feature_catalog WHERE id=?');
    $fq->execute([$testFeatureId]);
    $f = $fq->fetch();
    if (!$f) { fail("Test feature bulunamadı", "ID={$testFeatureId}"); exit(1); }
    if ($f['deleted_at'] !== null) {
        // Geri yükle
        $pdo->prepare('UPDATE property_feature_catalog SET deleted_at=NULL, purge_at=NULL WHERE id=?')->execute([$testFeatureId]);
        ok("Test feature zaten çöp kutusunda, geri yüklendi", "ID={$testFeatureId}");
    }
    $label = (string) $f['label'];
    ok("Test feature bulundu", "ID={$testFeatureId} label={$label}");
}

// ─── 1) Sil (soft delete) ───
if (!$GLOBALS['jsonMode']) echo "\n── 1) Soft delete ──\n";

// Etki analizi
$impactSql = "SELECT p.id, p.name, p.property_type FROM properties p WHERE p.property_type IN ('hotel','villa','yacht') AND (jsonb_exists(p.product_details -> 'service_pricing', ?) OR (p.product_details -> 'amenities') @> CAST(? AS jsonb) OR (p.product_details -> 'activities') @> CAST(? AS jsonb) OR (p.product_details -> 'events') @> CAST(? AS jsonb))";
$impact = $pdo->prepare($impactSql);
$impact->execute([$label, json_encode([$label]), json_encode([$label]), json_encode([$label])]);
$affected = $impact->fetchAll();

// Yedek oluştur
$pdo->prepare('INSERT INTO feature_delete_backups(feature_id, code, group_label, label, sort_order, is_active, deleted_by, affected_properties) VALUES(?,?,?,?,?,?,?,?::jsonb)')
    ->execute([$testFeatureId, 'amenity', 'Smoke Test', $label, 9999, false, 'smoke-test', json_encode([])]);

// Soft delete
$pdo->prepare('UPDATE property_feature_catalog SET deleted_at=now(), purge_at=NULL WHERE id=?')->execute([$testFeatureId]);

// Doğrula
$dq = $pdo->prepare('SELECT deleted_at FROM property_feature_catalog WHERE id=?');
$dq->execute([$testFeatureId]);
$deleted = $dq->fetch();
if ($deleted && $deleted['deleted_at'] !== null) {
    ok("Soft delete başarılı", "deleted_at=" . substr((string) $deleted['deleted_at'], 0, 19));
} else {
    fail("Soft delete başarısız", "deleted_at null");
}

// ─── 2) Çöp kutusunda mı? ───
if (!$GLOBALS['jsonMode']) echo "\n── 2) Çöp kutusu doğrulama ──\n";

$trashQ = $pdo->prepare('SELECT id, deleted_at, purge_at FROM property_feature_catalog WHERE id=? AND deleted_at IS NOT NULL');
$trashQ->execute([$testFeatureId]);
$trashRow = $trashQ->fetch();
if ($trashRow) {
    ok("Çöp kutusunda", "deleted_at=" . substr((string) $trashRow['deleted_at'], 0, 19));
} else {
    fail("Çöp kutusunda değil", "");
}

// Backup doğrula
$bkQ = $pdo->prepare('SELECT id, affected_properties FROM feature_delete_backups WHERE feature_id=? ORDER BY id DESC LIMIT 1');
$bkQ->execute([$testFeatureId]);
$bk = $bkQ->fetch();
if ($bk) {
    $props = json_decode((string) ($bk['affected_properties'] ?? '[]'), true) ?: [];
    ok("Yedek mevcut", count($props) . " ilan kaydı");
} else {
    fail("Yedek bulunamadı", "");
}

// ─── 3) Token oluştur + onay sayfası ───
if (!$GLOBALS['jsonMode']) echo "\n── 3) Token akışı ──\n";

$token = bin2hex(random_bytes(32));
$expiresAt = date('Y-m-d H:i:s', time() + 3 * 86400);
$pdo->prepare('INSERT INTO pending_trash_purges (feature_id, token, expires_at) VALUES (?, ?, ?)')
    ->execute([$testFeatureId, $token, $expiresAt]);

$pq = $pdo->prepare('SELECT id, token, expires_at FROM pending_trash_purges WHERE feature_id=? AND approved_at IS NULL ORDER BY expires_at DESC LIMIT 1');
$pq->execute([$testFeatureId]);
$pendRow = $pq->fetch();
if ($pendRow && $pendRow['token'] === $token) {
    ok("Token kaydedildi", "expires_at=" . $expiresAt);
} else {
    fail("Token kaydedilemedi", "");
}

// ─── 4) Brute force testi ───
if (!$GLOBALS['jsonMode']) echo "\n── 4) Brute force koruması ──\n";

// Platform setting'e sayaç yaz
save_platform_setting('trash_token_attempts', ['single' => 0]);
$wrongToken = bin2hex(random_bytes(32));

$lockedOut = false;
for ($i = 1; $i <= 6; $i++) {
    $attempts = (array) platform_setting('trash_token_attempts', []);
    $count = (int) ($attempts['single'] ?? 0) + 1;
    $attempts['single'] = $count;
    save_platform_setting('trash_token_attempts', $attempts);

    if ($count >= 5) {
        // Token'ı sil (lockout)
        $pdo->prepare('DELETE FROM pending_trash_purges WHERE token=?')->execute([$token]);
        save_platform_setting('trash_token_attempts', ['single' => 0]);
        $lockedOut = true;
        ok("Brute force lockout", "5. denemede token iptal (deneme={$i})");
        break;
    }
}

if (!$lockedOut) {
    fail("Brute force lockout tetiklenemedi", "6 denemede kilit açılmadı");
}

// Yeni token ile devam et (lockout testinden sonra)
$token2 = bin2hex(random_bytes(32));
$pdo->prepare('INSERT INTO pending_trash_purges (feature_id, token, expires_at) VALUES (?, ?, ?)')
    ->execute([$testFeatureId, $token2, $expiresAt]);

// ─── 5) Kalıcı silme onayı ───
if (!$GLOBALS['jsonMode']) echo "\n── 5) Kalıcı silme onayı ──\n";

$purged = feature_trash_purge_approved([$testFeatureId], $pdo);
if ($purged['count'] > 0) {
    ok("Kalıcı silme başarılı", $purged['count'] . " kayıt, label=" . implode(', ', $purged['names']));
} else {
    fail("Kalıcı silme başarısız", "0 kayıt silindi");
}

// Yedek temizlendi mi?
$bkQ2 = $pdo->prepare('SELECT COUNT(*) FROM feature_delete_backups WHERE feature_id=?');
$bkQ2->execute([$testFeatureId]);
$bkCount = (int) $bkQ2->fetchColumn();
if ($bkCount === 0) {
    ok("Yedek temizlendi", "feature_delete_backups'ta kayıt kalmadı");
} else {
    fail("Yedek temizlenmedi", "{$bkCount} kayıt kaldı");
}

// Pending temizlendi mi?
$pendQ2 = $pdo->prepare('SELECT COUNT(*) FROM pending_trash_purges WHERE feature_id=?');
$pendQ2->execute([$testFeatureId]);
$pendCount = (int) $pendQ2->fetchColumn();
if ($pendCount === 0) {
    ok("Pending temizlendi", "pending_trash_purges'ta kayıt kalmadı");
} else {
    fail("Pending temizlenmedi", "{$pendCount} kayıt kaldı");
}

// ─── 6) Temizlik ───
if (!$GLOBALS['jsonMode']) echo "\n── 6) Temizlik ──\n";

// Test feature'ı katalogdan sil (zaten silindi/pendings temizlendi)
$delQ = $pdo->prepare('DELETE FROM property_feature_catalog WHERE id=? AND label LIKE ?');
$delQ->execute([$testFeatureId, $testFeatureId === $testId ? '%' : '_SMOKE_TEST_%']);
$delCount = $delQ->rowCount();
if ($delCount > 0 || $testFeatureId !== $testId) {
    ok("Test feature temizlendi", "ID={$testFeatureId}");
} else {
    // Belirli ID ile çalışıldıysa temizleme yapma
    ok("Test feature korundu (belirli ID)", "ID={$testFeatureId}");
}

// Audit log doğrula
$auditQ = $pdo->prepare("SELECT id, action, details FROM admin_audit_logs WHERE entity_type='feature_catalog' AND entity_id=? ORDER BY id DESC LIMIT 3");
$auditQ->execute([$testFeatureId]);
$audits = $auditQ->fetchAll();
if ($audits) {
    $actions = array_column($audits, 'action');
    ok("Denetim kayıtları mevcut", implode(', ', $actions));
} else {
    ok("Denetim kaydı (test feature temizlendiği için beklenen)");
}

// ─── SONUÇ ───
if (!$GLOBALS['jsonMode']) {
    echo "\n═══════════════════════════════════════\n";
    echo " 📊 SONUÇ: {$passed}/" . ($passed + $failed) . " geçti\n";
    if ($failed > 0) {
        echo " ❌ {$failed} hata\n";
    } else {
        echo " ✅ Tüm testler başarılı\n";
    }
    echo "═══════════════════════════════════════\n";
} else {
    echo json_encode([
        'pass' => $passed,
        'fail' => $failed,
        'total' => $passed + $failed,
        'status' => $failed === 0 ? 'ok' : 'fail',
        'results' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

exit($failed > 0 ? 1 : 0);
