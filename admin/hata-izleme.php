<?php

declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';

require_admin();
if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));

$message = '';
$error = '';
$level = (string) ($_GET['level'] ?? '');
$status = (string) ($_GET['status'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['admin_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Güvenlik doğrulaması geçersiz.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'review' && (int) ($_POST['id'] ?? 0) > 0) {
            db()->prepare("UPDATE error_logs SET status='reviewed' WHERE id=?")->execute([(int) $_POST['id']]);
            $message = 'Kayıt incelendi olarak işaretlendi.';
        }
        if ($action === 'purge') {
            $q = db()->prepare("DELETE FROM error_logs WHERE status='reviewed' AND created_at < now() - interval '30 days'");
            $q->execute();
            $message = $q->rowCount() . ' eski kayıt temizlendi.';
        }
    }
}

$sql = 'SELECT * FROM error_logs WHERE 1=1';
$params = [];
if ($level !== '' && in_array($level, ['debug', 'info', 'warning', 'error', 'critical'], true)) {
    $sql .= ' AND level=?';
    $params[] = $level;
}
if ($status === 'new') $sql .= " AND status='new'";
elseif ($status === 'reviewed') $sql .= " AND status='reviewed'";
$sql .= ' ORDER BY id DESC LIMIT 150';
$q = db()->prepare($sql);
$q->execute($params);
$rows = $q->fetchAll();
$counts = db()->query('SELECT level,status,COUNT(*) c FROM error_logs GROUP BY level,status')->fetchAll();
$totalNew = (int) db()->query("SELECT COUNT(*) FROM error_logs WHERE status='new'")->fetchColumn();
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Hata izleme | NEXUS Admin</title><style>body{margin:0;background:#f7f7f2;color:#10211f;font-family:Arial,sans-serif}.wrap{width:min(1180px,calc(100% - 32px));margin:40px auto}.top{display:flex;justify-content:space-between;gap:20px;align-items:center}.brand{font-size:28px;font-weight:800}.brand span{color:#e85f42}.back{color:#10211f}.notice,.error{padding:11px}.notice{background:#e6f8c7}.error{background:#ffe2de}.bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:16px 0}.bar a{padding:8px 12px;background:#fff;border:1px solid #e1e5de;color:#10211f;text-decoration:none;font-size:13px}.bar a.on{background:#10211f;color:#fff}table{width:100%;border-collapse:collapse;background:#fff}th,td{text-align:left;border-bottom:1px solid #e1e5de;padding:10px 12px;font-size:13px;vertical-align:top}th{font-size:11px;text-transform:uppercase;color:#64716d}pre{margin:4px 0 0;white-space:pre-wrap;font-size:12px;color:#555}.lvl{font-weight:800;font-size:11px;text-transform:uppercase}.lvl-error,.lvl-critical{color:#b0301a}.lvl-warning{color:#a86026}.lvl-info{color:#2e6da4}.st-new{background:#ffe2de;padding:2px 6px;font-size:11px;font-weight:700}.st-reviewed{background:#eef1ec;padding:2px 6px;font-size:11px;color:#64716d}</style></head><body><main class="wrap"><div class="top"><div><div class="brand">N<span>∿</span>XUS Admin</div><p>Uygulama hataları ve uyarıları — <?=(int)$totalNew?> yeni kayıt</p></div><a class="back" href="/nexustraveltech/admin/">← Panele dön</a></div>
<?php if ($message): ?><p class="notice"><?=htmlspecialchars($message)?></p><?php endif; ?>
<?php if ($error): ?><p class="error"><?=htmlspecialchars($error)?></p><?php endif; ?>
<div class="bar"><a class="<?=$level===''?'on':''?>" href="/nexustraveltech/admin/hata-izleme">Tümü</a><a class="<?=$level==='error'?'on':''?>" href="?level=error">Hatalar</a><a class="<?=$level==='warning'?'on':''?>" href="?level=warning">Uyarılar</a><a class="<?=$level==='critical'?'on':''?>" href="?level=critical">Kritik</a><span style="flex:1"></span><a class="<?=$status==='new'?'on':''?>" href="?status=new">Yeni</a><a class="<?=$status==='reviewed'?'on':''?>" href="?status=reviewed">İncelendi</a></div>
<?php if ($counts): ?><p style="font-size:13px;color:#64716d"><?php foreach ($counts as $c): ?><b style="color:#10211f"><?=htmlspecialchars($c['level'])?>/<?=htmlspecialchars($c['status'])?>: <?=(int)$c['c']?></b>&nbsp;&nbsp;<?php endforeach; ?></p><?php endif; ?>
<table><tr><th>Seviye</th><th>Mesaj / bağlam</th><th>Sayfa</th><th>IP</th><th>Zaman</th><th>Durum</th></tr>
<?php foreach ($rows as $r): ?>
<tr><td><span class="lvl lvl-<?=htmlspecialchars($r['level'])?>"><?=htmlspecialchars($r['level'])?></span></td>
<td><b><?=htmlspecialchars($r['message'])?></b><?php $ctx = json_decode((string)$r['context'], true); if ($ctx): ?><pre><?=htmlspecialchars(json_encode($ctx, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT))?></pre><?php endif; ?></td>
<td><?=htmlspecialchars((string)$r['request_uri'])?></td><td><?=htmlspecialchars((string)$r['ip'])?></td>
<td><?=htmlspecialchars((string)$r['created_at'])?></td>
<td><?php if ($r['status'] === 'new'): ?><form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="action" value="review"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button style="background:#10211f;color:#fff;border:0;padding:6px 10px;cursor:pointer">İncelendi</button></form><?php else: ?><span class="st-reviewed">incelendi</span><?php endif; ?></td></tr>
<?php endforeach; ?>
</table>
<form method="post" style="margin-top:16px"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="action" value="purge"><button style="background:#ffe3dd;border:0;padding:9px 13px;font-weight:700;cursor:pointer;color:#8e2410">30 günden eski incelenmiş kayıtları temizle</button></form>
</main><?php require_once __DIR__.'/../config/ai_widget.php'; ai_widget('/nexustraveltech/admin/ai-chat','admin_csrf'); ?></body></html>
