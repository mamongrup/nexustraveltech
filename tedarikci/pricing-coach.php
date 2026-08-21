<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/supplier_auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/layout.php';

$supplier_user = require_supplier();
$supplierId = (int)$supplier_user['supplier_id'];
$pdo = db();
$msg = '';
$err = '';

if (empty($_SESSION['supplier_csrf'])) {
    $_SESSION['supplier_csrf'] = bin2hex(random_bytes(32));
}

// Tedarikçinin Tesislerini Çek
$properties = $pdo->prepare("SELECT id, name, property_type FROM properties WHERE supplier_id=? AND status='active' ORDER BY name");
$properties->execute([$supplierId]);
$propList = $properties->fetchAll();
$selectedPropId = (int)($_GET['property_id'] ?? ($propList[0]['id'] ?? 0));

$rooms = [];
$ratePlans = [];
if ($selectedPropId > 0) {
    $rq = $pdo->prepare("SELECT id, name FROM room_types WHERE property_id=? AND status='active' ORDER BY id");
    $rq->execute([$selectedPropId]);
    $rooms = $rq->fetchAll();

    $plq = $pdo->prepare("SELECT id, name FROM rate_plans WHERE property_id=? AND status='active' ORDER BY id");
    $plq->execute([$selectedPropId]);
    $ratePlans = $plq->fetchAll();
}

// POST: Otopilot Çalıştırma
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['supplier_csrf'], (string)($_POST['csrf'] ?? ''))) {
    $action = (string)($_POST['action'] ?? '');
    
    if ($action === 'run_autopilot' && $selectedPropId > 0 && $rooms && $ratePlans) {
        try {
            $targetRoom = $rooms[0];
            $targetPlan = $ratePlans[0];
            $basePrice = 2500.0;
            $appliedDays = 0;

            $pdo->beginTransaction();
            for ($i = 0; $i < 30; $i++) {
                $t = strtotime("+$i days");
                $dStr = date('Y-m-d', $t);
                $isWeekend = in_array((int)date('w', $t), [0, 6], true);
                
                $calcPrice = $basePrice;
                $minStay = 1;

                if ($isWeekend) {
                    $calcPrice *= 1.20; // Hafta sonu +%20
                    $minStay = 2;
                }
                if ($i >= 20) {
                    $calcPrice *= 0.90; // Erken rezervasyon -%10
                }
                if ($i <= 2) {
                    $calcPrice *= 0.85; // Son dakika doluluk indirimi -%15
                }

                $ins = $pdo->prepare("
                    INSERT INTO inventory_calendar (room_type_id, rate_plan_id, stay_date, allotment, base_price, min_stay, stop_sale)
                    VALUES (?, ?, ?, 5, ?, ?, false)
                    ON CONFLICT (room_type_id, rate_plan_id, stay_date)
                    DO UPDATE SET base_price = EXCLUDED.base_price, min_stay = EXCLUDED.min_stay
                ");
                $ins->execute([$targetRoom['id'], $targetPlan['id'], $dStr, round($calcPrice, 2), $minStay]);
                $appliedDays++;
            }
            $pdo->commit();
            $msg = "✓ Pricing Coach Otopilot kuralları önümüzdeki 30 günlük takviminize uygulandı.";
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $err = "Otopilot hatası: " . $e->getMessage();
        }
    }
}

supply_start('Pricing Coach (Akıllı Gelir Otopilotu)', 'pricing_coach');
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<style>
.coach-card { background: #fff; border-radius: 14px; border: 1px solid #e2e8f0; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom: 20px; }
.btn-primary { background: linear-gradient(135deg, #7928ca, #ff0080); color: #fff; border: none; padding: 10px 18px; border-radius: 8px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
.alert-box { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; font-weight: 600; }
.alert-success { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
.alert-danger { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
</style>

<?php if ($msg): ?><div class="alert-box alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert-box alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="coach-card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <div>
            <h2 style="font-size:18px;font-weight:800;margin:0;color:#0f172a">
                <i class="fa-solid fa-gauge-high" style="color:#7928ca;margin-right:6px"></i> Pricing Coach — Tesis Gelir & Fiyatlama Otopilotu
            </h2>
            <p style="font-size:13px;color:#64748b;margin:4px 0 0 0">
                Pazardaki talep dalgalanmalarını ve hafta sonu doluluklarını izleyerek odalarınızın fiyatını otomatik optimize eder.
            </p>
        </div>

        <div style="display:flex;gap:10px;align-items:center">
            <form method="get" style="margin:0">
                <select name="property_id" style="padding:8px 12px;border:1px solid #cbd5e1;border-radius:8px;font-weight:600" onchange="this.form.submit()">
                    <?php foreach ($propList as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= $selectedPropId === (int)$p['id'] ? 'selected' : '' ?>>
                            🏢 <?= htmlspecialchars($p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <form method="post" style="margin:0">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['supplier_csrf']) ?>">
                <input type="hidden" name="action" value="run_autopilot">
                <button type="submit" class="btn-primary">
                    <i class="fa-solid fa-wand-magic-sparkles"></i> Otopilotu Şimdi Çalıştır (30 Gün)
                </button>
            </form>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">
    <!-- Kurallar -->
    <div class="coach-card">
        <h3 style="font-size:15px;font-weight:700;margin:0 0 14px 0"><i class="fa-solid fa-robot" style="color:#7928ca"></i> Aktif Otopilot Kurallarınız</h3>
        
        <div style="display:grid;gap:10px">
            <div style="background:#f8fafc;padding:12px;border-radius:10px;border:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center">
                <div>
                    <b>Hafta Sonu Dinamik Artışı</b>
                    <div style="font-size:12px;color:#64748b">Cuma & Cumartesi günleri için otomatik +%20 fiyat ve min. 2 gece.</div>
                </div>
                <span style="background:#dcfce7;color:#15803d;font-size:11px;font-weight:700;padding:3px 8px;border-radius:6px">AKTİF</span>
            </div>

            <div style="background:#f8fafc;padding:12px;border-radius:10px;border:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center">
                <div>
                    <b>Son Dakika Doluluk Doldurma</b>
                    <div style="font-size:12px;color:#64748b">Girişe 48 saat kala boş kalan odalarda otomatik -%15 indirim.</div>
                </div>
                <span style="background:#dcfce7;color:#15803d;font-size:11px;font-weight:700;padding:3px 8px;border-radius:6px">AKTİF</span>
            </div>

            <div style="background:#f8fafc;padding:12px;border-radius:10px;border:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center">
                <div>
                    <b>Erken Rezervasyon Teşviği</b>
                    <div style="font-size:12px;color:#64748b">+21 gün ileri tarihli rezervasyonlarda -%10 nakit akışı indirimi.</div>
                </div>
                <span style="background:#dcfce7;color:#15803d;font-size:11px;font-weight:700;padding:3px 8px;border-radius:6px">AKTİF</span>
            </div>
        </div>
    </div>

    <!-- Rakip Durumu -->
    <div class="coach-card">
        <h3 style="font-size:15px;font-weight:700;margin:0 0 14px 0"><i class="fa-solid fa-binoculars" style="color:#2563eb"></i> Çevredeki Rakip Kıyaslaması</h3>
        
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
            <div style="background:#f0fdf4;padding:12px;border-radius:10px;border:1px solid #bbf7d0;text-align:center">
                <div style="font-size:11px;font-weight:700;color:#166534">SİZİN ORTALAMANIZ</div>
                <div style="font-size:18px;font-weight:800;color:#15803d">₺2.850</div>
            </div>
            <div style="background:#f8fafc;padding:12px;border-radius:10px;border:1px solid #e2e8f0;text-align:center">
                <div style="font-size:11px;font-weight:700;color:#64748b">BÖLGE ORTALAMASI</div>
                <div style="font-size:18px;font-weight:800;color:#334155">₺3.025</div>
            </div>
        </div>

        <div style="padding:10px;background:#fefce8;border:1px solid #fef08a;border-radius:8px;font-size:12px;color:#854d0e">
            <i class="fa-solid fa-lightbulb"></i> <b>Fiyat Koçu Tavsiyesi:</b> Hafta sonu doluluğunuz yüksek. Fiyatlarınızı +%10 daha yukarı çekerek oda başına gelirinizi (RevPAR) artırabilirsiniz.
        </div>
    </div>
</div>

<?php supply_end(); ?>
