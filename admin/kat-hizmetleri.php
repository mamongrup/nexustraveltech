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

// Tablo Güvencesi
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS room_housekeeping (
            room_type_id BIGINT PRIMARY KEY REFERENCES room_types(id) ON DELETE CASCADE,
            status VARCHAR(30) NOT NULL DEFAULT 'clean',
            housekeeper_name VARCHAR(100),
            priority VARCHAR(20) DEFAULT 'normal',
            notes TEXT,
            last_cleaned_at TIMESTAMPTZ,
            updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
        );
    ");
} catch (Throwable $e) {}

// POST: Durum Güncelleme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['admin_csrf'], (string)($_POST['csrf'] ?? ''))) {
    $roomId = (int)($_POST['room_type_id'] ?? 0);
    $status = (string)($_POST['status'] ?? 'clean');
    $hkName = trim((string)($_POST['housekeeper_name'] ?? ''));
    $priority = (string)($_POST['priority'] ?? 'normal');
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($roomId > 0) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO room_housekeeping (room_type_id, status, housekeeper_name, priority, notes, last_cleaned_at, updated_at)
                VALUES (?, ?, ?, ?, ?, now(), now())
                ON CONFLICT (room_type_id)
                DO UPDATE SET 
                    status = EXCLUDED.status,
                    housekeeper_name = EXCLUDED.housekeeper_name,
                    priority = EXCLUDED.priority,
                    notes = EXCLUDED.notes,
                    updated_at = now(),
                    last_cleaned_at = CASE WHEN EXCLUDED.status='clean' THEN now() ELSE room_housekeeping.last_cleaned_at END
            ");
            $stmt->execute([$roomId, $status, $hkName, $priority, $notes]);
            audit_log('housekeeping.update', 'room_housekeeping', $roomId, ['status' => $status]);
            $msg = "Oda temizlik durumu güncellendi.";
        } catch (Throwable $e) {
            $err = "Güncelleme hatası: " . $e->getMessage();
        }
    }
}

// Tesisleri Çek
$properties = $pdo->query("SELECT id, name, property_type, city FROM properties WHERE status='active' ORDER BY name")->fetchAll();
$selectedPropId = (int)($_GET['property_id'] ?? ($properties[0]['id'] ?? 0));

// Odaları ve Housekeeping Durumunu Çek
$rooms = [];
if ($selectedPropId > 0) {
    $rq = $pdo->prepare("
        SELECT r.*, hk.status as hk_status, hk.housekeeper_name, hk.priority, hk.notes, hk.last_cleaned_at, hk.updated_at as hk_updated
        FROM room_types r
        LEFT JOIN room_housekeeping hk ON hk.room_type_id = r.id
        WHERE r.property_id=? AND r.status='active'
        ORDER BY r.name
    ");
    $rq->execute([$selectedPropId]);
    $rooms = $rq->fetchAll();
}

$statusMap = [
    'clean' => ['label' => 'TEMİZ / HAZIR', 'cls' => 'sui-badge-success', 'bg' => '#dcfce7', 'border' => '#86efac', 'icon' => 'fa-solid fa-sparkles'],
    'dirty' => ['label' => 'KİRLİ / BEKLİYOR', 'cls' => 'sui-badge-danger', 'bg' => '#fee2e2', 'border' => '#fca5a5', 'icon' => 'fa-solid fa-broom'],
    'cleaning' => ['label' => 'TEMİZLENİYOR', 'cls' => 'sui-badge-warning', 'bg' => '#fef3c7', 'border' => '#fde047', 'icon' => 'fa-solid fa-soap'],
    'inspected' => ['label' => 'KONTROL EDİLDİ', 'cls' => 'sui-badge-primary', 'bg' => '#f3e8ff', 'border' => '#d8b4fe', 'icon' => 'fa-solid fa-clipboard-check'],
    'maintenance' => ['label' => 'ARIZALI / BAKIMDA', 'cls' => 'sui-badge-info', 'bg' => '#f1f5f9', 'border' => '#cbd5e1', 'icon' => 'fa-solid fa-wrench'],
];

require_once __DIR__ . '/layout.php';
admin_layout_start('Kat Hizmetleri & Oda Temizlik Paneli (Housekeeping)', 'kat-hizmetleri');
?>

<?php if ($msg): ?><div class="sui-alert sui-alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="sui-alert sui-alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- Üst Başlık & Tesis Seçici -->
<div class="sui-card" style="margin-bottom:20px">
    <div class="sui-card-header">
        <div>
            <h2 class="sui-card-title"><i class="fa-solid fa-broom" style="color:var(--sui-primary);margin-right:8px"></i> Canlı Kat Hizmetleri & Housekeeping Durumu</h2>
            <p style="color:var(--sui-muted);font-size:13px;margin:4px 0 0 0">
                Karion Housekeeping standardında: Kat görevlileri ve resepsiyon arasında anlık oda temizlik, arıza ve kontrol durumunu yönetin.
            </p>
        </div>
        <div>
            <form method="get" style="margin:0">
                <select name="property_id" class="sui-input" style="font-weight:600;min-width:220px" onchange="this.form.submit()">
                    <?php foreach ($properties as $pr): ?>
                        <option value="<?= (int)$pr['id'] ?>" <?= $selectedPropId === (int)$pr['id'] ? 'selected' : '' ?>>
                            🏢 <?= htmlspecialchars($pr['name']) ?> (<?= htmlspecialchars($pr['city'] ?? 'TR') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>
</div>

<!-- Oda Durum Kartları Grid -->
<div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(300px, 1fr));gap:18px;margin-bottom:24px">
    <?php foreach ($rooms as $rm): 
        $stKey = $rm['hk_status'] ?: 'clean';
        $st = $statusMap[$stKey] ?? $statusMap['clean'];
    ?>
        <div class="sui-card" style="border-left:5px solid <?= $st['border'] ?>;display:flex;flex-direction:column;justify-content:space-between">
            <div>
                <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px">
                    <h3 style="font-size:16px;font-weight:700;margin:0"><?= htmlspecialchars($rm['name']) ?></h3>
                    <span class="sui-badge <?= $st['cls'] ?>"><?= $st['label'] ?></span>
                </div>
                <div style="font-size:12px;color:var(--sui-muted);margin-bottom:10px">
                    Kapasite: <?= (int)$rm['capacity_adults'] ?> Kişi · Toplam: <?= (int)$rm['total_units'] ?> Birim
                </div>

                <?php if (!empty($rm['housekeeper_name'])): ?>
                    <div style="font-size:12px;color:#334155;margin-bottom:6px">
                        <b>Görevli:</b> <?= htmlspecialchars($rm['housekeeper_name']) ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($rm['notes'])): ?>
                    <div style="font-size:12px;color:#64748b;background:#f8fafc;padding:8px;border-radius:8px;margin-bottom:10px">
                        <?= htmlspecialchars($rm['notes']) ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Hızlı Durum Değiştirme Formu -->
            <form method="post" style="margin:0;margin-top:12px;border-top:1px solid #f1f5f9;padding-top:10px">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                <input type="hidden" name="room_type_id" value="<?= (int)$rm['id'] ?>">
                
                <div style="display:grid;grid-template-columns:1fr auto;gap:8px">
                    <select name="status" class="sui-input" style="font-size:12px;font-weight:600">
                        <option value="clean" <?= $stKey === 'clean' ? 'selected' : '' ?>>✓ Temiz (Hazır)</option>
                        <option value="dirty" <?= $stKey === 'dirty' ? 'selected' : '' ?>>✕ Kirli (Bekliyor)</option>
                        <option value="cleaning" <?= $stKey === 'cleaning' ? 'selected' : '' ?>>⏳ Temizleniyor</option>
                        <option value="inspected" <?= $stKey === 'inspected' ? 'selected' : '' ?>>★ Kontrol Edildi</option>
                        <option value="maintenance" <?= $stKey === 'maintenance' ? 'selected' : '' ?>>⚠ Arızalı / Bakım</option>
                    </select>
                    <button type="submit" class="sui-btn sui-btn-primary sui-btn-sm">Güncelle</button>
                </div>
            </form>
        </div>
    <?php endforeach; ?>
</div>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>
