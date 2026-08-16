<?php

declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/audit.php';

require_admin();
if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['admin_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Güvenlik doğrulaması geçersiz.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $ipRaw = trim((string) ($_POST['ip'] ?? ''));
        if ($ipRaw === '' || !filter_var($ipRaw, FILTER_VALIDATE_IP)) {
            $error = 'Geçersiz IP adresi.';
        } elseif ($action === 'unblock') {
            db()->prepare('DELETE FROM blocked_ips WHERE ip=?::inet')->execute([$ipRaw]);
            audit_log('ip.unblocked', 'ip', 0, ['ip' => $ipRaw]);
            $message = 'IP kısıtlaması kaldırıldı: ' . $ipRaw;
        } elseif ($action === 'block' || $action === 'flag') {
            $reason = trim((string) ($_POST['reason'] ?? ''));
            db()->prepare('INSERT INTO blocked_ips(ip,action,reason,created_by) VALUES(?::inet,?,?,?) ON CONFLICT(ip) DO UPDATE SET action=EXCLUDED.action,reason=EXCLUDED.reason,created_at=now()')
                ->execute([$ipRaw, $action, $reason !== '' ? mb_substr($reason, 0, 500) : null, (string) ($_SESSION['admin_username'] ?? 'admin')]);
            audit_log($action === 'block' ? 'ip.blocked' : 'ip.flagged', 'ip', 0, ['ip' => $ipRaw, 'reason' => $reason]);
            $message = ($action === 'block' ? 'IP engellendi' : 'IP bayraklandı') . ': ' . $ipRaw;
        } else {
            $error = 'Bilinmeyen işlem.';
        }
    }
}

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$ip = trim((string) ($_GET['ip'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));

$where = ['1=1'];
$params = [];
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'm.created_at>=?'; $params[] = $from . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 'm.created_at<=?'; $params[] = $to . ' 23:59:59'; }
if ($ip !== '') { $where[] = 'm.ip::text LIKE ?'; $params[] = '%' . $ip . '%'; }
if ($q !== '') { $where[] = '(m.user_message ILIKE ? OR m.ai_reply ILIKE ?)'; $params[] = '%' . $q . '%'; $params[] = '%' . $q . '%'; }
$sqlWhere = implode(' AND ', $where);

// CSV dışa aktarma (aynı filtrelerle).
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ziyaretci-sohbet-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Zaman', 'IP', 'Soru', 'Yanıt', 'Durum']);
    $qRows = db()->prepare("SELECT m.created_at,m.ip,m.user_message,m.ai_reply,b.action ip_action FROM public_chat_messages m LEFT JOIN blocked_ips b ON b.ip=m.ip WHERE $sqlWhere ORDER BY m.id DESC");
    $qRows->execute($params);
    while ($row = $qRows->fetch()) {
        fputcsv($out, [
            (string) $row['created_at'],
            (string) ($row['ip'] ?? ''),
            (string) $row['user_message'],
            (string) $row['ai_reply'],
            (string) ($row['ip_action'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

$countStmt = db()->prepare("SELECT COUNT(*) FROM public_chat_messages m WHERE $sqlWhere");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$perPage = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$rowsStmt = db()->prepare("SELECT m.id,m.created_at,m.ip,m.user_message,m.ai_reply,b.action ip_action,b.reason ip_reason FROM public_chat_messages m LEFT JOIN blocked_ips b ON b.ip=m.ip WHERE $sqlWhere ORDER BY m.id DESC LIMIT $perPage OFFSET $offset");
$rowsStmt->execute($params);
$rows = $rowsStmt->fetchAll();

$totalAll = (int) db()->query('SELECT COUNT(*) FROM public_chat_messages')->fetchColumn();
$todayCount = (int) db()->query("SELECT COUNT(*) FROM public_chat_messages WHERE created_at >= CURRENT_DATE")->fetchColumn();

$banned = db()->query('SELECT ip,action,reason,created_at FROM blocked_ips ORDER BY created_at DESC LIMIT 50')->fetchAll();
$blockCount = (int) db()->query("SELECT COUNT(*) FROM blocked_ips WHERE action='block'")->fetchColumn();
$flagCount = (int) db()->query("SELECT COUNT(*) FROM blocked_ips WHERE action='flag'")->fetchColumn();

$qs = function (array $extra) use ($from, $to, $ip, $q): string {
    $p = ['from' => $from, 'to' => $to, 'ip' => $ip, 'q' => $q];
    foreach ($extra as $k => $v) $p[$k] = $v;
    return '?' . http_build_query(array_filter($p, fn($v) => $v !== ''));
};
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ziyaretçi sohbet kayıtları | NEXUS Admin</title><style>body{margin:0;background:#f7f7f2;color:#10211f;font-family:Arial,sans-serif}.wrap{width:min(1180px,calc(100% - 32px));margin:40px auto}.top{display:flex;justify-content:space-between;gap:20px;align-items:center}.brand{font-size:28px;font-weight:800}.brand span{color:#e85f42}.back{color:#10211f}.stats{display:flex;gap:10px;flex-wrap:wrap;margin:16px 0}.stat{background:#fff;border:1px solid #e1e5de;padding:10px 14px;font-size:13px}.stat b{font-size:20px;display:block}.stat.danger b{color:#b0301a}.stat.warn b{color:#a86026}.filters{background:#fff;border:1px solid #e1e5de;padding:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:14px 0}.filters input,.filters button{padding:8px 10px;font:inherit;border:1px solid #d8ded8;font-size:13px}.filters button{background:#10211f;color:#fff;border:0;font-weight:700;cursor:pointer}.notice,.error{padding:11px;margin:12px 0}.notice{background:#e6f8c7}.error{background:#ffe2de}table{width:100%;border-collapse:collapse;background:#fff}th,td{text-align:left;border-bottom:1px solid #e1e5de;padding:11px 12px;font-size:13px;vertical-align:top}th{font-size:11px;text-transform:uppercase;color:#64716d}.ip{font-family:monospace;font-size:12px;white-space:nowrap}.msg{white-space:pre-wrap;word-break:break-word}.muted{color:#64716d}.badge{display:inline-block;padding:2px 7px;font-size:11px;font-weight:700;border-radius:3px}.bd-block{background:#ffe2de;color:#8e2410}.bd-flag{background:#fdf0d8;color:#8a5a10}.acts{display:flex;gap:4px;flex-wrap:wrap;margin-top:6px}.acts form{margin:0}.acts button{background:#fff;border:1px solid #d8ded8;padding:4px 8px;font-size:11px;cursor:pointer;color:#10211f}.acts .block{color:#b0301a;border-color:#e8b9b0}.acts .kaldir{background:#ffe3dd;border-color:#e8b9b0;color:#8e2410;font-weight:700}.banbox{background:#fff;border:1px solid #e1e5de;padding:12px;margin:14px 0}.banbox h3{margin:0 0 8px;font-size:14px}.banrow{display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:6px 0;border-top:1px solid #f0f2ef;font-size:13px}.banrow form{margin:0}.pages{display:flex;gap:6px;margin:16px 0;flex-wrap:wrap}.pages a,.pages span{padding:7px 11px;background:#fff;border:1px solid #e1e5de;color:#10211f;text-decoration:none;font-size:13px}.pages .on{background:#10211f;color:#fff}.exp{font-size:12px;color:#0d7a4a;text-decoration:none;font-weight:700}</style></head><body><main class="wrap"><div class="top"><div><div class="brand">N<span>∿</span>XUS Admin</div><p>Önyüz AI asistan ziyaretçi sohbet kayıtları — soru, yanıt, IP, zaman · kötü niyetli IP'leri tek tıkla engelle</p></div><a class="back" href="/nexustraveltech/admin/">← Panele dön</a></div>
<?php if ($message): ?><p class="notice"><?=htmlspecialchars($message)?></p><?php endif; ?>
<?php if ($error): ?><p class="error"><?=htmlspecialchars($error)?></p><?php endif; ?>
<div class="stats"><div class="stat"><b><?= (int)$totalAll ?></b>Tüm kayıt</div><div class="stat"><b><?= (int)$todayCount ?></b>Bugün</div><div class="stat"><b><?= (int)$total ?></b>Filtre sonucu</div><div class="stat danger"><b><?= (int)$blockCount ?></b>Engelli IP</div><div class="stat warn"><b><?= (int)$flagCount ?></b>Bayraklı IP</div></div>
<form class="filters" method="get" action="/nexustraveltech/admin/ziyaretci-sohbet">
  <input type="date" name="from" value="<?=htmlspecialchars($from)?>" title="Başlangıç">
  <input type="date" name="to" value="<?=htmlspecialchars($to)?>" title="Bitiş">
  <input type="text" name="ip" value="<?=htmlspecialchars($ip)?>" placeholder="IP ara…" style="width:150px">
  <input type="text" name="q" value="<?=htmlspecialchars($q)?>" placeholder="Soru/yanıt içinde ara…" style="width:220px">
  <button>Filtrele</button>
  <a class="exp" href="<?=htmlspecialchars($qs(['export' => 'csv']))?>">⬇ CSV indir</a>
</form>
<?php if ($banned): ?>
<div class="banbox"><h3>🚫 Engelli / ⚠ Bayraklı IP'ler</h3>
<?php foreach ($banned as $b): ?>
<div class="banrow"><span class="badge <?=$b['action']==='block'?'bd-block':'bd-flag'?>"><?=$b['action']==='block'?'🚫 Engelli':'⚠ Bayraklı'?></span><b class="ip"><?=htmlspecialchars((string)$b['ip'])?></b><?php if ($b['reason']): ?><span class="muted">— <?=htmlspecialchars((string)$b['reason'])?></span><?php endif; ?><span class="muted"><?=htmlspecialchars((string)$b['created_at'])?></span>
<form method="post" action="/nexustraveltech/admin/ziyaretci-sohbet"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="action" value="unblock"><input type="hidden" name="ip" value="<?=htmlspecialchars((string)$b['ip'])?>"><button class="kaldir">Kısıtlamayı kaldır</button></form></div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php if (!$rows): ?><p class="muted">Kayıt bulunamadı.</p><?php endif; ?>
<table><tr><th>Zaman</th><th>IP</th><th>Soru</th><th>Yanıt</th></tr>
<?php foreach ($rows as $r): ?>
<tr><td class="muted" style="white-space:nowrap"><?=htmlspecialchars((string)$r['created_at'])?></td>
<td class="ip"><b><?=htmlspecialchars((string)($r['ip'] ?? '—'))?></b>
<?php if ($r['ip_action'] === 'block'): ?><br><span class="badge bd-block">🚫 Engelli</span>
<?php elseif ($r['ip_action'] === 'flag'): ?><br><span class="badge bd-flag">⚠ Bayraklı</span><?php endif; ?>
<div class="acts">
<?php if ($r['ip_action'] === 'block'): ?>
<form method="post" action="/nexustraveltech/admin/ziyaretci-sohbet"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="action" value="unblock"><input type="hidden" name="ip" value="<?=htmlspecialchars((string)$r['ip'])?>"><button class="kaldir">Engeli kaldır</button></form>
<?php else: ?>
<form method="post" action="/nexustraveltech/admin/ziyaretci-sohbet"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="action" value="block"><input type="hidden" name="ip" value="<?=htmlspecialchars((string)$r['ip'])?>"><button class="block">🚫 Engelle</button></form>
<?php endif; ?>
<?php if ($r['ip_action'] !== 'flag'): ?>
<form method="post" action="/nexustraveltech/admin/ziyaretci-sohbet"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="action" value="flag"><input type="hidden" name="ip" value="<?=htmlspecialchars((string)$r['ip'])?>"><button>⚠ Bayrakla</button></form>
<?php else: ?>
<form method="post" action="/nexustraveltech/admin/ziyaretci-sohbet"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="action" value="unblock"><input type="hidden" name="ip" value="<?=htmlspecialchars((string)$r['ip'])?>"><button class="kaldir">Bayrağı kaldır</button></form>
<?php endif; ?>
</div></td>
<td class="msg"><?=htmlspecialchars((string)$r['user_message'])?></td><td class="msg"><?=htmlspecialchars((string)$r['ai_reply'])?></td></tr>
<?php endforeach; ?>
</table>
<?php if ($pages > 1): ?><div class="pages"><?php for ($i = 1; $i <= $pages; $i++): ?><a class="<?=$i===$page?'on':''?>" href="<?=htmlspecialchars($qs(['page' => $i]))?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>
</main><?php require_once __DIR__.'/../config/ai_widget.php'; ai_widget('/nexustraveltech/admin/ai-chat','admin_csrf'); ?></body></html>
