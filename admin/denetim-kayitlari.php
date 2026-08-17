<?php

declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';

require_admin();

// Hızlı bakış uç noktası — toplu işlem detayındaki ID chip'ine tıklayınca özelliğin
// o anki durumunu döndürür (aktif/pasif/çöp kutusu + kalan gün).
if (($_GET['qview'] ?? '') === 'feature') {
    $fid = (int) ($_GET['id'] ?? 0);
    header('Content-Type: application/json; charset=UTF-8');
    if ($fid <= 0) { http_response_code(400); exit(json_encode(['ok' => false, 'error' => 'Geçersiz özellik kimliği.'])); }
    $fq = db()->prepare('SELECT id, code, group_label, label, is_active, deleted_at, purge_at FROM property_feature_catalog WHERE id=?');
    $fq->execute([$fid]);
    $f = $fq->fetch();
    if (!$f) { http_response_code(404); exit(json_encode(['ok' => false, 'error' => 'Özellik bulunamadı (kalıcı silinmiş olabilir).'])); }
    $ttl = max(7, (int) platform_setting('feature_trash_ttl_days', 30));
    $delTs = $f['deleted_at'] !== null ? (strtotime((string) $f['deleted_at']) ?: 0) : 0;
    $customPurge = !empty($f['purge_at']);
    $purgeTs = $customPurge ? (strtotime((string) $f['purge_at']) ?: 0) : 0;
    if ($purgeTs <= 0 && $delTs > 0) $purgeTs = $delTs + $ttl * 86400;
    $remain = $purgeTs > 0 ? (int) ceil(($purgeTs - time()) / 86400) : 0;
    exit(json_encode([
        'ok' => true,
        'id' => (int) $f['id'],
        'code' => (string) $f['code'],
        'label' => (string) $f['label'],
        'group' => (string) ($f['group_label'] ?? ''),
        'is_active' => (bool) $f['is_active'],
        'in_trash' => $f['deleted_at'] !== null,
        'deleted_at' => (string) ($f['deleted_at'] ?? ''),
        'purge_at' => (string) ($f['purge_at'] ?? ''),
        'custom_purge' => $customPurge,
        'remain_days' => $remain,
    ], JSON_UNESCAPED_UNICODE));
}

// feature.delete satırından CSV: silinen özelliğin etki listesi.
// Canlı product_details yerine feature_delete_backups yedeğinden okunur — silme sonrası
// özellik ilanlardan temizlendiği için canlı sorgu boş döner, yedek etkiyi korur.
if (($_GET['export'] ?? '') === 'feature_delete_impact' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $fid = (int) ($_GET['feature_id'] ?? 0);
    if ($fid <= 0) { http_response_code(400); exit('Geçersiz özellik kimliği.'); }
    $bkQ = db()->prepare('SELECT label, affected_properties FROM feature_delete_backups WHERE feature_id=? ORDER BY id DESC LIMIT 1');
    $bkQ->execute([$fid]);
    $bk = $bkQ->fetch();
    if (!$bk) { http_response_code(404); exit('Silme yedeği bulunamadı.'); }
    $props = json_decode((string) ($bk['affected_properties'] ?? '[]'), true) ?: [];
    $secLabels = ['service_pricing' => 'fiyatlandırma', 'amenities' => 'olanaklar', 'activities' => 'aktiviteler', 'events' => 'etkinlikler'];
    $typeLabelCsv = fn($t) => ['hotel' => 'Otel', 'villa' => 'Villa', 'yacht' => 'Yat'][$t] ?? $t;
    // Yedek yalnızca id/name/sections/price tutar — ilan adı/tür/tedarikçi properties'ten zenginleştirilir.
    $pids = array_values(array_filter(array_map(fn($p) => (int) ($p['id'] ?? 0), $props), fn($i) => $i > 0));
    $meta = [];
    if ($pids) {
        $inSql = implode(',', $pids);
        foreach (db()->query("SELECT p.id, p.name, p.property_type, s.company_name FROM properties p JOIN suppliers s ON s.id=p.supplier_id WHERE p.id IN ({$inSql})")->fetchAll() as $m) {
            $meta[(int) $m['id']] = $m;
        }
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="silinen-ozellik-etkisi-' . $fid . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM — Excel'de Türkçe karakterler
    fputcsv($out, ['Özellik', 'Tedarikçi', 'İlan', 'Tür', 'Etkilenen bölüm sayısı', 'Bölümler', 'Fiyat', 'İlan ID']);
    foreach ($props as $p) {
        $pid = (int) ($p['id'] ?? 0);
        $sections = array_values(array_filter(array_map('strval', (array) ($p['sections'] ?? []))));
        $m = $meta[$pid] ?? null;
        fputcsv($out, [
            (string) $bk['label'],
            $m ? (string) $m['company_name'] : '',
            $m ? (string) $m['name'] : (string) ($p['name'] ?? ''),
            $m ? $typeLabelCsv((string) $m['property_type']) : '',
            count($sections),
            implode(', ', array_map(fn($s) => $secLabels[$s] ?? $s, $sections)),
            (string) ($p['price'] ?? ''),
            $pid,
        ]);
    }
    fclose($out);
    exit;
}

$action = trim((string) ($_GET['action'] ?? ''));
$dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date_from'] ?? '')) ? (string) $_GET['date_from'] : '';
$dateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date_to'] ?? '')) ? (string) $_GET['date_to'] : '';
$adminName = trim((string) ($_GET['admin'] ?? ''));
$today = date('Y-m-d');
$weekAgo = date('Y-m-d', strtotime('-6 days'));
$monthStart = date('Y-m-01');
$sql = 'SELECT * FROM admin_audit_logs WHERE 1=1';
$params = [];
if ($action !== '') {
    $sql .= ' AND action LIKE ?';
    $params[] = '%' . $action . '%';
}
if ($dateFrom !== '') {
    $sql .= ' AND created_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $sql .= ' AND created_at <= ?';
    $params[] = $dateTo . ' 23:59:59';
}
if ($adminName !== '') {
    $sql .= ' AND admin_username ILIKE ?';
    $params[] = '%' . $adminName . '%';
}
$sql .= ' ORDER BY id DESC';
// Sayfalama — 500 limiti yerine sayfa başına 50 kayıt; CSV aynı sayfanın satırlarını dışa aktarır.
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$limitSql = $sql . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage);
$countQ = db()->prepare(preg_replace('/^SELECT \*/', 'SELECT COUNT(*)', $sql));
$countQ->execute($params);
$total = (int) $countQ->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
// İşlem türüne göre okunabilir Türkçe açıklama (CSV'nin 'Açıklama' sütunu için).
$describeAction = function (array $r): string {
    $details = json_decode((string) ($r['details'] ?? ''), true);
    if (!is_array($details)) $details = [];
    $label = (string) ($details['label'] ?? '');
    $n = (int) ($details['count'] ?? $details['affected_count'] ?? 0);
    switch ((string) $r['action']) {
        case 'feature.add': return 'Özellik eklendi' . ($label !== '' ? ': ' . $label : '');
        case 'feature.delete': return 'Özellik silindi' . ($n > 0 ? ' — ' . $n . ' ilan' : '');
        case 'feature.restore': return 'Özellik geri yüklendi' . ($n > 0 ? ' — ' . $n . ' ilana eklendi' : '');
        case 'feature.toggle': return 'Özellik durumu değişti' . ($label !== '' ? ': ' . $label : '') . (isset($details['is_active']) ? ' (' . ($details['is_active'] ? 'aktif' : 'pasif') . ')' : '');
        case 'feature.move': return 'Özellik sıralaması değişti' . ($label !== '' ? ': ' . $label : '');
        case 'feature.bulk_delete': return 'Toplu silme' . ($n > 0 ? ' — ' . $n . ' özellik' : '') . (($details['affected_count'] ?? 0) > 0 ? ', ' . (int) $details['affected_count'] . ' ilan' : '');
        case 'feature.bulk_activate': return 'Toplu aktifleştirme' . ($n > 0 ? ' — ' . $n . ' özellik' : '') . (($details['skipped_unchanged'] ?? 0) > 0 ? ' (' . (int) $details['skipped_unchanged'] . ' atlandı)' : '');
        case 'feature.bulk_deactivate': return 'Toplu pasifleştirme' . ($n > 0 ? ' — ' . $n . ' özellik' : '') . (($details['skipped_unchanged'] ?? 0) > 0 ? ' (' . (int) $details['skipped_unchanged'] . ' atlandı)' : '');
        case 'feature.trash_purge': return 'Çöp kutusu temizliği' . ($n > 0 ? ' — ' . $n . ' özellik' : '');
        case 'scheduler.toggle': return 'Zamanlayıcı durumu değişti';
        case 'scheduler.edit': return 'Zamanlayıcı düzenlendi';
        case 'scheduler.run': return 'Zamanlayıcı elle çalıştırıldı' . (isset($details['status']) ? ' (' . (string) $details['status'] . ')' : '');
        default: return (string) $r['action'] . ($label !== '' ? ': ' . $label : '');
    }
};
// CSV dışa aktarma — aynı filtrelerle (limit yok).
$wantCsv = (string) ($_GET['export'] ?? '') === 'csv';
if ($wantCsv) {
    header('Content-Type: text/csv; charset=UTF-8');
    // Dosya adına aktif filtreler eklenir: denetim-{işlem}-{tarih aralığı}-{yönetici}.csv
    $fileParts = ['denetim'];
    if ($action !== '') $fileParts[] = preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $action);
    if ($dateFrom !== '' || $dateTo !== '') {
        $range = $dateFrom !== '' ? $dateFrom : 'baslangic';
        if ($dateTo !== '') $range .= '..' . $dateTo;
        $fileParts[] = $range;
    }
    if ($adminName !== '') $fileParts[] = preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $adminName);
    header('Content-Disposition: attachment; filename="' . implode('-', $fileParts) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM — Excel'de Türkçe karakterler
    fputcsv($out, ['Zaman', 'Yönetici', 'İşlem', 'Açıklama', 'Nesne', 'Nesne ID', 'Detay (JSON)', 'IP']);
    $cq = db()->prepare($limitSql);
    $cq->execute($params);
    foreach ($cq->fetchAll() as $r) {
        fputcsv($out, [
            (string) $r['created_at'],
            (string) $r['admin_username'],
            (string) $r['action'],
            $describeAction($r),
            (string) ($r['entity_type'] ?? ''),
            (string) ($r['entity_id'] ?? ''),
            (string) ($r['details'] ?? ''),
            (string) ($r['ip'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}
$extraParts = [];
if ($dateFrom !== '') $extraParts['date_from'] = $dateFrom;
if ($dateTo !== '') $extraParts['date_to'] = $dateTo;
if ($adminName !== '') $extraParts['admin'] = $adminName;
$extra = http_build_query($extraParts);
$filtersSuffix = $extra !== '' ? '&' . $extra : '';
$baseAudit = '/nexustraveltech/admin/denetim-kayitlari';
// CSV bağlantısı: aktif İŞLEM filtresi (chip) + sayfa dahil tüm filtreleri taşır — bu sayfanın satırları indirilir.
$csvUrl = $baseAudit . '?export=csv&page=' . $page . ($action !== '' ? '&action=' . urlencode($action) : '') . $filtersSuffix;
// Sayfalama kontrolleri — tüm filtreler korunur.
$pageUrl = function (int $p) use ($baseAudit, $action, $dateFrom, $dateTo, $adminName): string {
    $pq = [];
    if ($action !== '') $pq['action'] = $action;
    if ($dateFrom !== '') $pq['date_from'] = $dateFrom;
    if ($dateTo !== '') $pq['date_to'] = $dateTo;
    if ($adminName !== '') $pq['admin'] = $adminName;
    if ($p > 1) $pq['page'] = $p;
    $qs = http_build_query($pq);
    return $baseAudit . ($qs !== '' ? '?' . $qs : '');
};
$pager = '';
if ($totalPages > 1) {
    $pgBtn = fn(int $p, string $label, bool $current = false) => $current
        ? '<span style="padding:6px 12px;background:#10211f;color:#fff;border-radius:4px;font-weight:bold">' . $label . '</span>'
        : '<a href="' . htmlspecialchars($pageUrl($p)) . '" style="padding:6px 12px;background:#fff;border:1px solid #e1e5de;text-decoration:none;color:#10211f">' . $label . '</a>';
    $pager .= '<div style="display:flex;gap:6px;align-items:center;margin:14px 0;flex-wrap:wrap;font-size:13px">';
    if ($page > 1) $pager .= '<a href="' . htmlspecialchars($pageUrl($page - 1)) . '" style="padding:6px 12px;background:#fff;border:1px solid #e1e5de;text-decoration:none;color:#10211f">← Önceki</a>';
    $start = max(1, min($page - 4, $totalPages - 9));
    $end = min($totalPages, max($page + 4, 10));
    if ($start > 1) $pager .= '<span style="color:#64716d">…</span>';
    for ($i = $start; $i <= $end; $i++) $pager .= $pgBtn($i, (string) $i, $i === $page);
    if ($end < $totalPages) $pager .= '<span style="color:#64716d">…</span>';
    if ($page < $totalPages) $pager .= '<a href="' . htmlspecialchars($pageUrl($page + 1)) . '" style="padding:6px 12px;background:#fff;border:1px solid #e1e5de;text-decoration:none;color:#10211f">Sonraki →</a>';
    $pager .= '<span style="color:#64716d;margin-left:6px">' . $total . ' kayıt · sayfa ' . $page . ' / ' . $totalPages . '</span></div>';
}
// Hızlı tarih aralıkları — tek tıkla; mevcut işlem + yönetici filtreleri korunur.
$quickRangeHtml = '';
foreach (['Bugün' => [$today, $today], 'Son 7 gün' => [$weekAgo, $today], 'Bu ay' => [$monthStart, $today]] as $qrLabel => [$qrFrom, $qrTo]) {
    $qrActive = $dateFrom === $qrFrom && $dateTo === $qrTo;
    $qrUrl = $baseAudit . '?' . ($action !== '' ? 'action=' . urlencode($action) . '&' : '') . 'date_from=' . $qrFrom . '&date_to=' . $qrTo . ($adminName !== '' ? '&admin=' . urlencode($adminName) : '');
    $quickRangeHtml .= '<a href="' . htmlspecialchars($qrUrl) . '" style="padding:4px 10px;background:' . ($qrActive ? '#10211f;color:#fff' : '#f4f6f1;color:#10211f') . ';border:1px solid #d8ded8;border-radius:12px;text-decoration:none;font-size:12px;font-weight:bold"' . ($qrActive ? ' title="Şu an bu aralık aktif"' : '') . '>' . htmlspecialchars($qrLabel) . '</a>';
}
$q = db()->prepare($limitSql);
$q->execute($params);
$rows = $q->fetchAll();
$actions = db()->query('SELECT action,COUNT(*) c FROM admin_audit_logs GROUP BY action ORDER BY c DESC LIMIT 30')->fetchAll();
$admins = db()->query("SELECT DISTINCT admin_username FROM admin_audit_logs WHERE admin_username IS NOT NULL AND admin_username <> '' ORDER BY admin_username LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Denetim kayıtları | NEXUS Admin</title><style>body{margin:0;background:#f7f7f2;color:#10211f;font-family:Arial,sans-serif}.wrap{width:min(1180px,calc(100% - 32px));margin:40px auto}.top{display:flex;justify-content:space-between;gap:20px;align-items:center}.brand{font-size:28px;font-weight:800}.brand span{color:#e85f42}.back{color:#10211f}.chips{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0}.chip{background:#fff;border:1px solid #e1e5de;padding:6px 10px;font-size:12px;text-decoration:none;color:#10211f}.chip.on{background:#10211f;color:#fff}table{width:100%;border-collapse:collapse;background:#fff}th,td{text-align:left;border-bottom:1px solid #e1e5de;padding:11px 12px;font-size:13px;vertical-align:top}th{font-size:11px;text-transform:uppercase;color:#64716d}code{background:#f2f4ef;padding:2px 5px;font-size:12px}pre{margin:4px 0 0;white-space:pre-wrap;font-size:11px;color:#555}</style></head><body><main class="wrap"><div class="top"><div><div class="brand">N<span>∿</span>XUS Admin</div><p>Yönetim işlemleri denetim kaydı — kim, ne, ne zaman</p></div><a class="back" href="/nexustraveltech/admin/">← Panele dön</a></div>
<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;background:#fff;border:1px solid #e1e5de;padding:10px 12px;border-radius:8px;margin:16px 0 4px"><input type="hidden" name="action" value="<?=htmlspecialchars($action)?>"><label style="font-size:12px;color:#64716d">Başlangıç<input type="date" name="date_from" value="<?=htmlspecialchars($dateFrom)?>" style="margin-left:6px;padding:6px;border:1px solid #d8ded8"></label><label style="font-size:12px;color:#64716d">Bitiş<input type="date" name="date_to" value="<?=htmlspecialchars($dateTo)?>" style="margin-left:6px;padding:6px;border:1px solid #d8ded8"></label><label style="font-size:12px;color:#64716d">Yönetici<input type="text" name="admin" value="<?=htmlspecialchars($adminName)?>" placeholder="Kullanıcı adı" list="audit-admins" autocomplete="off" style="margin-left:6px;padding:6px;border:1px solid #d8ded8"></label><datalist id="audit-admins"><?php foreach ($admins as $an): ?><option value="<?=htmlspecialchars((string)$an)?>"><?php endforeach; ?></datalist><span style="font-size:12px;color:#64716d;display:inline-flex;gap:6px;align-items:center">Hızlı:<?=$quickRangeHtml?></span><button style="padding:6px 14px;background:#10211f;color:#fff;border:0;cursor:pointer;font-weight:bold">Filtrele</button><?php if ($dateFrom !== '' || $dateTo !== '' || $adminName !== ''): ?><a href="<?=$baseAudit?>" style="font-size:12px;color:#64716d">Sıfırla</a><?php endif; ?><a href="<?=$csvUrl?>" style="padding:6px 14px;background:#405b13;color:#fff;border:0;font-weight:bold;text-decoration:none;font-size:13px" title="Geçerli filtrelerle dışa aktar (işlem filtresi dahil)">⬇ CSV indir</a><?php if ($action !== '' || $dateFrom !== '' || $dateTo !== '' || $adminName !== ''): ?> <small style="color:#64716d;font-size:12px">(aktif filtrelerle)</small><?php endif; ?></form>
<div class="chips"><a class="chip <?=$action===''?'on':''?>" href="<?=$baseAudit?><?= $extra !== '' ? '?' . $extra : '' ?>">Tümü</a><a class="chip <?=$action==='feature.restore'?'on':''?>" style="background:<?=$action==='feature.restore'?'#405b13':'#e6f8c7'?>;color:#10211f;border-color:#bcd98a" href="?action=<?=urlencode('feature.restore')?><?=$filtersSuffix?>">↩ Geri alınan özellikler</a><a class="chip <?=$action==='feature.delete'?'on':''?>" style="background:<?=$action==='feature.delete'?'#8e2410':'#ffe2de'?>;color:#10211f;border-color:#f3c4ba" href="?action=<?=urlencode('feature.delete')?><?=$filtersSuffix?>">✗ Silinen özellikler</a><a class="chip <?=$action==='feature.trash_purge'?'on':''?>" href="?action=<?=urlencode('feature.trash_purge')?><?=$filtersSuffix?>">🗑 Çöp temizliği</a><a class="chip <?=str_starts_with($action,'feature.bulk_')?'on':''?>" style="background:<?=str_starts_with($action,'feature.bulk_')?'#2b4a7a':'#eef3fb'?>;color:#10211f;border-color:#b9cbe8" href="?action=<?=urlencode('feature.bulk_')?><?=$filtersSuffix?>">⚡ Toplu işlemler</a><?php foreach ($actions as $a): ?><a class="chip <?=$action===$a['action']?'on':''?>" href="?action=<?=urlencode($a['action'])?><?=$filtersSuffix?>"><?=htmlspecialchars($a['action'])?> (<?=(int)$a['c']?>)</a><?php endforeach; ?></div>
<table><tr><th>Zaman</th><th>Yönetici</th><th>İşlem</th><th>Nesne</th><th>Detay</th><th>IP</th></tr>
<?php foreach ($rows as $r): $details = json_decode((string)$r['details'], true); $isRestore = $r['action'] === 'feature.restore'; $isBulk = in_array($r['action'], ['feature.bulk_delete', 'feature.bulk_deactivate', 'feature.bulk_activate'], true); $isDelete = $r['action'] === 'feature.delete'; $rowStyle = $isRestore ? 'background:#f4fbea' : ($isBulk ? 'background:#eef3fb' : ($isDelete ? 'background:#fff7f5' : '')); ?>
<tr<?= $rowStyle !== '' ? ' style="' . $rowStyle . '"' : '' ?>><td><?=htmlspecialchars((string)$r['created_at'])?></td><td><?=htmlspecialchars($r['admin_username'])?></td><td><code><?=htmlspecialchars($r['action'])?></code></td><td><?=htmlspecialchars((string)($r['entity_type'] ?? ''))?><?= $r['entity_id'] ? ' #' . (int)$r['entity_id'] : '' ?></td><td><?php if ($details): ?><?php if ($isBulk): ?><div><b style="color:#1a3d6d"><?= (int)($details['count'] ?? 0) ?> özellik</b><?php if (array_key_exists('affected_count', $details)): ?> · <b style="color:#b0301a"><?= (int)$details['affected_count'] ?> ilan</b><?php endif; ?></div><?php if (!empty($details['feature_ids']) && is_array($details['feature_ids'])): ?><div style="margin:5px 0 4px">ID'ler: <?php foreach ($details['feature_ids'] as $fid): ?><a href="javascript:void(0)" class="fid-qv" data-id="<?= (int)$fid ?>" title="Duruma hızlı bak"><code style="margin-right:4px;cursor:pointer;border-bottom:1px dashed #64716d">#<?= (int)$fid ?> ⚡</code></a><?php endforeach; ?><span class="fid-qv-box" style="display:none;margin:8px 0 4px;padding:10px 12px;background:#f4f6f1;border:1px solid #d5dccf;border-radius:8px;font-size:13px;max-width:420px"></span></div><?php endif; ?><pre><?=htmlspecialchars(json_encode($details, JSON_UNESCAPED_UNICODE))?></pre><?php elseif ($isRestore): $restoreLabel = (string) ($details['label'] ?? ''); $restoreCode = (string) ($details['code'] ?? ''); $propIds = is_array($details['affected_listing_ids'] ?? null) ? array_values(array_filter(array_map('intval', $details['affected_listing_ids']))) : []; $propsByName = []; if ($propIds) { $pidsSql = implode(',', $propIds); foreach (db()->query("SELECT id, name, property_type FROM properties WHERE id IN ({$pidsSql})")->fetchAll() as $pr) { $propsByName[(int) $pr['id']] = $pr; } } $secPerProp = []; $bkQ = db()->prepare('SELECT affected_properties FROM feature_delete_backups WHERE feature_id=? ORDER BY id DESC LIMIT 1'); $bkQ->execute([(int) $r['entity_id']]); $bkRow = $bkQ->fetch(); if ($bkRow) { foreach ((array) (json_decode((string) $bkRow['affected_properties'], true) ?: []) as $bp) { if (is_array($bp) && isset($bp['id'], $bp['sections']) && is_array($bp['sections'])) { $secPerProp[(int) $bp['id']] = count($bp['sections']); } } } ?><div style="margin-bottom:6px"><b><?= htmlspecialchars($restoreLabel) ?></b><?= $restoreCode !== '' ? ' <code style="margin-left:4px">' . htmlspecialchars($restoreCode) . '</code>' : '' ?></div><table style="margin:4px 0 8px;border-collapse:collapse;width:100%"><tr><th style="text-align:left;padding:5px 8px;border:1px solid #e1e5de;font-size:10px;color:#64716d">İlan</th><th style="text-align:left;padding:5px 8px;border:1px solid #e1e5de;font-size:10px;color:#64716d">Geri eklenen bölüm</th></tr><?php foreach ($propIds as $pid): ?><tr><td style="padding:5px 8px;border:1px solid #e1e5de"><?= isset($propsByName[$pid]) ? htmlspecialchars($propsByName[$pid]['name']) : '#' . (int) $pid ?><?= isset($propsByName[$pid]) && $propsByName[$pid]['property_type'] ? ' <small style="color:#6b7774">(' . htmlspecialchars($propsByName[$pid]['property_type']) . ')</small>' : '' ?></td><td style="padding:5px 8px;border:1px solid #e1e5de"><?= isset($secPerProp[$pid]) ? (int) $secPerProp[$pid] : '—' ?></td></tr><?php endforeach; ?><tr><td style="padding:5px 8px;border:1px solid #e1e5de;font-weight:bold;background:#f4fbea">Toplam</td><td style="padding:5px 8px;border:1px solid #e1e5de;font-weight:bold;background:#f4fbea"><?= (int) ($details['restored_sections'] ?? 0) ?></td></tr></table><details style="font-size:11px"><summary style="cursor:pointer;color:#6b7774">Ham JSON</summary><pre style="margin:4px 0 0"><?= htmlspecialchars(json_encode($details, JSON_UNESCAPED_UNICODE)) ?></pre></details><?php elseif ($isDelete): $delLabel = (string) ($details['label'] ?? ''); $delCode = (string) ($details['code'] ?? ''); $delPropIds = is_array($details['affected_listing_ids'] ?? null) ? array_values(array_filter(array_map('intval', $details['affected_listing_ids']))) : []; $delProps = []; if ($delPropIds) { $dpsSql = implode(',', $delPropIds); foreach (db()->query("SELECT id, name, property_type FROM properties WHERE id IN ({$dpsSql})")->fetchAll() as $dp) { $delProps[(int) $dp['id']] = $dp; } } $delSec = []; $delBkQ = db()->prepare('SELECT affected_properties FROM feature_delete_backups WHERE feature_id=? ORDER BY id DESC LIMIT 1'); $delBkQ->execute([(int) $r['entity_id']]); $delBk = $delBkQ->fetch(); if ($delBk) { foreach ((array) (json_decode((string) $delBk['affected_properties'], true) ?: []) as $dbp) { if (is_array($dbp) && isset($dbp['id'], $dbp['sections']) && is_array($dbp['sections'])) { $delSec[(int) $dbp['id']] = count($dbp['sections']); } } } ?><div style="margin-bottom:6px"><b><?= htmlspecialchars($delLabel) ?></b><?= $delCode !== '' ? ' <code style="margin-left:4px">' . htmlspecialchars($delCode) . '</code>' : '' ?><?= !empty($details['purge_at']) ? ' <small style="color:#6b7774">· kalıcı silme ' . htmlspecialchars((string) $details['purge_at']) . '</small>' : '' ?></div><table style="margin:4px 0 8px;border-collapse:collapse;width:100%"><tr><th style="text-align:left;padding:5px 8px;border:1px solid #e1e5de;font-size:10px;color:#64716d">İlan</th><th style="text-align:left;padding:5px 8px;border:1px solid #e1e5de;font-size:10px;color:#64716d">Etkilenen bölüm</th></tr><?php foreach ($delPropIds as $dpid): ?><tr><td style="padding:5px 8px;border:1px solid #e1e5de"><?= isset($delProps[$dpid]) ? htmlspecialchars($delProps[$dpid]['name']) : '#' . (int) $dpid ?><?= isset($delProps[$dpid]) && $delProps[$dpid]['property_type'] ? ' <small style="color:#6b7774">(' . htmlspecialchars($delProps[$dpid]['property_type']) . ')</small>' : '' ?></td><td style="padding:5px 8px;border:1px solid #e1e5de"><?= isset($delSec[$dpid]) ? (int) $delSec[$dpid] : '—' ?></td></tr><?php endforeach; ?><tr><td style="padding:5px 8px;border:1px solid #e1e5de;font-weight:bold;background:#fdf3e3">Toplam</td><td style="padding:5px 8px;border:1px solid #e1e5de;font-weight:bold;background:#fdf3e3"><?= (int) array_sum($delSec) ?></td></tr></table><div style="margin-top:8px"><a href="/nexustraveltech/admin/denetim-kayitlari?export=feature_delete_impact&feature_id=<?= (int) $r['entity_id'] ?>" style="display:inline-block;background:#f4f6f1;border:1px solid #d5dccf;color:#405b13;padding:7px 12px;font-size:12px;font-weight:bold;text-decoration:none;border-radius:5px" title="Silinen özelliğin kaldırıldığı ilanların etki listesini indir (yedeğe dayalı)">⬇ CSV indir (etki listesi)</a></div><details style="font-size:11px"><summary style="cursor:pointer;color:#6b7774">Ham JSON</summary><pre style="margin:4px 0 0"><?= htmlspecialchars(json_encode($details, JSON_UNESCAPED_UNICODE)) ?></pre></details><?php else: ?><pre><?=htmlspecialchars(json_encode($details, JSON_UNESCAPED_UNICODE))?></pre><?php endif; ?><?php endif; ?></td><td><?=htmlspecialchars((string)$r['ip'])?></td></tr>
<?php endforeach; ?>
</table>
<?= $pager ?>
</main><script>
function fidQuickView(el){var fid=el.dataset.id,box=el.closest('div').querySelector('.fid-qv-box');if(!box)return;if(box.dataset.fid===fid&&box.style.display!=='none'){box.style.display='none';box.dataset.fid='';return}box.dataset.fid=fid;box.textContent='Yükleniyor…';box.style.display='block';fetch('/nexustraveltech/admin/denetim-kayitlari?qview=feature&id='+fid).then(function(r){return r.json()}).then(function(d){if(!d.ok){box.innerHTML='<b style="color:#8e2410">#'+fid+'</b> — '+d.error;return}var html='<b style="color:#10211f">'+d.label+'</b> <code>'+d.code+'</code>';if(d.group)html+=' <small style="color:#6b7774">· '+d.group+'</small>';if(d.in_trash){html+='<div style="margin-top:4px"><span style="display:inline-block;background:#fdf3e3;border:1px solid #e0c9a3;color:#8a6100;border-radius:12px;padding:2px 10px;font-size:12px;font-weight:bold">🗑 Çöp kutusunda</span> <small style="color:#6b7774">silindi '+d.deleted_at.slice(0,16)+' · kalıcı silmeye '+d.remain_days+' gün'+(d.custom_purge?' · özel tarih':'')+'</small></div>'}else{html+='<div style="margin-top:4px"><span style="display:inline-block;border-radius:12px;padding:2px 10px;font-size:12px;font-weight:bold;background:'+(d.is_active?'#e6f8c7;color:#2e7d32':'#ffe2de;color:#b0301a')+'">'+(d.is_active?'● Aktif':'○ Pasif')+'</span></div>'}html+='<div style="margin-top:6px"><a href="/nexustraveltech/admin/ozellik-listeleri#feat-'+fid+'" style="color:#1a3d6d;font-size:12px;font-weight:bold;text-decoration:none">🔗 Özellik kataloğunda aç</a></div>';box.innerHTML=html}).catch(function(){box.innerHTML='<b style="color:#8e2410">#'+fid+'</b> — Durum okunamadı (oturum süresi dolmuş olabilir).'})}
document.querySelectorAll('.fid-qv').forEach(function(a){a.addEventListener('click',function(){fidQuickView(a)})});
</script><?php require_once __DIR__.'/../config/ai_widget.php'; ai_widget('/nexustraveltech/admin/ai-chat','admin_csrf'); ?></body></html>
