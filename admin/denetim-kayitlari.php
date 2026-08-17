<?php

declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';

require_admin();

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
$sql .= ' ORDER BY id DESC LIMIT 500';
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
<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;background:#fff;border:1px solid #e1e5de;padding:10px 12px;border-radius:8px;margin:16px 0 4px"><input type="hidden" name="action" value="<?=htmlspecialchars($action)?>"><label style="font-size:12px;color:#64716d">Başlangıç<input type="date" name="date_from" value="<?=htmlspecialchars($dateFrom)?>" style="margin-left:6px;padding:6px;border:1px solid #d8ded8"></label><label style="font-size:12px;color:#64716d">Bitiş<input type="date" name="date_to" value="<?=htmlspecialchars($dateTo)?>" style="margin-left:6px;padding:6px;border:1px solid #d8ded8"></label><label style="font-size:12px;color:#64716d">Yönetici<input type="text" name="admin" value="<?=htmlspecialchars($adminName)?>" placeholder="Kullanıcı adı" style="margin-left:6px;padding:6px;border:1px solid #d8ded8"></label><button style="padding:6px 14px;background:#10211f;color:#fff;border:0;cursor:pointer;font-weight:bold">Filtrele</button><?php if ($dateFrom !== '' || $dateTo !== '' || $adminName !== ''): ?><a href="<?=$baseAudit?>" style="font-size:12px;color:#64716d">Sıfırla</a><?php endif; ?></form>
<div class="chips"><a class="chip <?=$action===''?'on':''?>" href="<?=$baseAudit?><?= $extra !== '' ? '?' . $extra : '' ?>">Tümü</a><a class="chip <?=$action==='feature.restore'?'on':''?>" style="background:<?=$action==='feature.restore'?'#405b13':'#e6f8c7'?>;color:#10211f;border-color:#bcd98a" href="?action=<?=urlencode('feature.restore')?><?=$filtersSuffix?>">↩ Geri alınan özellikler</a><a class="chip <?=$action==='feature.delete'?'on':''?>" style="background:<?=$action==='feature.delete'?'#8e2410':'#ffe2de'?>;color:#10211f;border-color:#f3c4ba" href="?action=<?=urlencode('feature.delete')?><?=$filtersSuffix?>">✗ Silinen özellikler</a><a class="chip <?=$action==='feature.trash_purge'?'on':''?>" href="?action=<?=urlencode('feature.trash_purge')?><?=$filtersSuffix?>">🗑 Çöp temizliği</a><a class="chip <?=str_starts_with($action,'feature.bulk_')?'on':''?>" style="background:<?=str_starts_with($action,'feature.bulk_')?'#2b4a7a':'#eef3fb'?>;color:#10211f;border-color:#b9cbe8" href="?action=<?=urlencode('feature.bulk_')?><?=$filtersSuffix?>">⚡ Toplu işlemler</a><?php foreach ($actions as $a): ?><a class="chip <?=$action===$a['action']?'on':''?>" href="?action=<?=urlencode($a['action'])?><?=$filtersSuffix?>"><?=htmlspecialchars($a['action'])?> (<?=(int)$a['c']?>)</a><?php endforeach; ?></div>
<table><tr><th>Zaman</th><th>Yönetici</th><th>İşlem</th><th>Nesne</th><th>Detay</th><th>IP</th></tr>
<?php foreach ($rows as $r): $details = json_decode((string)$r['details'], true); $isRestore = $r['action'] === 'feature.restore'; $isBulk = in_array($r['action'], ['feature.bulk_delete', 'feature.bulk_deactivate', 'feature.bulk_activate'], true); $rowStyle = $isRestore ? 'background:#f4fbea' : ($isBulk ? 'background:#eef3fb' : ''); ?>
<tr<?= $rowStyle !== '' ? ' style="' . $rowStyle . '"' : '' ?>><td><?=htmlspecialchars((string)$r['created_at'])?></td><td><?=htmlspecialchars($r['admin_username'])?></td><td><code><?=htmlspecialchars($r['action'])?></code></td><td><?=htmlspecialchars((string)($r['entity_type'] ?? ''))?><?= $r['entity_id'] ? ' #' . (int)$r['entity_id'] : '' ?></td><td><?php if ($details): ?><?php if ($isBulk): ?><div><b style="color:#1a3d6d"><?= (int)($details['count'] ?? 0) ?> özellik</b><?php if (array_key_exists('affected_count', $details)): ?> · <b style="color:#b0301a"><?= (int)$details['affected_count'] ?> ilan</b><?php endif; ?></div><?php if (!empty($details['feature_ids']) && is_array($details['feature_ids'])): ?><div style="margin:5px 0 4px">ID'ler: <?php foreach ($details['feature_ids'] as $fid): ?><code style="margin-right:4px">#<?= (int)$fid ?></code><?php endforeach; ?></div><?php endif; ?><pre><?=htmlspecialchars(json_encode($details, JSON_UNESCAPED_UNICODE))?></pre><?php else: ?><pre><?=htmlspecialchars(json_encode($details, JSON_UNESCAPED_UNICODE))?></pre><?php endif; ?><?php endif; ?></td><td><?=htmlspecialchars((string)$r['ip'])?></td></tr>
<?php endforeach; ?>
</table>
</main><?php require_once __DIR__.'/../config/ai_widget.php'; ai_widget('/nexustraveltech/admin/ai-chat','admin_csrf'); ?></body></html>
