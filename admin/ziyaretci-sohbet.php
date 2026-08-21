<?php

declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/audit.php';
require __DIR__ . '/../config/platform_settings.php';
require __DIR__ . '/../config/chat_topics.php';

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
$quality = (string) ($_GET['quality'] ?? 'good');

$where = ['1=1'];
$params = [];
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'm.created_at>=?'; $params[] = $from . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 'm.created_at<=?'; $params[] = $to . ' 23:59:59'; }
if ($ip !== '') { $where[] = 'm.ip::text LIKE ?'; $params[] = '%' . $ip . '%'; }
if ($q !== '') { $where[] = '(m.user_message ILIKE ? OR m.ai_reply ILIKE ?)'; $params[] = '%' . $q . '%'; $params[] = '%' . $q . '%'; }
$topic = trim((string) ($_GET['topic'] ?? ''));
if ($topic !== '') {
    $kws = chat_topic_defs()[$topic] ?? null;
    if ($kws !== null && $kws !== []) {
        // SQL tarafında da aynı normalizasyon: Türkçe karakterler düz ASCII'ye.
        $normExpr = "translate(lower(btrim(m.user_message)),'çğıiöşüİ','cgiiosui')";
        $ors = [];
        foreach ($kws as $kw) {
            $ors[] = "$normExpr LIKE ?";
            $params[] = '%' . chat_topic_normalize($kw) . '%';
        }
        $where[] = '(' . implode(' OR ', $ors) . ')';
    }
}

// Kalitesiz girdiler (eşikler admin → Kontrol merkezi'nde düzenlenir) varsayılan olarak gizlenir.
$minLen = max(1, (int) platform_setting('chat_min_length', 5));
$requireSpace = (bool) platform_setting('chat_require_space', true);
$qualitySql = 'CHAR_LENGTH(BTRIM(m.user_message)) >= ' . $minLen . ($requireSpace ? " AND POSITION(' ' IN BTRIM(m.user_message)) > 0" : '');
$junkSql = '(CHAR_LENGTH(BTRIM(m.user_message)) < ' . $minLen . ($requireSpace ? " OR POSITION(' ' IN BTRIM(m.user_message)) = 0" : '') . ')';
$junkStmt = db()->prepare('SELECT COUNT(*) FROM public_chat_messages m WHERE ' . implode(' AND ', $where) . ' AND ' . $junkSql);
$junkStmt->execute($params);
$junkCount = (int) $junkStmt->fetchColumn();
if ($quality === 'good') {
    $where[] = $qualitySql;
}
$sqlWhere = implode(' AND ', $where);

// CSV dışa aktarma (aynı filtrelerle).
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ziyaretci-sohbet-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Zaman', 'IP', 'Soru', 'Konu', 'Yanıt', 'Durum']);
    $qRows = db()->prepare("SELECT m.created_at,m.ip,m.user_message,m.ai_reply,b.action ip_action FROM public_chat_messages m LEFT JOIN blocked_ips b ON b.ip=m.ip WHERE $sqlWhere ORDER BY m.id DESC");
    $qRows->execute($params);
    while ($row = $qRows->fetch()) {
        $jm = mb_strlen(trim((string) $row['user_message'])) < $minLen || ($requireSpace && !str_contains(trim((string) $row['user_message']), ' '));
        fputcsv($out, [
            (string) $row['created_at'],
            (string) ($row['ip'] ?? ''),
            (string) $row['user_message'],
            $jm ? '' : implode(', ', chat_classify((string) $row['user_message'])),
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

// Son 30 günün günlük kırılımı (grafik).
$chartDays = [];
for ($i = 29; $i >= 0; $i--) {
    $chartDays[date('Y-m-d', time() - $i * 86400)] = ['blocks' => 0, 'flags' => 0];
}
$chartRows = db()->query("SELECT created_at::date d, COUNT(*) FILTER (WHERE action='block') blocks, COUNT(*) FILTER (WHERE action='flag') flags FROM blocked_ips WHERE created_at >= CURRENT_DATE - 29 GROUP BY created_at::date")->fetchAll();
foreach ($chartRows as $r) {
    $d = (string) $r['d'];
    if (isset($chartDays[$d])) {
        $chartDays[$d]['blocks'] = (int) $r['blocks'];
        $chartDays[$d]['flags'] = (int) $r['flags'];
    }
}
$chartMax = 1;
foreach ($chartDays as $v) $chartMax = max($chartMax, $v['blocks'] + $v['flags']);

$qs = function (array $extra) use ($from, $to, $ip, $q, $quality, $topic): string {
    $p = ['from' => $from, 'to' => $to, 'ip' => $ip, 'q' => $q, 'quality' => $quality, 'topic' => $topic];
    foreach ($extra as $k => $v) $p[$k] = $v;
    return '?' . http_build_query(array_filter($p, fn($v) => $v !== ''));
};
?>
<?php
require_once __DIR__ . '/layout.php';
admin_layout_start('Ziyaretçi AI Sohbet & Güvenlik', 'ziyaretci-sohbet');
?>

<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:14px;margin-bottom:24px">
    <div class="sui-card" style="padding:16px">
        <div style="font-size:12px;color:var(--sui-muted);font-weight:600">Toplam Sohbet</div>
        <div style="font-size:24px;font-weight:800;margin-top:4px"><?= (int)$totalAll ?></div>
    </div>
    <div class="sui-card" style="padding:16px">
        <div style="font-size:12px;color:var(--sui-primary);font-weight:600">Bugünkü Sohbet</div>
        <div style="font-size:24px;font-weight:800;color:var(--sui-primary);margin-top:4px"><?= (int)$todayCount ?></div>
    </div>
    <div class="sui-card" style="padding:16px">
        <div style="font-size:12px;color:var(--sui-muted);font-weight:600">Filtre Sonucu</div>
        <div style="font-size:24px;font-weight:800;margin-top:4px"><?= (int)$total ?></div>
    </div>
    <div class="sui-card" style="padding:16px">
        <div style="font-size:12px;color:var(--sui-danger);font-weight:600">Engelli IP Sayısı</div>
        <div style="font-size:24px;font-weight:800;color:var(--sui-danger);margin-top:4px"><?= (int)$blockCount ?></div>
    </div>
    <div class="sui-card" style="padding:16px">
        <div style="font-size:12px;color:var(--sui-warning);font-weight:600">Bayraklı IP</div>
        <div style="font-size:24px;font-weight:800;color:var(--sui-warning);margin-top:4px"><?= (int)$flagCount ?></div>
    </div>
</div>

<?php if ($message): ?><div class="sui-alert sui-alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="sui-alert sui-alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Filtreler -->
<div class="sui-card" style="margin-bottom:24px">
    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="sui-input" style="width:auto" title="Başlangıç">
        <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="sui-input" style="width:auto" title="Bitiş">
        <input type="text" name="ip" value="<?= htmlspecialchars($ip) ?>" class="sui-input" style="width:140px" placeholder="IP ara...">
        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" class="sui-input" style="width:200px" placeholder="Soru / yanıt ara...">
        <select name="topic" class="sui-input" style="width:auto">
            <option value="">Tüm Konular</option>
            <?php foreach (array_keys(chat_topic_defs()) as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>" <?= $topic === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="sui-btn sui-btn-primary">Filtrele</button>
        <a href="<?= htmlspecialchars($qs(['quality' => $quality === 'good' ? 'all' : 'good', 'page' => 1])) ?>" class="sui-btn sui-btn-outline sui-btn-sm">
            <?= $quality === 'good' ? "Kalitesizleri Göster ($junkCount)" : 'Kalitesizleri Gizle' ?>
        </a>
        <a href="<?= htmlspecialchars($qs(['export' => 'csv'])) ?>" class="sui-btn sui-btn-success sui-btn-sm">⬇ CSV İndir</a>
    </form>
</div>

<!-- Sohbet Tablosu -->
<div class="sui-card">
    <div class="sui-card-header">
        <h2 class="sui-card-title">💬 Ziyaretçi AI Sohbet Kayıtları (Sayfa <?= $page ?> / <?= $pages ?>)</h2>
    </div>

    <div style="overflow-x:auto">
        <table class="sui-table">
            <thead>
                <tr>
                    <th>Tarih / Saat</th>
                    <th>Ziyaretçi IP & Güvenlik</th>
                    <th>Misafir Sorusu</th>
                    <th>Konu</th>
                    <th>AI Yanıtı</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): 
                    $junkMsg = mb_strlen(trim((string)$r['user_message'])) < $minLen || ($requireSpace && !str_contains(trim((string)$r['user_message']), ' '));
                    $rowTopics = $junkMsg ? [] : chat_classify((string)$r['user_message']);
                ?>
                    <tr>
                        <td style="font-size:12px;color:var(--sui-muted);white-space:nowrap"><?= htmlspecialchars((string)$r['created_at']) ?></td>
                        <td>
                            <code style="font-size:12px"><?= htmlspecialchars((string)($r['ip'] ?? '—')) ?></code>
                            <?php if ($r['ip_action'] === 'block'): ?>
                                <span class="sui-badge sui-badge-danger" style="display:block;margin-top:4px">🚫 Engelli</span>
                            <?php elseif ($r['ip_action'] === 'flag'): ?>
                                <span class="sui-badge sui-badge-warning" style="display:block;margin-top:4px">⚠ Bayraklı</span>
                            <?php endif; ?>
                            
                            <div style="display:flex;gap:4px;margin-top:6px">
                                <?php if ($r['ip_action'] === 'block'): ?>
                                    <form method="post" style="margin:0">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                                        <input type="hidden" name="action" value="unblock">
                                        <input type="hidden" name="ip" value="<?= htmlspecialchars((string)$r['ip']) ?>">
                                        <button class="sui-btn sui-btn-outline sui-btn-sm" style="padding:2px 6px;font-size:10px">Kaldır</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" style="margin:0">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                                        <input type="hidden" name="action" value="block">
                                        <input type="hidden" name="ip" value="<?= htmlspecialchars((string)$r['ip']) ?>">
                                        <button class="sui-btn sui-btn-danger sui-btn-sm" style="padding:2px 6px;font-size:10px">Engelle</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td style="font-size:13px;max-width:240px">
                            <?php if ($junkMsg): ?>
                                <span class="sui-badge sui-badge-warning">Kalitesiz</span>
                            <?php endif; ?>
                            <?= htmlspecialchars((string)$r['user_message']) ?>
                        </td>
                        <td>
                            <?php if ($rowTopics): ?>
                                <?php foreach ($rowTopics as $t): ?>
                                    <span class="sui-badge sui-badge-info" style="margin:2px 2px 2px 0"><?= htmlspecialchars($t) ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span style="color:var(--sui-muted)">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:13px;color:var(--sui-text);max-width:320px;line-height:1.5">
                            <?= htmlspecialchars((string)$r['ai_reply']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="5" style="text-align:center;color:var(--sui-muted);padding:20px">Kayıtlı sohbet bulunamadı.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <div style="display:flex;gap:6px;justify-content:center;margin-top:20px;flex-wrap:wrap">
            <?php for ($i = 1; $i <= min(15, $pages); $i++): ?>
                <a href="<?= htmlspecialchars($qs(['page' => $i])) ?>" class="sui-btn <?= $i === $page ? 'sui-btn-primary' : 'sui-btn-outline' ?> sui-btn-sm">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>

