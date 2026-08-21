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

require_once __DIR__ . '/layout.php';
admin_layout_start('Hata ve İstisna İzleme', 'hata-izleme');
?>

<?php if ($message): ?><div class="sui-alert sui-alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="sui-alert sui-alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="sui-card" style="margin-bottom:24px">
    <div class="sui-card-header">
        <div>
            <h2 class="sui-card-title">🐛 Uygulama Hata Günlükleri (<?= (int)$totalNew ?> Yeni)</h2>
            <div style="display:flex;gap:12px;margin-top:6px;font-size:12px;color:var(--sui-muted);flex-wrap:wrap">
                <?php foreach ($counts as $c): ?>
                    <span><?= htmlspecialchars($c['level']) ?>/<?= htmlspecialchars($c['status']) ?>: <b><?= (int)$c['c'] ?></b></span>
                <?php endforeach; ?>
            </div>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
            <a href="hata-izleme" class="sui-btn <?= $level === '' && $status === '' ? 'sui-btn-primary' : 'sui-btn-outline' ?> sui-btn-sm">Tümü</a>
            <a href="?level=error" class="sui-btn <?= $level === 'error' ? 'sui-btn-primary' : 'sui-btn-outline' ?> sui-btn-sm">Hatalar</a>
            <a href="?level=warning" class="sui-btn <?= $level === 'warning' ? 'sui-btn-primary' : 'sui-btn-outline' ?> sui-btn-sm">Uyarılar</a>
            <a href="?level=critical" class="sui-btn <?= $level === 'critical' ? 'sui-btn-primary' : 'sui-btn-outline' ?> sui-btn-sm">Kritik</a>
            <a href="?status=new" class="sui-btn <?= $status === 'new' ? 'sui-btn-primary' : 'sui-btn-outline' ?> sui-btn-sm">Sadece Yeni</a>
        </div>
    </div>

    <div style="overflow-x:auto">
        <table class="sui-table">
            <thead>
                <tr>
                    <th>Seviye</th>
                    <th>Mesaj / Bağlam</th>
                    <th>Sayfa URI</th>
                    <th>IP</th>
                    <th>Zaman</th>
                    <th>İşlem</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): 
                    $lvl = (string)$r['level'];
                    $lvlBadge = in_array($lvl, ['error', 'critical'], true) ? 'sui-badge-danger' : ($lvl === 'warning' ? 'sui-badge-warning' : 'sui-badge-info');
                    $ctx = json_decode((string)$r['context'], true);
                ?>
                    <tr>
                        <td>
                            <span class="sui-badge <?= $lvlBadge ?>">
                                <?= htmlspecialchars($lvl) ?>
                            </span>
                        </td>
                        <td>
                            <b><?= htmlspecialchars($r['message']) ?></b>
                            <?php if ($ctx): ?>
                                <pre style="background:var(--sui-bg);border:1px solid var(--sui-border);border-radius:4px;padding:6px;font-size:11px;margin:4px 0 0 0;max-height:120px;overflow-y:auto"><?= htmlspecialchars(json_encode($ctx, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)) ?></pre>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px;font-family:monospace"><?= htmlspecialchars((string)$r['request_uri']) ?></td>
                        <td style="font-size:12px;color:var(--sui-muted)"><?= htmlspecialchars((string)$r['ip']) ?></td>
                        <td style="font-size:12px;color:var(--sui-muted);white-space:nowrap"><?= htmlspecialchars((string)$r['created_at']) ?></td>
                        <td>
                            <?php if ($r['status'] === 'new'): ?>
                                <form method="post" style="margin:0">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                                    <input type="hidden" name="action" value="review">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button class="sui-btn sui-btn-outline sui-btn-sm">İncelendi</button>
                                </form>
                            <?php else: ?>
                                <span class="sui-badge" style="background:#eee;color:#777">İncelendi</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="6" style="text-align:center;color:var(--sui-muted);padding:20px">Kayıtlı hata bulunamadı.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<form method="post">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
    <input type="hidden" name="action" value="purge">
    <button class="sui-btn sui-btn-danger">30 günden eski incelenmiş kayıtları temizle</button>
</form>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>
