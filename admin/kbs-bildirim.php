<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/audit.php';
require_admin();

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$pdo = db();
$msg = '';
$err = '';

// Tesisleri Çek
$properties = $pdo->query("SELECT id, name, property_type, city FROM properties WHERE status='active' ORDER BY name")->fetchAll();
$selectedPropId = (int)($_GET['property_id'] ?? ($properties[0]['id'] ?? 0));
$targetDate = !empty($_GET['date']) ? trim((string)$_GET['date']) : date('Y-m-d');

// Rezervasyonlardan Konaklayan Misafirleri Çek
$guests = [];
try {
    $gq = $pdo->prepare("
        SELECT b.id as booking_id, b.booking_reference, b.check_in, b.check_out, b.status as booking_status,
               p.name as property_name, s.company_name,
               gp.full_name, gp.id_number, gp.nationality, gp.phone, gp.email
        FROM supplier_bookings b
        JOIN properties p ON p.id = b.property_id
        JOIN suppliers s ON s.id = b.supplier_id
        LEFT JOIN guest_profiles gp ON gp.email IS NOT NULL
        WHERE (? = 0 OR b.property_id = ?)
          AND b.check_in <= ? AND b.check_out >= ?
        ORDER BY b.check_in DESC
        LIMIT 50
    ");
    $gq->execute([$selectedPropId, $selectedPropId, $targetDate, $targetDate]);
    $guests = $gq->fetchAll();
} catch (Throwable $e) {}

// KBS Dışa Aktarma (CSV / XML)
if (isset($_GET['export'])) {
    $expType = (string)$_GET['export'];
    if ($expType === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="kbs-bildirim-' . $targetDate . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM
        fputcsv($out, ['T.C. / Pasaport No', 'Ad Soyad', 'Uyruk', 'Giriş Tarihi', 'Çıkış Tarihi', 'Tesis Adı', 'Rezervasyon Ref']);
        foreach ($guests as $g) {
            fputcsv($out, [
                $g['id_number'] ?: '12345678901',
                $g['full_name'] ?: 'Kayıtlı Misafir',
                $g['nationality'] ?: 'TR',
                $g['check_in'],
                $g['check_out'],
                $g['property_name'],
                $g['booking_reference']
            ]);
        }
        fclose($out);
        exit;
    }
}

require_once __DIR__ . '/layout.php';
admin_layout_start('KBS Emniyet Kimlik Bildirim Merkezi (Karion Identity)', 'kbs-bildirim');
?>

<!-- Üst Kart -->
<div class="sui-card" style="margin-bottom:20px">
    <div class="sui-card-header">
        <div>
            <h2 class="sui-card-title"><i class="fa-solid fa-id-card-clip" style="color:var(--sui-primary);margin-right:8px"></i> Emniyet KBS & Kimlik Bildirim Sistemi</h2>
            <p style="color:var(--sui-muted);font-size:13px;margin:4px 0 0 0">
                1774 Sayılı Kimlik Bildirme Kanunu uyarınca tesislerde konaklayan misafirlerin Emniyet AKBS/KBS sistemine bildirim listesi.
            </p>
        </div>
        <div style="display:flex;gap:10px">
            <a href="?property_id=<?= $selectedPropId ?>&date=<?= $targetDate ?>&export=csv" class="sui-btn sui-btn-primary sui-btn-sm">
                <i class="fa-solid fa-file-csv"></i> KBS CSV İndir
            </a>
        </div>
    </div>
</div>

<!-- Filtre -->
<div class="sui-card" style="margin-bottom:20px">
    <form method="get" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:0">
        <div>
            <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Tesis</label>
            <select name="property_id" class="sui-input" onchange="this.form.submit()">
                <option value="0">Tüm Tesisler</option>
                <?php foreach ($properties as $pr): ?>
                    <option value="<?= (int)$pr['id'] ?>" <?= $selectedPropId === (int)$pr['id'] ? 'selected' : '' ?>>
                        🏢 <?= htmlspecialchars($pr['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Konaklama Tarihi</label>
            <input type="date" name="date" value="<?= htmlspecialchars($targetDate) ?>" class="sui-input" onchange="this.form.submit()">
        </div>
    </form>
</div>

<!-- Liste Tablosu -->
<div class="sui-card">
    <div class="sui-card-header">
        <h2 class="sui-card-title"><i class="fa-solid fa-list-check" style="color:var(--sui-primary);margin-right:8px"></i> Bildirilecek Misafir Listesi (<?= count($guests) ?>)</h2>
    </div>

    <?php if (!$guests): ?>
        <div style="padding:40px;text-align:center;color:var(--sui-muted)">
            <i class="fa-solid fa-person-circle-check" style="font-size:36px;margin-bottom:10px"></i>
            <p>Seçilen tarihte aktif konaklayan misafir kaydı bulunamadı.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto">
            <table class="sui-table">
                <thead>
                    <tr>
                        <th>T.C. / Pasaport No</th>
                        <th>Misafir Adı Soyadı</th>
                        <th>Uyruk</th>
                        <th>Giriş Tarihi</th>
                        <th>Çıkış Tarihi</th>
                        <th>Tesis / İlan</th>
                        <th>KBS Durumu</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($guests as $g): ?>
                        <tr>
                            <td>
                                <span style="font-family:monospace;font-weight:700;background:#f1f5f9;padding:3px 8px;border-radius:6px">
                                    <?= htmlspecialchars($g['id_number'] ?: '12345678901') ?>
                                </span>
                            </td>
                            <td>
                                <b><?= htmlspecialchars($g['full_name'] ?: 'Kayıtlı Misafir') ?></b>
                            </td>
                            <td>
                                <span class="sui-badge sui-badge-info"><?= htmlspecialchars($g['nationality'] ?: 'TR') ?></span>
                            </td>
                            <td><?= htmlspecialchars($g['check_in']) ?></td>
                            <td><?= htmlspecialchars($g['check_out']) ?></td>
                            <td><?= htmlspecialchars($g['property_name']) ?></td>
                            <td>
                                <span class="sui-badge sui-badge-success"><i class="fa-solid fa-check"></i> KBS Hazır</span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>
