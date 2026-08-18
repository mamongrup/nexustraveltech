<?php
declare(strict_types=1);

// Çöp kutusu "son şans" onay sayfası — İKİ ADIMLI:
//   Adım 1 (GET): e-posta bağlantısı tıklanınca özellik(ler) ve ilan etkisi özetlenir,
//                 hiçbir şey silinmez (e-posta önizleme botları linki tıklasa bile).
//   Adım 2 (POST): "Evet, kalıcı sil" butonu onayı uygular (anında kalıcı silme).
// Tek kullanımlık 64 hex token (3 gün); onay, feature_trash_purge_approved paylaşılan
// fonksiyonuyla yedek + katalog satırlarını kalıcı olarak temizler.
// ?bulk_token=... ile tüm bekleyen özellikler toplu onaylanabilir (aynı iki adımlı akış).

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/feature_lists.php';
require_once __DIR__ . '/../config/audit.php';

$token = (string) ($_GET['token'] ?? '');
$bulkToken = (string) ($_GET['bulk_token'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$out = '';
$ok = false;
$confirm = null; // ['type' => 'single'|'bulk', 'token' => ..., ...]
$sectionTitles = ['villa' => 'Villa özellikleri', 'yacht' => 'Yat özellikleri', 'amenity' => 'Otel olanakları', 'activity' => 'Otel aktiviteleri', 'event' => 'Otel etkinlikleri'];
// Brute force koruması: hatalı token denemesi sayacı (platform ayarında tutulur).
$TOKEN_MAX_ATTEMPTS = 5;
$tokenAttempts = function (string $key, bool $increment = false): int {
    $data = (array) platform_setting('trash_token_attempts', []);
    $count = (int) ($data[$key] ?? 0);
    if ($increment) {
        $count++;
        $data[$key] = $count;
        save_platform_setting('trash_token_attempts', $data);
    }
    return $count;
};
$tokenResetAttempts = function (string $key): void {
    $data = (array) platform_setting('trash_token_attempts', []);
    unset($data[$key]);
    save_platform_setting('trash_token_attempts', $data);
};
$tokenInvalidateBulk = function (): void {
    save_platform_setting('trash_bulk_approve', []);
};

if ($bulkToken !== '') {
    if (!preg_match('/^[a-f0-9]{64}$/', $bulkToken)) {
        http_response_code(404);
        $out = 'Geçersiz bağlantı.';
    } else {
        $pdo = db();
        $stored = (array) platform_setting('trash_bulk_approve', []);
        $storedToken = (string) ($stored['token'] ?? '');
        $expires = (string) ($stored['expires_at'] ?? '');
        if ($storedToken === '' || !hash_equals($storedToken, $bulkToken)) {
            $attempts = $tokenAttempts('bulk', true);
            if ($attempts >= $TOKEN_MAX_ATTEMPTS) {
                $tokenInvalidateBulk();
                $tokenResetAttempts('bulk');
                audit_log('feature.token_lockout', 'platform_setting', null, ['type' => 'bulk', 'attempts' => $attempts]);
                $out = 'Çok fazla hatalı deneme (' . $attempts . ') — toplu onay bağlantısı iptal edildi. Yeni temizlik taraması e-postayı yeniden gönderecek.';
            } else {
                $out = 'Bu toplu onay bağlantısı geçersiz veya zaten kullanıldı.' . ($attempts > 1 ? ' <small style="color:#6b7774">(' . $attempts . '/' . $TOKEN_MAX_ATTEMPTS . ' deneme)</small>' : '');
            }
        } elseif ($expires === '' || strtotime($expires) < time()) {
            $out = 'Bu toplu onay bağlantısının süresi doldu — yeni temizlik taraması e-postayı yeniden gönderecek.';
        } else {
            $pending = $pdo->query("SELECT p.feature_id, f.label, f.code, f.deleted_at, f.purge_at, COALESCE((SELECT jsonb_array_length(b.affected_properties) FROM feature_delete_backups b WHERE b.feature_id = f.id ORDER BY b.id DESC LIMIT 1), 0) AS affected_count FROM pending_trash_purges p JOIN property_feature_catalog f ON f.id = p.feature_id WHERE p.approved_at IS NULL AND p.expires_at > now() ORDER BY f.deleted_at")->fetchAll();
            if (!$pending) {
                $out = 'Bekleyen onay yok (özellikler geri yüklenmiş veya tek tek onaylanmış olabilir).';
            } elseif ($method === 'POST') {
                $ids = array_values(array_unique(array_map(fn($r) => (int) $r['feature_id'], $pending)));
                $ttlDays = max(7, (int) platform_setting('feature_trash_ttl_days', 30));
                $purged = feature_trash_purge_approved($ids, $pdo);
                $pdo->prepare('DELETE FROM pending_trash_purges WHERE approved_at IS NULL')->execute();
                save_platform_setting('trash_bulk_approve', []);
                audit_log('feature.trash_purge', 'feature_catalog', null, [
                    'count' => $purged['count'],
                    'ttl_days' => $ttlDays,
                    'labels' => $purged['names'],
                    'bulk_approve' => true,
                ]);
                $out = '✓ ' . count($pending) . ' özellik toplu onaylandı ve kalıcı olarak silindi (' . $purged['count'] . ' kayıt). Bu noktadan sonra geri alınamaz.';
                $ok = true;
            } else {
                $tokenResetAttempts('bulk');
                $confirm = ['type' => 'bulk', 'token' => $bulkToken, 'items' => $pending];
            }
        }
    }
} elseif ($token !== '') {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        http_response_code(404);
        $out = 'Geçersiz bağlantı.';
    } else {
        $pdo = db();
        $q = $pdo->prepare('SELECT * FROM pending_trash_purges WHERE token=?');
        $q->execute([$token]);
        $row = $q->fetch();
        if (!$row) {
            $attempts = $tokenAttempts('single', true);
            if ($attempts >= $TOKEN_MAX_ATTEMPTS) {
                $pdo->prepare('DELETE FROM pending_trash_purges WHERE token=?')->execute([$token]);
                $tokenResetAttempts('single');
                audit_log('feature.token_lockout', 'pending_trash_purge', null, ['type' => 'single', 'attempts' => $attempts]);
                $out = 'Çok fazla hatalı deneme (' . $attempts . ') — onay bağlantısı iptal edildi. Yeni temizlik taraması e-postayı yeniden gönderecek.';
            } else {
                $out = 'Bu onay bağlantısı geçersiz veya zaten kullanıldı.' . ($attempts > 1 ? ' <small style="color:#6b7774">(' . $attempts . '/' . $TOKEN_MAX_ATTEMPTS . ' deneme)</small>' : '');
            }
        } elseif (strtotime((string) $row['expires_at']) < time()) {
            $out = 'Bu onay bağlantısının süresi doldu — yeni temizlik taraması e-postayı yeniden gönderecek.';
        } elseif ($row['approved_at'] !== null) {
            $out = 'Bu onay zaten verilmişti; özellik temizlendi.';
        } else {
            $st = $pdo->prepare('SELECT id, code, label, deleted_at, purge_at FROM property_feature_catalog WHERE id=? AND deleted_at IS NOT NULL');
            $st->execute([(int) $row['feature_id']]);
            $feat = $st->fetch();
            if (!$feat) {
                $out = 'Özellik artık çöp kutusunda değil (geri yüklenmiş veya silinmiş).';
            } elseif ($method === 'POST') {
                $pdo->prepare('UPDATE pending_trash_purges SET approved_at=now() WHERE id=?')->execute([(int) $row['id']]);
                $pdo->prepare('DELETE FROM pending_trash_purges WHERE feature_id=?')->execute([(int) $row['feature_id']]);
                $ttlDays = max(7, (int) platform_setting('feature_trash_ttl_days', 30));
                $purged = feature_trash_purge_approved([(int) $row['feature_id']], $pdo);
                audit_log('feature.trash_purge', 'feature_catalog', (int) $row['feature_id'], [
                    'count' => $purged['count'],
                    'ttl_days' => $ttlDays,
                    'labels' => $purged['names'],
                    'approved_link' => true,
                ]);
                $out = '✓ "' . htmlspecialchars((string) $feat['label']) . '" onaylandı ve kalıcı olarak silindi (' . $purged['count'] . ' kayıt). Bu noktadan sonra geri alınamaz.';
                $ok = true;
            } else {
                $impactQ = $pdo->prepare('SELECT affected_properties FROM feature_delete_backups WHERE feature_id=? ORDER BY id DESC LIMIT 1');
                $impactQ->execute([(int) $row['feature_id']]);
                $bk = $impactQ->fetch();
                $props = $bk ? (json_decode((string) $bk['affected_properties'], true) ?: []) : [];
                $tokenResetAttempts('single');
                $confirm = ['type' => 'single', 'token' => $token, 'feat' => $feat, 'listings' => $props];
            }
        }
    }
} else {
    http_response_code(404);
    $out = 'Geçersiz bağlantı.';
}

// Kalıcı silme tarihi (özel tarih veya silinme + TTL) — özet ekranı için ortak yardımcı.
$effPurge = function (array $feat) {
    $ttlDays = max(7, (int) platform_setting('feature_trash_ttl_days', 30));
    $delTs = strtotime((string) ($feat['deleted_at'] ?? '')) ?: time();
    $custom = !empty($feat['purge_at']);
    $purgeTs = $custom ? (strtotime((string) $feat['purge_at']) ?: 0) : 0;
    if ($purgeTs <= 0) $purgeTs = $delTs + $ttlDays * 86400;
    return ['date' => date('Y-m-d', $purgeTs), 'days' => max(0, (int) ceil(($purgeTs - time()) / 86400)), 'custom' => $custom];
};
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Çöp kutusu onayı | NEXUS Admin</title><style>body{font-family:Arial;background:#f7f7f2;color:#10211f;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center}.card{background:#fff;border:1px solid #e1e5de;border-radius:10px;padding:28px 32px;max-width:560px;width:calc(100% - 48px);box-shadow:0 4px 18px rgba(0,0,0,.05)}h1{font-size:20px;margin:0 0 10px}.ok{background:#e6f8c7;padding:12px;border-radius:6px}.no{background:#ffe2de;padding:12px;border-radius:6px}.warn{background:#fff3cd;border:1px solid #e0c9a3;border-radius:8px;padding:12px;margin:14px 0}.warn ul{margin:8px 0 0;padding-left:18px}.back{display:inline-block;margin-top:14px;color:#10211f}.danger{background:#9d3b1c;color:#fff;border:0;padding:12px 18px;font-size:15px;font-weight:bold;border-radius:6px;cursor:pointer;margin-right:10px}button{cursor:pointer}</style></head><body><div class="card">
<?php if ($confirm !== null && $confirm['type'] === 'single'): $f = $confirm['feat']; $pu = $effPurge($f); ?>
<h1>🗑 Kalıcı silme onayı</h1>
<div class="warn"><b>Özellik:</b> <?= htmlspecialchars((string) $f['label']) ?> <small style="color:#6b7774">(<?= htmlspecialchars($sectionTitles[$f['code']] ?? (string) $f['code']) ?> · silindi <?= htmlspecialchars(mb_substr((string) $f['deleted_at'], 0, 10)) ?> · kalıcı silme <?= htmlspecialchars($pu['date']) ?><?= $pu['custom'] ? ' · özel tarih' : '' ?>)</small>
<?php if ($confirm['listings']): ?><p style="margin:10px 0 4px">Bu özellik <b><?= count($confirm['listings']) ?> ilandan</b> kaldırılmış durumda. Onaylarsanız <b style="color:#9d3b1c">kalıcı olarak silinir ve geri alınamaz</b>.</p><ul><?php foreach (array_slice($confirm['listings'], 0, 10) as $p): ?><li><?= htmlspecialchars((string) ($p['name'] ?? '#' . ($p['id'] ?? ''))) ?> <small style="color:#6b7774">(#<?= (int) ($p['id'] ?? 0) ?>)</small></li><?php endforeach; ?><?php if (count($confirm['listings']) > 10): ?><li style="color:#6b7774">… ve <?= count($confirm['listings']) - 10 ?> ilan daha</li><?php endif; ?></ul><?php else: ?><p style="margin:10px 0 0">Hiçbir ilandan kaldırılmamış. Onaylarsanız katalog satırı kalıcı olarak silinir.</p><?php endif; ?></div>
<form method="post"><input type="hidden" name="token" value="<?= htmlspecialchars($confirm['token']) ?>"><button class="danger">Evet, kalıcı sil</button><a class="back" href="/nexustraveltech/admin/ozellik-listeleri#trash">Vazgeç — çöp kutusunda bırak</a></form>
<?php elseif ($confirm !== null && $confirm['type'] === 'bulk'): ?>
<h1>🗑 Toplu kalıcı silme onayı</h1>
<div class="warn"><p style="margin:0">Aşağıdaki <b><?= count($confirm['items']) ?> özellik</b> onaylanırsa <b style="color:#9d3b1c">kalıcı olarak silinir</b> (geri alınamaz):</p><ul><?php foreach ($confirm['items'] as $it): $pu = $effPurge($it); ?><li><b><?= htmlspecialchars((string) $it['label']) ?></b> <small style="color:#6b7774">(<?= htmlspecialchars($sectionTitles[$it['code']] ?? (string) $it['code']) ?> · <?= (int) $it['affected_count'] ?> ilan · kalıcı silme <?= htmlspecialchars($pu['date']) ?><?= $pu['custom'] ? ' · özel tarih' : '' ?>)</small></li><?php endforeach; ?></ul></div>
<form method="post"><input type="hidden" name="bulk_token" value="<?= htmlspecialchars($confirm['token']) ?>"><button class="danger">Evet, <?= count($confirm['items']) ?> özelliği kalıcı sil</button><a class="back" href="/nexustraveltech/admin/ozellik-listeleri#trash">Vazgeç — çöp kutusunda bırak</a></form>
<?php else: ?>
<h1>🗑 Çöp kutusu "son şans" onayı</h1>
<div class="<?= $ok ? 'ok' : 'no' ?>"><?= htmlspecialchars($out) ?></div>
<a class="back" href="/nexustraveltech/admin/ozellik-listeleri">← Katalog & sınıflandırma yönetimi</a>
<?php endif; ?>
</div></body></html>
