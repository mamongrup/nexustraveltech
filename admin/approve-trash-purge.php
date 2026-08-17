<?php
declare(strict_types=1);

// Çöp kutusu "son şans" onay sayfası — TTL dolan özelliğin kalıcı silinmesini tek tıkla
// onaylar. E-posta bağlantısı tek kullanımlıktır (64 hex, 3 gün geçerli); onay, kalıcı
// silmeyi anında uygular (feature_trash_purge_approved paylaşılan fonksiyon).
// ?bulk_token=... ile e-postadaki "hepsini onayla" bağlantısı, o an bekleyen TÜM onayları
// tek tıkla onaylar (platform ayarı trash_bulk_approve içinde 3 gün saklanan token).

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/feature_lists.php';
require_once __DIR__ . '/../config/audit.php';

$token = (string) ($_GET['token'] ?? '');
$bulkToken = (string) ($_GET['bulk_token'] ?? '');
$out = '';
$ok = false;

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
            $out = 'Bu toplu onay bağlantısı geçersiz veya zaten kullanıldı.';
        } elseif ($expires === '' || strtotime($expires) < time()) {
            $out = 'Bu toplu onay bağlantısının süresi doldu — yeni temizlik taraması e-postayı yeniden gönderecek.';
        } else {
            $pending = $pdo->query('SELECT p.feature_id, f.label FROM pending_trash_purges p JOIN property_feature_catalog f ON f.id=p.feature_id WHERE p.approved_at IS NULL AND p.expires_at > now()')->fetchAll();
            if (!$pending) {
                $out = 'Bekleyen onay yok (özellikler geri yüklenmiş veya tek tek onaylanmış olabilir).';
            } else {
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
            }
        }
    }
} elseif (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    $out = 'Geçersiz bağlantı.';
} else {
    $pdo = db();
    $q = $pdo->prepare('SELECT * FROM pending_trash_purges WHERE token=?');
    $q->execute([$token]);
    $row = $q->fetch();
    if (!$row) {
        $out = 'Bu onay bağlantısı geçersiz veya zaten kullanıldı.';
    } elseif (strtotime((string) $row['expires_at']) < time()) {
        $out = 'Bu onay bağlantısının süresi doldu — yeni temizlik taraması e-postayı yeniden gönderecek.';
    } elseif ($row['approved_at'] !== null) {
        $out = 'Bu onay zaten verilmişti; özellik temizlendi.';
    } else {
        $st = $pdo->prepare('SELECT label FROM property_feature_catalog WHERE id=? AND deleted_at IS NOT NULL');
        $st->execute([(int) $row['feature_id']]);
        $feat = $st->fetch();
        if (!$feat) {
            $out = 'Özellik artık çöp kutusunda değil (geri yüklenmiş veya silinmiş).';
        } else {
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
        }
    }
}
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Çöp kutusu onayı | NEXUS Admin</title><style>body{font-family:Arial;background:#f7f7f2;color:#10211f;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center}.card{background:#fff;border:1px solid #e1e5de;border-radius:10px;padding:28px 32px;max-width:520px;width:calc(100% - 48px);box-shadow:0 4px 18px rgba(0,0,0,.05)}h1{font-size:20px;margin:0 0 10px}.ok{background:#e6f8c7;padding:12px;border-radius:6px}.no{background:#ffe2de;padding:12px;border-radius:6px}.back{display:inline-block;margin-top:14px;color:#10211f}</style></head><body><div class="card"><h1>🗑 Çöp kutusu "son şans" onayı</h1><div class="<?= $ok ? 'ok' : 'no' ?>"><?= htmlspecialchars($out) ?></div><a class="back" href="/nexustraveltech/admin/ozellik-listeleri">← Katalog & sınıflandırma yönetimi</a></div></body></html>
