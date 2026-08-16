<?php

declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';

require_admin();

$action = trim((string) ($_GET['action'] ?? ''));
$sql = 'SELECT * FROM admin_audit_logs WHERE 1=1';
$params = [];
if ($action !== '') {
    $sql .= ' AND action LIKE ?';
    $params[] = '%' . $action . '%';
}
$sql .= ' ORDER BY id DESC LIMIT 200';
$q = db()->prepare($sql);
$q->execute($params);
$rows = $q->fetchAll();
$actions = db()->query('SELECT action,COUNT(*) c FROM admin_audit_logs GROUP BY action ORDER BY c DESC LIMIT 30')->fetchAll();
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Denetim kayıtları | NEXUS Admin</title><style>body{margin:0;background:#f7f7f2;color:#10211f;font-family:Arial,sans-serif}.wrap{width:min(1180px,calc(100% - 32px));margin:40px auto}.top{display:flex;justify-content:space-between;gap:20px;align-items:center}.brand{font-size:28px;font-weight:800}.brand span{color:#e85f42}.back{color:#10211f}.chips{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0}.chip{background:#fff;border:1px solid #e1e5de;padding:6px 10px;font-size:12px;text-decoration:none;color:#10211f}.chip.on{background:#10211f;color:#fff}table{width:100%;border-collapse:collapse;background:#fff}th,td{text-align:left;border-bottom:1px solid #e1e5de;padding:11px 12px;font-size:13px;vertical-align:top}th{font-size:11px;text-transform:uppercase;color:#64716d}code{background:#f2f4ef;padding:2px 5px;font-size:12px}pre{margin:4px 0 0;white-space:pre-wrap;font-size:11px;color:#555}</style></head><body><main class="wrap"><div class="top"><div><div class="brand">N<span>∿</span>XUS Admin</div><p>Yönetim işlemleri denetim kaydı — kim, ne, ne zaman</p></div><a class="back" href="/nexustraveltech/admin/">← Panele dön</a></div>
<div class="chips"><a class="chip <?=$action===''?'on':''?>" href="/nexustraveltech/admin/denetim-kayitlari">Tümü</a><?php foreach ($actions as $a): ?><a class="chip <?=$action===$a['action']?'on':''?>" href="?action=<?=urlencode($a['action'])?>"><?=htmlspecialchars($a['action'])?> (<?=(int)$a['c']?>)</a><?php endforeach; ?></div>
<table><tr><th>Zaman</th><th>Yönetici</th><th>İşlem</th><th>Nesne</th><th>Detay</th><th>IP</th></tr>
<?php foreach ($rows as $r): $details = json_decode((string)$r['details'], true); ?>
<tr><td><?=htmlspecialchars((string)$r['created_at'])?></td><td><?=htmlspecialchars($r['admin_username'])?></td><td><code><?=htmlspecialchars($r['action'])?></code></td><td><?=htmlspecialchars((string)($r['entity_type'] ?? ''))?><?= $r['entity_id'] ? ' #' . (int)$r['entity_id'] : '' ?></td><td><?php if ($details): ?><pre><?=htmlspecialchars(json_encode($details, JSON_UNESCAPED_UNICODE))?></pre><?php endif; ?></td><td><?=htmlspecialchars((string)$r['ip'])?></td></tr>
<?php endforeach; ?>
</table>
</main></body></html>
