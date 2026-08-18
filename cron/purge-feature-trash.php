<?php
declare(strict_types=1);

// Çöp kutusu temizliği — geri alınabilirlik süresi dolan silinmiş özellikler için önce
// yöneticiye "son şans" onay e-postası gider; yalnızca onaylananlar kalıcı silinir
// (feature_delete_backups + property_feature_catalog). Onay bağlantısı 3 gün geçerlidir;
// süre dolarsa bu görev yeniden ister ve e-postayı tekrar gönderir.
// Vade hesabı iki yolludur:
//   • purge_at dolu  → o tarihte kalıcı sil (özellik bazında geçersiz kılma)
//   • purge_at NULL   → silinme + feature_trash_ttl_days gün (varsayılan, en az 7).
// admin_alert_email tanımsızsa (e-posta gönderilemiyor) eski davranış: doğrudan temizler.
//
// Zamanlayıcı: nexus-feature-trash-purge (varsayılan: her gün 04:00).

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/../config/feature_lists.php';
require_once __DIR__ . '/../config/table-guard.php';
if (!table_guard(['property_feature_catalog', 'pending_trash_purges', 'feature_delete_backups'])) {
    fwrite(STDERR, "[purge-feature-trash] Eksik tablo — atlandı.
");
    exit(0);
}

$pdo = db();
$ttlDays = max(7, (int) platform_setting('feature_trash_ttl_days', 30));
$adminEmail = trim((string) platform_setting('admin_alert_email', ''));
$canNotify = $adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL);

$stale = $pdo->query("SELECT id, code, group_label, label, deleted_at, purge_at FROM property_feature_catalog WHERE deleted_at IS NOT NULL AND ((purge_at IS NOT NULL AND purge_at <= now()) OR (purge_at IS NULL AND deleted_at < now() - interval '{$ttlDays} days')) ORDER BY deleted_at")->fetchAll();

if (!$stale) {
    echo "Çöp kutusu temiz: {$ttlDays} günden eski silinmiş özellik yok.\n";
    exit(0);
}

$existingSt = $pdo->prepare("SELECT id, token, approved_at FROM pending_trash_purges WHERE feature_id=? AND expires_at > now()");
$insertReq = $pdo->prepare("INSERT INTO pending_trash_purges(feature_id, token, expires_at) VALUES(?,?, now() + interval '3 days')");
$delReq = $pdo->prepare('DELETE FROM pending_trash_purges WHERE feature_id=?');

$approved = [];
$toRequest = [];
$waiting = 0;
foreach ($stale as $r) {
    $fid = (int) $r['id'];
    $existingSt->execute([$fid]);
    $pend = $existingSt->fetch();
    if ($pend) {
        if ($pend['approved_at'] !== null) {
            $approved[] = $fid;
        } else {
            $waiting++;
        }
        continue;
    }
    $toRequest[] = $r;
}

// Onay e-postası gönderilemiyorsa sistem durmasın — eski davranış: doğrudan temizle.
if (!$canNotify) {
    $approved = array_values(array_unique(array_merge($approved, array_map(fn($r) => (int) $r['id'], $stale))));
    $toRequest = [];
    $waiting = 0;
}

$purged = ['count' => 0, 'ids' => [], 'names' => []];
if ($approved) {
    foreach ($approved as $fid) {
        $delReq->execute([$fid]);
    }
    $purged = feature_trash_purge_approved($approved, $pdo);
    if ($purged['count'] > 0) {
        audit_log('feature.trash_purge', 'feature_catalog', null, [
            'count' => $purged['count'],
            'ttl_days' => $ttlDays,
            'feature_ids' => $purged['ids'],
            'labels' => $purged['names'],
            'approved' => true,
        ]);
    }
}

$emailed = 0;
if ($toRequest && $canNotify) {
    // Toplu onay bağlantısı: tek tıkla tüm bekleyen özelliklerin kalıcı silinmesini onaylar.
    // Token platform ayarında 3 günle saklanır; kullanıldığında temizlenir.
    $bulkToken = bin2hex(random_bytes(32));
    save_platform_setting('trash_bulk_approve', ['token' => $bulkToken, 'expires_at' => date('Y-m-d H:i:s', time() + 3 * 86400)]);
    $bulkLink = 'https://nexustraveltech.com/admin/approve-trash-purge.php?bulk_token=' . $bulkToken;
    $rowsHtml = '';
    foreach ($toRequest as $r) {
        $fid = (int) $r['id'];
        $token = bin2hex(random_bytes(32));
        $insertReq->execute([$fid, $token]);
        $link = 'https://nexustraveltech.com/admin/approve-trash-purge.php?token=' . $token;
        $delTs = strtotime((string) $r['deleted_at']) ?: time();
        $purgeTs = !empty($r['purge_at']) ? (strtotime((string) $r['purge_at']) ?: 0) : 0;
        if ($purgeTs <= 0) $purgeTs = $delTs + $ttlDays * 86400;
        $customTag = !empty($r['purge_at']) ? ' · <b style="color:#8a6100">özel tarih</b>' : '';
        $rowsHtml .= '<li><b>' . htmlspecialchars($r['label']) . '</b> <span style="color:#6b7774">(' . htmlspecialchars((string) $r['code']) . ' · silindi ' . htmlspecialchars(mb_substr((string) $r['deleted_at'], 0, 10)) . ' · kalıcı silme ' . date('Y-m-d', $purgeTs) . $customTag . ')</span> — <a href="' . $link . '" style="color:#b0301a">Kalıcı silmeyi onayla →</a></li>';
        $emailed++;
    }
    $body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
        . '<h2 style="margin:0 0 6px">⏳ Son şans: ' . count($toRequest) . ' özellik kalıcı silinmek üzere</h2>'
        . '<p>Kalıcı silme vadesi dolan aşağıdaki özellikler onaylanırsa silinir (geri alınamaz). Vade: özel tarih verilenler için o tarih, diğerleri için silinme + ' . $ttlDays . ' gün. Onaylamak istemiyorsanız bağlantıya tıklamayın — özellik çöp kutusunda kalır; bağlantılar <b>3 gün</b> geçerlidir, süre dolunca bu e-posta yeniden istenir.</p>'
        . '<p style="background:#fff3cd;border:1px solid #e0c9a3;border-radius:8px;padding:10px 12px"><a href="' . $bulkLink . '" style="color:#8a6100;font-weight:bold;font-size:15px;text-decoration:none">✅ Hepsini onayla (' . count($toRequest) . ' özellik) →</a><br><span style="color:#6b7774;font-size:12px">Tek tıkla tüm bekleyen özelliklerin kalıcı silinmesini onaylar (3 gün geçerli, tek kullanımlık).</span></p>'
        . '<ul>' . $rowsHtml . '</ul>'
        . '<div style="margin:16px 0 8px;padding:12px 14px;background:#f4f6f1;border:1px solid #d5dccf;border-radius:8px;font-size:13px">'
        . '<p style="margin:0 0 6px">⚠️ <b>Bu e-postayı yanlışlıkla aldıysanız</b> — lütfen herhangi bir bağlantıya tıklamayın. Bu e-posta yalnızca NEXUS platform yöneticilerine gönderilir ve özellik silme onayları içerir. Bağlantılara tıklamamanız durumunda hiçbir işlem yapılmaz; özellikler çöp kutusunda güvende kalır.</p>'
        . '<p style="margin:0">⏳ <b>Geri yükleme kısayolu:</b> Silinen bir özelliği geri almak isterseniz <a href="https://nexustraveltech.com/admin/ozellik-listeleri#trash" style="color:#405b13;font-weight:bold">Katalog & çöp kutusu</a> sayfasından tek tıkla geri yükleyebilirsiniz — ilanlara aynı bölüm ve fiyat durumuyla eklenir. Geri yükleme süresi: silinme + ' . $ttlDays . ' gün.</p>'
        . '</div>'
        . '<p><a href="https://nexustraveltech.com/admin/ozellik-listeleri" style="color:#b0301a">Katalog & sınıflandırma yönetimi →</a></p>'
        . '</div>';
    queue_email($adminEmail, 'Son şans: ' . count($toRequest) . ' özellik kalıcı silinmek üzere (onay gerekli)', $body, 'trash_purge_approval', count($toRequest));
    echo 'Onay e-postası kuyruğa eklendi (' . $emailed . " bağlantı + 1 toplu onay).\n";
}

echo 'TTL dolan özellik: ' . count($stale) . ' · onaylı silinen: ' . $purged['count'] . ' · onay bekleyen: ' . $waiting . ' · onay istenen: ' . count($toRequest) . ".\n";

if ($purged['count'] > 0 && $canNotify) {
    $rows = '';
    foreach ($purged['names'] as $n) {
        $rows .= '<li>' . htmlspecialchars($n) . '</li>';
    }
    $body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
        . '<h2 style="margin:0 0 6px">🗑 Çöp kutusu temizliği (onaylandı)</h2>'
        . '<p>Onayınızla <b style="color:#b0301a">' . $purged['count'] . '</b> özellik kalıcı olarak silindi (katalog satırı + ilan/bölüm yedekleri). Bu noktadan sonra geri alınamazlar.</p>'
        . '<ul>' . $rows . '</ul>'
        . '<p><a href="https://nexustraveltech.com/admin/ozellik-listeleri" style="color:#b0301a">Katalog & sınıflandırma yönetimi →</a></p>'
        . '</div>';
    queue_email($adminEmail, 'Çöp kutusu temizlendi: ' . $purged['count'] . ' özellik kalıcı olarak silindi', $body, 'feature_trash_purge', $purged['count']);
    echo "Temizlik bilgi e-postası kuyruğa eklendi.\n";
}
