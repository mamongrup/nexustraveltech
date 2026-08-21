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

// AJAX / POST Tekil veya Toplu Güncelleme
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf'] ?? '');
    if (!hash_equals($_SESSION['admin_csrf'] ?? '', $csrf)) {
        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Güvenlik doğrulaması geçersiz.']);
            exit;
        }
        $err = 'Güvenlik doğrulaması geçersiz.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        
        // 1) Tekil Hücre Kaydı (AJAX)
        if ($action === 'save_cell') {
            header('Content-Type: application/json');
            $roomId = (int)($_POST['room_type_id'] ?? 0);
            $planId = (int)($_POST['rate_plan_id'] ?? 0);
            $dateStr = trim((string)($_POST['stay_date'] ?? ''));
            $price = (float)str_replace(',', '.', (string)($_POST['price'] ?? 0));
            $allotment = (int)($_POST['allotment'] ?? 0);
            $minStay = max(1, (int)($_POST['min_stay'] ?? 1));
            $stopSale = !empty($_POST['stop_sale']) ? 1 : 0;

            if ($roomId > 0 && $planId > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO inventory_calendar (room_type_id, rate_plan_id, stay_date, allotment, base_price, min_stay, stop_sale)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                        ON CONFLICT (room_type_id, rate_plan_id, stay_date)
                        DO UPDATE SET 
                            base_price = EXCLUDED.base_price,
                            allotment = EXCLUDED.allotment,
                            min_stay = EXCLUDED.min_stay,
                            stop_sale = EXCLUDED.stop_sale
                    ");
                    $stmt->execute([$roomId, $planId, $dateStr, $allotment, $price, $minStay, $stopSale === 1]);
                    audit_log('rate_matrix.cell_update', 'inventory_calendar', $roomId, [
                        'date' => $dateStr, 'price' => $price, 'allotment' => $allotment, 'stop_sale' => $stopSale
                    ]);
                    echo json_encode(['ok' => true, 'message' => 'Kayıt güncellendi.']);
                } catch (Throwable $e) {
                    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
                }
            } else {
                echo json_encode(['ok' => false, 'error' => 'Geçersiz parametreler.']);
            }
            exit;
        }

        // 2) Toplu Güncelleme (Bulk Update)
        if ($action === 'bulk_update') {
            $roomId = (int)($_POST['bulk_room_type_id'] ?? 0);
            $planId = (int)($_POST['bulk_rate_plan_id'] ?? 0);
            $startDate = trim((string)($_POST['start_date'] ?? ''));
            $endDate = trim((string)($_POST['end_date'] ?? ''));
            $daysOfWeek = (array)($_POST['days'] ?? [0,1,2,3,4,5,6]); // 0=Pazar..6=Cts
            $opType = (string)($_POST['op_type'] ?? 'set_price');
            $val = (float)str_replace(',', '.', (string)($_POST['op_value'] ?? 0));
            $bulkMinStay = max(1, (int)($_POST['bulk_min_stay'] ?? 1));
            $bulkStopSale = (string)($_POST['bulk_stop_sale'] ?? '');

            if ($roomId > 0 && $planId > 0 && $startDate && $endDate && strtotime($startDate) <= strtotime($endDate)) {
                try {
                    $curr = strtotime($startDate);
                    $end = strtotime($endDate);
                    $updatedCount = 0;

                    $pdo->beginTransaction();
                    while ($curr <= $end) {
                        $w = (int)date('w', $curr);
                        if (in_array($w, array_map('intval', $daysOfWeek), true)) {
                            $dStr = date('Y-m-d', $curr);
                            
                            // Mevcut satırı oku
                            $exStmt = $pdo->prepare("SELECT * FROM inventory_calendar WHERE room_type_id=? AND rate_plan_id=? AND stay_date=?");
                            $exStmt->execute([$roomId, $planId, $dStr]);
                            $ex = $exStmt->fetch();

                            $curPrice = $ex ? (float)$ex['base_price'] : 0.0;
                            $curAllotment = $ex ? (int)$ex['allotment'] : 5;
                            $curMinStay = $ex ? (int)$ex['min_stay'] : 1;
                            $curStopSale = $ex ? (bool)$ex['stop_sale'] : false;

                            // Fiyat Operasyonu
                            if ($opType === 'set_price') {
                                $curPrice = max(0, $val);
                            } elseif ($opType === 'pct_increase') {
                                $curPrice = max(0, $curPrice * (1 + ($val / 100)));
                            } elseif ($opType === 'pct_decrease') {
                                $curPrice = max(0, $curPrice * (1 - ($val / 100)));
                            } elseif ($opType === 'set_allotment') {
                                $curAllotment = max(0, (int)$val);
                            }

                            if ($bulkMinStay > 0 && !empty($_POST['apply_min_stay'])) {
                                $curMinStay = $bulkMinStay;
                            }
                            if ($bulkStopSale === 'open') {
                                $curStopSale = false;
                            } elseif ($bulkStopSale === 'closed') {
                                $curStopSale = true;
                            }

                            $ins = $pdo->prepare("
                                INSERT INTO inventory_calendar (room_type_id, rate_plan_id, stay_date, allotment, base_price, min_stay, stop_sale)
                                VALUES (?, ?, ?, ?, ?, ?, ?)
                                ON CONFLICT (room_type_id, rate_plan_id, stay_date)
                                DO UPDATE SET 
                                    base_price = EXCLUDED.base_price,
                                    allotment = EXCLUDED.allotment,
                                    min_stay = EXCLUDED.min_stay,
                                    stop_sale = EXCLUDED.stop_sale
                            ");
                            $ins->execute([$roomId, $planId, $dStr, $curAllotment, $curPrice, $curMinStay, $curStopSale]);
                            $updatedCount++;
                        }
                        $curr = strtotime('+1 day', $curr);
                    }
                    $pdo->commit();
                    audit_log('rate_matrix.bulk_update', 'inventory_calendar', $roomId, [
                        'start' => $startDate, 'end' => $endDate, 'updated_days' => $updatedCount
                    ]);
                    $msg = "Başarılı! Toplam {$updatedCount} gün için fiyat ve müsaitlik güncellendi.";
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $err = "Toplu güncelleme hatası: " . $e->getMessage();
                }
            } else {
                $err = "Lütfen geçerli bir tarih aralığı ve oda tipi seçin.";
            }
        }
    }
}

// Tesisleri Çek
$properties = $pdo->query("SELECT id, name, property_type, city FROM properties WHERE status='active' ORDER BY name")->fetchAll();
$selectedPropId = (int)($_GET['property_id'] ?? ($properties[0]['id'] ?? 0));

// Seçili tesisin oda tipleri & planları
$rooms = [];
$ratePlans = [];
if ($selectedPropId > 0) {
    $rq = $pdo->prepare("SELECT * FROM room_types WHERE property_id=? AND status='active' ORDER BY id");
    $rq->execute([$selectedPropId]);
    $rooms = $rq->fetchAll();

    $pq = $pdo->prepare("SELECT * FROM rate_plans WHERE property_id=? AND status='active' ORDER BY id");
    $pq->execute([$selectedPropId]);
    $ratePlans = $pq->fetchAll();
}

$daysCount = max(7, min(90, (int)($_GET['days'] ?? 30)));
$startDate = !empty($_GET['start']) ? trim((string)$_GET['start']) : date('Y-m-d');
$startTs = strtotime($startDate) ?: time();

// Takvim Günleri Listesi
$dates = [];
for ($i = 0; $i < $daysCount; $i++) {
    $ts = strtotime("+{$i} days", $startTs);
    $dates[] = [
        'date' => date('Y-m-d', $ts),
        'day' => date('d', $ts),
        'month' => date('M', $ts),
        'day_name' => ['Paz', 'Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cts'][(int)date('w', $ts)],
        'is_weekend' => in_array((int)date('w', $ts), [0, 6], true),
    ];
}

// Matris Verisini Çek
$matrixData = [];
if ($rooms && $ratePlans) {
    $firstDate = $dates[0]['date'];
    $lastDate = $dates[count($dates) - 1]['date'];
    
    $invQ = $pdo->prepare("
        SELECT * FROM inventory_calendar 
        WHERE stay_date >= ? AND stay_date <= ?
    ");
    $invQ->execute([$firstDate, $lastDate]);
    $allInv = $invQ->fetchAll();
    
    foreach ($allInv as $inv) {
        $k = $inv['room_type_id'] . '_' . $inv['rate_plan_id'] . '_' . $inv['stay_date'];
        $matrixData[$k] = $inv;
    }
}

require_once __DIR__ . '/layout.php';
admin_layout_start('Dinamik Fiyat & Müsaitlik Matrisi', 'fiyat-matrisi');
?>

<style>
.matrix-container {
    background: #fff;
    border-radius: 16px;
    border: 1px solid var(--sui-border);
    box-shadow: var(--sui-shadow-sm);
    overflow: hidden;
    margin-bottom: 24px;
}
.matrix-toolbar {
    padding: 16px 20px;
    border-bottom: 1px solid var(--sui-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 14px;
    background: #fafbfc;
}
.matrix-scroll-wrap {
    overflow-x: auto;
    max-height: calc(100vh - 280px);
}
.matrix-table {
    border-collapse: separate;
    border-spacing: 0;
    width: 100%;
    font-size: 13px;
}
.matrix-table th, .matrix-table td {
    padding: 6px 8px;
    border-right: 1px solid #edf2f7;
    border-bottom: 1px solid #edf2f7;
    text-align: center;
    white-space: nowrap;
}
.matrix-sticky-col {
    position: sticky;
    left: 0;
    background: #fff;
    z-index: 10;
    text-align: left !important;
    min-width: 220px;
    max-width: 220px;
    border-right: 2px solid #cbd5e1 !important;
}
.matrix-header-th {
    background: #f8f9fa;
    color: #4a5568;
    font-weight: 700;
    position: sticky;
    top: 0;
    z-index: 20;
}
.matrix-header-th.weekend {
    background: #fef2f2;
    color: #e11d48;
}
.matrix-row-title {
    background: #f1f5f9;
    font-weight: 700;
    color: #1e293b;
    padding: 10px 14px !important;
}
.matrix-input {
    width: 68px;
    padding: 4px 6px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    text-align: center;
    font-size: 12px;
    font-weight: 600;
    background: #fff;
    transition: all 0.2s;
}
.matrix-input:focus {
    border-color: #7928ca;
    outline: none;
    box-shadow: 0 0 0 2px rgba(121,40,202,0.2);
}
.stop-pill {
    cursor: pointer;
    font-size: 10px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 6px;
    display: inline-block;
    user-select: none;
    transition: transform 0.1s;
}
.stop-pill.open { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
.stop-pill.closed { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
.stop-pill:active { transform: scale(0.95); }
.modal-backdrop {
    position: fixed; inset: 0; background: rgba(15,23,42,0.6); backdrop-filter: blur(4px);
    display: none; align-items: center; justify-content: center; z-index: 9999;
}
.modal-box {
    background: #fff; border-radius: 16px; width: 100%; max-width: 540px;
    box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2); overflow: hidden;
}
</style>

<?php if ($msg): ?><div class="sui-alert sui-alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="sui-alert sui-alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- Matris Ana Kartı -->
<div class="matrix-container">
    <div class="matrix-toolbar">
        <form method="get" style="display:flex;align-items:center;gap:12px;margin:0;flex-wrap:wrap">
            <div>
                <select name="property_id" class="sui-input" style="font-weight:600;min-width:200px" onchange="this.form.submit()">
                    <?php foreach ($properties as $pr): ?>
                        <option value="<?= (int)$pr['id'] ?>" <?= $selectedPropId === (int)$pr['id'] ? 'selected' : '' ?>>
                            🏢 <?= htmlspecialchars($pr['name']) ?> (<?= htmlspecialchars($pr['city'] ?? 'TR') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <input type="date" name="start" class="sui-input" value="<?= htmlspecialchars($startDate) ?>" onchange="this.form.submit()">
            </div>
            <div>
                <select name="days" class="sui-input" onchange="this.form.submit()">
                    <option value="14" <?= $daysCount === 14 ? 'selected' : '' ?>>14 Günlük</option>
                    <option value="30" <?= $daysCount === 30 ? 'selected' : '' ?>>30 Günlük</option>
                    <option value="60" <?= $daysCount === 60 ? 'selected' : '' ?>>60 Günlük</option>
                    <option value="90" <?= $daysCount === 90 ? 'selected' : '' ?>>90 Günlük</option>
                </select>
            </div>
        </form>

        <div style="display:flex;gap:10px">
            <button type="button" class="sui-btn sui-btn-primary" onclick="openBulkModal()">
                <i class="fa-solid fa-wand-magic-sparkles"></i> Toplu Güncelleme Sihirbazı
            </button>
            <a href="ai-gelir-yonetimi?property_id=<?= $selectedPropId ?>" class="sui-btn sui-btn-outline">
                <i class="fa-solid fa-brain"></i> AI Fiyat Önerileri
            </a>
        </div>
    </div>

    <?php if (!$rooms): ?>
        <div style="padding:40px;text-align:center;color:var(--sui-muted)">
            <p>Seçili tesise ait aktif oda tipi bulunamadı. Lütfen önce oda tipi ve fiyat planı ekleyin.</p>
        </div>
    <?php else: ?>
        <div class="matrix-scroll-wrap">
            <table class="matrix-table">
                <thead>
                    <tr>
                        <th class="matrix-sticky-col matrix-header-th" style="z-index:30">Oda & Plan / Tarihler</th>
                        <?php foreach ($dates as $d): ?>
                            <th class="matrix-header-th <?= $d['is_weekend'] ? 'weekend' : '' ?>">
                                <div style="font-size:10px;text-transform:uppercase;color:<?= $d['is_weekend'] ? '#e11d48' : '#64748b' ?>"><?= $d['day_name'] ?></div>
                                <div style="font-size:14px"><?= $d['day'] ?></div>
                                <div style="font-size:9px;color:#94a3b8"><?= $d['month'] ?></div>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rooms as $rm): ?>
                        <!-- Oda Başlık Satırı -->
                        <tr>
                            <td colspan="<?= count($dates) + 1 ?>" class="matrix-row-title">
                                <i class="fa-solid fa-door-open" style="color:var(--sui-primary);margin-right:6px"></i>
                                <?= htmlspecialchars($rm['name']) ?> 
                                <span style="font-weight:normal;font-size:11px;color:#64748b;margin-left:6px">
                                    (Kapasite: <?= (int)$rm['capacity_adults'] ?> Yetişkin · Toplam: <?= (int)$rm['total_units'] ?> Birim)
                                </span>
                            </td>
                        </tr>

                        <?php foreach ($ratePlans as $rp): ?>
                            <!-- Fiyat Satırı -->
                            <tr>
                                <td class="matrix-sticky-col" style="padding-left:24px">
                                    <div style="font-weight:600;color:#334155"><?= htmlspecialchars($rp['name']) ?></div>
                                    <div style="font-size:10px;color:#8392ab">Fiyat (<?= htmlspecialchars($rp['currency']) ?>)</div>
                                </td>
                                <?php foreach ($dates as $d): 
                                    $k = $rm['id'] . '_' . $rp['id'] . '_' . $d['date'];
                                    $cell = $matrixData[$k] ?? null;
                                    $price = $cell ? (float)$cell['base_price'] : 0.0;
                                ?>
                                    <td class="<?= $d['is_weekend'] ? 'weekend' : '' ?>">
                                        <input type="number" step="0.01" class="matrix-input price-in" 
                                               data-room="<?= (int)$rm['id'] ?>" 
                                               data-plan="<?= (int)$rp['id'] ?>" 
                                               data-date="<?= $d['date'] ?>"
                                               value="<?= $price > 0 ? $price : '' ?>" 
                                               placeholder="0.00"
                                               onblur="autoSaveCell(this, 'price')">
                                    </td>
                                <?php endforeach; ?>
                            </tr>

                            <!-- Kontenjan Satırı -->
                            <tr>
                                <td class="matrix-sticky-col" style="padding-left:24px">
                                    <div style="font-size:11px;color:#64748b">Müsait Kontenjan</div>
                                </td>
                                <?php foreach ($dates as $d): 
                                    $k = $rm['id'] . '_' . $rp['id'] . '_' . $d['date'];
                                    $cell = $matrixData[$k] ?? null;
                                    $allot = $cell ? (int)$cell['allotment'] : (int)$rm['total_units'];
                                ?>
                                    <td>
                                        <input type="number" class="matrix-input allot-in" 
                                               data-room="<?= (int)$rm['id'] ?>" 
                                               data-plan="<?= (int)$rp['id'] ?>" 
                                               data-date="<?= $d['date'] ?>"
                                               value="<?= $allot ?>" 
                                               onblur="autoSaveCell(this, 'allotment')">
                                    </td>
                                <?php endforeach; ?>
                            </tr>

                            <!-- Satış Durumu (Stop-Sell) Satırı -->
                            <tr style="border-bottom:2px solid #e2e8f0">
                                <td class="matrix-sticky-col" style="padding-left:24px">
                                    <div style="font-size:11px;color:#64748b">Satış Durumu</div>
                                </td>
                                <?php foreach ($dates as $d): 
                                    $k = $rm['id'] . '_' . $rp['id'] . '_' . $d['date'];
                                    $cell = $matrixData[$k] ?? null;
                                    $isClosed = $cell ? (bool)$cell['stop_sale'] : false;
                                ?>
                                    <td>
                                        <span class="stop-pill <?= $isClosed ? 'closed' : 'open' ?>"
                                              data-room="<?= (int)$rm['id'] ?>" 
                                              data-plan="<?= (int)$rp['id'] ?>" 
                                              data-date="<?= $d['date'] ?>"
                                              data-closed="<?= $isClosed ? '1' : '0' ?>"
                                              onclick="toggleStopSale(this)">
                                            <?= $isClosed ? 'KAPALI' : 'AÇIK' ?>
                                        </span>
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

<!-- Toplu Güncelleme Modalı -->
<div id="bulkModal" class="modal-backdrop">
    <div class="modal-box">
        <div style="padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center">
            <h3 style="margin:0;font-size:16px;color:#1e293b"><i class="fa-solid fa-wand-magic-sparkles" style="color:var(--sui-primary)"></i> Toplu Fiyat & Müsaitlik Güncelle</h3>
            <button type="button" style="background:none;border:none;font-size:18px;cursor:pointer" onclick="closeBulkModal()">×</button>
        </div>
        <form method="post" style="padding:20px;margin:0">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
            <input type="hidden" name="action" value="bulk_update">

            <div style="margin-bottom:14px">
                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Hedef Oda ve Fiyat Planı</label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                    <select name="bulk_room_type_id" class="sui-input" required>
                        <?php foreach ($rooms as $r): ?>
                            <option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="bulk_rate_plan_id" class="sui-input" required>
                        <?php foreach ($ratePlans as $p): ?>
                            <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['currency']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="margin-bottom:14px">
                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Tarih Aralığı</label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                    <input type="date" name="start_date" class="sui-input" value="<?= htmlspecialchars($startDate) ?>" required>
                    <input type="date" name="end_date" class="sui-input" value="<?= htmlspecialchars(date('Y-m-d', strtotime('+30 days', $startTs))) ?>" required>
                </div>
            </div>

            <div style="margin-bottom:14px">
                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Uygulanacak Günler</label>
                <div style="display:flex;gap:10px;flex-wrap:wrap">
                    <?php foreach (['0'=>'Paz','1'=>'Pzt','2'=>'Sal','3'=>'Çar','4'=>'Per','5'=>'Cum','6'=>'Cts'] as $dNum => $dLbl): ?>
                        <label style="font-size:12px;display:flex;align-items:center;gap:4px">
                            <input type="checkbox" name="days[]" value="<?= $dNum ?>" checked> <?= $dLbl ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="margin-bottom:14px">
                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Fiyat / Kontenjan İşlemi</label>
                <div style="display:grid;grid-template-columns:140px 1fr;gap:10px">
                    <select name="op_type" class="sui-input">
                        <option value="set_price">Sabit Fiyat Ata</option>
                        <option value="pct_increase">% Fiyat Artır (+)</option>
                        <option value="pct_decrease">% Fiyat İndir (-)</option>
                        <option value="set_allotment">Kontenjan Belirle</option>
                    </select>
                    <input type="number" step="0.01" name="op_value" class="sui-input" placeholder="Tutar veya %" required>
                </div>
            </div>

            <div style="margin-bottom:18px">
                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Satış Kapatma (Stop-Sell)</label>
                <select name="bulk_stop_sale" class="sui-input">
                    <option value="">Değiştirme (Aynı Kalsın)</option>
                    <option value="open">Satışa Aç (Open)</option>
                    <option value="closed">Satışa Kapat (Stop-Sale)</option>
                </select>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px">
                <button type="button" class="sui-btn sui-btn-outline" onclick="closeBulkModal()">İptal</button>
                <button type="submit" class="sui-btn sui-btn-primary"><i class="fa-solid fa-check"></i> Toplu Güncellemeyi Uygula</button>
            </div>
        </form>
    </div>
</div>

<script>
function openBulkModal() {
    document.getElementById('bulkModal').style.display = 'flex';
}
function closeBulkModal() {
    document.getElementById('bulkModal').style.display = 'none';
}

function autoSaveCell(inputEl, field) {
    var room = inputEl.getAttribute('data-room');
    var plan = inputEl.getAttribute('data-plan');
    var date = inputEl.getAttribute('data-date');
    var val = inputEl.value;

    // Kardeş hücre değerlerini bul
    var row = inputEl.closest('tbody');
    var priceEl = row.querySelector('.price-in[data-room="'+room+'"][data-plan="'+plan+'"][data-date="'+date+'"]');
    var allotEl = row.querySelector('.allot-in[data-room="'+room+'"][data-plan="'+plan+'"][data-date="'+date+'"]');
    var stopEl = row.querySelector('.stop-pill[data-room="'+room+'"][data-plan="'+plan+'"][data-date="'+date+'"]');

    var pVal = priceEl ? priceEl.value : 0;
    var aVal = allotEl ? allotEl.value : 0;
    var sVal = stopEl ? stopEl.getAttribute('data-closed') : 0;

    var fd = new FormData();
    fd.append('ajax', '1');
    fd.append('csrf', '<?= htmlspecialchars($_SESSION['admin_csrf']) ?>');
    fd.append('action', 'save_cell');
    fd.append('room_type_id', room);
    fd.append('rate_plan_id', plan);
    fd.append('stay_date', date);
    fd.append('price', pVal);
    fd.append('allotment', aVal);
    fd.append('stop_sale', sVal);

    inputEl.style.borderColor = '#3b82f6';
    fetch('fiyat-matrisi.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.ok) {
            inputEl.style.borderColor = '#22c55e';
            setTimeout(function() { inputEl.style.borderColor = '#e2e8f0'; }, 1000);
        } else {
            inputEl.style.borderColor = '#ef4444';
            alert('Hata: ' + (d.error || 'Kaydedilemedi.'));
        }
    }).catch(function() {
        inputEl.style.borderColor = '#ef4444';
    });
}

function toggleStopSale(pillEl) {
    var isClosed = pillEl.getAttribute('data-closed') === '1';
    var nextClosed = !isClosed;
    pillEl.setAttribute('data-closed', nextClosed ? '1' : '0');
    pillEl.className = 'stop-pill ' + (nextClosed ? 'closed' : 'open');
    pillEl.innerText = nextClosed ? 'KAPALI' : 'AÇIK';

    autoSaveCell(pillEl, 'stop_sale');
}
</script>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>
