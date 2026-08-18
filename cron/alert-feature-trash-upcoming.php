<?php
declare(strict_types=1);

// Çöp kutusu yaklaşan kalıcı silme uyarısı — kalıcı silme vadesine 3 gün kalan silinmiş
// özellikleri admin_alert_email'e günlük listeler. Vade hesabı iki yolludur:
//   • purge_at dolu  → o tarih (özellik bazında geçersiz kılma)
//   • purge_at NULL   → silinme + feature_trash_ttl_days gün (varsayılan)
// Aynı özellik + kalıcı silme tarihi için uyarı yalnızca bir kez gider
// (trash_upcoming_alerts tekilleştirme tablosu, migration 058).
// Vadesi DOLAN özellikleri bu görev değil, purge-feature-trash "son şans" onayıyla yönetir.
//
// Zamanlayıcı: nexus-trash-upcoming-alerts (varsayılan: her gün 07:30).

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/platform_settings.php';

$pdo = db();
$ttlDays = max(7, (int) platform_setting('feature_trash_ttl_days', 30));
$adminEmail = trim((string) platform_setting('admin_alert_email', ''));
$sectionTitles = ['villa' => 'Villa özellikleri', 'yacht' => 'Yat özellikleri', 'amenity' => 'Otel olanakları', 'activity' => 'Otel aktiviteleri', 'event' => 'Otel etkinlikleri'];

// Migration 058 bekliyorsa (dedup tablosu yok) güvenle atlanır — günlük spam önlenir.
try {
    $hasDedup = (bool) $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='trash_upcoming_alerts'")->fetchColumn();
} catch (Throwable $e) {
    $hasDedup = false;
}
if (!$hasDedup) {
    echo "Çöp kutusu uyarısı atlandı: trash_upcoming_alerts tablosu eksik (migration 058 bekliyor).\n";
    exit(0);
}

// purge_at kolonu migration 057 ile gelir; bekliyorsa atla.
try {
    $rows = $pdo->query("SELECT id, code, group_label, label, deleted_at, purge_at FROM property_feature_catalog WHERE deleted_at IS NOT NULL ORDER BY deleted_at")->fetchAll();
} catch (Throwable $e) {
    echo "Çöp kutusu uyarısı atlandı: property_feature_catalog.purge_at eksik (migration 057 bekliyor).\n";
    exit(0);
}

$upcoming = [];
foreach ($rows as $r) {
    $delTs = strtotime((string) $r['deleted_at']) ?: 0;
    if ($delTs <= 0) continue;
    $custom = !empty($r['purge_at']);
    $purgeTs = $custom ? (strtotime((string) $r['purge_at']) ?: 0) : 0;
    if ($purgeTs <= 0) $purgeTs = $delTs + $ttlDays * 86400;
    $diff = $purgeTs - time();
    $warnDays = max(1, (int) platform_setting('trash_upcoming_warning_days', 3));
    if ($diff <= 0 || $diff > $warnDays * 86400) continue; // yalnızca önümüzdeki $warnDays gün
    $upcoming[] = [
        'id' => (int) $r['id'],
        'label' => (string) $r['label'],
        'code' => (string) $r['code'],
        'deleted_at' => (string) $r['deleted_at'],
        'purge_date' => date('Y-m-d', $purgeTs),
        'remain_days' => max(1, (int) ceil($diff / 86400)),
        'custom' => $custom,
    ];
}

if (!$upcoming) {
    echo "Çöp kutusu uyarısı temiz: 3 gün içinde kalıcı silinecek özellik yok.\n";
    exit(0);
}

if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    echo count($upcoming) . " özellik 3 gün içinde silinecek ama admin_alert_email tanımsız — e-posta kuyruğa alınmadı.\n";
    exit(1);
}

// Daha önce uyarılmış (özellik + silme tarihi) olanları eler.
$dedupSt = $pdo->prepare('SELECT 1 FROM trash_upcoming_alerts WHERE feature_id=? AND purge_date=?');
$fresh = [];
foreach ($upcoming as $u) {
    $dedupSt->execute([$u['id'], $u['purge_date']]);
    if (!$dedupSt->fetch()) $fresh[] = $u;
}

if (!$fresh) {
    echo 'Bekleyen özellik var ama tümü daha önce uyarıldı (' . count($upcoming) . ' özellik, yeni değil).' . "\n";
    exit(0);
}

$rowsHtml = '';
foreach ($fresh as $u) {
    $rowsHtml .= '<li><b>' . htmlspecialchars($u['label']) . '</b> <span style="color:#6b7774">('
        . htmlspecialchars($sectionTitles[$u['code']] ?? (string) $u['code'])
        . ' · silindi ' . htmlspecialchars(mb_substr($u['deleted_at'], 0, 10))
        . ' · kalıcı silme <b style="color:#8a6100">' . htmlspecialchars($u['purge_date']) . '</b>'
        . ' · ' . (int) $u['remain_days'] . ' gün'
        . ($u['custom'] ? ' · özel tarih' : '')
        . ')</span></li>';
}

$body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
    . '<h2 style="margin:0 0 6px">⏳ Yaklaşan kalıcı silme: ' . count($fresh) . ' özellik</h2>'
    . '<p>Aşağıdaki silinmiş özellikler kalıcı silme vadesine <b>' . $warnDays . ' gün içinde</b> ulaşacak. Vade dolduğunda ayrı bir "son şans" onay e-postası istenir; onaylanırsa <b>geri alınamaz</b>. Silinmesini istemiyorsanız çöp kutusundan geri yükleyin.</p>'
    . '<ul>' . $rowsHtml . '</ul>'
    // Bolum bazinda ozet tablosu
    . '<div style="margin:16px 0 0;border:1px solid #e1e5de;border-radius:8px;overflow:hidden">'
    . '<div style="background:#f7f7f2;padding:8px 12px;font-weight:bold;font-size:13px;border-bottom:1px solid #e1e5de">'
    . '&#x1F4CB; Bu hafta silinecek t&#x00FC;m &#xF6;zellikler (' . count($upcoming) . ' &#xF6;zellik, ' . count($byCode) . ' b&#x00F6;l&#x00FC;m)'
    . '</div>'
    . '<table style="width:100%;border-collapse:collapse;font-size:13px">'
    . '<tr style="background:#f0f2ed"><th style="padding:6px 12px;text-align:left;border-bottom:1px solid #e1e5de;font-size:11px;text-transform:uppercase;color:#64716d">B&#x00F6;l&#x00FC;m</th><th style="padding:6px 12px;text-align:center;border-bottom:1px solid #e1e5de;font-size:11px;text-transform:uppercase;color:#64716d">Adet</th><th style="padding:6px 12px;text-align:left;border-bottom:1px solid #e1e5de;font-size:11px;text-transform:uppercase;color:#64716d">&#xD6;zellikler</th></tr>'
    . $summaryRows
    . '</table></div>'

    . '<p><a href="https://nexustraveltech.com/admin/ozellik-listeleri#trash" style="color:#b0301a">Katalog & çöp kutusu →</a></p>'
    . '</div>';

queue_email($adminEmail, 'Yaklaşan kalıcı silme: ' . count($fresh) . ' özellik (' . $warnDays . ' gün içinde)', $body, 'trash_upcoming', count($fresh));

// Tekilleştirme kayıtlarını işaretle — aynı özellik + tarih için bir daha e-posta gitmez.
$insSt = $pdo->prepare('INSERT INTO trash_upcoming_alerts(feature_id, purge_date) VALUES(?,?) ON CONFLICT (feature_id, purge_date) DO NOTHING');
foreach ($fresh as $u) {
    $insSt->execute([$u['id'], $u['purge_date']]);
}

// Bolum bazinda gruplu ozet
$byCode = [];
foreach ($upcoming as $u) {
    $c = (string)$u['code'];
    if (!isset($byCode[$c])) $byCode[$c] = [];
    $byCode[$c][] = $u;
}
$summaryRows = "";
foreach ($byCode as $code => $items) {
    $codeLabel = htmlspecialchars($sectionTitles[$code] ?? (string)$code);
    $names = array_map(fn($x) => '<b>' . htmlspecialchars($x['label']) . '</b> <span style="color:#6b7774;font-size:12px">(' . htmlspecialchars($x['purge_date']) . ' · ' . (int)$x['remain_days'] . ' gun' . ($x['custom'] ? ' · ozel' : '') . ')</span>', $items);
    $summaryRows .= '<tr style="border-bottom:1px solid #e8e5de">'
        . '<td style="padding:8px 12px;font-weight:bold;background:#f7f7f2;white-space:nowrap">>'.$codeLabel.'</td>'
        . '<td style="padding:8px 12px;text-align:center;background:#f7f7f2"><b style="color:#b0301a">'.count($items).'</b></td>'
        . '<td style="padding:8px 12px">'.implode(', ',$names).'</td>'
        . '</tr>';
}


echo 'Uyarı e-postası kuyruğa eklendi: ' . count($fresh) . ' özellik (' . count($upcoming) . ' bulundu, ' . (count($upcoming) - count($fresh)) . " zaten uyarılmıştı).\n";
