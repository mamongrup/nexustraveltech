<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/feature_lists.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_admin();
if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
$msg = '';
$err = '';
$pendingDelete = null;
$deletedAudit = null;
$pendingBulk = null;
$typeLabel = fn($t) => ['hotel' => 'Otel', 'villa' => 'Villa', 'yacht' => 'Yat'][$t] ?? $t;
$taxonomies = ['property_type' => 'Tesis tipleri', 'star_rating' => 'Yıldız seviyeleri', 'theme' => 'Otel temaları'];
$ttlDays = max(7, (int) platform_setting('feature_trash_ttl_days', 30));
// Tekil silme işlemcisi — geri alınabilir silme: yedek + soft-delete + ilanlardan kaldırma + denetim.
// Hem tekil silme hem toplu silme aynı yolu kullanır.
$deleteFeature = function (int $featureId, ?string $purgeAt = null) use ($typeLabel): array {
    $pdo = db();
    $featQ = $pdo->prepare('SELECT id, code, group_label, label, sort_order, is_active FROM property_feature_catalog WHERE id=?');
    $featQ->execute([$featureId]);
    $feat = $featQ->fetch();
    if (!$feat) throw new RuntimeException('Özellik bulunamadı.');
    $impactSql = "SELECT p.id, p.name, p.property_type, s.id AS supplier_id, s.company_name, p.product_details FROM properties p JOIN suppliers s ON s.id=p.supplier_id WHERE p.property_type IN ('hotel','villa','yacht') AND (jsonb_exists(p.product_details -> 'service_pricing', ?) OR (p.product_details -> 'amenities') @> CAST(? AS jsonb) OR (p.product_details -> 'activities') @> CAST(? AS jsonb) OR (p.product_details -> 'events') @> CAST(? AS jsonb))";
    $impact = $pdo->prepare($impactSql);
    $impact->execute([$feat['label'], json_encode([$feat['label']]), json_encode([$feat['label']]), json_encode([$feat['label']])]);
    $affected = $impact->fetchAll();
    $backupProps = [];
    foreach ($affected as $a) {
        $pd = json_decode((string) ($a['product_details'] ?? '{}'), true) ?: [];
        $sections = [];
        $price = null;
        $sp = $pd['service_pricing'] ?? [];
        if (is_array($sp) && array_key_exists($feat['label'], $sp)) { $sections[] = 'service_pricing'; $price = (string) $sp[$feat['label']]; }
        foreach (['amenities', 'activities', 'events'] as $sec) {
            if (is_array($pd[$sec] ?? null) && in_array($feat['label'], $pd[$sec], true)) $sections[] = $sec;
        }
        $backupProps[] = ['id' => (int) $a['id'], 'name' => $a['name'], 'sections' => $sections, 'price' => $price];
    }
    $pdo->prepare('INSERT INTO feature_delete_backups(feature_id, code, group_label, label, sort_order, is_active, deleted_by, affected_properties) VALUES(?,?,?,?,?,?,?,?::jsonb)')
        ->execute([$featureId, $feat['code'], $feat['group_label'] ?? '', $feat['label'], (int) ($feat['sort_order'] ?? 100), (bool) ($feat['is_active'] ?? true), (string) ($_SESSION['admin_username'] ?? 'admin'), json_encode($backupProps)]);
    // Özellik bazında TTL geçersiz kılma: purge_at doluysa özellik o tarihte kalıcı silinir;
    // NULL ise genel feature_trash_ttl_days ayarı geçerli olur.
    $purgeAtTs = null;
    if ($purgeAt !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $purgeAt)) $purgeAtTs = $purgeAt . ' 00:00:00';
    $pdo->prepare('UPDATE property_feature_catalog SET deleted_at=now(), purge_at=? WHERE id=?')->execute([$purgeAtTs, $featureId]);
    $stripSql = "UPDATE properties SET product_details = jsonb_set(jsonb_set(jsonb_set(jsonb_set(product_details, '{service_pricing}', COALESCE(product_details -> 'service_pricing', '{}'::jsonb) - ?, true), '{amenities}', COALESCE(product_details -> 'amenities', '[]'::jsonb) - ?, true), '{activities}', COALESCE(product_details -> 'activities', '[]'::jsonb) - ?, true), '{events}', COALESCE(product_details -> 'events', '[]'::jsonb) - ?, true) WHERE id = ?";
    $strip = $pdo->prepare($stripSql);
    foreach ($affected as $a) $strip->execute([$feat['label'], $feat['label'], $feat['label'], $feat['label'], (int) $a['id']]);
    audit_log('feature.delete', 'feature_catalog', $featureId, [
        'code' => $feat['code'],
        'label' => $feat['label'],
        'affected_count' => count($affected),
        'affected_listing_ids' => array_map(fn($a) => (int) $a['id'], $affected),
        'affected_listings' => array_map(fn($a) => $a['name'] . ' (' . $typeLabel($a['property_type']) . ')', $affected),
        'purge_at' => $purgeAt,
    ]);
    return ['id' => $featureId, 'label' => $feat['label'], 'affected' => $affected];
};
// Geri yükleme — tekil ve toplu restore ortak kullanır (özellik + ilanlara bölüm bazlı geri ekleme).
$restoreFeature = function (int $featureId): array {
    $bkQ = db()->prepare('SELECT * FROM feature_delete_backups WHERE feature_id=? ORDER BY id DESC LIMIT 1');
    $bkQ->execute([$featureId]);
    $bk = $bkQ->fetch();
    if (!$bk) throw new RuntimeException('Geri alınacak kayıt bulunamadı.');
    $label = (string) $bk['label'];
    // 1) Katalog satırını geri getir (aynı id korunur, sıralama/durum geri gelir).
    db()->prepare('UPDATE property_feature_catalog SET deleted_at=NULL, purge_at=NULL, group_label=?, label=?, sort_order=?, is_active=? WHERE id=?')
        ->execute([$bk['group_label'] ?? '', $label, (int) ($bk['sort_order'] ?? 100), (bool) ($bk['is_active'] ?? true), $featureId]);
    // Özellik çöp kutusundan çıktığı için bekleyen "son şans" onay kaydını temizle.
    db()->prepare('DELETE FROM pending_trash_purges WHERE feature_id=?')->execute([$featureId]);
    // 2) İlanlara bölüm bazlı geri ekle (zaten varsa dokunma).
    $props = json_decode((string) ($bk['affected_properties'] ?? '[]'), true) ?: [];
    $restored = 0;
    $restoreSp = db()->prepare("UPDATE properties SET product_details = jsonb_set(product_details, '{service_pricing}', COALESCE(product_details -> 'service_pricing', '{}'::jsonb) || jsonb_build_object(?, ?), true) WHERE id=? AND NOT jsonb_exists(COALESCE(product_details -> 'service_pricing', '{}'::jsonb), ?)");
    $restoreSec = [
        'amenities' => db()->prepare("UPDATE properties SET product_details = jsonb_set(product_details, '{amenities}', COALESCE(product_details -> 'amenities', '[]'::jsonb) || ?::jsonb, true) WHERE id=? AND NOT (COALESCE(product_details -> 'amenities', '[]'::jsonb) @> ?::jsonb)"),
        'activities' => db()->prepare("UPDATE properties SET product_details = jsonb_set(product_details, '{activities}', COALESCE(product_details -> 'activities', '[]'::jsonb) || ?::jsonb, true) WHERE id=? AND NOT (COALESCE(product_details -> 'activities', '[]'::jsonb) @> ?::jsonb)"),
        'events' => db()->prepare("UPDATE properties SET product_details = jsonb_set(product_details, '{events}', COALESCE(product_details -> 'events', '[]'::jsonb) || ?::jsonb, true) WHERE id=? AND NOT (COALESCE(product_details -> 'events', '[]'::jsonb) @> ?::jsonb)"),
    ];
    foreach ($props as $pr) {
        $sections = is_array($pr['sections'] ?? null) ? $pr['sections'] : [];
        $pid = (int) ($pr['id'] ?? 0);
        $price = (string) ($pr['price'] ?? '');
        foreach ($sections as $sec) {
            if ($sec === 'service_pricing') { $restoreSp->execute([$label, $price, $pid, $label]); $restored++; }
            elseif (isset($restoreSec[$sec])) { $restoreSec[$sec]->execute([json_encode([$label]), $pid, json_encode([$label])]); $restored++; }
        }
    }
    audit_log('feature.restore', 'feature_catalog', $featureId, [
        'code' => $bk['code'] ?? '',
        'label' => $label,
        'affected_count' => count($props),
        'affected_listing_ids' => array_map(fn($pr) => (int) ($pr['id'] ?? 0), $props),
        'restored_sections' => $restored,
    ]);
    return ['label' => $label, 'affected_count' => count($props), 'restored_sections' => $restored];
};
// CSV dışa aktarma — silme onay ekranından tedarikçi bazlı etki listesi indirilir.
// Tekil: ?export=delete_impact&feature_id=N · Toplu: ?export=bulk_impact&ids[]=N&ids[]=M
$export = (string) ($_GET['export'] ?? '');
// Çöp kutusu önizleme uç noktası — chipteki "▸ N ilan" butonu, özelliğin kaldırıldığı
// ilan listesini (ad, bölümler, fiyat) feature_delete_backups yedeğinden buradan çeker.
if (($_GET['qview'] ?? '') === 'trash_listings' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json; charset=UTF-8');
    $fid = (int) ($_GET['id'] ?? 0);
    if ($fid <= 0) { http_response_code(400); exit(json_encode(['ok' => false, 'error' => 'Geçersiz özellik kimliği.'])); }
    try {
        $bkQ = db()->prepare('SELECT label, affected_properties FROM feature_delete_backups WHERE feature_id=? ORDER BY id DESC LIMIT 1');
        $bkQ->execute([$fid]);
        $bk = $bkQ->fetch();
        if (!$bk) { http_response_code(404); exit(json_encode(['ok' => false, 'error' => 'Yedek kaydı bulunamadı.'])); }
        $props = json_decode((string) ($bk['affected_properties'] ?? '[]'), true) ?: [];
        $secLabels = ['service_pricing' => 'fiyatlandırma', 'amenities' => 'olanaklar', 'activities' => 'aktiviteler', 'events' => 'etkinlikler'];
        $list = [];
        foreach ($props as $p) {
            $sections = array_values(array_filter(array_map('strval', (array) ($p['sections'] ?? []))));
            $list[] = [
                'id' => (int) ($p['id'] ?? 0),
                'name' => (string) ($p['name'] ?? ''),
                'sections' => array_map(fn($s) => $secLabels[$s] ?? $s, $sections),
                'price' => (string) ($p['price'] ?? ''),
            ];
        }
        exit(json_encode(['ok' => true, 'label' => (string) $bk['label'], 'listings' => $list], JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
        http_response_code(500);
        exit(json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
    }
}
// Tür bazlı etki sayıları (toplu silme onayı + tür tablosu CSV'si için ortak).
$bulkTypeCounts = function (array $ids): array {
    $byType = ['hotel' => 0, 'villa' => 0, 'yacht' => 0];
    $fq = db()->prepare('SELECT label FROM property_feature_catalog WHERE id=?');
    $iq = db()->prepare("SELECT p.property_type FROM properties p WHERE p.property_type IN ('hotel','villa','yacht') AND (jsonb_exists(p.product_details -> 'service_pricing', ?) OR (p.product_details -> 'amenities') @> CAST(? AS jsonb) OR (p.product_details -> 'activities') @> CAST(? AS jsonb) OR (p.product_details -> 'events') @> CAST(? AS jsonb))");
    foreach ($ids as $fid) {
        $fq->execute([$fid]);
        $label = (string) ($fq->fetchColumn() ?: '');
        if ($label === '') continue;
        $iq->execute([$label, json_encode([$label]), json_encode([$label]), json_encode([$label])]);
        foreach ($iq->fetchAll() as $a) { if (isset($byType[$a['property_type']])) $byType[$a['property_type']]++; }
    }
    $byType['total'] = array_sum($byType);
    return $byType;
};
// Tedarikçi bazlı özet: her tedarikçi için ilan sayısı, toplam bölüm ve bölüm bazında kırılım.
// Özet sayfası (?view=supplier_summary) ve CSV (?export=delete_supplier_summary / bulk_supplier_summary) ortak kullanır.
$supplierSummary = function (array $ids): array {
    $bySupp = [];
    $fq = db()->prepare('SELECT id, label FROM property_feature_catalog WHERE id=?');
    $iq = db()->prepare("SELECT p.id, p.name, p.property_type, s.id AS supplier_id, s.company_name, p.product_details FROM properties p JOIN suppliers s ON s.id=p.supplier_id WHERE p.property_type IN ('hotel','villa','yacht') AND (jsonb_exists(p.product_details -> 'service_pricing', ?) OR (p.product_details -> 'amenities') @> CAST(? AS jsonb) OR (p.product_details -> 'activities') @> CAST(? AS jsonb) OR (p.product_details -> 'events') @> CAST(? AS jsonb))");
    foreach ($ids as $fid) {
        $fq->execute([$fid]);
        $label = (string) ($fq->fetchColumn() ?: '');
        if ($label === '') continue;
        $iq->execute([$label, json_encode([$label]), json_encode([$label]), json_encode([$label])]);
        foreach ($iq->fetchAll() as $a) {
            $pd = json_decode((string) ($a['product_details'] ?? '{}'), true) ?: [];
            $key = (int) ($a['supplier_id'] ?? 0) . '|' . (string) ($a['company_name'] ?? '');
            if (!isset($bySupp[$key])) $bySupp[$key] = ['supplier_id' => (int) ($a['supplier_id'] ?? 0), 'company_name' => (string) ($a['company_name'] ?? ''), 'listing_ids' => []];
            $secs = [];
            $sp = $pd['service_pricing'] ?? [];
            if (is_array($sp) && array_key_exists($label, $sp)) $secs[] = 'service_pricing';
            foreach (['amenities', 'activities', 'events'] as $s) {
                if (is_array($pd[$s] ?? null) && in_array($label, $pd[$s], true)) $secs[] = $s;
            }
            $lid = (int) $a['id'];
            if (!isset($bySupp[$key]['listing_ids'][$lid])) $bySupp[$key]['listing_ids'][$lid] = [];
            foreach ($secs as $s) $bySupp[$key]['listing_ids'][$lid][$s] = true;
        }
    }
    $rows = [];
    foreach ($bySupp as $b) {
        $c = ['service_pricing' => 0, 'amenities' => 0, 'activities' => 0, 'events' => 0];
        $total = 0;
        foreach ($b['listing_ids'] as $secSet) { $total += count($secSet); foreach ($secSet as $s => $t) { if (isset($c[$s])) $c[$s]++; } }
        $rows[] = ['supplier_id' => $b['supplier_id'], 'company_name' => $b['company_name'], 'listing_count' => count($b['listing_ids']), 'total_sections' => $total] + $c;
    }
    usort($rows, fn($x, $y) => $y['listing_count'] <=> $x['listing_count'] ?: strcmp((string) $x['company_name'], (string) $y['company_name']));
    return $rows;
};
if (($_GET['view'] ?? '') === 'supplier_summary' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $ids = isset($_GET['feature_id']) ? [(int) $_GET['feature_id']] : array_values(array_unique(array_filter(array_map('intval', (array) ($_GET['ids'] ?? [])), fn($i) => $i > 0)));
    $ids = array_values(array_filter($ids, fn($i) => $i > 0));
    if (!$ids) { http_response_code(400); exit('Geçersiz özellik listesi.'); }
    $sum = $supplierSummary($ids);
    $isBulk = !isset($_GET['feature_id']);
    $csvUrl = $isBulk
        ? '/nexustraveltech/admin/ozellik-listeleri?export=bulk_supplier_summary' . implode('', array_map(fn($i) => '&ids[]=' . $i, $ids))
        : '/nexustraveltech/admin/ozellik-listeleri?export=delete_supplier_summary&feature_id=' . $ids[0];
    $tSec = ['service_pricing' => 'Fiyatlandırma', 'amenities' => 'Olanaklar', 'activities' => 'Aktiviteler', 'events' => 'Etkinlikler'];
    echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tedarikçi özeti | NEXUS Admin</title><style>body{font-family:Arial;background:#f7f7f2;color:#10211f;margin:0}.w{width:min(1000px,calc(100% - 30px));margin:35px auto}.c{background:#fff;border:1px solid #ddd;padding:18px;margin:16px 0;border-radius:8px}table{width:100%;border-collapse:collapse;margin-top:12px}th,td{border:1px solid #e1e5de;padding:9px 10px;text-align:left;font-size:13px}th{background:#f7f7f2;font-size:11px;text-transform:uppercase;color:#64716d}tr.tot td{font-weight:bold;background:#f7f7f2}a.btn{display:inline-block;background:#405b13;color:#fff;padding:9px 14px;font-size:13px;font-weight:bold;text-decoration:none;border-radius:5px;margin-top:12px}</style></head><body><main class="w"><a href="/nexustraveltech/admin/ozellik-listeleri">← Katalog</a><h1>📊 Tedarikçi bazlı etki özeti</h1><p style="color:#6b7774;font-size:13px">Seçilen özelliklerin kullanıldığı ilanlar tedarikçi bazında özetlenir: ilan sayısı, toplam etkilenen bölüm ve bölüm bazında kırılım (bir ilan birden çok bölümde sayılabilir).</p>';
    if (!$sum) { echo '<div class="c"><p style="color:#6b7774">Bu özellik(ler) şu an hiçbir ilanda kullanılmıyor.</p></div>'; }
    else {
        echo '<div class="c"><table><tr><th>Tedarikçi</th><th>İlan sayısı</th><th>Toplam bölüm</th>' . implode('', array_map(fn($s) => '<th>' . htmlspecialchars($s) . '</th>', $tSec)) . '</tr>';
        $tot = ['listing_count' => 0, 'total_sections' => 0, 'service_pricing' => 0, 'amenities' => 0, 'activities' => 0, 'events' => 0];
        foreach ($sum as $r) {
            echo '<tr><td><b>' . htmlspecialchars($r['company_name'] ?: '—') . '</b>' . ($r['supplier_id'] > 0 ? ' <small style="color:#6b7774">#' . (int) $r['supplier_id'] . '</small>' : '') . '</td><td>' . (int) $r['listing_count'] . '</td><td><b>' . (int) $r['total_sections'] . '</b></td><td>' . (int) $r['service_pricing'] . '</td><td>' . (int) $r['amenities'] . '</td><td>' . (int) $r['activities'] . '</td><td>' . (int) $r['events'] . '</td></tr>';
            foreach (['listing_count', 'total_sections', 'service_pricing', 'amenities', 'activities', 'events'] as $k) $tot[$k] += (int) $r[$k];
        }
        echo '<tr class="tot"><td>TOPLAM</td><td>' . $tot['listing_count'] . '</td><td>' . $tot['total_sections'] . '</td><td>' . $tot['service_pricing'] . '</td><td>' . $tot['amenities'] . '</td><td>' . $tot['activities'] . '</td><td>' . $tot['events'] . '</td></tr></table>';
        echo '<a class="btn" href="' . htmlspecialchars($csvUrl) . '">⬇ CSV indir (tedarikçi özeti)</a></div>';
    }
    echo '</main></body></html>';
    exit;
}
// Metrik detayı — toplu silme onayındaki metrik rozetlerine tıklayınca ilan bazında
// görsel/oda tipi/plan/rezervasyon sayılarını döndürür (hangi ilanda kaç tane).
if (($_GET['qview'] ?? '') === 'bulk_metrics' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json; charset=UTF-8');
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_GET['ids'] ?? [])), fn($i) => $i > 0)));
    if (!$ids) { http_response_code(400); exit(json_encode(['ok' => false, 'error' => 'Geçersiz özellik listesi.'])); }
    try {
        $fq2 = db()->prepare('SELECT label FROM property_feature_catalog WHERE id=?');
        $iq2 = db()->prepare("SELECT p.id, p.name, p.property_type, s.company_name FROM properties p JOIN suppliers s ON s.id=p.supplier_id WHERE p.property_type IN ('hotel','villa','yacht') AND (jsonb_exists(p.product_details -> 'service_pricing', ?) OR (p.product_details -> 'amenities') @> CAST(? AS jsonb) OR (p.product_details -> 'activities') @> CAST(? AS jsonb) OR (p.product_details -> 'events') @> CAST(? AS jsonb))");
        $mq = db()->prepare('SELECT (SELECT COUNT(*) FROM property_media pm WHERE pm.property_id=p.id) AS media, (SELECT COUNT(*) FROM room_types r WHERE r.property_id=p.id) AS rooms, (SELECT COUNT(*) FROM rate_plans rp WHERE rp.property_id=p.id) AS plans, (SELECT COUNT(*) FROM supplier_bookings b WHERE b.property_id=p.id AND b.status NOT IN (\'cancelled\',\'rejected\')) AS bookings FROM properties p WHERE p.id=?');
        $list = [];
        $seen = [];
        foreach ($ids as $fid) {
            $fq2->execute([$fid]);
            $label2 = (string) ($fq2->fetchColumn() ?: '');
            if ($label2 === '') continue;
            $iq2->execute([$label2, json_encode([$label2]), json_encode([$label2]), json_encode([$label2])]);
            foreach ($iq2->fetchAll() as $a) {
                $pid = (int) $a['id'];
                if (isset($seen[$pid])) continue;
                $seen[$pid] = 1;
                $mq->execute([$pid]);
                $m = $mq->fetch();
                $list[] = ['id' => $pid, 'name' => (string) $a['name'], 'type' => (string) $a['property_type'], 'company' => (string) ($a['company_name'] ?? ''), 'media' => (int) ($m['media'] ?? 0), 'rooms' => (int) ($m['rooms'] ?? 0), 'plans' => (int) ($m['plans'] ?? 0), 'bookings' => (int) ($m['bookings'] ?? 0)];
            }
        }
        usort($list, fn($x, $y) => $y['media'] <=> $x['media']);
        exit(json_encode(['ok' => true, 'list' => $list], JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
        http_response_code(500);
        exit(json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
    }
}
if (in_array($export, ['delete_impact', 'delete_type', 'bulk_impact', 'bulk_type', 'bulk_metrics', 'delete_supplier_summary', 'bulk_supplier_summary'], true) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($export === 'delete_supplier_summary' || $export === 'bulk_supplier_summary') {
        $ids = $export === 'delete_supplier_summary' ? [(int) ($_GET['feature_id'] ?? 0)] : array_values(array_unique(array_filter(array_map('intval', (array) ($_GET['ids'] ?? [])), fn($i) => $i > 0)));
        $ids = array_values(array_filter($ids, fn($i) => $i > 0));
        if (!$ids) { http_response_code(400); exit('Geçersiz özellik listesi.'); }
        $sum = $supplierSummary($ids);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="tedarikci-ozeti.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM — Excel'de Türkçe karakterler
        fputcsv($out, ['Tedarikçi', 'İlan sayısı', 'Toplam bölüm', 'Fiyatlandırma', 'Olanaklar', 'Aktiviteler', 'Etkinlikler']);
        foreach ($sum as $r) fputcsv($out, [$r['company_name'], (int) $r['listing_count'], (int) $r['total_sections'], (int) $r['service_pricing'], (int) $r['amenities'], (int) $r['activities'], (int) $r['events']]);
        fputcsv($out, ['TOPLAM', array_sum(array_column($sum, 'listing_count')), array_sum(array_column($sum, 'total_sections')), array_sum(array_column($sum, 'service_pricing')), array_sum(array_column($sum, 'amenities')), array_sum(array_column($sum, 'activities')), array_sum(array_column($sum, 'events'))]);
        fclose($out);
        exit;
    }
    $secLabels = ['service_pricing' => 'fiyatlandırma', 'amenities' => 'olanaklar', 'activities' => 'aktiviteler', 'events' => 'etkinlikler'];
    $typeLabelCsv = fn($t) => ['hotel' => 'Otel', 'villa' => 'Villa', 'yacht' => 'Yat'][$t] ?? $t;
    $sectionsOf = function (array $a, string $label): array {
        $pd = json_decode((string) ($a['product_details'] ?? '{}'), true) ?: [];
        $sec = [];
        $sp = $pd['service_pricing'] ?? [];
        if (is_array($sp) && array_key_exists($label, $sp)) $sec[] = 'service_pricing';
        foreach (['amenities', 'activities', 'events'] as $s) {
            if (is_array($pd[$s] ?? null) && in_array($label, $pd[$s], true)) $sec[] = $s;
        }
        return $sec;
    };
    $rows = [];
    $fileName = 'etki-export.csv';
    if ($export === 'delete_type') {
        $fid = (int) ($_GET['feature_id'] ?? 0);
        $byType = $bulkTypeCounts([$fid]);
        $detail = (($_GET['detail'] ?? '') === '1');
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . ($detail ? 'etki-tur-tablosu-detayli.csv' : 'etki-tur-tablosu.csv') . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM — Excel'de Türkçe karakterler
        fputcsv($out, ['Tür', 'İlan sayısı', 'Pay (%)']);
        $dtTot = (int) ($byType['total'] ?? 0);
        $dtPct = fn(int $n) => $dtTot > 0 ? str_replace('.', ',', (string) round($n / $dtTot * 100, 1)) . '%' : '—';
        foreach (['hotel' => 'Otel', 'villa' => 'Villa', 'yacht' => 'Yat', 'total' => 'Toplam'] as $k => $tl) {
            fputcsv($out, [$tl, (int) $byType[$k], $k === 'total' ? '100%' : $dtPct((int) $byType[$k])]);
        }
        // Ayrıntılı mod — özetin altına etkilenen ilan adları (tür, tedarikçi, ID).
        if ($detail) {
            $lq = db()->prepare('SELECT label FROM property_feature_catalog WHERE id=?');
            $lq->execute([$fid]);
            $label = (string) ($lq->fetchColumn() ?: '');
            if ($label !== '') {
                fputcsv($out, []);
                fputcsv($out, ['Tür', 'İlan adı', 'Tedarikçi', 'İlan ID']);
                $iq = db()->prepare("SELECT p.id, p.name, p.property_type, s.company_name FROM properties p JOIN suppliers s ON s.id=p.supplier_id WHERE p.property_type IN ('hotel','villa','yacht') AND (jsonb_exists(p.product_details -> 'service_pricing', ?) OR (p.product_details -> 'amenities') @> CAST(? AS jsonb) OR (p.product_details -> 'activities') @> CAST(? AS jsonb) OR (p.product_details -> 'events') @> CAST(? AS jsonb)) ORDER BY p.property_type, p.name");
                $iq->execute([$label, json_encode([$label]), json_encode([$label]), json_encode([$label])]);
                $seen = [];
                foreach ($iq->fetchAll() as $a) {
                    $pid = (int) $a['id'];
                    if (isset($seen[$pid])) continue;
                    $seen[$pid] = 1;
                    fputcsv($out, [$typeLabelCsv((string) $a['property_type']), (string) $a['name'], (string) ($a['company_name'] ?? ''), $pid]);
                }
            }
        }
        fclose($out);
        exit;
    }
    if ($export === 'delete_impact') {
        $fid = (int) ($_GET['feature_id'] ?? 0);
        $fq = db()->prepare('SELECT label FROM property_feature_catalog WHERE id=?');
        $fq->execute([$fid]);
        $label = (string) ($fq->fetchColumn() ?: '');
        if ($fid <= 0 || $label === '') { http_response_code(404); exit('Özellik bulunamadı.'); }
        $fileName = 'etki-' . preg_replace('/[^a-z0-9]+/i', '-', $label) . '.csv';
        $iq = db()->prepare("SELECT p.id, p.name, p.property_type, s.id AS supplier_id, s.company_name, p.product_details FROM properties p JOIN suppliers s ON s.id=p.supplier_id WHERE p.property_type IN ('hotel','villa','yacht') AND (jsonb_exists(p.product_details -> 'service_pricing', ?) OR (p.product_details -> 'amenities') @> CAST(? AS jsonb) OR (p.product_details -> 'activities') @> CAST(? AS jsonb) OR (p.product_details -> 'events') @> CAST(? AS jsonb))");
        $iq->execute([$label, json_encode([$label]), json_encode([$label]), json_encode([$label])]);
        foreach ($iq->fetchAll() as $a) { $sec = $sectionsOf($a, $label); $rows[] = ['Tedarikçi', $a['company_name'] ?? '', $a['name'], $typeLabelCsv($a['property_type']), count($sec), implode(', ', array_map(fn($s) => $secLabels[$s] ?? $s, $sec)), $a['id']]; }
    } else {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_GET['ids'] ?? [])), fn($i) => $i > 0)));
        if (!$ids) { http_response_code(400); exit('Geçersiz özellik listesi.'); }
        if ($export === 'bulk_type') {
            $counts = $bulkTypeCounts($ids);
            $detail = (($_GET['detail'] ?? '') === '1');
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . ($detail ? 'etki-tur-tablosu-detayli.csv' : 'etki-tur-tablosu.csv') . '"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM — Excel'de Türkçe karakterler
            fputcsv($out, ['Tür', 'İlan sayısı', 'Pay (%)']);
            $btTot = (int) ($counts['total'] ?? 0);
            $btPct = fn(int $n) => $btTot > 0 ? str_replace('.', ',', (string) round($n / $btTot * 100, 1)) . '%' : '—';
            foreach (['hotel' => 'Otel', 'villa' => 'Villa', 'yacht' => 'Yat', 'total' => 'Toplam'] as $k => $tl) {
                fputcsv($out, [$tl, (int) $counts[$k], $k === 'total' ? '100%' : $btPct((int) $counts[$k])]);
            }
            // Ayrıntılı mod — seçilen özelliklerin etkilediği ilanlar (aynı ilan birden çok özellikle eşleşse bile tek satır).
            if ($detail) {
                fputcsv($out, []);
                fputcsv($out, ['Tür', 'İlan adı', 'Tedarikçi', 'İlan ID']);
                $fq = db()->prepare('SELECT label FROM property_feature_catalog WHERE id=?');
                $iq = db()->prepare("SELECT p.id, p.name, p.property_type, s.company_name FROM properties p JOIN suppliers s ON s.id=p.supplier_id WHERE p.property_type IN ('hotel','villa','yacht') AND (jsonb_exists(p.product_details -> 'service_pricing', ?) OR (p.product_details -> 'amenities') @> CAST(? AS jsonb) OR (p.product_details -> 'activities') @> CAST(? AS jsonb) OR (p.product_details -> 'events') @> CAST(? AS jsonb)) ORDER BY p.property_type, p.name");
                $seen = [];
                foreach ($ids as $fid) {
                    $fq->execute([$fid]);
                    $label = (string) ($fq->fetchColumn() ?: '');
                    if ($label === '') continue;
                    $iq->execute([$label, json_encode([$label]), json_encode([$label]), json_encode([$label])]);
                    foreach ($iq->fetchAll() as $a) {
                        $pid = (int) $a['id'];
                        if (isset($seen[$pid])) continue;
                        $seen[$pid] = 1;
                        fputcsv($out, [$typeLabelCsv((string) $a['property_type']), (string) $a['name'], (string) ($a['company_name'] ?? ''), $pid]);
                    }
                }
            }
            fclose($out);
            exit;
        }
        if ($export === 'bulk_metrics') {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="etki-metrikler.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM — Excel'de Türkçe karakterler
            fputcsv($out, ['Metrik', 'Değer']);
            fputcsv($out, ['Toplam görsel', (int) ($_GET['media'] ?? 0)]);
            fputcsv($out, ['Oda tipi', (int) ($_GET['rooms'] ?? 0)]);
            fputcsv($out, ['Fiyat planı', (int) ($_GET['plans'] ?? 0)]);
            fputcsv($out, ['Aktif rezervasyon', (int) ($_GET['bookings'] ?? 0)]);
            fclose($out);
            exit;
        }
        $fileName = 'etki-toplu-silme.csv';
        $fq = db()->prepare('SELECT id, label FROM property_feature_catalog WHERE id=?');
        $iq = db()->prepare("SELECT p.id, p.name, p.property_type, s.id AS supplier_id, s.company_name, p.product_details FROM properties p JOIN suppliers s ON s.id=p.supplier_id WHERE p.property_type IN ('hotel','villa','yacht') AND (jsonb_exists(p.product_details -> 'service_pricing', ?) OR (p.product_details -> 'amenities') @> CAST(? AS jsonb) OR (p.product_details -> 'activities') @> CAST(? AS jsonb) OR (p.product_details -> 'events') @> CAST(? AS jsonb))");
        foreach ($ids as $fid) {
            $fq->execute([$fid]);
            $label = (string) ($fq->fetchColumn() ?: '');
            if ($label === '') continue;
            $iq->execute([$label, json_encode([$label]), json_encode([$label]), json_encode([$label])]);
            // Özellik başına tür bazlı etki (otel/villa/yat) — önce topla, sonra her satıra nihai değeri yaz.
            $listingRows = $iq->fetchAll();
            $featByType = ['hotel' => 0, 'villa' => 0, 'yacht' => 0];
            foreach ($listingRows as $a) { if (isset($featByType[$a['property_type']])) $featByType[$a['property_type']]++; }
            foreach ($listingRows as $a) { $sec = $sectionsOf($a, $label); $rows[] = ['Özellik', $label, $a['company_name'] ?? '', $a['name'], $typeLabelCsv($a['property_type']), count($sec), implode(', ', array_map(fn($s) => $secLabels[$s] ?? $s, $sec)), $a['id'], $featByType['hotel'], $featByType['villa'], $featByType['yacht']]; }
        }
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM — Excel'de Türkçe karakterler
    if ($export === 'delete_impact') {
        fputcsv($out, ['Tedarikçi', 'İlan', 'Tür', 'Etkilenen bölüm sayısı', 'Bölümler', 'İlan ID']);
    } else {
        fputcsv($out, ['Özellik', 'Tedarikçi', 'İlan', 'Tür', 'Etkilenen bölüm sayısı', 'Bölümler', 'İlan ID', 'Otel etkisi', 'Villa etkisi', 'Yat etkisi']);
    }
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['admin_csrf'], (string) ($_POST['csrf'] ?? ''))) {
    $err = 'Güvenlik doğrulaması geçersiz.';
  } else {
    try {
      $action = $_POST['action'] ?? '';
      // Özellik bazında çöp kutusu TTL geçersiz kılma: 'kalıcı silme tarihi' (opsiyonel).
      // Boş bırakılırsa özellik varsayılan TTL (silinme + feature_trash_ttl_days) ile silinir.
      $purgeAtRaw = trim((string) ($_POST['purge_at'] ?? ''));
      $purgeAt = null;
      if ($purgeAtRaw !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $purgeAtRaw)) throw new RuntimeException('Kalıcı silme tarihi geçersiz (YYYY-AA-GG).');
        if (strtotime($purgeAtRaw) < strtotime(date('Y-m-d'))) throw new RuntimeException('Kalıcı silme tarihi bugünden önce olamaz.');
        $purgeAt = $purgeAtRaw;
      }
      if ($action === 'add') {
        $code = $_POST['code'] ?? '';
        $label = trim((string) ($_POST['label'] ?? ''));
        $group = trim((string) ($_POST['group'] ?? ''));
        $isHotelCat = in_array($code, ['amenity', 'activity', 'event'], true);
        if (!in_array($code, ['villa', 'yacht', 'amenity', 'activity', 'event'], true) || $label === '' || mb_strlen($label) > 120) throw new RuntimeException('Tür ve özellik adı gereklidir (en fazla 120 karakter).');
        if ($isHotelCat && $group === '') throw new RuntimeException('Otel hizmetleri için grup adı gereklidir.');
        $check = db()->prepare('SELECT id FROM property_feature_catalog WHERE code=? AND label=?');
        $check->execute([$code, $label]);
        if ($check->fetch()) throw new RuntimeException('Bu özellik zaten listede.');
        $max = db()->prepare('SELECT COALESCE(MAX(sort_order),0)+10 FROM property_feature_catalog WHERE code=? AND group_label=?');
        $max->execute([$code, $isHotelCat ? $group : '']);
        db()->prepare('INSERT INTO property_feature_catalog (code,group_label,label,sort_order) VALUES (?,?,?,?)')->execute([$code, $isHotelCat ? $group : '', $label, (int) $max->fetchColumn()]);
        audit_log('feature.add', 'feature_catalog', (int) db()->lastInsertId(), ['code' => $code, 'label' => $label, 'group' => $group]);
        $msg = 'Özellik eklendi.';
      } elseif ($action === 'delete') {
        $featureId = (int) ($_POST['id'] ?? 0);
        $featQ = db()->prepare('SELECT id, code, group_label, label, sort_order, is_active FROM property_feature_catalog WHERE id=?');
        $featQ->execute([$featureId]);
        $feat = $featQ->fetch();
        if (!$feat) throw new RuntimeException('Özellik bulunamadı.');
        // Etki analizi: bu özelliği kullanan kayıtlı villa/yat ilanları.
        $impactSql = "SELECT p.id, p.name, p.property_type, s.id AS supplier_id, s.company_name, p.product_details FROM properties p JOIN suppliers s ON s.id=p.supplier_id WHERE p.property_type IN ('hotel','villa','yacht') AND (jsonb_exists(p.product_details -> 'service_pricing', ?) OR (p.product_details -> 'amenities') @> CAST(? AS jsonb) OR (p.product_details -> 'activities') @> CAST(? AS jsonb) OR (p.product_details -> 'events') @> CAST(? AS jsonb))";
        $impact = db()->prepare($impactSql);
        $impact->execute([$feat['label'], json_encode([$feat['label']]), json_encode([$feat['label']]), json_encode([$feat['label']])]);
        $affected = $impact->fetchAll();
        if (empty($_POST['confirmed'])) {
          // İlk adım: etki listesini göster, onay iste. İlan başına hangi bölümlerin
          // etkilendiğini de hesapla (service_pricing/amenities/activities/events).
          foreach ($affected as &$a) {
            $pd = json_decode((string) ($a['product_details'] ?? '{}'), true) ?: [];
            $sec = [];
            $sp = $pd['service_pricing'] ?? [];
            if (is_array($sp) && array_key_exists($feat['label'], $sp)) $sec[] = 'service_pricing';
            foreach (['amenities', 'activities', 'events'] as $s) {
              if (is_array($pd[$s] ?? null) && in_array($feat['label'], $pd[$s], true)) $sec[] = $s;
            }
            $a['sections'] = $sec;
          }
          unset($a);
          $byType = ['hotel' => 0, 'villa' => 0, 'yacht' => 0];
          foreach ($affected as $a) { if (isset($byType[$a['property_type']])) $byType[$a['property_type']]++; }
          $byType['total'] = count($affected);
          $pendingDelete = ['id' => $featureId, 'label' => $feat['label'], 'affected' => $affected, 'by_type' => $byType];
        } else {
          $res = $deleteFeature($featureId, $purgeAt);
          $msg = 'Özellik silindi' . ($res['affected'] ? ' ve ' . count($res['affected']) . ' ilandan kaldırıldı: ' . implode(', ', array_map(fn($a) => $a['name'], $res['affected'])) . '. ' : '. ') . 'Çöp kutusundan geri alınabilir.';
          $deletedAudit = ['id' => $featureId, 'label' => $res['label'], 'affected' => $res['affected']];
        }
      } elseif ($action === 'restore') {
        $featureId = (int) ($_POST['id'] ?? 0);
        $res = $restoreFeature($featureId);
        $msg = 'Özellik geri yüklendi' . ($res['affected_count'] > 0 ? ' ve ' . $res['affected_count'] . ' ilana tekrar eklendi.' : '.');
      } elseif ($action === 'bulk_restore') {
        // Çöp kutusundan toplu geri yükleme — yalnızca çöp kutusundakiler işlenir, diğerleri atlanır.
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), fn($i) => $i > 0)));
        if (!$ids) throw new RuntimeException('Geri yüklenecek özellik seçin.');
        $done = 0; $skipped = 0; $doneNames = []; $skippedNames = []; $errors = [];
        $curQ = db()->prepare('SELECT label, deleted_at FROM property_feature_catalog WHERE id=?');
        foreach ($ids as $fid) {
          try {
            $curQ->execute([$fid]);
            $cur = $curQ->fetch();
            if (!$cur || $cur['deleted_at'] === null) { $skipped++; $skippedNames[] = $cur ? (string) $cur['label'] : ('#' . $fid); continue; }
            $res = $restoreFeature($fid);
            $doneNames[] = (string) ($res['label'] ?? ('#' . $fid));
            $done++;
          } catch (Throwable $e) { $errors[] = '#' . $fid . ': ' . $e->getMessage(); }
        }
        audit_log('feature.bulk_restore', 'feature_catalog', null, ['count' => $done, 'feature_ids' => $ids, 'skipped_unchanged' => $skipped]);
        $msg = "$done özellik geri yüklendi" . ($skipped ? ", $skipped özellik atlandı (çöp kutusunda değil ya da kayıt yok)" : '') . '.';
        if ($errors) $msg .= ' Hatalar: ' . implode('; ', array_slice($errors, 0, 5)) . '.';
        setcookie('nexus_bulk_result', json_encode([
            'msg' => $msg,
            'sub' => 'restore',
            'done' => $done,
            'skipped' => $skipped,
            'done_names' => array_slice($doneNames, 0, 12),
            'done_more' => max(0, count($doneNames) - 12),
            'skipped_names' => array_slice($skippedNames, 0, 12),
            'skipped_more' => max(0, count($skippedNames) - 12),
            'removed' => 0,
            'trash_ttl' => 0,
            'errors' => array_slice($errors, 0, 5),
        ], JSON_UNESCAPED_UNICODE), [
            'expires' => time() + 3600,
            'path' => '/',
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
      } elseif ($action === 'bulk') {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), fn($i) => $i > 0)));
        $sub = in_array($_POST['sub'] ?? '', ['deactivate', 'activate'], true) ? (string) $_POST['sub'] : 'delete';
        if (!$ids) throw new RuntimeException('En az bir özellik seçin.');
        if ($sub === 'delete' && empty($_POST['confirm'])) {
          // Adım 1: etki özeti (önce onay, sonra uygula).
          $fq = db()->prepare('SELECT id, label FROM property_feature_catalog WHERE id=?');
          $iq = db()->prepare("SELECT p.id, p.name, p.property_type FROM properties p WHERE p.property_type IN ('hotel','villa','yacht') AND (jsonb_exists(p.product_details -> 'service_pricing', ?) OR (p.product_details -> 'amenities') @> CAST(? AS jsonb) OR (p.product_details -> 'activities') @> CAST(? AS jsonb) OR (p.product_details -> 'events') @> CAST(? AS jsonb))");
          $items = [];
          $totalAffected = 0;
          $byType = ['hotel' => 0, 'villa' => 0, 'yacht' => 0];
          foreach ($ids as $fid) {
            $fq->execute([$fid]);
            $f = $fq->fetch();
            if (!$f) continue;
            $iq->execute([$f['label'], json_encode([$f['label']]), json_encode([$f['label']]), json_encode([$f['label']])]);
            $aff = $iq->fetchAll();
            foreach ($aff as $a) { if (isset($byType[$a['property_type']])) $byType[$a['property_type']]++; }
            $items[] = ['label' => $f['label'], 'affected_count' => count($aff), 'affected_names' => array_map(fn($a) => $a['name'], array_slice($aff, 0, 5))];
            $totalAffected += count($aff);
          }
          // Ek metrikler: etkilenen ilanların toplam görsel/oda tipi/plan/rezervasyon sayısı.
          $metrics = ['media' => 0, 'rooms' => 0, 'plans' => 0, 'bookings' => 0];
          if ($totalAffected > 0) {
            $mq = db()->prepare('SELECT
                (SELECT COUNT(*) FROM property_media pm WHERE pm.property_id=p.id) AS media,
                (SELECT COUNT(*) FROM room_types r WHERE r.property_id=p.id) AS rooms,
                (SELECT COUNT(*) FROM rate_plans rp WHERE rp.property_id=p.id) AS plans,
                (SELECT COUNT(*) FROM supplier_bookings b WHERE b.property_id=p.id AND b.status NOT IN (\'cancelled\',\'rejected\')) AS bookings
              FROM properties p WHERE p.id=?');
            $pidList = [];
            $fq2 = db()->prepare('SELECT label FROM property_feature_catalog WHERE id=?');
            $iq2 = db()->prepare("SELECT p.id FROM properties p WHERE p.property_type IN ('hotel','villa','yacht') AND (jsonb_exists(p.product_details -> 'service_pricing', ?) OR (p.product_details -> 'amenities') @> CAST(? AS jsonb) OR (p.product_details -> 'activities') @> CAST(? AS jsonb) OR (p.product_details -> 'events') @> CAST(? AS jsonb))");
            foreach ($ids as $fid) {
                $fq2->execute([$fid]);
                $label2 = (string) ($fq2->fetchColumn() ?: '');
                if ($label2 === '') continue;
                $iq2->execute([$label2, json_encode([$label2]), json_encode([$label2]), json_encode([$label2])]);
                foreach ($iq2->fetchAll(PDO::FETCH_COLUMN) as $pid) $pidList[(int) $pid] = true;
            }
            foreach (array_keys($pidList) as $pid) {
                $mq->execute([$pid]);
                $m = $mq->fetch();
                $metrics['media'] += (int) ($m['media'] ?? 0);
                $metrics['rooms'] += (int) ($m['rooms'] ?? 0);
                $metrics['plans'] += (int) ($m['plans'] ?? 0);
                $metrics['bookings'] += (int) ($m['bookings'] ?? 0);
            }
          }
          $pendingBulk = ['ids' => $ids, 'items' => $items, 'total_affected' => $totalAffected, 'by_type' => $byType, 'metrics' => $metrics];
        } else {
          // Adım 2: uygula (her özellik için tekil akışın aynısı + toplu denetim kaydı).
          $done = 0; $removed = 0; $skipped = 0; $doneNames = []; $skippedNames = []; $errors = [];
          $curQ = db()->prepare('SELECT is_active, label, deleted_at FROM property_feature_catalog WHERE id=?');
          $updQ = db()->prepare('UPDATE property_feature_catalog SET is_active=? WHERE id=?');
          foreach ($ids as $fid) {
            try {
              if ($sub === 'deactivate' || $sub === 'activate') {
                $newState = $sub === 'activate';
                $curQ->execute([$fid]);
                $cur = $curQ->fetch();
                // Idempotent davranış: zaten hedef durumda olan özellik işlenmez, sayılmaz.
                if (!$cur) { $errors[] = "Özellik #$fid bulunamadı."; continue; }
                if ((bool) $cur['is_active'] === $newState) { $skipped++; $skippedNames[] = (string) $cur['label']; continue; }
                $updQ->execute([$newState, $fid]);
                audit_log('feature.toggle', 'feature_catalog', $fid, ['is_active' => $newState, 'bulk' => true]);
              } else {
                // Idempotent davranış: zaten çöp kutusunda olan özellik yeniden silinmez, atlanır.
                $curQ->execute([$fid]);
                $cur = $curQ->fetch();
                if (!$cur) { $errors[] = "Özellik #$fid bulunamadı."; continue; }
                if ($cur['deleted_at'] !== null) { $skipped++; $skippedNames[] = (string) $cur['label']; continue; }
                $res = $deleteFeature($fid, $purgeAt);
                $removed += count($res['affected']);
              }
              $doneNames[] = (string) $cur['label'];
              $done++;
            } catch (Throwable $e) { $errors[] = $e->getMessage(); }
          }
          audit_log($sub === 'delete' ? 'feature.bulk_delete' : ($sub === 'activate' ? 'feature.bulk_activate' : 'feature.bulk_deactivate'), 'feature_catalog', null, [
              'count' => $done,
              'feature_ids' => $ids,
              'skipped_unchanged' => $skipped,
              'affected_count' => $removed,
          ]);
          $msg = $sub === 'delete'
              ? "$done özellik silindi, $removed ilandan kaldırıldı" . ($skipped ? ", $skipped özellik zaten çöp kutusunda olduğu için atlandı (sayılmadı)" : '') . ". Çöp kutusundan geri alınabilir."
              : ($sub === 'activate'
                  ? "$done özellik aktifleştirildi" . ($skipped ? ", $skipped özellik zaten aktif olduğu için atlandı (sayılmadı)" : '') . '.'
                  : "$done özellik pasifleştirildi" . ($skipped ? ", $skipped özellik zaten pasif olduğu için atlandı (sayılmadı)" : '') . '.');
          if ($errors) $msg .= ' Hatalar: ' . implode('; ', $errors) . '.';
          // Toplu işlem sonucunu çereze yaz — sayfa yenilense bile kalıcı bildirim olarak görünür.
          // Toplu işlem sonucunu yapılandırılmış özet kartı olarak çereze yaz (atlanan adlar dahil).
          setcookie('nexus_bulk_result', json_encode([
              'msg' => $msg,
              'sub' => $sub,
              'done' => $done,
              'skipped' => $skipped,
              'done_names' => array_slice($doneNames, 0, 12),
              'done_more' => max(0, count($doneNames) - 12),
              'skipped_names' => array_slice($skippedNames, 0, 12),
              'skipped_more' => max(0, count($skippedNames) - 12),
              'removed' => $removed,
              'trash_ttl' => (int) $ttlDays,
              'errors' => array_slice($errors, 0, 5),
          ], JSON_UNESCAPED_UNICODE), [
              'expires' => time() + 3600,
              'path' => '/',
              'httponly' => false,
              'samesite' => 'Lax',
          ]);
        }
      } elseif ($action === 'taxonomy_add') {
        $tx = $_POST['taxonomy_type'] ?? '';
        $name = trim((string) ($_POST['name'] ?? ''));
        if (!isset($taxonomies[$tx]) || $name === '' || mb_strlen($name) > 120) throw new RuntimeException('Sınıflandırma türü ve adı gereklidir (en fazla 120 karakter).');
        db()->prepare('INSERT INTO hotel_taxonomies (taxonomy_type,name,sort_order) VALUES (?,?,?) ON CONFLICT (taxonomy_type,name) DO NOTHING')->execute([$tx, $name, max(0, (int) ($_POST['sort_order'] ?? 100))]);
        $count = db()->prepare('SELECT COUNT(*) FROM hotel_taxonomies WHERE taxonomy_type=? AND name=?');
        $count->execute([$tx, $name]);
        if ((int) $count->fetchColumn() > 0) {
          $tid = db()->prepare('SELECT id FROM hotel_taxonomies WHERE taxonomy_type=? AND name=?');
          $tid->execute([$tx, $name]);
          audit_log('feature.taxonomy_add', 'hotel_taxonomy', (int) $tid->fetchColumn(), ['taxonomy_type' => $tx, 'name' => $name]);
          $msg = 'Sınıflandırma seçeneği eklendi.';
        } else {
          $msg = 'Bu seçenek zaten listede.';
        }
      } elseif ($action === 'taxonomy_toggle') {
        $txq = db()->prepare('SELECT id, taxonomy_type, name, is_active FROM hotel_taxonomies WHERE id=?');
        $txq->execute([(int) ($_POST['id'] ?? 0)]);
        $txr = $txq->fetch();
        if (!$txr) throw new RuntimeException('Sınıflandırma bulunamadı.');
        db()->prepare('UPDATE hotel_taxonomies SET is_active = NOT is_active WHERE id=?')->execute([(int) $txr['id']]);
        audit_log('feature.taxonomy_toggle', 'hotel_taxonomy', (int) $txr['id'], ['taxonomy_type' => $txr['taxonomy_type'], 'name' => $txr['name'], 'is_active' => !(bool) $txr['is_active']]);
        $msg = 'Sınıflandırma durumu güncellendi.';
      } elseif ($action === 'move') {
        $featureId = (int) ($_POST['id'] ?? 0);
        $dir = ($_POST['direction'] ?? '') === 'up' ? 'up' : 'down';
        $fq = db()->prepare('SELECT id, code, group_label, label FROM property_feature_catalog WHERE id=?');
        $fq->execute([$featureId]);
        $f = $fq->fetch();
        if (!$f) throw new RuntimeException('Özellik bulunamadı.');
        $q = db()->prepare('SELECT id, sort_order FROM property_feature_catalog WHERE code=? AND group_label=? ORDER BY sort_order, id');
        $q->execute([$f['code'], $f['group_label']]);
        $rows = $q->fetchAll();
        $pos = null;
        foreach ($rows as $i => $r) if ((int) $r['id'] === $featureId) { $pos = $i; break; }
        $target = $pos !== null ? ($dir === 'up' ? $pos - 1 : $pos + 1) : -1;
        if ($pos !== null && $target >= 0 && $target < count($rows) && $pos !== $target) {
          $upd = db()->prepare('UPDATE property_feature_catalog SET sort_order=? WHERE id=?');
          $upd->execute([$rows[$target]['sort_order'], $rows[$pos]['id']]);
          $upd->execute([$rows[$pos]['sort_order'], $rows[$target]['id']]);
          // Grubu temiz 10'luk adımlara yeniden numarala.
          $renum = db()->prepare('UPDATE property_feature_catalog SET sort_order=? WHERE id=?');
          $n = 10;
          $all = db()->prepare('SELECT id FROM property_feature_catalog WHERE code=? AND group_label=? ORDER BY sort_order, id');
          $all->execute([$f['code'], $f['group_label']]);
          foreach ($all->fetchAll(PDO::FETCH_COLUMN) as $rid) { $renum->execute([$n, $rid]); $n += 10; }
          audit_log('feature.move', 'feature_catalog', $featureId, ['code' => $f['code'], 'label' => $f['label'], 'group' => $f['group_label'], 'direction' => $dir]);
          $msg = 'Sıralama güncellendi.';
        } else {
          $msg = 'Özellik zaten listenin başında/sonunda.';
        }
      } elseif ($action === 'toggle') {
        $toggleQ = db()->prepare('SELECT id, code, label, is_active FROM property_feature_catalog WHERE id=?');
        $toggleQ->execute([(int) ($_POST['id'] ?? 0)]);
        $toggleF = $toggleQ->fetch();
        db()->prepare('UPDATE property_feature_catalog SET is_active = NOT is_active WHERE id=?')->execute([(int) ($_POST['id'] ?? 0)]);
        audit_log('feature.toggle', 'feature_catalog', (int) ($_POST['id'] ?? 0), ['code' => $toggleF['code'] ?? null, 'label' => $toggleF['label'] ?? null, 'is_active' => $toggleF ? !(bool) $toggleF['is_active'] : null]);
        $msg = 'Özellik durumu güncellendi.';
      } else {
        throw new RuntimeException('Geçersiz işlem.');
      }
    } catch (Throwable $e) {
      $err = $e->getMessage();
    }
  }
}
$rows = db()->query('SELECT id, code, group_label, label, is_active FROM property_feature_catalog WHERE deleted_at IS NULL ORDER BY code, group_label, sort_order, id')->fetchAll();
$byCode = ['villa' => [], 'yacht' => [], 'amenity' => [], 'activity' => [], 'event' => []];
foreach ($rows as $r) $byCode[$r['code']][] = $r;
$txRows = db()->query('SELECT id, taxonomy_type, name, is_active FROM hotel_taxonomies ORDER BY taxonomy_type, sort_order, name')->fetchAll();
$byTx = ['property_type' => [], 'star_rating' => [], 'theme' => []];
foreach ($txRows as $t) $byTx[$t['taxonomy_type']][] = $t;
$sectionTitles = ['villa' => 'Villa özellikleri', 'yacht' => 'Yat özellikleri', 'amenity' => 'Otel olanakları', 'activity' => 'Otel aktiviteleri', 'event' => 'Otel etkinlikleri'];
$trash = db()->query("SELECT f.id, f.code, f.group_label, f.label, f.deleted_at, f.purge_at, COALESCE((SELECT jsonb_array_length(b.affected_properties) FROM feature_delete_backups b WHERE b.feature_id = f.id ORDER BY b.id DESC LIMIT 1), 0) AS affected_count, (SELECT p.expires_at FROM pending_trash_purges p WHERE p.feature_id = f.id AND p.approved_at IS NULL ORDER BY p.expires_at DESC LIMIT 1) AS pending_until FROM property_feature_catalog f WHERE f.deleted_at IS NOT NULL ORDER BY f.deleted_at DESC")->fetchAll();
// Çöp kutusu zenginleştirme: etkin vade (özel tarih veya TTL) + kalan gün hesaplanır,
// acil (< 7 gün) olanlar rozete taşınır ve liste başına sıralanır; aynı grupta vadesi
// en yakın olan önce gelir.
$trashUrgent = 0;
$trashPending = 0;
$trashEnriched = [];
foreach ($trash as $t) {
    $delTs = strtotime((string) $t['deleted_at']) ?: time();
    $custom = !empty($t['purge_at']);
    $purgeTs = $custom ? (strtotime((string) $t['purge_at']) ?: 0) : 0;
    if ($purgeTs <= 0) $purgeTs = $delTs + $ttlDays * 86400;
    $remain = (int) ceil(($purgeTs - time()) / 86400);
    $t['_purge_ts'] = $purgeTs;
    $t['_remain'] = max(0, $remain);
    $t['_custom'] = $custom;
    $t['_urgent'] = $remain < 7;
    if ($t['_urgent']) $trashUrgent++;
    // Bekleyen "son şans" onayı: pending_trash_purges satırı varsa kalan süreyle rozet gösterilir.
    $t['_pending'] = null;
    if (!empty($t['pending_until'])) {
        $pendTs = strtotime((string) $t['pending_until']) ?: 0;
        if ($pendTs > time()) {
            $pendRemain = $pendTs - time();
            $pendDays = (int) floor($pendRemain / 86400);
            $pendHours = (int) floor(($pendRemain % 86400) / 3600);
            $t['_pending'] = $pendDays > 0 ? "{$pendDays} gün {$pendHours} sa" : ($pendHours > 0 ? "{$pendHours} saat" : '1 saatten az');
        }
    }
    if ($t['_pending'] !== null) $trashPending++;
    $trashEnriched[] = $t;
}
usort($trashEnriched, function ($a, $b) {
    if ($a['_urgent'] !== $b['_urgent']) return $a['_urgent'] ? -1 : 1;
    return $a['_purge_ts'] <=> $b['_purge_ts']; // vadesi en yakın önce
});
$trash = $trashEnriched;
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Özellik listeleri</title><style>body{font-family:Arial;background:#f7f7f2;color:#10211f;margin:0}.w{width:min(1000px,calc(100% - 30px));margin:35px auto}.c{background:#fff;border:1px solid #ddd;padding:18px;margin:16px 0;border-radius:8px}.f{display:grid;gap:9px}.r{display:flex;gap:9px;align-items:center;flex-wrap:wrap}input,select,button{padding:9px;font:inherit;border:1px solid #ddd;border-radius:5px}button{background:#10211f;color:#fff;font-weight:bold;border:0;cursor:pointer}.chip{display:inline-flex;align-items:center;gap:8px;border:1px solid #d5dccf;background:#fafbf8;border-radius:20px;padding:5px 10px;font-size:13px;margin:4px}.chip.off{opacity:.5;text-decoration:line-through}.chip.sel{border-color:#2b4a7a;box-shadow:0 0 0 1px #2b4a7a;background:#eef3fb}.chip form{display:inline}.mini{background:#fff;color:#10211f;border:1px solid #ddd;padding:4px 8px;font-size:11px}.del{background:#ffe2de;color:#9d3b1c;border:1px solid #f3c4ba}.ok{background:#e6f8c7;padding:9px;border-radius:5px}.er{background:#ffe2de;padding:9px;border-radius:5px}h2{letter-spacing:-.02em}.two{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media(max-width:700px){.two{grid-template-columns:1fr}}</style></head><body><main class="w"><a href="/nexustraveltech/admin/kontrol-merkezi">← Kontrol merkezi</a><h1>Katalog & sınıflandırma yönetimi</h1><p>Tek sayfa: otel sınıflandırmaları (tesis tipleri, yıldız seviyeleri, temalar) + villa/yat özellikleri + otel olanak/aktivite/etkinlik katalogları. Pasifleştirilen seçenek formlarda görünmez; silinen özellik kullanıldığı ilanlardan da kaldırılır ve çöp kutusundan tek tıkla geri alınabilir. Tüm işlemler denetim kaydına yazılır.</p>
<?php $bulkNoticeRaw = (string) ($_COOKIE['nexus_bulk_result'] ?? ''); $bulkCard = null; $bulkNotice = ''; if ($bulkNoticeRaw !== '') { $decoded = json_decode($bulkNoticeRaw, true); if (is_array($decoded)) { $bulkCard = $decoded; $bulkNotice = (string) ($decoded['msg'] ?? ''); } else { $bulkNotice = trim($bulkNoticeRaw); } } ?>
<?php if ($msg): ?><p class="ok">✓ <?= htmlspecialchars($msg) ?></p><?php endif; ?>
<?php if ($bulkNotice !== '' || $bulkCard): ?><div id="bulkNotice" style="position:sticky;top:8px;z-index:20;background:#10211f;color:#d9f0b4;border:1px solid #405b13;border-radius:8px;padding:12px 16px;margin:10px 0;display:flex;align-items:center;justify-content:space-between;gap:12px;box-shadow:0 4px 14px rgba(0,0,0,.12)"><div style="flex:1">⚡ <b>Toplu işlem sonucu:</b> <?= htmlspecialchars($bulkNotice) ?><?php if ($bulkCard): ?><div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px"><span style="display:inline-block;background:#2e7d32;color:#fff;border-radius:12px;padding:2px 10px;font-size:12px;font-weight:bold">✓ <?= (int) $bulkCard['done'] ?> değişti</span><?php if ((int) ($bulkCard['removed'] ?? 0) > 0): ?><span style="display:inline-block;background:#1a3d6d;color:#fff;border-radius:12px;padding:2px 10px;font-size:12px;font-weight:bold"><?= (int) $bulkCard['removed'] ?> ilan kaldırıldı</span><?php endif; ?><?php if ((int) ($bulkCard['skipped'] ?? 0) > 0): ?><span style="display:inline-block;background:#8a6100;color:#fff;border-radius:12px;padding:2px 10px;font-size:12px;font-weight:bold">⏭ <?= (int) $bulkCard['skipped'] ?> atlandı</span><?php endif; ?><?php if (($bulkCard['sub'] ?? '') === 'delete'): ?><span style="display:inline-block;background:#8a6100;color:#fff;border-radius:12px;padding:2px 10px;font-size:12px;font-weight:bold" title="Silinen özellikler bu süre boyunca çöp kutusunda durur ve geri yüklenebilir">🗑 Çöp kutusunda <?= (int) ($bulkCard['trash_ttl'] ?? 30) ?> gün</span><?php endif; ?></div><?php if (!empty($bulkCard['done_names'])): ?><details style="margin-top:8px"><summary style="cursor:pointer;font-size:12px;color:#d9f0b4;font-weight:bold">Değişenler (<?= (int) $bulkCard['done'] ?>)</summary><ul style="margin:6px 0 0;padding-left:18px;font-size:12px;color:#e8f5d0"><?php foreach ($bulkCard['done_names'] as $dn): ?><li><?= htmlspecialchars((string) $dn) ?></li><?php endforeach; ?><?php if ((int) ($bulkCard['done_more'] ?? 0) > 0): ?><li style="color:#8a9aa0">… ve <?= (int) $bulkCard['done_more'] ?> daha</li><?php endif; ?></ul></details><?php endif; ?><?php if (!empty($bulkCard['skipped_names'])): ?><details style="margin-top:8px"><summary style="cursor:pointer;font-size:12px;color:#d9f0b4;font-weight:bold">Atlananlar (<?= (int) $bulkCard['skipped'] ?>)</summary><ul style="margin:6px 0 0;padding-left:18px;font-size:12px;color:#e8f5d0"><?php foreach ($bulkCard['skipped_names'] as $sn): ?><li><?= htmlspecialchars((string) $sn) ?></li><?php endforeach; ?><?php if ((int) ($bulkCard['skipped_more'] ?? 0) > 0): ?><li style="color:#8a9aa0">… ve <?= (int) $bulkCard['skipped_more'] ?> daha</li><?php endif; ?></ul></details><?php endif; ?><?php if (!empty($bulkCard['errors'])): ?><div style="margin-top:8px;color:#f3c4ba;font-size:12px">Hatalar: <?= htmlspecialchars(implode('; ', array_slice($bulkCard['errors'], 0, 5))) ?><?= count($bulkCard['errors']) > 5 ? ' …' : '' ?></div><?php endif; ?><?php if (($bulkCard['sub'] ?? '') === 'delete' && (int) ($bulkCard['done'] ?? 0) > 0): ?><div style="margin-top:8px"><a href="#trash" style="color:#d9f0b4;font-size:12px;font-weight:bold;text-decoration:underline">↩ Çöp kutusunda geri yükle →</a> <small style="color:#8a9aa0;font-size:11px">(silinen her özelliğin yanındaki ↩ butonuyla)</small></div><?php endif; ?><?php endif; ?></div><button type="button" onclick="dismissBulkNotice()" style="background:#33424a;color:#fff;border:0;padding:6px 12px;font-size:12px;font-weight:bold;cursor:pointer;border-radius:5px;white-space:nowrap">Kapat ✕</button></div><?php endif; ?>
<?php if ($err): ?><p class="er"><?= htmlspecialchars($err) ?></p><?php endif; ?>
<?php if (!empty($pendingDelete)): ?>
<div class="c" style="border-color:#f3c4ba;background:#fff7f5"><h2>⚠ Silinecek: "<?= htmlspecialchars($pendingDelete['label']) ?>" <span style="display:inline-block;background:#8a6100;color:#fff;border-radius:12px;padding:2px 10px;font-size:12px;font-weight:bold;vertical-align:middle" title="Silinen özellik çöp kutusunda durur ve bu süre boyunca geri yüklenebilir">🗑 Çöp kutusunda <?= (int) $ttlDays ?> gün</span></h2><?php if ($pendingDelete['affected']): ?><p>Bu özellik <b><?= count($pendingDelete['affected']) ?></b> kayıtlı ilanda kullanılıyor — <b><?= count(array_unique(array_column($pendingDelete['affected'], 'company_name'))) ?></b> tedarikçi etkilenir. Silerseniz aşağıdaki ilanlardan da kaldırılır (çöp kutusundan tek tıkla geri yüklenebilir):</p><?php $bySupplier = []; foreach ($pendingDelete['affected'] as $a) { $bySupplier[(int) ($a['supplier_id'] ?? 0)][] = $a; } foreach ($bySupplier as $sid => $list): $company = (string) ($list[0]['company_name'] ?? ''); ?><div style="border:1px solid #f3c4ba;border-radius:8px;padding:10px 12px;margin:10px 0;background:#fff"><b>🏢 <?= htmlspecialchars($company) ?></b> <small style="color:#6b7774">— <a href="/nexustraveltech/admin/tedarikci-ilanlari?supplier_id=<?= (int) $sid ?>" style="color:#405b13;font-weight:bold;text-decoration:none" title="Bu tedarikçinin ilanlarını görüntüle"><?= count($list) ?> ilan →</a></small><?php if ($sid > 0): ?>&nbsp;<small style="color:#6b7774"><a href="/nexustraveltech/tedarikci/" target="_blank" rel="noopener" style="color:#6b7774">panel ↗</a></small><?php endif; ?><ul style="margin:8px 0 0"><?php foreach ($list as $a): ?><li><b><?= htmlspecialchars($a['name']) ?></b> <small style="color:#6b7774">(<?= $typeLabel($a['property_type']) ?>)</small><?php $secLabels = ['service_pricing' => 'fiyatlandırma', 'amenities' => 'olanaklar', 'activities' => 'aktiviteler', 'events' => 'etkinlikler']; if (!empty($a['sections'])): ?> <small style="color:#9d3b1c">· <?= count($a['sections']) ?> bölüm (<?= htmlspecialchars(implode(', ', array_map(fn($s) => $secLabels[$s] ?? $s, $a['sections']))) ?>)</small><?php endif; ?></li><?php endforeach; ?></ul></div><?php endforeach; ?><table style="border-collapse:collapse;margin:12px 0;font-size:13px;min-width:280px"><tr><th style="text-align:left;padding:7px 10px;border:1px solid #e1e5de;background:#f7f7f2">Tür</th><th style="text-align:right;padding:7px 10px;border:1px solid #e1e5de;background:#f7f7f2">İlan sayısı</th><th style="text-align:right;padding:7px 10px;border:1px solid #e1e5de;background:#f7f7f2">Pay</th></tr><?php $dtTot = (int) ($pendingDelete['by_type']['total'] ?? 0); foreach (['hotel' => 'Otel', 'villa' => 'Villa', 'yacht' => 'Yat'] as $tk => $tl): $dtN = (int) ($pendingDelete['by_type'][$tk] ?? 0); ?><tr><td style="padding:7px 10px;border:1px solid #e1e5de"><?= $tl ?></td><td style="padding:7px 10px;border:1px solid #e1e5de;text-align:right"><?= $dtN ?></td><td style="padding:7px 10px;border:1px solid #e1e5de;text-align:right"><?= $dtTot > 0 ? str_replace('.', ',', (string) round($dtN / $dtTot * 100, 1)) . '%' : '—' ?></td></tr><?php endforeach; ?><tr><td style="padding:7px 10px;border:1px solid #e1e5de;font-weight:bold;background:#f7f7f2">Toplam</td><td style="padding:7px 10px;border:1px solid #e1e5de;text-align:right;font-weight:bold;background:#f7f7f2"><?= (int) ($pendingDelete['by_type']['total'] ?? 0) ?></td><td style="padding:7px 10px;border:1px solid #e1e5de;text-align:right;font-weight:bold;background:#f7f7f2">100%</td></tr></table><p style="color:#9d3b1c">Etkilenen tedarikçilerin panellerinde bu özellik artık görünmeyecek; yine de silme geri alınabilir — özellik çöp kutusuna taşınır, ilanlara aynı bölüm ve fiyat durumuyla geri yüklenebilir. Varsayılan geri yükleme penceresi: silinme + <?= (int) $ttlDays ?> gün; aşağıdan bu özellik için özel bir kalıcı silme tarihi seçebilirsiniz.</p><?php else: ?><p>Bu özellik şu an hiçbir ilanda kullanılmıyor — güvenle silinebilir.</p><?php endif; ?><form method="post" style="display:flex;gap:9px;margin-top:12px;flex-wrap:wrap;align-items:flex-end"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $pendingDelete['id'] ?>"><input type="hidden" name="confirmed" value="1"><label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:#405b13;font-weight:bold">Kalıcı silme tarihi (opsiyonel)<input type="date" name="purge_at" min="<?= date('Y-m-d') ?>" style="padding:8px;border:1px solid #d8ded8;border-radius:5px;font-weight:normal"><small style="color:#6b7774;font-weight:normal">Boş bırakılırsa varsayılan TTL (silinme + <?= (int) $ttlDays ?> gün) geçerli olur.</small></label><button style="background:#9d3b1c">Evet, sil ve ilanlardan kaldır</button><a href="/nexustraveltech/admin/ozellik-listeleri?export=delete_impact&feature_id=<?= (int) $pendingDelete['id'] ?>" style="display:inline-block;margin-top:10px;background:#f4f6f1;border:1px solid #d5dccf;color:#405b13;padding:8px 12px;font-size:13px;font-weight:bold;text-decoration:none;border-radius:5px">⬇ CSV indir (tedarikçi bazlı)</a><a href="/nexustraveltech/admin/ozellik-listeleri?export=delete_type&feature_id=<?= (int) $pendingDelete['id'] ?>" style="display:inline-block;margin-top:10px;background:#f4f6f1;border:1px solid #d5dccf;color:#405b13;padding:8px 12px;font-size:13px;font-weight:bold;text-decoration:none;border-radius:5px">⬇ Tür tablosu CSV</a><a href="/nexustraveltech/admin/ozellik-listeleri?export=delete_type&detail=1&feature_id=<?= (int) $pendingDelete['id'] ?>" style="display:inline-block;margin-top:10px;background:#eef3fb;border:1px solid #b9cbe8;color:#2b4a7a;padding:8px 12px;font-size:13px;font-weight:bold;text-decoration:none;border-radius:5px" title="Özet + etkilenen ilan adları (tür, tedarikçi, ID)">⬇ Tür tablosu CSV (ayrıntılı)</a><a href="/nexustraveltech/admin/ozellik-listeleri?view=supplier_summary&feature_id=<?= (int) $pendingDelete['id'] ?>" style="display:inline-block;margin-top:10px;background:#eef3fb;border:1px solid #b9cbe8;color:#2b4a7a;padding:8px 12px;font-size:13px;font-weight:bold;text-decoration:none;border-radius:5px">📊 Tedarikçi özeti</a></form><a href="/nexustraveltech/admin/ozellik-listeleri" style="display:inline-block;margin-top:10px;color:#6b7774;font-size:13px">Vazgeç</a></div>
<?php endif; ?>
<?php if (!empty($pendingBulk)): ?>
<div class="c" style="border-color:#f3c4ba;background:#fff7f5"><h2>⚠ <?= count($pendingBulk['items']) ?> özellik silinecek — toplam <?= (int) $pendingBulk['total_affected'] ?> ilan etkilenecek <span style="display:inline-block;background:#10211f;color:#f3c4ba;border:1px solid #9d3b1c;border-radius:12px;padding:2px 10px;font-size:12px;vertical-align:middle">🗑 Geri alınabilir silme</span></h2><table style="border-collapse:collapse;margin:12px 0;font-size:13px;min-width:280px"><tr><th style="text-align:left;padding:7px 10px;border:1px solid #e1e5de;background:#f7f7f2">Tür</th><th style="text-align:right;padding:7px 10px;border:1px solid #e1e5de;background:#f7f7f2">İlan sayısı</th><th style="text-align:right;padding:7px 10px;border:1px solid #e1e5de;background:#f7f7f2">Pay</th></tr><?php $pbTot = (int) ($pendingBulk['total_affected'] ?? 0); foreach (['hotel' => 'Otel', 'villa' => 'Villa', 'yacht' => 'Yat'] as $tk => $tl): $pbN = (int) ($pendingBulk['by_type'][$tk] ?? 0); ?><tr><td style="padding:7px 10px;border:1px solid #e1e5de"><?= $tl ?></td><td style="padding:7px 10px;border:1px solid #e1e5de;text-align:right"><?= $pbN ?></td><td style="padding:7px 10px;border:1px solid #e1e5de;text-align:right"><?= $pbTot > 0 ? str_replace('.', ',', (string) round($pbN / $pbTot * 100, 1)) . '%' : '—' ?></td></tr><?php endforeach; ?><tr><td style="padding:7px 10px;border:1px solid #e1e5de;font-weight:bold;background:#f7f7f2">Toplam</td><td style="padding:7px 10px;border:1px solid #e1e5de;text-align:right;font-weight:bold;background:#f7f7f2"><?= (int) $pendingBulk['total_affected'] ?></td><td style="padding:7px 10px;border:1px solid #e1e5de;text-align:right;font-weight:bold;background:#f7f7f2">100%</td></tr></table><?php if (!empty($pendingBulk['metrics'])): $mk = $pendingBulk['metrics']; ?><div style="display:flex;gap:10px;flex-wrap:wrap;margin:10px 0 2px"><?php foreach (['media' => '🖼 Toplam görsel', 'rooms' => '🛏 Oda tipi', 'plans' => '💶 Fiyat planı', 'bookings' => '📅 Aktif rezervasyon'] as $mKey => $mLabel): ?><span style="display:inline-block;background:#f4f6f1;border:1px solid #e1e5de;border-radius:8px;padding:8px 14px;font-size:13px;cursor:pointer" onclick="toggleMetricDetail('<?= $mKey ?>')" title="İlan bazında görüntüle — hangi ilanda kaç tane"><?= $mLabel ?>: <b><?= (int) $mk[$mKey] ?></b> ▾</span><?php endforeach; ?></div><div id="metricDetailBox" style="display:none;margin:8px 0 4px;padding:10px 12px;background:#f4f6f1;border:1px solid #d5dccf;border-radius:8px;font-size:13px;max-width:760px"></div><?php endif; ?><p style="margin:8px 0 4px;font-weight:bold">Özellik bazında etki:</p><ul><?php foreach ($pendingBulk['items'] as $bi): ?><li><b><?= htmlspecialchars($bi['label']) ?></b> — <?= (int) $bi['affected_count'] ?> ilan<?php if ($bi['affected_names']): ?> (<?= htmlspecialchars(implode(', ', $bi['affected_names'])) ?><?= $bi['affected_count'] > 5 ? ', …' : '' ?>)<?php endif; ?></li><?php endforeach; ?></ul><p style="color:#9d3b1c">🗑 Silinen özellikler <b>çöp kutusuna taşınır</b> ve <b>tek tıkla geri yüklenebilir</b> — kaldırıldığı ilanlara aynı bölüm ve fiyat durumuyla geri eklenir. Geri yükleme penceresi: silinme + <?= (int) $ttlDays ?> gün TTL (kontrol merkezinden ayarlanabilir). Aşağıdan tüm seçilenler için ortak bir kalıcı silme tarihi de belirleyebilirsiniz.</p><form method="post" style="margin-top:12px"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="bulk"><input type="hidden" name="sub" value="delete"><input type="hidden" name="confirm" value="1"><?php foreach ($pendingBulk['ids'] as $fid): ?><input type="hidden" name="ids[]" value="<?= (int) $fid ?>"><?php endforeach; ?><label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:#405b13;font-weight:bold">Kalıcı silme tarihi (opsiyonel — tüm seçilenler için ortak)<input type="date" name="purge_at" min="<?= date('Y-m-d') ?>" style="padding:8px;border:1px solid #d8ded8;border-radius:5px;font-weight:normal"><small style="color:#6b7774;font-weight:normal">Boş bırakılırsa her özellik kendi varsayılan TTL'siyle silinir.</small></label><button style="background:#9d3b1c">Evet, <?= count($pendingBulk['ids']) ?> özelliği sil</button><a href="/nexustraveltech/admin/ozellik-listeleri?export=bulk_impact<?php foreach ($pendingBulk['ids'] as $fid): ?>&ids[]=<?= (int) $fid ?><?php endforeach; ?>" style="display:inline-block;margin-top:10px;background:#f4f6f1;border:1px solid #d5dccf;color:#405b13;padding:8px 12px;font-size:13px;font-weight:bold;text-decoration:none;border-radius:5px">⬇ CSV indir (tedarikçi bazlı)</a><a href="/nexustraveltech/admin/ozellik-listeleri?export=bulk_type<?php foreach ($pendingBulk['ids'] as $fid): ?>&ids[]=<?= (int) $fid ?><?php endforeach; ?>" style="display:inline-block;margin-top:10px;background:#f4f6f1;border:1px solid #d5dccf;color:#405b13;padding:8px 12px;font-size:13px;font-weight:bold;text-decoration:none;border-radius:5px">⬇ Tür tablosu CSV</a><a href="/nexustraveltech/admin/ozellik-listeleri?export=bulk_type&detail=1<?php foreach ($pendingBulk['ids'] as $fid): ?>&ids[]=<?= (int) $fid ?><?php endforeach; ?>" style="display:inline-block;margin-top:10px;background:#eef3fb;border:1px solid #b9cbe8;color:#2b4a7a;padding:8px 12px;font-size:13px;font-weight:bold;text-decoration:none;border-radius:5px" title="Özet + etkilenen ilan adları (tür, tedarikçi, ID)">⬇ Tür tablosu CSV (ayrıntılı)</a><a href="/nexustraveltech/admin/ozellik-listeleri?export=bulk_metrics<?php foreach ($pendingBulk['ids'] as $fid): ?>&ids[]=<?= (int) $fid ?><?php endforeach; ?><?php $mk = $pendingBulk['metrics'] ?? []; ?>&media=<?= (int) ($mk['media'] ?? 0) ?>&rooms=<?= (int) ($mk['rooms'] ?? 0) ?>&plans=<?= (int) ($mk['plans'] ?? 0) ?>&bookings=<?= (int) ($mk['bookings'] ?? 0) ?>" style="display:inline-block;margin-top:10px;background:#f4f6f1;border:1px solid #d5dccf;color:#405b13;padding:8px 12px;font-size:13px;font-weight:bold;text-decoration:none;border-radius:5px">⬇ Metrikler CSV</a><a href="/nexustraveltech/admin/ozellik-listeleri?view=supplier_summary<?php foreach ($pendingBulk['ids'] as $fid): ?>&ids[]=<?= (int) $fid ?><?php endforeach; ?>" style="display:inline-block;margin-top:10px;background:#eef3fb;border:1px solid #b9cbe8;color:#2b4a7a;padding:8px 12px;font-size:13px;font-weight:bold;text-decoration:none;border-radius:5px">📊 Tedarikçi özeti</a></form><a href="/nexustraveltech/admin/ozellik-listeleri" style="display:inline-block;margin-top:10px;color:#6b7774;font-size:13px">Vazgeç</a></div>
<?php endif; ?>
<?php if (!empty($deletedAudit)): ?>
<div class="c" style="border-color:#bcd98a;background:#f4fbea"><h2>✓ "<?= htmlspecialchars($deletedAudit['label']) ?>" silindi</h2><?php if ($deletedAudit['affected']): ?><p>Kaldırıldığı ilanlar (<b><?= count($deletedAudit['affected']) ?></b>):</p><ul><?php foreach ($deletedAudit['affected'] as $a): ?><li><b><?= htmlspecialchars($a['name']) ?></b> <small style="color:#6b7774">(<?= $typeLabel($a['property_type']) ?> · <?= htmlspecialchars($a['company_name']) ?>)</small></li><?php endforeach; ?></ul><?php else: ?><p>Hiçbir ilanda kullanılmıyordu.</p><?php endif; ?><form method="post" style="margin-top:12px"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?= (int) $deletedAudit['id'] ?>"><button style="background:#405b13">↩ Geri al — özelliği ve ilanları geri yükle</button></form></div>
<?php endif; ?>
<form class="c f" method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="add"><div class="r"><select name="code"><option value="villa">Villa</option><option value="yacht">Yat</option><option value="amenity">Otel olanakları</option><option value="activity">Otel aktiviteleri</option><option value="event">Otel etkinlikleri</option></select><input name="label" placeholder="Yeni özellik adı" maxlength="120" required style="flex:1"><input name="group" placeholder="Grup (yalnızca otel hizmetleri; Örn. Spa & spor)" maxlength="120" style="flex:1"><button>Özellik ekle</button></div></form>
<h2 style="margin:26px 0 4px;border-top:1px solid #e2e6df;padding-top:20px">Otel sınıflandırmaları</h2>
<div class="two"><?php foreach ($taxonomies as $txKey => $txTitle): ?>
<div class="c"><h2><?= htmlspecialchars($txTitle) ?> <small style="color:#6b7774;font-weight:normal">(<?= count($byTx[$txKey]) ?>)</small></h2><form class="r" method="post" style="margin-bottom:12px"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="taxonomy_add"><input type="hidden" name="taxonomy_type" value="<?= htmlspecialchars($txKey) ?>"><input name="name" placeholder="Yeni seçenek adı" maxlength="120" required style="flex:1"><input name="sort_order" type="number" value="100" min="0" title="Sıra" style="width:80px"><button>+ Ekle</button></form><?php foreach (($byTx[$txKey] ?? []) as $tx): ?><span class="chip <?= $tx['is_active'] ? '' : 'off' ?>"><?= htmlspecialchars($tx['name']) ?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="taxonomy_toggle"><input type="hidden" name="id" value="<?= (int) $tx['id'] ?>"><button class="mini" title="Aktif/pasif"><?= $tx['is_active'] ? 'Pasifleştir' : 'Aktifleştir' ?></button></form></span><?php endforeach; ?><?php if (!$byTx[$txKey]): ?><p style="color:#6b7774">Liste boş.</p><?php endif; ?></div>
<?php endforeach; ?></div>
<h2 style="margin:26px 0 4px;border-top:1px solid #e2e6df;padding-top:20px">Özellik katalogları</h2>
<div id="bulkBar" style="display:none;position:sticky;top:0;z-index:5;background:#10211f;color:#fff;padding:10px 14px;border-radius:8px;margin:14px 0;align-items:center;gap:12px;flex-wrap:wrap"><b id="bulkCount">0</b> özellik seçildi <span style="display:inline-flex;gap:6px;align-items:center"><span class="bulk-badge" data-b="on" style="display:none;background:#2e7d32;color:#fff;border-radius:12px;padding:2px 10px;font-size:12px;font-weight:bold"></span><span class="bulk-badge" data-b="off" style="display:none;background:#b26a00;color:#fff;border-radius:12px;padding:2px 10px;font-size:12px;font-weight:bold"></span></span><span id="bulkCatBadges" style="display:inline-flex;gap:6px;align-items:center"></span><details id="bulkNamesBox" style="display:none;position:relative"><summary id="bulkNamesSum" style="cursor:pointer;font-size:12px;color:#d9f0b4;font-weight:bold;user-select:none">İsimler ▾</summary><div id="bulkNamesList" style="position:absolute;top:100%;left:0;background:#fff;color:#10211f;border:1px solid #d5dccf;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,.18);padding:8px 12px;max-height:280px;overflow:auto;min-width:240px;font-size:13px;z-index:30"></div></details><button type="button" class="mini" style="background:#33424a;color:#fff" onclick="selectAllFeats()">Tümünü seç</button><button type="button" class="mini" style="background:#33424a;color:#fff" onclick="selectState('on')" title="Yalnızca aktif (AÇIK) özellikleri işaretle">Yalnızca aktifleri seç</button><button type="button" class="mini" style="background:#33424a;color:#fff" onclick="selectState('off')" title="Yalnızca pasif (KAPALI) özellikleri işaretle">Yalnızca pasifleri seç</button><button type="button" class="mini" style="background:#33424a;color:#fff" onclick="collapseAll(0)">Tümünü daralt</button><button type="button" class="mini" style="background:#33424a;color:#fff" onclick="collapseAll(1)">Tümünü genişlet</button><button type="button" class="mini" style="background:#d9f0b4;color:#10211f;font-weight:bold" onclick="bulkGo('activate')">Aktifleştir</button><button type="button" class="mini" style="background:#e6f8c7;color:#10211f;font-weight:bold" onclick="bulkGo('deactivate')">Pasifleştir</button><button type="button" class="mini" style="background:#b0301a;color:#fff;font-weight:bold" onclick="bulkGo('delete')">Sil</button><button type="button" class="mini" style="background:#33424a;color:#fff" onclick="clearBulk()">Seçimi temizle</button></div>
<form id="bulkForm" method="post" style="display:none"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="bulk"><input type="hidden" name="sub" id="bulkSub" value="delete"></form>
<div class="two"><?php foreach ($sectionTitles as $code => $title): $isHotelCat = in_array($code, ['amenity', 'activity', 'event'], true); $grouped = []; foreach (($byCode[$code] ?? []) as $item) $grouped[$item['group_label'] ?: 'Genel'][] = $item; ?>
<div class="c" style="padding:0"><div style="display:flex;justify-content:space-between;align-items:center;padding:14px 18px;cursor:pointer" onclick="toggleCat('<?= htmlspecialchars($code) ?>')" title="Tıklayınca genişlet/daralt"><h2 style="margin:0"><?= $title ?> <small style="color:#6b7774;font-weight:normal">(<?= count($byCode[$code]) ?>)</small></h2><span id="catArrow-<?= htmlspecialchars($code) ?>" style="color:#6b7774;font-size:13px;user-select:none">▾ daralt</span></div><div id="catBody-<?= htmlspecialchars($code) ?>"><div style="padding:0 18px 14px"><label style="display:inline-flex;align-items:center;gap:4px;font-size:12px;color:#6b7774;margin:0 0 6px"><input type="checkbox" class="selall" data-code="<?= htmlspecialchars($code) ?>"> Bu katalogdakilerin tümünü seç</label><?php if (!$byCode[$code]): ?><p style="color:#6b7774">Liste boş — yukarıdan ekleyin.</p><?php endif; ?>
<?php foreach ($grouped as $groupName => $groupItems): ?><?php if ($isHotelCat): ?><h3 style="font-size:13px;margin:14px 0 4px;color:#405b13"><?= htmlspecialchars($groupName) ?></h3><?php endif; ?><?php foreach ($groupItems as $item): ?><span class="chip <?= $item['is_active'] ? '' : 'off' ?>" id="feat-<?= (int) $item['id'] ?>"><label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer;margin-right:2px" title="Toplu işlem için seç"><input type="checkbox" class="feat-check" data-code="<?= htmlspecialchars($code) ?>" data-state="<?= $item['is_active'] ? 'on' : 'off' ?>" data-label="<?= htmlspecialchars($item['label']) ?>" value="<?= (int) $item['id'] ?>"></label><?= htmlspecialchars($item['label']) ?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="direction" value="up"><button class="mini" title="Yukarı taşı">↑</button></form><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="direction" value="down"><button class="mini" title="Aşağı taşı">↓</button></form><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><button class="mini" title="Aktif/pasif"><?= $item['is_active'] ? 'Pasifleştir' : 'Aktifleştir' ?></button></form><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><button class="mini del" onclick="return confirm('Bu özellik silinsin mi?');">×</button></form></span><?php endforeach; ?><?php endforeach; ?></div></div></div>
<?php endforeach; ?></div>
<?php if ($trash): ?><h2 id="trash" style="margin:26px 0 4px;border-top:1px solid #e2e6df;padding-top:20px">🗑 Çöp kutusu — geri alınabilir silmeler<?php if ($trashUrgent > 0): ?> <span style="display:inline-block;background:#ffe2de;color:#b0301a;border:1px solid #f3c4ba;border-radius:12px;padding:2px 10px;font-size:12px;vertical-align:middle" title="Kalıcı silmeye 7 günden az kalan özellikler">🚨 <?= (int) $trashUrgent ?> acil</span><?php endif; ?><?php if ($trashPending > 0): ?> <span style="display:inline-block;background:#fff3cd;color:#8a6100;border:1px solid #e0c9a3;border-radius:12px;padding:2px 10px;font-size:12px;vertical-align:middle" title="Son şans onayı bekleyen özellikler — e-postadaki bağlantıyla onaylanır">⏳ <?= (int) $trashPending ?> onay bekliyor</span><?php endif; ?></h2><div class="c" style="border-color:#e0c9a3;background:#fdf9f2"><p style="color:#6b7774;font-size:13px;margin-top:0">Silinen özellikler burada durur ve tek tıkla geri yüklenebilir; kaldırıldığı ilanlara aynı bölüm ve fiyat durumuyla geri eklenir. Her özellik <b>silinme + <?= (int) $ttlDays ?> gün TTL</b> sonra kalıcı silinir (geri alınamaz); silme sırasında <b>özel kalıcı silme tarihi</b> verilenler o tarihte silinir.</p><div style="display:flex;align-items:center;gap:12px;margin:4px 0 10px;flex-wrap:wrap"><label style="font-size:13px;cursor:pointer;user-select:none"><input type="checkbox" id="trashAll" onchange="trashToggleAll(this)" style="vertical-align:-2px"> Tümünü seç</label><span id="trashSelCount" style="font-size:12px;color:#6b7774"></span><form method="post" style="display:inline" onsubmit="return trashSubmitCheck()"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="bulk_restore"><span id="trashRestoreIds"></span><button type="submit" id="trashBulkBtn" class="mini" style="background:#e6f8c7;color:#10211f;border:1px solid #bcd98a;font-weight:bold" disabled>↩ Seçilenleri geri yükle</button></form></div><?php foreach ($trash as $t): $customPurge = $t['_custom']; $purgeTs = $t['_purge_ts']; $remainDays = $t['_remain']; $urgent = $t['_urgent']; ?><span class="chip" style="opacity:1;border-color:#e0c9a3" id="feat-<?= (int) $t['id'] ?>"><input type="checkbox" class="trash-check" value="<?= (int) $t['id'] ?>" data-label="<?= htmlspecialchars($t['label'], ENT_QUOTES) ?>" onchange="trashRefresh()" title="Toplu geri yükleme için seç" style="vertical-align:-2px;margin-right:6px;cursor:pointer"><?= htmlspecialchars($t['label']) ?> <small style="color:#6b7774">(<?= htmlspecialchars($sectionTitles[$t['code']] ?? (string) $t['code']) ?> · <?= (int) $t['affected_count'] ?> ilan · silindi <?= htmlspecialchars(mb_substr((string) $t['deleted_at'], 0, 16)) ?> · <b style="color:<?= $urgent ? '#b0301a' : '#8a6100' ?>">kalıcı silme <?= date('Y-m-d', $purgeTs) ?> (<?= max(0, $remainDays) ?> gün)<?= $customPurge ? ' · özel tarih' : '' ?></b><?php if ($t['_pending'] !== null): ?> <span style="display:inline-block;background:#fff3cd;color:#8a6100;border:1px solid #e0c9a3;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:bold" title="Kalıcı silme onayı bekleniyor — son şans e-postasındaki bağlantıyla onaylanır">⏳ onay bekliyor · <?= htmlspecialchars($t['_pending']) ?></span><?php endif; ?>)</small><?php if ((int) $t['affected_count'] > 0): ?><button class="mini" type="button" onclick="toggleTrashPreview(<?= (int) $t['id'] ?>)" title="Kaldırıldığı ilanları göster">▸ <?= (int) $t['affected_count'] ?> ilan</button><?php endif; ?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="mini" style="background:#e6f8c7;color:#10211f;border:1px solid #bcd98a" title="Geri yükle">↩ Geri yükle</button></form></span><?php if ((int) $t['affected_count'] > 0): ?><div id="tp-<?= (int) $t['id'] ?>" style="display:none;margin:2px 0 4px 10px;padding:8px 12px;background:#fff;border:1px solid #e0c9a3;border-radius:6px;font-size:12px;color:#10211f"></div><?php endif; ?><?php endforeach; ?></div><?php endif; ?>
</main><script>
function dismissBulkNotice(){document.cookie='nexus_bulk_result=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/';var n=document.getElementById('bulkNotice');if(n)n.remove()}
function bulkRefresh(){var sel=[].filter.call(document.querySelectorAll('.feat-check'),function(x){return x.checked});var c=sel.length;document.getElementById('bulkCount').textContent=c;var bar=document.getElementById('bulkBar');bar.style.display=c?'flex':'none';var on=sel.filter(function(x){return x.dataset.state==='on'}).length,off=c-on;var onBadge=bar.querySelector('[data-b="on"]'),offBadge=bar.querySelector('[data-b="off"]');onBadge.style.display=on?'inline-block':'none';onBadge.textContent='● '+on+' Aktif';offBadge.style.display=off?'inline-block':'none';offBadge.textContent='○ '+off+' Pasif';var cats=[['villa','Villa','#405b13'],['yacht','Yat','#1a3d6d'],['amenity','Otel olanakları','#8a6100'],['activity','Otel aktiviteleri','#7a4a8a'],['event','Otel etkinlikleri','#9d3b1c']],catBox=document.getElementById('bulkCatBadges'),catHtml='';cats.forEach(function(ca){var n=sel.filter(function(x){return x.dataset.code===ca[0]}).length;if(n>0)catHtml+='<span style="display:inline-block;background:'+ca[2]+';color:#fff;border-radius:12px;padding:2px 10px;font-size:12px;font-weight:bold" title="'+ca[1]+' kataloğundan seçilen özellik">'+ca[1]+': '+n+'</span>'});catBox.innerHTML=catHtml;document.querySelectorAll('.feat-check').forEach(function(x){var c=x.closest('.chip');if(c)c.classList.toggle('sel',x.checked)});var names=sel.map(function(x){return x.dataset.label||('#'+x.value)}),namesBox=document.getElementById('bulkNamesBox'),namesList=document.getElementById('bulkNamesList');if(namesBox){namesBox.style.display=c?'inline-block':'none';namesList.innerHTML='';if(c){names.slice(0,25).forEach(function(nm){var d=document.createElement('div');d.style.padding='2px 0';d.textContent='• '+nm;namesList.appendChild(d)});if(names.length>25){var m=document.createElement('div');m.style.cssText='color:#64716d;font-size:11px;padding-top:4px';m.textContent='… ve '+(names.length-25)+' daha';namesList.appendChild(m)};document.getElementById('bulkNamesSum').textContent='İsimler ('+names.length+') ▾'}}document.querySelectorAll('.selall').forEach(function(sa){var code=sa.dataset.code,all=document.querySelectorAll('.feat-check[data-code="'+code+'"]'),ch=document.querySelectorAll('.feat-check[data-code="'+code+'"]:checked');sa.checked=all.length>0&&ch.length===all.length})}
function clearBulk(){document.querySelectorAll('.feat-check:checked').forEach(function(c){c.checked=false});bulkRefresh()}
function selectAllFeats(){document.querySelectorAll('.feat-check').forEach(function(c){c.checked=true});document.querySelectorAll('.selall').forEach(function(sa){sa.checked=true});bulkRefresh()}
function selectState(st){document.querySelectorAll('.feat-check').forEach(function(x){x.checked=x.dataset.state===st});bulkRefresh()}
function catState(){try{return JSON.parse(localStorage.getItem('nexus_cat_state')||'{}')}catch(e){return{}}}
function catSave(st){try{localStorage.setItem('nexus_cat_state',JSON.stringify(st))}catch(e){}}
function catFromUrl(){var p=new URLSearchParams(location.search).get('cats')||'';if(p==='')return null;return p.split(',').filter(function(x){return x})}
function catToUrl(st){var collapsed=Object.keys(st).filter(function(k){return st[k]===1});var params=new URLSearchParams(location.search);if(collapsed.length){params.set('cats',collapsed.join(','))}else{params.delete('cats')};var qs=params.toString();try{history.replaceState(null,'',location.pathname+(qs?'?'+qs:''))}catch(e){}}
function catApply(){var fromUrl=catFromUrl(),st;if(fromUrl!==null){st={};fromUrl.forEach(function(c){st[c]=1});catSave(st)}else{st=catState()}document.querySelectorAll('[id^="catBody-"]').forEach(function(b){var code=b.id.replace('catBody-',''),hidden=st[code]===1;b.style.display=hidden?'none':'block';var a=document.getElementById('catArrow-'+code);if(a)a.textContent=hidden?'▸ genişlet':'▾ daralt'})}
function toggleCat(code){var b=document.getElementById('catBody-'+code),a=document.getElementById('catArrow-'+code);if(!b)return;var hidden=b.style.display==='none';b.style.display=hidden?'block':'none';a.textContent=hidden?'▾ daralt':'▸ genişlet';var st=catState();st[code]=hidden?0:1;catSave(st);catToUrl(st)}
function collapseAll(open){document.querySelectorAll('[id^="catBody-"]').forEach(function(b){b.style.display=open?'block':'none';var a=document.getElementById('catArrow-'+b.id.replace('catBody-','catArrow-'));if(a)a.textContent=open?'▾ daralt':'▸ genişlet'});var st={};if(!open)document.querySelectorAll('[id^="catBody-"]').forEach(function(b){st[b.id.replace('catBody-','')]=1});catSave(st);catToUrl(st)}
catApply();
function focusFeature(){if(!location.hash||location.hash.indexOf('#feat-')!==0)return;var el=document.getElementById(location.hash.slice(1));if(!el)return;var body=el.closest('[id^="catBody-"]');if(body&&body.style.display==='none'){var code=body.id.replace('catBody-',''),a=document.getElementById('catArrow-'+code);body.style.display='block';if(a)a.textContent='▾ daralt'}el.scrollIntoView({behavior:'smooth',block:'center'});if(!document.getElementById('nexusFeatFlash')){var s=document.createElement('style');s.id='nexusFeatFlash';s.textContent='@keyframes nexusFeatFlash{0%,100%{box-shadow:0 0 0 2px transparent}25%,75%{box-shadow:0 0 0 3px #2b4a7a,0 0 14px rgba(43,74,122,.55)}}';document.head.appendChild(s)}el.style.animation='nexusFeatFlash 1.1s ease-in-out 3';setTimeout(function(){el.style.animation=''},3600)}
focusFeature();
function bulkGo(sub){var ids=[].map.call(document.querySelectorAll('.feat-check:checked'),function(x){return x.value});if(!ids.length)return;if(sub==='delete'&&!confirm(ids.length+' özellik silinecek. Önce etki özeti gösterilecek, devam edilsin mi?'))return;var f=document.getElementById('bulkForm');f.querySelectorAll('input[name="ids[]"]').forEach(function(x){x.remove()});ids.forEach(function(v){var i=document.createElement('input');i.type='hidden';i.name='ids[]';i.value=v;f.appendChild(i)});document.getElementById('bulkSub').value=sub;f.submit()}
document.querySelectorAll('.feat-check').forEach(function(c){c.addEventListener('change',bulkRefresh)});
document.querySelectorAll('.selall').forEach(function(sa){sa.addEventListener('change',function(){var code=sa.dataset.code;document.querySelectorAll('.feat-check[data-code="'+code+'"]').forEach(function(c){c.checked=sa.checked});bulkRefresh()})});
function toggleTrashPreview(id){var box=document.getElementById('tp-'+id);if(!box)return;if(box.style.display!=='none'){box.style.display='none';return}if(box.dataset.loaded){box.style.display='block';return}box.textContent='Yükleniyor…';box.style.display='block';fetch('/nexustraveltech/admin/ozellik-listeleri?qview=trash_listings&id='+id).then(function(r){return r.json()}).then(function(d){if(!d.ok){box.innerHTML='<b style="color:#9d3b1c">'+d.error+'</b>';return}if(!d.listings.length){box.innerHTML='<span style="color:#6b7774">Hiçbir ilandan kaldırılmamış.</span>';box.dataset.loaded='1';return}var html='<div style="font-weight:bold;margin-bottom:4px">“'+d.label+'” kaldırıldığı ilanlar ('+d.listings.length+'):</div><ul style="margin:0;padding-left:18px">';d.listings.forEach(function(p){html+='<li><b>'+p.name+'</b> <small style="color:#6b7774">(#'+p.id+')</small>'+(p.sections.length?' <small style="color:#9d3b1c">· '+p.sections.join(', ')+'</small>':'')+(p.price!==''?' <small style="color:#405b13">· fiyat: '+p.price+'</small>':'')+'</li>'});html+='</ul>';box.innerHTML=html;box.dataset.loaded='1'}).catch(function(){box.innerHTML='<b style="color:#9d3b1c">Liste alınamadı (oturum süresi dolmuş olabilir).</b>'})}
function trashRefresh(){var s=[].filter.call(document.querySelectorAll('.trash-check'),function(x){return x.checked});var idsBox=document.getElementById('trashRestoreIds');if(idsBox)idsBox.innerHTML='';s.forEach(function(x){var i=document.createElement('input');i.type='hidden';i.name='ids[]';i.value=x.value;idsBox.appendChild(i)});var cnt=document.getElementById('trashSelCount');if(cnt)cnt.textContent=s.length?('Seçili: '+s.length):'';var all=document.getElementById('trashAll');if(all)all.checked=s.length>0&&s.length===document.querySelectorAll('.trash-check').length;var btn=document.getElementById('trashBulkBtn');if(btn)btn.disabled=!s.length}
function trashToggleAll(ch){document.querySelectorAll('.trash-check').forEach(function(x){x.checked=ch.checked});trashRefresh()}
function trashSubmitCheck(){var s=[].filter.call(document.querySelectorAll('.trash-check'),function(x){return x.checked});if(!s.length){alert('Geri yüklenecek özellik seçin.');return false}return confirm(s.length+' özellik çöp kutusundan geri yüklenecek. Devam edilsin mi?')}
var BULK_METRIC_IDS=<?= json_encode($pendingBulk['ids'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
var METRIC_LABELS={media:'Görsel',rooms:'Oda tipi',plans:'Fiyat planı',bookings:'Rezervasyon'};
function toggleMetricDetail(key){var box=document.getElementById('metricDetailBox');if(!box)return;if(box.dataset.key===key&&box.style.display!=='none'){box.style.display='none';box.dataset.key='';return}box.dataset.key=key;box.style.display='block';if(box.dataset.loaded){sortMetricRows(key);return}box.textContent='Yükleniyor…';fetch('/nexustraveltech/admin/ozellik-listeleri?qview=bulk_metrics&'+BULK_METRIC_IDS.map(function(i){return 'ids[]='+i}).join('&')).then(function(r){return r.json()}).then(function(d){if(!d.ok){box.innerHTML='<b style="color:#8e2410">'+d.error+'</b>';return}box.dataset.data=JSON.stringify(d.list);box.dataset.loaded='1';box.innerHTML='<div style="font-weight:bold;margin-bottom:6px">İlan bazında metrikler ('+d.list.length+' ilan) — sütun başlığına tıklayınca sıralanır:</div><table style="border-collapse:collapse;width:100%"><tr><th data-k="name" onclick="sortMetricRows(\'name\')" style="text-align:left;padding:5px 8px;border:1px solid #d5dccf;font-size:11px;cursor:pointer;background:#eef3fb">İlan</th>'+Object.keys(METRIC_LABELS).map(function(k){return '<th data-k="'+k+'" onclick="sortMetricRows(\''+k+'\')" style="text-align:right;padding:5px 8px;border:1px solid #d5dccf;font-size:11px;cursor:pointer;background:#eef3fb">'+METRIC_LABELS[k]+'</th>'}).join('')+'</tr><tbody id="metricRows"></tbody></table>';sortMetricRows(key)}).catch(function(){box.innerHTML='<b style="color:#8e2410">Liste alınamadı (oturum süresi dolmuş olabilir).</b>'})}
function sortMetricRows(key){var box=document.getElementById('metricDetailBox');if(!box||!box.dataset.data)return;var list=JSON.parse(box.dataset.data);list.sort(function(a,b){if(key==='name')return a.name.localeCompare(b.name,'tr');return (b[key]||0)-(a[key]||0)});var TL={hotel:'Otel',villa:'Villa',yacht:'Yat'};document.getElementById('metricRows').innerHTML=list.map(function(p){return '<tr><td style="padding:5px 8px;border:1px solid #d5dccf"><b>'+p.name+'</b> <small style="color:#6b7774">('+(TL[p.type]||p.type)+') · '+p.company+'</small></td><td style="padding:5px 8px;border:1px solid #d5dccf;text-align:right">'+p.media+'</td><td style="padding:5px 8px;border:1px solid #d5dccf;text-align:right">'+p.rooms+'</td><td style="padding:5px 8px;border:1px solid #d5dccf;text-align:right">'+p.plans+'</td><td style="padding:5px 8px;border:1px solid #d5dccf;text-align:right">'+p.bookings+'</td></tr>'}).join('');box.querySelectorAll('th').forEach(function(th){th.style.background=th.dataset.k===key?'#2b4a7a;color:#fff':'#eef3fb'})}
</script><?php require_once __DIR__ . '/../config/ai_widget.php'; ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); ?></body></html>
