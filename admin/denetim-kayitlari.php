<?php

declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/../config/trash-helpers.php';

require_admin();
if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));

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
    // Geçmiş karşılaştırma — tıklanan denetim satırındaki işlem anındaki durum vs şimdiki durum.
    // audit_id verilirse o satırın işlemi hangi durumu ürettiyse (silme→çöp, aktifleştirme→aktif vb.)
    // canlı durumla karşılaştırılıp fark bayrağı döndürülür.
    $currentState = $f['deleted_at'] !== null ? 'trash' : ($f['is_active'] ? 'active' : 'passive');
    $auditId = (int) ($_GET['audit_id'] ?? 0);
    $op = null;
    if ($auditId > 0) {
        $aQ = db()->prepare('SELECT id, action, details, created_at, admin_username FROM admin_audit_logs WHERE id=?');
        $aQ->execute([$auditId]);
        $a = $aQ->fetch();
        if ($a) {
            $aDet = json_decode((string) ($a['details'] ?? ''), true);
            if (!is_array($aDet)) $aDet = [];
            $fids = array_map('intval', is_array($aDet['feature_ids'] ?? null) ? $aDet['feature_ids'] : []);
            $opState = null;
            $opLabel = '';
            $act = (string) $a['action'];
            if ($act === 'feature.add') { $opState = 'active'; $opLabel = 'Özellik eklendi'; }
            elseif ($act === 'feature.toggle') { $opState = !empty($aDet['is_active']) ? 'active' : 'passive'; $opLabel = 'Durum değişikliği'; }
            elseif ($act === 'feature.delete') { $opState = 'trash'; $opLabel = 'Silindi (çöp kutusu)'; }
            elseif ($act === 'feature.restore') { $bkS = db()->prepare('SELECT is_active FROM feature_delete_backups WHERE feature_id=? ORDER BY id DESC LIMIT 1'); $bkS->execute([$fid]); $bks = $bkS->fetch(); $opState = $bks ? (!empty($bks['is_active']) ? 'active' : 'passive') : null; $opLabel = 'Geri yüklendi'; }
            elseif ($act === 'feature.bulk_delete' && in_array($fid, $fids, true)) { $opState = 'trash'; $opLabel = 'Toplu silme'; }
            elseif ($act === 'feature.bulk_activate' && in_array($fid, $fids, true)) { $opState = 'active'; $opLabel = 'Toplu aktifleştirme'; }
            elseif ($act === 'feature.bulk_deactivate' && in_array($fid, $fids, true)) { $opState = 'passive'; $opLabel = 'Toplu pasifleştirme'; }
            if ($opState !== null) {
                $op = [
                    'state' => $opState,
                    'label' => $opLabel,
                    'action' => $act,
                    'time' => (string) ($a['created_at'] ?? ''),
                    'admin' => (string) ($a['admin_username'] ?? ''),
                    'changed' => $opState !== $currentState,
                ];
            }
        }
    }
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
        'current_state' => $currentState,
        'op' => $op,
    ], JSON_UNESCAPED_UNICODE));
}

// Çöp kutusundaki özelliği tek tıkla geri yükle — hızlı bakış kutusundaki butonun uç noktası.
// ozellik-listeleri'ndeki restore akışının birebir aynısı; JSON döner.
if (($_GET['restore'] ?? '') === '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    if (!hash_equals($_SESSION['admin_csrf'], (string) ($_POST['csrf'] ?? ''))) { http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'Güvenlik doğrulaması geçersiz.'])); }
    $fid = (int) ($_POST['id'] ?? 0);
    if ($fid <= 0) { http_response_code(400); exit(json_encode(['ok' => false, 'error' => 'Geçersiz özellik kimliği.'])); }
    try {
        $bkQ = db()->prepare('SELECT * FROM feature_delete_backups WHERE feature_id=? ORDER BY id DESC LIMIT 1');
        $bkQ->execute([$fid]);
        $bk = $bkQ->fetch();
        if (!$bk) throw new RuntimeException('Geri alınacak kayıt bulunamadı.');
        $label = (string) $bk['label'];
        db()->prepare('UPDATE property_feature_catalog SET deleted_at=NULL, purge_at=NULL, group_label=?, label=?, sort_order=?, is_active=? WHERE id=?')
            ->execute([$bk['group_label'] ?? '', $label, (int) ($bk['sort_order'] ?? 100), (bool) ($bk['is_active'] ?? true), $fid]);
        db()->prepare('DELETE FROM pending_trash_purges WHERE feature_id=?')->execute([$fid]);
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
        audit_log('feature.restore', 'feature_catalog', $fid, [
            'code' => $bk['code'] ?? '',
            'label' => $label,
            'affected_count' => count($props),
            'affected_listing_ids' => array_map(fn($pr) => (int) ($pr['id'] ?? 0), $props),
            'restored_sections' => $restored,
            'source' => 'quickview',
        ]);
        exit(json_encode(['ok' => true, 'label' => $label, 'code' => (string) ($bk['code'] ?? ''), 'affected_count' => count($props), 'restored_sections' => $restored], JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
        http_response_code(500);
        exit(json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
    }
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
        case 'channel.room_mapping_approve': return 'Oda eşleştirme önerisi onaylandı' . ((string) ($details['code'] ?? '') !== '' ? ': ' . htmlspecialchars((string) $details['code']) : '') . ((string) ($details['channel'] ?? '') !== '' ? ' (' . htmlspecialchars((string) $details['channel']) . ')' : '');
        case 'channel.room_mapping_reject': return 'Oda eşleştirme önerisi reddedildi' . ((string) ($details['code'] ?? '') !== '' ? ': ' . htmlspecialchars((string) $details['code']) : '') . ((string) ($details['channel'] ?? '') !== '' ? ' (' . htmlspecialchars((string) $details['channel']) . ')' : '');
        case 'channel.plan_mapping_approve': return 'Plan eşleştirme önerisi onaylandı' . ((string) ($details['code'] ?? '') !== '' ? ': ' . htmlspecialchars((string) $details['code']) : '') . ((string) ($details['channel'] ?? '') !== '' ? ' (' . htmlspecialchars((string) $details['channel']) . ')' : '');
        case 'channel.plan_mapping_reject': return 'Plan eşleştirme önerisi reddedildi' . ((string) ($details['code'] ?? '') !== '' ? ': ' . htmlspecialchars((string) $details['code']) : '') . ((string) ($details['channel'] ?? '') !== '' ? ' (' . htmlspecialchars((string) $details['channel']) . ')' : '');
        case 'channel.quick_match': return 'Hızlı eşleştirme başlatıldı' . ((string) ($details['code'] ?? '') !== '' ? ': ' . htmlspecialchars((string) $details['code']) : '') . ((string) ($details['room_name'] ?? '') !== '' ? ' → ' . htmlspecialchars((string) $details['room_name']) : '') . ((string) ($details['channel'] ?? '') !== '' ? ' (' . htmlspecialchars((string) $details['channel']) . ')' : '');
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

require_once __DIR__ . '/layout.php';
admin_layout_start('Yönetim Denetim ve Audit Kayıtları', 'denetim-kayitlari');
?>

<!-- Filtreler Kartı -->
<div class="sui-card" style="margin-bottom:20px">
    <form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
        <input type="hidden" name="action" value="<?= htmlspecialchars($action) ?>">
        
        <div>
            <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Başlangıç Tarihi</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="sui-input">
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Bitiş Tarihi</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="sui-input">
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Yönetici Adı</label>
            <input type="text" name="admin" value="<?= htmlspecialchars($adminName) ?>" placeholder="Tümü..." list="audit-admins" class="sui-input">
            <datalist id="audit-admins">
                <?php foreach ($admins as $an): ?>
                    <option value="<?= htmlspecialchars((string)$an) ?>">
                <?php endforeach; ?>
            </datalist>
        </div>

        <div style="margin-top:18px;display:flex;gap:8px;align-items:center">
            <button class="sui-btn sui-btn-primary" type="submit">🔍 Filtrele</button>
            <?php if ($dateFrom !== '' || $dateTo !== '' || $adminName !== ''): ?>
                <a href="<?= $baseAudit ?>" class="sui-btn sui-btn-outline">Sıfırla</a>
            <?php endif; ?>
            <a href="<?= $csvUrl ?>" class="sui-btn sui-btn-success" title="Geçerli filtrelerle CSV indir">⬇ CSV İndir</a>
        </div>
    </form>
</div>

<!-- İşlem Filtre Çipleri -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px">
    <a class="sui-btn <?= $action===''?'sui-btn-primary':'sui-btn-outline' ?> sui-btn-sm" href="<?= $baseAudit ?><?= $extra !== '' ? '?' . $extra : '' ?>">Tümü (<?= $total ?>)</a>
    <a class="sui-btn <?= $action==='feature.restore'?'sui-btn-success':'sui-btn-outline' ?> sui-btn-sm" href="?action=<?= urlencode('feature.restore') ?><?= $filtersSuffix ?>">↩ Geri Alınanlar</a>
    <a class="sui-btn <?= $action==='feature.delete'?'sui-btn-danger':'sui-btn-outline' ?> sui-btn-sm" href="?action=<?= urlencode('feature.delete') ?><?= $filtersSuffix ?>">✗ Silinenler</a>
    <a class="sui-btn <?= str_starts_with($action,'feature.bulk_')?'sui-btn-primary':'sui-btn-outline' ?> sui-btn-sm" href="?action=<?= urlencode('feature.bulk_') ?><?= $filtersSuffix ?>">⚡ Toplu İşlemler</a>
    <a class="sui-btn <?= $action==='channel.room_mapping_approve'?'sui-btn-success':'sui-btn-outline' ?> sui-btn-sm" href="?action=<?= urlencode('channel.room_mapping_approve') ?><?= $filtersSuffix ?>">🛏 Oda Onayları</a>
    <a class="sui-btn <?= $action==='channel.plan_mapping_approve'?'sui-btn-success':'sui-btn-outline' ?> sui-btn-sm" href="?action=<?= urlencode('channel.plan_mapping_approve') ?><?= $filtersSuffix ?>">💶 Plan Onayları</a>
</div>

<!-- Tablo Kartı -->
<div class="sui-card">
    <div style="overflow-x:auto">
        <table class="sui-table">
            <thead>
                <tr>
                    <th>Zaman</th>
                    <th>Yönetici</th>
                    <th>İşlem</th>
                    <th>Nesne</th>
                    <th>Detay</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): $details = json_decode((string)$r['details'], true); ?>
                    <tr>
                        <td style="white-space:nowrap;font-size:12px"><?= htmlspecialchars((string)$r['created_at']) ?></td>
                        <td><span class="sui-badge sui-badge-info"><?= htmlspecialchars($r['admin_username']) ?></span></td>
                        <td><code><?= htmlspecialchars($r['action']) ?></code></td>
                        <td><?= htmlspecialchars((string)($r['entity_type'] ?? '')) ?><?= $r['entity_id'] ? ' #' . (int)$r['entity_id'] : '' ?></td>
                        <td style="max-width:350px">
                            <?php if ($details): ?>
                                <details style="font-size:11px">
                                    <summary style="cursor:pointer;color:var(--sui-primary);font-weight:600">Detayları Göster</summary>
                                    <pre style="background:var(--sui-bg);padding:8px;border-radius:6px;margin:4px 0 0;white-space:pre-wrap"><?= htmlspecialchars(json_encode($details, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
                                </details>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td style="font-size:11px;color:var(--sui-muted)"><?= htmlspecialchars((string)$r['ip']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= $pager ?>
</div>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>

