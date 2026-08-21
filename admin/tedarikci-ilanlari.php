<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/listing_integrity.php';

$supplierId = (int) ($_GET['supplier_id'] ?? 0);
$typeLabel = fn($t) => ['hotel' => 'Otel', 'villa' => 'Villa', 'yacht' => 'Yat'][$t] ?? $t;
$statusLabel = ['draft' => 'Taslak', 'active' => 'Yayında', 'paused' => 'Duraklatıldı'];

$pdo = db();
$supplier = null;
$props = [];
$allSuppliers = [];

if ($supplierId > 0) {
    $q = $pdo->prepare('SELECT s.*, (SELECT COUNT(*) FROM supplier_users u WHERE u.supplier_id=s.id) AS user_count FROM suppliers s WHERE s.id=?');
    $q->execute([$supplierId]);
    $supplier = $q->fetch();
    if ($supplier) {
        $pq = $pdo->prepare("SELECT p.id, p.name, p.property_type, p.status, p.city, p.created_at,
                (SELECT COUNT(*) FROM room_types r WHERE r.property_id=p.id) AS room_count,
                (SELECT COUNT(*) FROM rate_plans rp WHERE rp.property_id=p.id) AS plan_count,
                (SELECT COUNT(*) FROM property_media pm WHERE pm.property_id=p.id) AS media_count
             FROM properties p WHERE p.supplier_id=? ORDER BY p.property_type, p.name");
        $pq->execute([$supplierId]);
        $props = $pq->fetchAll();
    }
} else {
    // Tüm tedarikçileri listele
    $allSuppliers = $pdo->query("SELECT s.*, 
        (SELECT COUNT(*) FROM properties p WHERE p.supplier_id=s.id) AS prop_count,
        (SELECT COUNT(*) FROM supplier_users u WHERE u.supplier_id=s.id) AS user_count
        FROM suppliers s ORDER BY s.id DESC")->fetchAll();
}

$pageTitle = $supplier ? ($supplier['company_name'] . ' — İlanlar') : 'Tedarikçi & Tesis Listesi';
admin_layout_start($pageTitle, 'tedarikci-ilanlari');
?>
<?php if ($supplierId > 0 && $supplier): ?>
    <div style="margin-bottom:16px">
        <a href="tedarikci-ilanlari" class="sui-btn sui-btn-outline sui-btn-sm">← Tüm Tedarikçilere Dön</a>
    </div>

    <div class="sui-card" style="margin-bottom:24px">
        <div class="sui-card-header">
            <h2 class="sui-card-title">🏢 <?= htmlspecialchars((string) $supplier['company_name']) ?></h2>
            <span class="sui-badge <?= $supplier['status'] === 'active' ? 'sui-badge-success' : 'sui-badge-danger' ?>">
                <?= htmlspecialchars($statusLabel[$supplier['status']] ?? (string) $supplier['status']) ?>
            </span>
        </div>
        <p style="color:var(--sui-muted);margin:0">
            Tedarikçi ID: #<?= (int) $supplier['id'] ?> · Toplam Tesis: <b><?= count($props) ?></b> · Kullanıcı Sayısı: <b><?= (int) $supplier['user_count'] ?></b>
        </p>
    </div>

    <div class="sui-card">
        <div class="sui-card-header">
            <h2 class="sui-card-title">🏨 Tesisler & İlanlar</h2>
        </div>
        <?php if (!$props): ?>
            <p style="color:var(--sui-muted);padding:10px 0">Bu tedarikçiye ait kayıtlı ilan/tesis bulunmuyor.</p>
        <?php else: ?>
            <div style="overflow-x:auto">
                <table class="sui-table">
                    <thead>
                        <tr>
                            <th>Görsel</th>
                            <th>Tesis Adı</th>
                            <th>Tür</th>
                            <th>Durum</th>
                            <th>Şehir</th>
                            <th>Oda Tipi</th>
                            <th>Fiyat Planı</th>
                            <th>Hazırlık Skoru</th>
                            <th>Kayıt Tarihi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($props as $p): ?>
                            <?php 
                            $thumbs = $pdo->prepare('SELECT file_path FROM property_media WHERE property_id=? ORDER BY is_cover DESC, sort_order, id LIMIT 3');
                            $thumbs->execute([(int) $p['id']]);
                            $thumbRows = $thumbs->fetchAll();
                            $rd = listing_readiness($p);
                            ?>
                            <tr>
                                <td>
                                    <?php if ($thumbRows): ?>
                                        <div style="display:flex;gap:4px">
                                            <?php foreach ($thumbRows as $tr): ?>
                                                <img src="<?= htmlspecialchars(ltrim((string) $tr['file_path'], '/')) ?>" style="width:36px;height:36px;object-fit:cover;border-radius:6px;border:1px solid var(--sui-border)" loading="lazy">
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:var(--sui-muted);font-size:12px">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><b><?= htmlspecialchars((string) $p['name']) ?></b></td>
                                <td><?= $typeLabel($p['property_type']) ?></td>
                                <td>
                                    <span class="sui-badge <?= $p['status'] === 'active' ? 'sui-badge-success' : 'sui-badge-warning' ?>">
                                        <?= htmlspecialchars($statusLabel[$p['status']] ?? (string) $p['status']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars((string) ($p['city'] ?? '—')) ?></td>
                                <td><?= (int) $p['room_count'] ?></td>
                                <td><?= (int) $p['plan_count'] ?></td>
                                <td>
                                    <span class="sui-badge <?= $rd['score'] >= 80 ? 'sui-badge-success' : ($rd['score'] >= 50 ? 'sui-badge-warning' : 'sui-badge-danger') ?>">
                                        %<?= (int) $rd['score'] ?>
                                    </span>
                                </td>
                                <td style="font-size:12px;color:var(--sui-muted)"><?= htmlspecialchars(mb_substr((string) $p['created_at'], 0, 10)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

<?php else: ?>
    <!-- TÜM TEDARİKÇİLER LİSTESİ -->
    <div class="sui-card">
        <div class="sui-card-header">
            <h2 class="sui-card-title">🏨 Tedarikçiler & Tesis Portföyü (<?= count($allSuppliers) ?>)</h2>
        </div>
        <div style="overflow-x:auto">
            <table class="sui-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Firma / Tedarikçi Ünvanı</th>
                        <th>Durum</th>
                        <th>Kayıtlı Tesis / İlan</th>
                        <th>Kullanıcı Sayısı</th>
                        <th>Kayıt Tarihi</th>
                        <th>İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allSuppliers as $s): ?>
                        <tr>
                            <td>#<?= (int) $s['id'] ?></td>
                            <td>
                                <b><?= htmlspecialchars((string) $s['company_name']) ?></b>
                                <div style="font-size:11px;color:var(--sui-muted)"><?= htmlspecialchars((string) ($s['tax_number'] ?? 'Vergi no belirtilmedi')) ?></div>
                            </td>
                            <td>
                                <span class="sui-badge <?= $s['status'] === 'active' ? 'sui-badge-success' : 'sui-badge-warning' ?>">
                                    <?= htmlspecialchars($statusLabel[$s['status']] ?? (string) $s['status']) ?>
                                </span>
                            </td>
                            <td>
                                <b><?= (int) $s['prop_count'] ?> Tesis</b>
                            </td>
                            <td><?= (int) $s['user_count'] ?> Kullanıcı</td>
                            <td style="font-size:12px;color:var(--sui-muted)"><?= htmlspecialchars(mb_substr((string) ($s['created_at'] ?? ''), 0, 10)) ?></td>
                            <td>
                                <a href="tedarikci-ilanlari?supplier_id=<?= (int) $s['id'] ?>" class="sui-btn sui-btn-primary sui-btn-sm">
                                    İlanları İncele →
                                </a>
                            </td>
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

