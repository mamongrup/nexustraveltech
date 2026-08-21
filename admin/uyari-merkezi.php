<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/listing_integrity.php';

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['admin_csrf'], (string)($_POST['csrf'] ?? ''))) {
    $id = (int)($_POST['alert_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($id && in_array($action, ['read', 'dismiss'], true)) {
        db()->prepare('UPDATE admin_alerts SET is_read=true WHERE id=?')->execute([$id]);
        record_audit_event('admin', null, 'alert.' . $action, 'admin_alert', $id);
        $message = 'Uyarı güncellendi.';
    }
}

$alerts = db()->query('SELECT a.*,s.company_name FROM admin_alerts a LEFT JOIN suppliers s ON s.id=a.supplier_id ORDER BY a.is_read,a.created_at DESC LIMIT 200')->fetchAll();
$logs = db()->query('SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 50')->fetchAll();

admin_layout_start('Operasyon Uyarı Merkezi', 'uyari-merkezi');
?>

<?php if ($message): ?>
    <div class="sui-alert sui-alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="sui-card" style="margin-bottom:24px">
    <div class="sui-card-header">
        <h2 class="sui-card-title">🚨 Açık ve Geçmiş Uyarılar</h2>
    </div>
    <?php if (!$alerts): ?>
        <p style="color:var(--sui-muted);padding:10px 0">Şu anda sistemde bekleyen bir operasyonel uyarı bulunmuyor.</p>
    <?php endif; ?>
    <div style="display:grid;gap:12px">
        <?php foreach ($alerts as $a): ?>
            <div style="background:#fff;border:1px solid var(--sui-border);border-left:4px solid <?= !$a['is_read'] ? 'var(--sui-danger)' : 'var(--sui-border)' ?>;border-radius:var(--sui-radius-xs);padding:16px;box-shadow:var(--sui-shadow-sm)">
                <div style="display:flex;justify-content:space-between;align-items:flex-start">
                    <b><?= htmlspecialchars($a['title']) ?></b>
                    <span style="font-size:11px;color:var(--sui-muted)"><?= htmlspecialchars($a['created_at']) ?></span>
                </div>
                <p style="margin:8px 0;font-size:13px;color:var(--sui-text)"><?= nl2br(htmlspecialchars($a['body'])) ?></p>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px">
                    <span style="font-size:12px;color:var(--sui-muted);background:var(--sui-bg);padding:2px 8px;border-radius:4px">
                        <?= htmlspecialchars($a['alert_type']) ?> · <?= htmlspecialchars($a['company_name'] ?: 'Platform') ?>
                    </span>
                    <?php if (!$a['is_read']): ?>
                        <form method="post" style="display:flex;gap:6px;margin:0">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                            <input type="hidden" name="alert_id" value="<?= (int)$a['id'] ?>">
                            <button name="action" value="read" class="sui-btn sui-btn-outline sui-btn-sm">Okundu</button>
                            <button name="action" value="dismiss" class="sui-btn sui-btn-danger sui-btn-sm">Kapat</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="sui-card">
    <div class="sui-card-header">
        <h2 class="sui-card-title">📝 Son Denetim Kayıtları (Audit Log)</h2>
    </div>
    <div style="overflow-x:auto">
        <table class="sui-table">
            <thead>
                <tr>
                    <th>Zaman</th>
                    <th>Eylem</th>
                    <th>Varlık</th>
                    <th>ID</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $l): ?>
                    <tr>
                        <td style="font-size:12px;color:var(--sui-muted)"><?= htmlspecialchars($l['created_at']) ?></td>
                        <td><b><?= htmlspecialchars($l['action']) ?></b></td>
                        <td><?= htmlspecialchars($l['entity_type']) ?></td>
                        <td>#<?= htmlspecialchars((string)$l['entity_id']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>
