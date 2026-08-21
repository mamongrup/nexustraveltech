<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/health.php';
require_admin();

if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));

// Tek satır temizleme handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_orphan'
    && hash_equals($_SESSION['admin_csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    $table = (string) ($_POST['table'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $allowed = ['channel_room_mappings', 'channel_rate_plan_mappings', 'channel_property_mappings', 'ical_connections'];
    if (in_array($table, $allowed, true) && $id > 0) {
        try {
            db()->prepare("DELETE FROM \"$table\" WHERE id=?")->execute([$id]);
            audit_log('orphan.delete_single', $table, $id, ['table' => $table]);
            $_SESSION['orphan_msg'] = "Kayıt #$id ($table) silindi.";
        } catch (Throwable $e) {
            $_SESSION['orphan_err'] = "Silme başarısız: " . $e->getMessage();
        }
    }
    header('Location: /nexustraveltech/admin/orphan-mappings');
    exit;
}

$msg = $_SESSION['orphan_msg'] ?? '';
$err = $_SESSION['orphan_err'] ?? '';
unset($_SESSION['orphan_msg'], $_SESSION['orphan_err']);

$pdo = db();

// Tüm yetim satırları topla
$orphans = [];
// 1) channel_room_mappings
try {
    $rows = $pdo->query("SELECT m.id, m.external_room_id AS code, m.status, m.suggestion_score,
        c.display_name AS channel_name, m.channel_connection_id AS conn_id,
        rt.name AS room_name, rp.name AS plan_name, m.property_id,
        p.name AS property_name
        FROM channel_room_mappings m
        LEFT JOIN room_types rt ON rt.id=m.room_type_id
        LEFT JOIN channel_connections c ON c.id=m.channel_connection_id
        LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id
        LEFT JOIN properties p ON p.id=m.property_id
        WHERE m.room_type_id>0 AND (rt.id IS NULL OR c.id IS NULL OR rt.property_id<>m.property_id
            OR (m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)))
        ORDER BY m.id")->fetchAll();
    foreach ($rows as $r) $orphans[] = array_merge($r, ['table' => 'channel_room_mappings', 'label' => 'Oda eşleştirmesi',
        'issue' => isset($r['room_name']) ? ($r['channel_name'] ? '' : 'kanal #' . $r['conn_id'] . ' (silindi)')
            : 'oda tipi #' . ($r['room_type_id'] ?? '?') . ' (silindi)']);
} catch (Throwable $e) {}

// 2) channel_rate_plan_mappings
try {
    $rows = $pdo->query("SELECT m.id, m.external_rate_plan_id AS code, m.status, m.suggestion_score,
        c.display_name AS channel_name, m.channel_connection_id AS conn_id,
        rp.name AS plan_name, rp.currency, m.property_id, p.name AS property_name
        FROM channel_rate_plan_mappings m
        LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id
        LEFT JOIN channel_connections c ON c.id=m.channel_connection_id
        LEFT JOIN properties p ON p.id=m.property_id
        WHERE (m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)) OR c.id IS NULL
        ORDER BY m.id")->fetchAll();
    foreach ($rows as $r) $orphans[] = array_merge($r, ['table' => 'channel_rate_plan_mappings', 'label' => 'Fiyat planı eşleştirmesi',
        'issue' => isset($r['plan_name']) ? ($r['channel_name'] ? '' : 'kanal #' . $r['conn_id'] . ' (silindi)')
            : 'plan #' . ($r['rate_plan_id'] ?? '?') . ' (silindi)']);
} catch (Throwable $e) {}

// 3) channel_property_mappings
try {
    $rows = $pdo->query("SELECT m.id, m.external_property_id AS code, m.status,
        c.display_name AS channel_name, m.channel_connection_id AS conn_id,
        p.name AS property_name, m.property_id
        FROM channel_property_mappings m
        LEFT JOIN properties p ON p.id=m.property_id
        LEFT JOIN channel_connections c ON c.id=m.channel_connection_id
        WHERE p.id IS NULL OR c.id IS NULL
        ORDER BY m.id")->fetchAll();
    foreach ($rows as $r) $orphans[] = array_merge($r, ['table' => 'channel_property_mappings', 'label' => 'Ürün eşleştirmesi',
        'issue' => isset($r['property_name']) ? ($r['channel_name'] ? '' : 'kanal #' . $r['conn_id'] . ' (silindi)')
            : 'ürün #' . $r['property_id'] . ' (silindi)']);
} catch (Throwable $e) {}

// 4) ical_connections
try {
    $rows = $pdo->query("SELECT c.id, c.label, c.status, c.direction, c.supplier_id, c.created_at,
        su.full_name AS supplier_name,
        (SELECT MAX(l.created_at) FROM ical_sync_logs l WHERE l.ical_connection_id=c.id) AS last_sync_at
        FROM ical_connections c
        LEFT JOIN properties p ON p.id=c.property_id
        LEFT JOIN supplier_users su ON su.supplier_id=c.supplier_id
        WHERE p.id IS NULL ORDER BY c.id")->fetchAll();
    foreach ($rows as $r) $orphans[] = array_merge($r, ['table' => 'ical_connections', 'label' => 'iCal bağlantısı', 'code' => $r['label'],
        'issue' => 'ürün silindi', 'property_name' => '— (silindi)']);
} catch (Throwable $e) {}

// Son 7 gün temizlik geçmişi
$history = [];
try {
    $histQ = $pdo->query("SELECT details, created_at, admin_username FROM admin_audit_logs
        WHERE action IN ('health.repair_orphan_cleanup', 'orphan.delete_single')
        AND created_at >= now() - interval '7 days' ORDER BY created_at DESC");
    $history = $histQ ? $histQ->fetchAll() : [];
} catch (Throwable $e) {}

$tableCounts = [];
foreach ($orphans as $o) {
    $t = $o['table'];
    $tableCounts[$t] = ($tableCounts[$t] ?? 0) + 1;
}
$totalOrphan = count($orphans);
?>
<?php
require_once __DIR__ . '/layout.php';
admin_layout_start('Yetim Eşleştirme Temizliği', 'orphan-mappings');
?>

<?php if ($msg): ?><div class="sui-alert sui-alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="sui-alert sui-alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="sui-card" style="margin-bottom:24px">
    <div class="sui-card-header">
        <div>
            <h2 class="sui-card-title">🧹 Yetim Eşleştirmeler (<?= $totalOrphan ?>)</h2>
            <p style="color:var(--sui-muted);font-size:13px;margin:4px 0 0 0">
                Silinmiş oda tipi, fiyat planı veya kanallara ait geçersiz eşleştirmeleri temizleyin.
            </p>
        </div>
        <div>
            <a href="approve-orphan-cleanup.php" class="sui-btn sui-btn-danger sui-btn-sm">
                <i class="fas fa-trash-alt"></i> Toplu Temizleme Onayı
            </a>
        </div>
    </div>

    <?php if ($totalOrphan === 0): ?>
        <div class="sui-alert sui-alert-success">
            ✓ Harika! Sistemde yetim eşleştirme kaydı bulunmuyor — tüm eşleştirmeler aktif varlıklarla bağlı.
        </div>
    <?php else: ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
            <?php foreach ($tableCounts as $tbl => $cnt): ?>
                <span class="sui-badge <?= $tbl === 'ical_connections' ? 'sui-badge-warning' : 'sui-badge-danger' ?>">
                    <?= htmlspecialchars(str_replace('channel_', '', $tbl)) ?>: <?= $cnt ?>
                </span>
            <?php endforeach; ?>
        </div>

        <div style="overflow-x:auto">
            <table class="sui-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Tür</th>
                        <th>Kod / Etiket</th>
                        <th>Durum</th>
                        <th>Ürün / Kanal</th>
                        <th>Sorun</th>
                        <th>İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orphans as $o): ?>
                        <tr>
                            <td><?= (int) $o['id'] ?></td>
                            <td><?= htmlspecialchars($o['label']) ?></td>
                            <td>
                                <b><?= htmlspecialchars((string) ($o['code'] ?? '')) ?></b>
                                <?php if (!empty($o['suggestion_score'])): ?>
                                    <span class="sui-badge sui-badge-warning">%<?= (int) $o['suggestion_score'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars((string) ($o['status'] ?? '')) ?></td>
                            <td>
                                <?= htmlspecialchars((string) ($o['property_name'] ?? '')) ?>
                                <?php if (!empty($o['channel_name'])): ?> · <span style="color:var(--sui-muted)"><?= htmlspecialchars($o['channel_name']) ?></span><?php endif; ?>
                                <?php if (!empty($o['supplier_name'])): ?> · <span style="color:var(--sui-muted)"><?= htmlspecialchars($o['supplier_name']) ?></span><?php endif; ?>
                            </td>
                            <td style="color:var(--sui-danger);font-size:12px">
                                <?= htmlspecialchars((string) ($o['issue'] ?? '')) ?>
                            </td>
                            <td>
                                <form method="post" style="display:inline;margin:0" onsubmit="return confirm('Bu kaydı silmek istediğinize emin misiniz?')">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                                    <input type="hidden" name="action" value="delete_orphan">
                                    <input type="hidden" name="table" value="<?= htmlspecialchars($o['table']) ?>">
                                    <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                                    <button class="sui-btn sui-btn-danger sui-btn-sm" title="Tek satırı sil">Sil</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($history): ?>
<div class="sui-card">
    <div class="sui-card-header">
        <h2 class="sui-card-title">📜 Son 7 Gün — Temizlik Geçmişi</h2>
    </div>
    <div style="overflow-x:auto">
        <table class="sui-table">
            <thead>
                <tr>
                    <th>Tarih</th>
                    <th>Yönetici</th>
                    <th>Detay</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($history, 0, 20) as $hr): ?>
                    <tr>
                        <td style="white-space:nowrap;font-size:12px;color:var(--sui-muted)"><?= htmlspecialchars(mb_substr((string) $hr['created_at'], 0, 16)) ?></td>
                        <td><b><?= htmlspecialchars((string) ($hr['admin_username'] ?? '')) ?></b></td>
                        <td style="font-size:12px"><?= htmlspecialchars(mb_substr((string) ($hr['details'] ?? ''), 0, 100)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>

