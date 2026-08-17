<?php
declare(strict_types=1);

// Çöp kutusu temizliği — geri alınabilirlik süresi (varsayılan 30 gün) dolan silinmiş
// özellikleri kalıcı olarak siler: önce feature_delete_backups (ilan/bölüm yedekleri),
// sonra property_feature_catalog satırı. Bu noktadan sonra restore mümkün değildir.
// Süre platform ayarı feature_trash_ttl_days ile değiştirilebilir (en az 7 gün).
// İşlem denetim kaydına yazılır; admin_alert_email tanımlıysa yöneticiye bilgi gider.
//
// Zamanlayıcı: nexus-feature-trash-purge (varsayılan: her gün 04:00).

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/audit.php';

$pdo = db();
$ttlDays = max(7, (int) platform_setting('feature_trash_ttl_days', 30));

$stale = $pdo->query("SELECT id, code, group_label, label, deleted_at FROM property_feature_catalog WHERE deleted_at IS NOT NULL AND deleted_at < now() - interval '{$ttlDays} days' ORDER BY deleted_at")->fetchAll();

if (!$stale) {
    echo "Çöp kutusu temiz: {$ttlDays} günden eski silinmiş özellik yok.\n";
    exit(0);
}

$ids = array_map(fn($r) => (int) $r['id'], $stale);
$idsSql = implode(',', $ids);

// Önce yedekler (ilan/bölüm anlık görüntüleri), sonra katalog satırları.
$pdo->exec("DELETE FROM feature_delete_backups WHERE feature_id IN ({$idsSql})");
$pdo->exec("DELETE FROM property_feature_catalog WHERE id IN ({$idsSql})");

$names = array_map(fn($r) => $r['label'] . ' (' . $r['code'] . ')', $stale);
$deletedAt = array_map(fn($r) => (string) $r['deleted_at'], $stale);

audit_log('feature.trash_purge', 'feature_catalog', null, [
    'count' => count($stale),
    'ttl_days' => $ttlDays,
    'feature_ids' => $ids,
    'labels' => $names,
    'deleted_at' => $deletedAt,
]);

echo 'Çöp kutusundan ' . count($stale) . ' özellik kalıcı olarak temizlendi: ' . implode(', ', $names) . ".\n";

$adminEmail = trim((string) platform_setting('admin_alert_email', ''));
if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    $rows = '';
    foreach ($names as $n) {
        $rows .= '<li>' . htmlspecialchars($n) . '</li>';
    }
    $body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
        . '<h2 style="margin:0 0 6px">🗑 Çöp kutusu otomatik temizliği</h2>'
        . '<p>' . $ttlDays . ' günlük geri alınabilirlik süresi dolan <b style="color:#b0301a">' . count($stale) . '</b> özellik kalıcı olarak silindi (katalog satırı + ilan/bölüm yedekleri). Bu noktadan sonra geri alınamazlar.</p>'
        . '<ul>' . $rows . '</ul>'
        . '<p><a href="https://nexustraveltech.com/admin/ozellik-listeleri" style="color:#b0301a">Katalog & sınıflandırma yönetimi →</a></p>'
        . '</div>';
    queue_email($adminEmail, 'Çöp kutusu temizlendi: ' . count($stale) . ' özellik kalıcı olarak silindi', $body, 'feature_trash_purge', count($stale));
    echo "Admin e-postası kuyruğa eklendi.\n";
}
