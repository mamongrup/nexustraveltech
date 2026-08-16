<?php

declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';

require_admin();

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$ip = trim((string) ($_GET['ip'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));

$where = ['1=1'];
$params = [];
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'created_at>=?'; $params[] = $from . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 'created_at<=?'; $params[] = $to . ' 23:59:59'; }
if ($ip !== '') { $where[] = 'ip::text LIKE ?'; $params[] = '%' . $ip . '%'; }
if ($q !== '') { $where[] = '(user_message ILIKE ? OR ai_reply ILIKE ?)'; $params[] = '%' . $q . '%'; $params[] = '%' . $q . '%'; }
$sqlWhere = implode(' AND ', $where);

// CSV dışa aktarma (aynı filtrelerle).
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ziyaretci-sohbet-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Zaman', 'IP', 'Soru', 'Yanıt']);
    $qRows = db()->prepare("SELECT created_at,ip,user_message,ai_reply FROM public_chat_messages WHERE $sqlWhere ORDER BY id DESC");
    $qRows->execute($params);
    while ($row = $qRows->fetch()) {
        fputcsv($out, [
            (string) $row['created_at'],
            (string) ($row['ip'] ?? ''),
            (string) $row['user_message'],
            (string) $row['ai_reply'],
        ]);
    }
    fclose($out);
    exit;
}

$countStmt = db()->prepare("SELECT COUNT(*) FROM public_chat_messages WHERE $sqlWhere");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$perPage = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$rowsStmt = db()->prepare("SELECT id,created_at,ip,user_message,ai_reply FROM public_chat_messages WHERE $sqlWhere ORDER BY id DESC LIMIT $perPage OFFSET $offset");
$rowsStmt->execute($params);
$rows = $rowsStmt->fetchAll();

$totalAll = (int) db()->query('SELECT COUNT(*) FROM public_chat_messages')->fetchColumn();
$todayCount = (int) db()->query("SELECT COUNT(*) FROM public_chat_messages WHERE created_at >= CURRENT_DATE")->fetchColumn();

$qs = function (array $extra) use ($from, $to, $ip, $q): string {
    $p = ['from' => $from, 'to' => $to, 'ip' => $ip, 'q' => $q];
    foreach ($extra as $k => $v) $p[$k] = $v;
    return '?' . http_build_query(array_filter($p, fn($v) => $v !== ''));
};
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ziyaretçi sohbet kayıtları | NEXUS Admin</title><style>body{margin:0;background:#f7f7f2;color:#10211f;font-family:Arial,sans-serif}.wrap{width:min(1180px,calc(100% - 32px));margin:40px auto}.top{display:flex;justify-content:space-between;gap:20px;align-items:center}.brand{font-size:28px;font-weight:800}.brand span{color:#e85f42}.back{color:#10211f}.stats{display:flex;gap:10px;flex-wrap:wrap;margin:16px 0}.stat{background:#fff;border:1px solid #e1e5de;padding:10px 14px;font-size:13px}.stat b{font-size:20px;display:block}.filters{background:#fff;border:1px solid #e1e5de;padding:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:14px 0}.filters input,.filters button{padding:8px 10px;font:inherit;border:1px solid #d8ded8;font-size:13px}.filters button{background:#10211f;color:#fff;border:0;font-weight:700;cursor:pointer}table{width:100%;border-collapse:collapse;background:#fff}th,td{text-align:left;border-bottom:1px solid #e1e5de;padding:11px 12px;font-size:13px;vertical-align:top}th{font-size:11px;text-transform:uppercase;color:#64716d}.ip{font-family:monospace;font-size:12px;white-space:nowrap}.msg{white-space:pre-wrap;word-break:break-word}.muted{color:#64716d}.pages{display:flex;gap:6px;margin:16px 0;flex-wrap:wrap}.pages a,.pages span{padding:7px 11px;background:#fff;border:1px solid #e1e5de;color:#10211f;text-decoration:none;font-size:13px}.pages .on{background:#10211f;color:#fff}.exp{font-size:12px;color:#0d7a4a;text-decoration:none;font-weight:700}</style></head><body><main class="wrap"><div class="top"><div><div class="brand">N<span>∿</span>XUS Admin</div><p>Önyüz AI asistan ziyaretçi sohbet kayıtları — soru, yanıt, IP, zaman</p></div><a class="back" href="/nexustraveltech/admin/">← Panele dön</a></div>
<div class="stats"><div class="stat"><b><?= (int)$totalAll ?></b>Tüm kayıt</div><div class="stat"><b><?= (int)$todayCount ?></b>Bugün</div><div class="stat"><b><?= (int)$total ?></b>Filtre sonucu</div></div>
<form class="filters" method="get" action="/nexustraveltech/admin/ziyaretci-sohbet">
  <input type="date" name="from" value="<?=htmlspecialchars($from)?>" title="Başlangıç">
  <input type="date" name="to" value="<?=htmlspecialchars($to)?>" title="Bitiş">
  <input type="text" name="ip" value="<?=htmlspecialchars($ip)?>" placeholder="IP ara…" style="width:150px">
  <input type="text" name="q" value="<?=htmlspecialchars($q)?>" placeholder="Soru/yanıt içinde ara…" style="width:220px">
  <button>Filtrele</button>
  <a class="exp" href="<?=htmlspecialchars($qs(['export' => 'csv']))?>">⬇ CSV indir</a>
</form>
<?php if (!$rows): ?><p class="muted">Kayıt bulunamadı.</p><?php endif; ?>
<table><tr><th>Zaman</th><th>IP</th><th>Soru</th><th>Yanıt</th></tr>
<?php foreach ($rows as $r): ?>
<tr><td class="muted" style="white-space:nowrap"><?=htmlspecialchars((string)$r['created_at'])?></td><td class="ip"><?=htmlspecialchars((string)($r['ip'] ?? '—'))?></td><td class="msg"><?=htmlspecialchars((string)$r['user_message'])?></td><td class="msg"><?=htmlspecialchars((string)$r['ai_reply'])?></td></tr>
<?php endforeach; ?>
</table>
<?php if ($pages > 1): ?><div class="pages"><?php for ($i = 1; $i <= $pages; $i++): ?><a class="<?=$i===$page?'on':''?>" href="<?=htmlspecialchars($qs(['page' => $i]))?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?>
</main><?php require_once __DIR__.'/../config/ai_widget.php'; ai_widget('/nexustraveltech/admin/ai-chat','admin_csrf'); ?></body></html>
