<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/supplier_auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/layout.php';

$supplier_user = require_supplier();
$supplierId = (int)$supplier_user['supplier_id'];
$pdo = db();

if (empty($_SESSION['supplier_csrf'])) {
    $_SESSION['supplier_csrf'] = bin2hex(random_bytes(32));
}

// AJAX ile Hücre Güncelleme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_update'])) {
    header('Content-Type: application/json');
    if (!hash_equals($_SESSION['supplier_csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        echo json_encode(['success' => false, 'error' => 'Geçersiz güvenlik doğrulaması (CSRF)']);
        exit;
    }

    $roomId = (int)($_POST['room_type_id'] ?? 0);
    $planId = (int)($_POST['rate_plan_id'] ?? 0);
    $stayDate = trim((string)($_POST['stay_date'] ?? ''));
    $field = trim((string)($_POST['field'] ?? ''));
    $value = trim((string)($_POST['value'] ?? ''));

    // Yetki kontrolü (Bu oda bu tedarikçiye mi ait?)
    $checkQ = $pdo->prepare("SELECT r.id FROM room_types r JOIN properties p ON p.id = r.property_id WHERE r.id = ? AND p.supplier_id = ?");
    $checkQ->execute([$roomId, $supplierId]);
    if (!$checkQ->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Bu odayı düzenleme yetkiniz yok.']);
        exit;
    }

    if (!in_array($field, ['base_price', 'allotment', 'min_stay', 'stop_sale'], true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $stayDate)) {
        echo json_encode(['success' => false, 'error' => 'Geçersiz parametreler.']);
        exit;
    }

    try {
        $curQ = $pdo->prepare("SELECT * FROM inventory_calendar WHERE room_type_id=? AND rate_plan_id=? AND stay_date=?");
        $curQ->execute([$roomId, $planId, $stayDate]);
        $row = $curQ->fetch();

        $allotment = $row ? (int)$row['allotment'] : 1;
        $price = $row ? (float)$row['base_price'] : 1000.00;
        $minStay = $row ? (int)$row['min_stay'] : 1;
        $stopSale = $row ? (bool)$row['stop_sale'] : false;

        if ($field === 'base_price') $price = max(0, (float)$value);
        if ($field === 'allotment') $allotment = max(0, (int)$value);
        if ($field === 'min_stay') $minStay = max(1, (int)$value);
        if ($field === 'stop_sale') $stopSale = (bool)$value;

        $up = $pdo->prepare("
            INSERT INTO inventory_calendar (room_type_id, rate_plan_id, stay_date, allotment, base_price, min_stay, stop_sale)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (room_type_id, rate_plan_id, stay_date)
            DO UPDATE SET 
                allotment = EXCLUDED.allotment,
                base_price = EXCLUDED.base_price,
                min_stay = EXCLUDED.min_stay,
                stop_sale = EXCLUDED.stop_sale
        ");
        $up->execute([$roomId, $planId, $stayDate, $allotment, $price, $minStay, $stopSale ? 1 : 0]);
        echo json_encode(['success' => true]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Tedarikçinin Tesislerini Çek
$properties = $pdo->prepare("SELECT id, name, property_type FROM properties WHERE supplier_id=? AND status='active' ORDER BY name");
$properties->execute([$supplierId]);
$propList = $properties->fetchAll();
$selectedPropId = (int)($_GET['property_id'] ?? ($propList[0]['id'] ?? 0));

$rooms = [];
$ratePlans = [];
if ($selectedPropId > 0) {
    $rq = $pdo->prepare("SELECT id, name, total_units, capacity_adults FROM room_types WHERE property_id=? AND status='active' ORDER BY id");
    $rq->execute([$selectedPropId]);
    $rooms = $rq->fetchAll();

    $pq = $pdo->prepare("SELECT id, name, currency FROM rate_plans WHERE property_id=? AND status='active' ORDER BY id");
    $pq->execute([$selectedPropId]);
    $ratePlans = $pq->fetchAll();
}

$startDateStr = trim((string)($_GET['start_date'] ?? date('Y-m-d')));
$daysCount = (int)($_GET['days'] ?? 14);
if (!in_array($daysCount, [7, 14, 30, 60], true)) $daysCount = 14;
$startTimestamp = strtotime($startDateStr) ?: time();

$dates = [];
for ($i = 0; $i < $daysCount; $i++) {
    $t = strtotime("+$i days", $startTimestamp);
    $dates[] = [
        'date' => date('Y-m-d', $t),
        'day_name' => ['Paz', 'Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cts'][(int)date('w', $t)],
        'day_num' => date('d/m', $t),
        'is_weekend' => in_array((int)date('w', $t), [0, 6], true),
    ];
}

$endDateStr = end($dates)['date'];
$inventoryGrid = [];
if ($rooms && $ratePlans) {
    $roomIds = array_column($rooms, 'id');
    $planIds = array_column($ratePlans, 'id');
    $inQ = $pdo->prepare("
        SELECT room_type_id, rate_plan_id, stay_date, allotment, sold, base_price, min_stay, stop_sale
        FROM inventory_calendar
        WHERE room_type_id = ANY(?) AND rate_plan_id = ANY(?) AND stay_date >= ? AND stay_date <= ?
    ");
    $inQ->execute(['{' . implode(',', $roomIds) . '}', '{' . implode(',', $planIds) . '}', $startDateStr, $endDateStr]);
    while ($r = $inQ->fetch()) {
        $key = $r['room_type_id'] . '_' . $r['rate_plan_id'] . '_' . $r['stay_date'];
        $inventoryGrid[$key] = $r;
    }
}

supply_start('Fiyat & Müsaitlik Matrisi', 'inventory');
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<style>
.mat-wrap { background: #fff; border-radius: 14px; border: 1px solid #e2e8f0; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom: 24px; }
.mat-table { width: 100%; border-collapse: collapse; font-size: 13px; font-family: 'DM Sans', sans-serif; }
.mat-table th, .mat-table td { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: center; }
.mat-table th { background: #f8fafc; font-weight: 700; color: #475569; position: sticky; top: 0; z-index: 2; }
.mat-table th.weekend, .mat-table td.weekend { background-color: #faf5ff; }
.mat-room-hdr { background: #f1f5f9; text-align: left !important; font-weight: 800; color: #1e293b; padding: 10px 14px !important; }
.mat-inp { width: 70px; padding: 4px; border: 1px solid transparent; border-radius: 6px; text-align: center; font-size: 12px; font-weight: 600; background: transparent; transition: all 0.2s; }
.mat-inp:hover { border-color: #cbd5e1; background: #fff; }
.mat-inp:focus { border-color: #7928ca; background: #fff; outline: none; box-shadow: 0 0 0 2px rgba(121,40,202,0.15); }
.mat-stop-btn { border: none; background: #fee2e2; color: #dc2626; padding: 3px 8px; border-radius: 6px; cursor: pointer; font-size: 11px; font-weight: 700; }
.mat-open-btn { border: none; background: #dcfce7; color: #16a34a; padding: 3px 8px; border-radius: 6px; cursor: pointer; font-size: 11px; font-weight: 700; }
.saved-flash { animation: flashGreen 1s ease; }
@keyframes flashGreen { 0% { background: #86efac; } 100% { background: transparent; } }
</style>

<div class="mat-wrap">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px">
        <div>
            <h2 style="font-size:18px;font-weight:800;margin:0;color:#0f172a">
                <i class="fa-solid fa-calendar-days" style="color:#7928ca;margin-right:6px"></i> HotelRunner Hızında Fiyat & Müsaitlik Matrisi
            </h2>
            <p style="font-size:12px;color:#64748b;margin:4px 0 0 0">
                Hücrelere tıklayıp doğrudan fiyat ve kontenjan değiştirebilirsiniz. Anlık otomatik kaydedilir.
            </p>
        </div>

        <form method="get" style="display:flex;gap:10px;align-items:center;margin:0;flex-wrap:wrap">
            <select name="property_id" class="mat-inp" style="width:auto;min-width:180px;border:1px solid #cbd5e1;background:#fff;padding:6px 10px" onchange="this.form.submit()">
                <?php foreach ($propList as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= $selectedPropId === (int)$p['id'] ? 'selected' : '' ?>>
                        🏢 <?= htmlspecialchars($p['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input type="date" name="start_date" value="<?= htmlspecialchars($startDateStr) ?>" class="mat-inp" style="width:auto;border:1px solid #cbd5e1;background:#fff;padding:6px 10px" onchange="this.form.submit()">
            
            <select name="days" class="mat-inp" style="width:auto;border:1px solid #cbd5e1;background:#fff;padding:6px 10px" onchange="this.form.submit()">
                <option value="7" <?= $daysCount === 7 ? 'selected' : '' ?>>7 Günlük</option>
                <option value="14" <?= $daysCount === 14 ? 'selected' : '' ?>>14 Günlük</option>
                <option value="30" <?= $daysCount === 30 ? 'selected' : '' ?>>30 Günlük</option>
            </select>
        </form>
    </div>

    <?php if (!$rooms): ?>
        <div style="padding:40px;text-align:center;color:#64748b">
            <p>Seçili tesise ait aktif oda tipi bulunamadı.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto;max-height:700px">
            <table class="mat-table">
                <thead>
                    <tr>
                        <th style="min-width:180px;text-align:left">Oda & Satır</th>
                        <?php foreach ($dates as $d): ?>
                            <th class="<?= $d['is_weekend'] ? 'weekend' : '' ?>" style="min-width:85px">
                                <div><?= $d['day_name'] ?></div>
                                <div style="font-size:11px;font-weight:400;color:#64748b"><?= $d['day_num'] ?></div>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rooms as $rm): ?>
                        <?php foreach ($ratePlans as $pl): ?>
                            <!-- Oda Başlık Satırı -->
                            <tr>
                                <td colspan="<?= count($dates) + 1 ?>" class="mat-room-hdr">
                                    <i class="fa-solid fa-bed" style="color:#7928ca;margin-right:6px"></i>
                                    <?= htmlspecialchars($rm['name']) ?> — <span style="font-size:12px;font-weight:600;color:#64748b"><?= htmlspecialchars($pl['name']) ?> (<?= htmlspecialchars($pl['currency']) ?>)</span>
                                </td>
                            </tr>

                            <!-- 1. Fiyat Satırı -->
                            <tr>
                                <td style="text-align:left;font-weight:700;color:#334155;background:#f8fafc">
                                    <i class="fa-solid fa-tag" style="color:#2563eb;width:16px"></i> Fiyat (₺)
                                </td>
                                <?php foreach ($dates as $d): 
                                    $k = $rm['id'] . '_' . $pl['id'] . '_' . $d['date'];
                                    $data = $inventoryGrid[$k] ?? null;
                                    $val = $data ? (float)$data['base_price'] : 1500;
                                ?>
                                    <td class="<?= $d['is_weekend'] ? 'weekend' : '' ?>">
                                        <input type="number" step="0.01" value="<?= $val ?>" class="mat-inp"
                                               onchange="updateCell(<?= $rm['id'] ?>, <?= $pl['id'] ?>, '<?= $d['date'] ?>', 'base_price', this.value, this)">
                                    </td>
                                <?php endforeach; ?>
                            </tr>

                            <!-- 2. Kontenjan Satırı -->
                            <tr>
                                <td style="text-align:left;font-weight:700;color:#334155;background:#f8fafc">
                                    <i class="fa-solid fa-door-open" style="color:#16a34a;width:16px"></i> Müsaitlik
                                </td>
                                <?php foreach ($dates as $d): 
                                    $k = $rm['id'] . '_' . $pl['id'] . '_' . $d['date'];
                                    $data = $inventoryGrid[$k] ?? null;
                                    $val = $data ? (int)$data['allotment'] : (int)$rm['total_units'];
                                ?>
                                    <td class="<?= $d['is_weekend'] ? 'weekend' : '' ?>">
                                        <input type="number" min="0" value="<?= $val ?>" class="mat-inp" style="font-weight:700;color:#16a34a"
                                               onchange="updateCell(<?= $rm['id'] ?>, <?= $pl['id'] ?>, '<?= $d['date'] ?>', 'allotment', this.value, this)">
                                    </td>
                                <?php endforeach; ?>
                            </tr>

                            <!-- 3. Min Konaklama Satırı -->
                            <tr>
                                <td style="text-align:left;font-size:11px;font-weight:700;color:#64748b;background:#f8fafc">
                                    <i class="fa-solid fa-moon" style="color:#d97706;width:16px"></i> Min Gece
                                </td>
                                <?php foreach ($dates as $d): 
                                    $k = $rm['id'] . '_' . $pl['id'] . '_' . $d['date'];
                                    $data = $inventoryGrid[$k] ?? null;
                                    $val = $data ? (int)$data['min_stay'] : 1;
                                ?>
                                    <td class="<?= $d['is_weekend'] ? 'weekend' : '' ?>">
                                        <input type="number" min="1" value="<?= $val ?>" class="mat-inp" style="color:#d97706"
                                               onchange="updateCell(<?= $rm['id'] ?>, <?= $pl['id'] ?>, '<?= $d['date'] ?>', 'min_stay', this.value, this)">
                                    </td>
                                <?php endforeach; ?>
                            </tr>

                            <!-- 4. Stop Sale (Satış Durumu) -->
                            <tr>
                                <td style="text-align:left;font-size:11px;font-weight:700;color:#64748b;background:#f8fafc">
                                    <i class="fa-solid fa-ban" style="color:#dc2626;width:16px"></i> Satış Durumu
                                </td>
                                <?php foreach ($dates as $d): 
                                    $k = $rm['id'] . '_' . $pl['id'] . '_' . $d['date'];
                                    $data = $inventoryGrid[$k] ?? null;
                                    $isStop = $data ? (bool)$data['stop_sale'] : false;
                                ?>
                                    <td class="<?= $d['is_weekend'] ? 'weekend' : '' ?>">
                                        <button type="button" class="<?= $isStop ? 'mat-stop-btn' : 'mat-open-btn' ?>"
                                                onclick="toggleStop(<?= $rm['id'] ?>, <?= $pl['id'] ?>, '<?= $d['date'] ?>', <?= $isStop ? 0 : 1 ?>, this)">
                                            <?= $isStop ? 'KAPALI' : 'AÇIK' ?>
                                        </button>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
const CSRF_TOKEN = '<?= htmlspecialchars($_SESSION['supplier_csrf']) ?>';

function updateCell(roomId, planId, stayDate, field, value, el) {
    const fd = new FormData();
    fd.append('ajax_update', '1');
    fd.append('csrf', CSRF_TOKEN);
    fd.append('room_type_id', roomId);
    fd.append('rate_plan_id', planId);
    fd.append('stay_date', stayDate);
    fd.append('field', field);
    fd.append('value', value);

    fetch('fiyat-matrisi.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            el.classList.add('saved-flash');
            setTimeout(() => el.classList.remove('saved-flash'), 1000);
        } else {
            alert('Hata: ' + (res.error || 'Kaydedilemedi'));
        }
    })
    .catch(() => alert('Bağlantı hatası oluştu.'));
}

function toggleStop(roomId, planId, stayDate, newStatus, btn) {
    const fd = new FormData();
    fd.append('ajax_update', '1');
    fd.append('csrf', CSRF_TOKEN);
    fd.append('room_type_id', roomId);
    fd.append('rate_plan_id', planId);
    fd.append('stay_date', stayDate);
    fd.append('field', 'stop_sale');
    fd.append('value', newStatus);

    fetch('fiyat-matrisi.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            if (newStatus === 1) {
                btn.className = 'mat-stop-btn';
                btn.innerText = 'KAPALI';
                btn.onclick = () => toggleStop(roomId, planId, stayDate, 0, btn);
            } else {
                btn.className = 'mat-open-btn';
                btn.innerText = 'AÇIK';
                btn.onclick = () => toggleStop(roomId, planId, stayDate, 1, btn);
            }
        } else {
            alert('Hata: ' + (res.error || 'İşlem başarısız'));
        }
    })
    .catch(() => alert('Bağlantı hatası oluştu.'));
}
</script>

<?php supply_end(); ?>
