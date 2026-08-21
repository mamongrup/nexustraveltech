<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/../config/trash-helpers.php';

require_admin();
if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$pdo = db();

// Tabloların varlığını kesinleştir
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_audit_logs (
            id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            action VARCHAR(100) NOT NULL,
            entity_type VARCHAR(100),
            entity_id BIGINT,
            admin_username VARCHAR(100) DEFAULT 'admin',
            details JSONB DEFAULT '{}'::jsonb,
            ip_address VARCHAR(45),
            created_at TIMESTAMPTZ NOT NULL DEFAULT now()
        );

        CREATE TABLE IF NOT EXISTS feature_delete_backups (
            id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            feature_id BIGINT NOT NULL,
            code VARCHAR(100),
            label VARCHAR(255),
            group_label VARCHAR(100),
            sort_order INTEGER,
            is_active BOOLEAN,
            affected_properties JSONB DEFAULT '[]'::jsonb,
            created_at TIMESTAMPTZ NOT NULL DEFAULT now()
        );
    ");
} catch (Throwable $e) {}

$action = trim((string) ($_GET['action'] ?? ''));
$dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date_from'] ?? '')) ? (string) $_GET['date_from'] : '';
$dateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date_to'] ?? '')) ? (string) $_GET['date_to'] : '';
$adminName = trim((string) ($_GET['admin'] ?? ''));
$today = date('Y-m-d');
$weekAgo = date('Y-m-d', strtotime('-6 days'));
$monthStart = date('Y-m-01');

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

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$limitSql = $sql . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage);

$total = 0;
$rows = [];
$actions = [];
$actionCounts = [];
$admins = [];

try {
    $countQ = $pdo->prepare(preg_replace('/^SELECT \*/', 'SELECT COUNT(*)', $sql));
    $countQ->execute($params);
    $total = (int) $countQ->fetchColumn();

    $q = $pdo->prepare($limitSql);
    $q->execute($params);
    $rows = $q->fetchAll();

    $actQ = $pdo->query('SELECT action, COUNT(*) as c FROM admin_audit_logs GROUP BY action ORDER BY c DESC LIMIT 30');
    if ($actQ) {
        $actions = $actQ->fetchAll();
        foreach ($actions as $_ac) {
            $actionCounts[$_ac['action']] = (int) $_ac['c'];
        }
    }

    $admQ = $pdo->query("SELECT DISTINCT admin_username FROM admin_audit_logs WHERE admin_username IS NOT NULL AND admin_username <> '' ORDER BY admin_username LIMIT 100");
    if ($admQ) {
        $admins = $admQ->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Throwable $e) {
    // Hata durumunda boş liste ile devam et
}

$totalPages = max(1, (int) ceil($total / $perPage));

$describeAction = function (array $r): string {
    $details = json_decode((string) ($r['details'] ?? ''), true);
    if (!is_array($details)) $details = [];
    $label = (string) ($details['label'] ?? '');
    $n = (int) ($details['count'] ?? $details['affected_count'] ?? 0);
    switch ((string) $r['action']) {
        case 'feature.add': return 'Özellik eklendi' . ($label !== '' ? ': ' . $label : '');
        case 'feature.delete': return 'Özellik silindi' . ($n > 0 ? ' — ' . $n . ' ilan' : '');
        case 'feature.restore': return 'Özellik geri yüklendi' . ($n > 0 ? ' — ' . $n . ' ilana eklendi' : '');
        case 'feature.toggle': return 'Özellik durumu değişti' . ($label !== '' ? ': ' . $label : '') . (isset($details['is_active']) ? ' (' . ($details['is_active'] ? 'aktif' : 'pasif') . ')' : '');
        case 'feature.move': return 'Özellik sıralaması değişti' . ($label !== '' ? ': ' . $label : '');
        case 'feature.bulk_delete': return 'Toplu silme' . ($n > 0 ? ' — ' . $n . ' özellik' : '');
        case 'feature.bulk_activate': return 'Toplu aktifleştirme' . ($n > 0 ? ' — ' . $n . ' özellik' : '');
        case 'feature.bulk_deactivate': return 'Toplu pasifleştirme' . ($n > 0 ? ' — ' . $n . ' özellik' : '');
        case 'scheduler.toggle': return 'Zamanlayıcı durumu değişti';
        case 'scheduler.edit': return 'Zamanlayıcı düzenlendi';
        case 'scheduler.run': return 'Zamanlayıcı elle çalıştırıldı' . (isset($details['status']) ? ' (' . (string) $details['status'] . ')' : '');
        case 'rate_matrix.cell_update': return 'Fiyat/Müsaitlik matrisi güncellendi';
        case 'rate_matrix.bulk_update': return 'Toplu fiyat güncellemesi uygulandı';
        case 'ai_revenue.apply': return 'AI Fiyat önerisi takvime uygulandı';
        default: return (string) $r['action'] . ($label !== '' ? ': ' . $label : '');
    }
};

require_once __DIR__ . '/layout.php';
admin_layout_start('Yönetim Denetim & Güvenlik Kayıtları', 'denetim-kayitlari');
?>

<!-- Filtreleme Kartı -->
<div class="sui-card" style="margin-bottom:20px">
    <form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin:0">
        <div>
            <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">İşlem Türü</label>
            <input type="text" name="action" value="<?= htmlspecialchars($action) ?>" placeholder="Örn: rate_matrix, feature" class="sui-input" style="min-width:180px">
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Başlangıç Tarihi</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="sui-input">
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Bitiş Tarihi</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="sui-input">
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Yönetici</label>
            <input type="text" name="admin" value="<?= htmlspecialchars($adminName) ?>" placeholder="Kullanıcı adı" class="sui-input" style="min-width:140px">
        </div>
        <div style="margin-top:18px;display:flex;gap:8px">
            <button type="submit" class="sui-btn sui-btn-primary"><i class="fa-solid fa-filter"></i> Filtrele</button>
            <a href="denetim-kayitlari" class="sui-btn sui-btn-outline">Temizle</a>
        </div>
    </form>
</div>

<!-- Denetim Kayıtları Tablosu -->
<div class="sui-card">
    <div class="sui-card-header">
        <div>
            <h2 class="sui-card-title"><i class="fa-solid fa-shield-halved" style="color:var(--sui-primary);margin-right:8px"></i> Sistem Denetim & Hareket Kayıtları</h2>
            <p style="color:var(--sui-muted);font-size:13px;margin:4px 0 0 0">
                Yöneticiler ve arka plan servisleri tarafından gerçekleştirilen tüm işlemler izlenir. Toplam <b><?= number_format($total) ?></b> kayıt.
            </p>
        </div>
    </div>

    <?php if (!$rows): ?>
        <div style="padding:40px;text-align:center;color:var(--sui-muted)">
            <i class="fa-regular fa-folder-open" style="font-size:36px;margin-bottom:10px"></i>
            <p>Seçilen filtrelere uygun denetim kaydı bulunamadı.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto">
            <table class="sui-table">
                <thead>
                    <tr>
                        <th># ID</th>
                        <th>İşlem / Eylem</th>
                        <th>Açıklama & Detay</th>
                        <th>Yönetici / Aktör</th>
                        <th>IP Adresi</th>
                        <th style="text-align:right">Tarih</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><span style="font-weight:700;color:var(--sui-muted)">#<?= (int)$r['id'] ?></span></td>
                            <td>
                                <span class="sui-badge sui-badge-primary">
                                    <?= htmlspecialchars((string)$r['action']) ?>
                                </span>
                            </td>
                            <td>
                                <b><?= htmlspecialchars($describeAction($r)) ?></b>
                                <?php if (!empty($r['details'])): ?>
                                    <div style="font-size:11px;color:var(--sui-muted);font-family:monospace;margin-top:2px">
                                        <?= htmlspecialchars(mb_substr((string)$r['details'], 0, 100)) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <i class="fa-solid fa-user-shield" style="color:var(--sui-primary);margin-right:4px"></i>
                                <?= htmlspecialchars((string)($r['admin_username'] ?? 'Sistem')) ?>
                            </td>
                            <td style="font-family:monospace;font-size:12px;color:var(--sui-muted)">
                                <?= htmlspecialchars((string)($r['ip_address'] ?? '127.0.0.1')) ?>
                            </td>
                            <td style="text-align:right;font-size:12px;color:var(--sui-muted)">
                                <?= htmlspecialchars((string)$r['created_at']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Sayfalama -->
        <?php if ($totalPages > 1): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:20px;padding-top:14px;border-top:1px solid var(--sui-border)">
                <div style="font-size:13px;color:var(--sui-muted)">
                    Sayfa <?= $page ?> / <?= $totalPages ?> (Toplam <?= $total ?> Kayıt)
                </div>
                <div style="display:flex;gap:6px">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>" class="sui-btn sui-btn-outline sui-btn-sm">← Önceki</a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>" class="sui-btn sui-btn-outline sui-btn-sm">Sonraki →</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>
