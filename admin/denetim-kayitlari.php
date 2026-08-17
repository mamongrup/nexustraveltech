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
    $fq = db()->prepare('SELECT id, code, group_label, label, is_active, deleted_at FROM property_feature_catalog WHERE id=?');
    $fq->execute([$fid]);
    $f = $fq->fetch();
    if (!$f) { http_response_code(404); exit(json_encode(['ok' => false, 'error' => 'Özellik bulunamadı (kalıcı silinmiş olabilir).'])); }
    $ttl = max(7, (int) platform_setting('feature_trash_ttl_days', 30));
    $delTs = $f['deleted_at'] !== null ? (strtotime((string) $f['deleted_at']) ?: 0) : 0;
    $remain = $delTs > 0 ? (int) ceil((($delTs + $ttl * 86400) - time()) / 86400) : 0;
    exit(json_encode([
        'ok' => true,
        'id' => (int) $f['id'],
        'code' => (string) $f['code'],
        'label' => (string) $f['label'],
        'group' => (string) ($f['group_label'] ?? ''),
        'is_active' => (bool) $f['is_active'],
        'in_trash' => $f['deleted_at'] !== null,
        'deleted_at' => (string) ($f['deleted_at'] ?? ''),
        'remain_days' => $remain,
    ], JSON_UNESCAPED_UNICODE));
}

$action = trim((string) ($_GET['action'] ?? ''));
$dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date_from'] ?? '')) ? (string) $_GET['date_from'] : '';
$dateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date_to'] ?? '')) ? (string) $_GET['date_to'] : '';
$adminName = trim((string) ($_GET['admin'] ?? ''));
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
// CSV dışa aktarma — aynı filtrelerle (limit yok).
$wantCsv = (string) ($_GET['export'] ?? '') === 'csv';
if ($wantCsv) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="denetim-kayitlari.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM — Excel'de Türkçe karakterler
    fputcsv($out, ['Zaman', 'Yönetici', 'İşlem', 'Nesne', 'Nesne ID', 'Detay (JSON)', 'IP']);
    $cq = db()->prepare($sql);
    $cq->execute($params);
    foreach ($cq->fetchAll() as $r) {
        fputcsv($out, [
            (string) $r['created_at'],
            (string) $r['admin_username'],
            (string) $r['action'],
            (string) ($r['entity_type'] ?? ''),
            (string) ($r['entity_id'] ?? ''),
            (string) ($r['details'] ?? ''),
            (string) ($r['ip'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}
$sql .= ' LIMIT 500';
$extraParts = [];
if ($dateFrom !== '') $extraParts['date_from'] = $dateFrom;
if ($dateTo !== '') $extraParts['date_to'] = $dateTo;
if ($adminName !== '') $extraParts['admin'] = $adminName;
$extra = http_build_query($extraParts);
$filtersSuffix = $extra !== '' ? '&' . $extra : '';
$baseAudit = '/nexustraveltech/admin/denetim-kayitlari';
$q = db()->prepare($sql);
$q->execute($params);
$rows = $q->fetchAll();
$actions = db()->query('SELECT action,COUNT(*) c FROM admin_audit_logs GROUP BY action ORDER BY c DESC LIMIT 30')->fetchAll();
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Denetim kayıtları | NEXUS Admin</title><style>body{margin:0;background:#f7f7f2;color:#10211f;font-family:Arial,sans-serif}.wrap{width:min(1180px,calc(100% - 32px));margin:40px auto}.top{display:flex;justify-content:space-between;gap:20px;align-items:center}.brand{font-size:28px;font-weight:800}.brand span{color:#e85f42}.back{color:#10211f}.chips{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0}.chip{background:#fff;border:1px solid #e1e5de;padding:6px 10px;font-size:12px;text-decoration:none;color:#10211f}.chip.on{background:#10211f;color:#fff}table{width:100%;border-collapse:collapse;background:#fff}th,td{text-align:left;border-bottom:1px solid #e1e5de;padding:11px 12px;font-size:13px;vertical-align:top}th{font-size:11px;text-transform:uppercase;color:#64716d}code{background:#f2f4ef;padding:2px 5px;font-size:12px}pre{margin:4px 0 0;white-space:pre-wrap;font-size:11px;color:#555}</style></head><body><main class="wrap"><div class="top"><div><div class="brand">N<span>∿</span>XUS Admin</div><p>Yönetim işlemleri denetim kaydı — kim, ne, ne zaman</p></div><a class="back" href="/nexustraveltech/admin/">← Panele dön</a></div>
<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;background:#fff;border:1px solid #e1e5de;padding:10px 12px;border-radius:8px;margin:16px 0 4px"><input type="hidden" name="action" value="<?=htmlspecialchars($action)?>"><label style="font-size:12px;color:#64716d">Başlangıç<input type="date" name="date_from" value="<?=htmlspecialchars($dateFrom)?>" style="margin-left:6px;padding:6px;border:1px solid #d8ded8"></label><label style="font-size:12px;color:#64716d">Bitiş<input type="date" name="date_to" value="<?=htmlspecialchars($dateTo)?>" style="margin-left:6px;padding:6px;border:1px solid #d8ded8"></label><label style="font-size:12px;color:#64716d">Yönetici<input type="text" name="admin" value="<?=htmlspecialchars($adminName)?>" placeholder="Kullanıcı adı" style="margin-left:6px;padding:6px;border:1px solid #d8ded8"></label><button style="padding:6px 14px;background:#10211f;color:#fff;border:0;cursor:pointer;font-weight:bold">Filtrele</button><?php if ($dateFrom !== '' || $dateTo !== '' || $adminName !== ''): ?><a href="<?=$baseAudit?>" style="font-size:12px;color:#64716d">Sıfırla</a><?php endif; ?><a href="<?=$baseAudit?>?export=csv<?=$filtersSuffix?>" style="padding:6px 14px;background:#405b13;color:#fff;border:0;font-weight:bold;text-decoration:none;font-size:13px" title="Geçerli filtrelerle dışa aktar">⬇ CSV indir</a></form>
<div class="chips"><a class="chip <?=$action===''?'on':''?>" href="<?=$baseAudit?><?= $extra !== '' ? '?' . $extra : '' ?>">Tümü</a><a class="chip <?=$action==='feature.restore'?'on':''?>" style="background:<?=$action==='feature.restore'?'#405b13':'#e6f8c7'?>;color:#10211f;border-color:#bcd98a" href="?action=<?=urlencode('feature.restore')?><?=$filtersSuffix?>">↩ Geri alınan özellikler</a><a class="chip <?=$action==='feature.delete'?'on':''?>" style="background:<?=$action==='feature.delete'?'#8e2410':'#ffe2de'?>;color:#10211f;border-color:#f3c4ba" href="?action=<?=urlencode('feature.delete')?><?=$filtersSuffix?>">✗ Silinen özellikler</a><a class="chip <?=$action==='feature.trash_purge'?'on':''?>" href="?action=<?=urlencode('feature.trash_purge')?><?=$filtersSuffix?>">🗑 Çöp temizliği</a><a class="chip <?=str_starts_with($action,'feature.bulk_')?'on':''?>" style="background:<?=str_starts_with($action,'feature.bulk_')?'#2b4a7a':'#eef3fb'?>;color:#10211f;border-color:#b9cbe8" href="?action=<?=urlencode('feature.bulk_')?><?=$filtersSuffix?>">⚡ Toplu işlemler</a><?php foreach ($actions as $a): ?><a class="chip <?=$action===$a['action']?'on':''?>" href="?action=<?=urlencode($a['action'])?><?=$filtersSuffix?>"><?=htmlspecialchars($a['action'])?> (<?=(int)$a['c']?>)</a><?php endforeach; ?></div>
<table><tr><th>Zaman</th><th>Yönetici</th><th>İşlem</th><th>Nesne</th><th>Detay</th><th>IP</th></tr>
<?php foreach ($rows as $r): $details = json_decode((string)$r['details'], true); $isRestore = $r['action'] === 'feature.restore'; $isBulk = in_array($r['action'], ['feature.bulk_delete', 'feature.bulk_deactivate', 'feature.bulk_activate'], true); $rowStyle = $isRestore ? 'background:#f4fbea' : ($isBulk ? 'background:#eef3fb' : ''); ?>
<tr<?= $rowStyle !== '' ? ' style="' . $rowStyle . '"' : '' ?>><td><?=htmlspecialchars((string)$r['created_at'])?></td><td><?=htmlspecialchars($r['admin_username'])?></td><td><code><?=htmlspecialchars($r['action'])?></code></td><td><?=htmlspecialchars((string)($r['entity_type'] ?? ''))?><?= $r['entity_id'] ? ' #' . (int)$r['entity_id'] : '' ?></td><td><?php if ($details): ?><?php if ($isBulk): ?><div><b style="color:#1a3d6d"><?= (int)($details['count'] ?? 0) ?> özellik</b><?php if (array_key_exists('affected_count', $details)): ?> · <b style="color:#b0301a"><?= (int)$details['affected_count'] ?> ilan</b><?php endif; ?></div><?php if (!empty($details['feature_ids']) && is_array($details['feature_ids'])): ?><div style="margin:5px 0 4px">ID'ler: <?php foreach ($details['feature_ids'] as $fid): ?><a href="javascript:void(0)" class="fid-qv" data-id="<?= (int)$fid ?>" title="Duruma hızlı bak"><code style="margin-right:4px;cursor:pointer;border-bottom:1px dashed #64716d">#<?= (int)$fid ?> ⚡</code></a><?php endforeach; ?><span class="fid-qv-box" style="display:none;margin:8px 0 4px;padding:10px 12px;background:#f4f6f1;border:1px solid #d5dccf;border-radius:8px;font-size:13px;max-width:420px"></span></div><?php endif; ?><pre><?=htmlspecialchars(json_encode($details, JSON_UNESCAPED_UNICODE))?></pre><?php elseif ($isRestore): $restoreLabel = (string) ($details['label'] ?? ''); $restoreCode = (string) ($details['code'] ?? ''); $propIds = is_array($details['affected_listing_ids'] ?? null) ? array_values(array_filter(array_map('intval', $details['affected_listing_ids']))) : []; $propsByName = []; if ($propIds) { $pidsSql = implode(',', $propIds); foreach (db()->query("SELECT id, name, property_type FROM properties WHERE id IN ({$pidsSql})")->fetchAll() as $pr) { $propsByName[(int) $pr['id']] = $pr; } } $secPerProp = []; $bkQ = db()->prepare('SELECT affected_properties FROM feature_delete_backups WHERE feature_id=? ORDER BY id DESC LIMIT 1'); $bkQ->execute([(int) $r['entity_id']]); $bkRow = $bkQ->fetch(); if ($bkRow) { foreach ((array) (json_decode((string) $bkRow['affected_properties'], true) ?: []) as $bp) { if (is_array($bp) && isset($bp['id'], $bp['sections']) && is_array($bp['sections'])) { $secPerProp[(int) $bp['id']] = count($bp['sections']); } } } ?><div style="margin-bottom:6px"><b><?= htmlspecialchars($restoreLabel) ?></b><?= $restoreCode !== '' ? ' <code style="margin-left:4px">' . htmlspecialchars($restoreCode) . '</code>' : '' ?></div><table style="margin:4px 0 8px;border-collapse:collapse;width:100%"><tr><th style="text-align:left;padding:5px 8px;border:1px solid #e1e5de;font-size:10px;color:#64716d">İlan</th><th style="text-align:left;padding:5px 8px;border:1px solid #e1e5de;font-size:10px;color:#64716d">Geri eklenen bölüm</th></tr><?php foreach ($propIds as $pid): ?><tr><td style="padding:5px 8px;border:1px solid #e1e5de"><?= isset($propsByName[$pid]) ? htmlspecialchars($propsByName[$pid]['name']) : '#' . (int) $pid ?><?= isset($propsByName[$pid]) && $propsByName[$pid]['property_type'] ? ' <small style="color:#6b7774">(' . htmlspecialchars($propsByName[$pid]['property_type']) . ')</small>' : '' ?></td><td style="padding:5px 8px;border:1px solid #e1e5de"><?= isset($secPerProp[$pid]) ? (int) $secPerProp[$pid] : '—' ?></td></tr><?php endforeach; ?><tr><td style="padding:5px 8px;border:1px solid #e1e5de;font-weight:bold;background:#f4fbea">Toplam</td><td style="padding:5px 8px;border:1px solid #e1e5de;font-weight:bold;background:#f4fbea"><?= (int) ($details['restored_sections'] ?? 0) ?></td></tr></table><details style="font-size:11px"><summary style="cursor:pointer;color:#6b7774">Ham JSON</summary><pre style="margin:4px 0 0"><?= htmlspecialchars(json_encode($details, JSON_UNESCAPED_UNICODE)) ?></pre></details><?php else: ?><pre><?=htmlspecialchars(json_encode($details, JSON_UNESCAPED_UNICODE))?></pre><?php endif; ?><?php endif; ?></td><td><?=htmlspecialchars((string)$r['ip'])?></td></tr>
<?php endforeach; ?>
</table>
</main><script>
function fidQuickView(el){var fid=el.dataset.id,box=el.closest('div').querySelector('.fid-qv-box');if(!box)return;if(box.dataset.fid===fid&&box.style.display!=='none'){box.style.display='none';box.dataset.fid='';return}box.dataset.fid=fid;box.textContent='Yükleniyor…';box.style.display='block';fetch('/nexustraveltech/admin/denetim-kayitlari?qview=feature&id='+fid).then(function(r){return r.json()}).then(function(d){if(!d.ok){box.innerHTML='<b style="color:#8e2410">#'+fid+'</b> — '+d.error;return}var html='<b style="color:#10211f">'+d.label+'</b> <code>'+d.code+'</code>';if(d.group)html+=' <small style="color:#6b7774">· '+d.group+'</small>';if(d.in_trash){html+='<div style="margin-top:4px"><span style="display:inline-block;background:#fdf3e3;border:1px solid #e0c9a3;color:#8a6100;border-radius:12px;padding:2px 10px;font-size:12px;font-weight:bold">🗑 Çöp kutusunda</span> <small style="color:#6b7774">silindi '+d.deleted_at.slice(0,16)+' · kalıcı silmeye '+d.remain_days+' gün</small></div>'}else{html+='<div style="margin-top:4px"><span style="display:inline-block;border-radius:12px;padding:2px 10px;font-size:12px;font-weight:bold;background:'+(d.is_active?'#e6f8c7;color:#2e7d32':'#ffe2de;color:#b0301a')+'">'+(d.is_active?'● Aktif':'○ Pasif')+'</span></div>'}box.innerHTML=html}).catch(function(){box.innerHTML='<b style="color:#8e2410">#'+fid+'</b> — Durum okunamadı (oturum süresi dolmuş olabilir).'})}
document.querySelectorAll('.fid-qv').forEach(function(a){a.addEventListener('click',function(){fidQuickView(a)})});
</script><?php require_once __DIR__.'/../config/ai_widget.php'; ai_widget('/nexustraveltech/admin/ai-chat','admin_csrf'); ?></body></html>
