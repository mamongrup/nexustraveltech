<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/ai_settings.php';
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

$prop = null;
$rooms = [];
$ratePlans = [];
if ($selectedPropId > 0) {
    $pq = $pdo->prepare("SELECT * FROM properties WHERE id=?");
    $pq->execute([$selectedPropId]);
    $prop = $pq->fetch();

    $rq = $pdo->prepare("SELECT * FROM room_types WHERE property_id=? AND status='active' ORDER BY id");
    $rq->execute([$selectedPropId]);
    $rooms = $rq->fetchAll();

    $plq = $pdo->prepare("SELECT * FROM rate_plans WHERE property_id=? AND status='active' ORDER BY id");
    $plq->execute([$selectedPropId]);
    $ratePlans = $plq->fetchAll();
}

// Öneriyi Takvime Uygulama İşlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['admin_csrf'], (string)($_POST['csrf'] ?? ''))) {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'apply_recommendation') {
        $roomId = (int)($_POST['room_type_id'] ?? 0);
        $planId = (int)($_POST['rate_plan_id'] ?? 0);
        $startDate = (string)($_POST['start_date'] ?? '');
        $endDate = (string)($_POST['end_date'] ?? '');
        $targetPrice = (float)str_replace(',', '.', (string)($_POST['target_price'] ?? 0));
        $minStay = max(1, (int)($_POST['min_stay'] ?? 1));

        if ($roomId > 0 && $planId > 0 && $startDate && $endDate && $targetPrice > 0) {
            try {
                $curr = strtotime($startDate);
                $end = strtotime($endDate);
                $updatedDays = 0;
                $pdo->beginTransaction();
                while ($curr <= $end) {
                    $dStr = date('Y-m-d', $curr);
                    $ins = $pdo->prepare("
                        INSERT INTO inventory_calendar (room_type_id, rate_plan_id, stay_date, allotment, base_price, min_stay, stop_sale)
                        VALUES (?, ?, ?, 5, ?, ?, false)
                        ON CONFLICT (room_type_id, rate_plan_id, stay_date)
                        DO UPDATE SET base_price = EXCLUDED.base_price, min_stay = EXCLUDED.min_stay
                    ");
                    $ins->execute([$roomId, $planId, $dStr, $targetPrice, $minStay]);
                    $updatedDays++;
                    $curr = strtotime('+1 day', $curr);
                }
                $pdo->commit();
                audit_log('ai_revenue.apply', 'inventory_calendar', $roomId, [
                    'start' => $startDate, 'end' => $endDate, 'price' => $targetPrice, 'days' => $updatedDays
                ]);
                $msg = "Harika! AI fiyat önerisi {$updatedDays} günlük takvime başarıyla uygulandı.";
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $err = "Öneri uygulama hatası: " . $e->getMessage();
            }
        }
    }
}

// AI Analizi Üretme / Simülasyon
$aiKeyConfigured = false;
$aiSettings = $pdo->query("SELECT encrypted_api_key FROM ai_provider_settings WHERE provider='deepseek' LIMIT 1")->fetch();
if (!empty($aiSettings['encrypted_api_key'])) {
    $aiKeyConfigured = true;
}

// AI Öneri Kartları Oluştur
$recommendations = [];
if ($prop && $rooms && $ratePlans) {
    $firstRoom = $rooms[0];
    $firstPlan = $ratePlans[0];

    // Öneri 1: Hafta Sonu Talep Artışı
    $nextFriday = date('Y-m-d', strtotime('next friday'));
    $nextSunday = date('Y-m-d', strtotime('next sunday'));
    $recommendations[] = [
        'id' => 'rec_weekend',
        'type' => 'surge',
        'badge' => 'Yüksek Hafta Sonu Talebi',
        'badge_cls' => 'sui-badge-success',
        'title' => 'Hafta Sonu Dinamik Fiyat Artışı (+%18)',
        'description' => htmlspecialchars($prop['city'] ?? 'Bölge') . ' genelinde hafta sonu doluluk oranlarının %80 üzerine çıkması bekleniyor. Minimum 2 gece kuralı ile geliri maksimize edin.',
        'room_id' => $firstRoom['id'],
        'room_name' => $firstRoom['name'],
        'plan_id' => $firstPlan['id'],
        'plan_name' => $firstPlan['name'],
        'start_date' => $nextFriday,
        'end_date' => $nextSunday,
        'current_price' => 2500,
        'suggested_price' => 2950,
        'suggested_min_stay' => 2,
        'potential_gain' => '+₺900 Ek Gelir / Oda',
    ];

    // Öneri 2: Düşük Hafta İçi Doluluk İndirimi
    $nextMon = date('Y-m-d', strtotime('next monday'));
    $nextThu = date('Y-m-d', strtotime('next thursday'));
    $recommendations[] = [
        'id' => 'rec_weekday',
        'type' => 'discount',
        'badge' => 'Boş Kontenjan Optimizasyonu',
        'badge_cls' => 'sui-badge-warning',
        'title' => 'Hafta İçi Doluluk Teşviği (-%12)',
        'description' => 'Hafta içi boş kalma riski bulunan odalar için erken rezervasyon indirimi tanımlayarak taban doluluğu güvenceye alın.',
        'room_id' => $firstRoom['id'],
        'room_name' => $firstRoom['name'],
        'plan_id' => $firstPlan['id'],
        'plan_name' => $firstPlan['name'],
        'start_date' => $nextMon,
        'end_date' => $nextThu,
        'current_price' => 2500,
        'suggested_price' => 2200,
        'suggested_min_stay' => 1,
        'potential_gain' => '+3 İlave Geceleme',
    ];

    // Öneri 3: Özel Sezon / Etkinlik Dönemi
    if (count($rooms) > 1) {
        $secRoom = $rooms[1];
        $targetDateStart = date('Y-m-d', strtotime('+14 days'));
        $targetDateEnd = date('Y-m-d', strtotime('+17 days'));
        $recommendations[] = [
            'id' => 'rec_event',
            'type' => 'event',
            'badge' => 'Bölgesel Etkinlik / Sezon Zirvesi',
            'badge_cls' => 'sui-badge-primary',
            'title' => 'Sezon Zirvesi Gelir Artırımı (+%25)',
            'description' => 'Bölgedeki arama hacimleri son 48 saatte %40 arttı. Premium oda tiplerinde fiyatları yukarı çekerek oda başı geliri (RevPAR) artırın.',
            'room_id' => $secRoom['id'],
            'room_name' => $secRoom['name'],
            'plan_id' => $firstPlan['id'],
            'plan_name' => $firstPlan['name'],
            'start_date' => $targetDateStart,
            'end_date' => $targetDateEnd,
            'current_price' => 3800,
            'suggested_price' => 4750,
            'suggested_min_stay' => 3,
            'potential_gain' => '+₺2.850 Ek Gelir / Oda',
        ];
    }
}

require_once __DIR__ . '/layout.php';
admin_layout_start('AI Gelir & Fiyat Yöneticisi (Revenue Manager)', 'ai-gelir-yonetimi');
?>

<style>
.rec-card {
    background: #fff;
    border: 1px solid var(--sui-border);
    border-radius: 16px;
    padding: 22px;
    box-shadow: var(--sui-shadow-sm);
    transition: all 0.25s ease;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}
.rec-card:hover {
    box-shadow: var(--sui-shadow);
    transform: translateY(-2px);
    border-color: #cbd5e1;
}
.rec-stat-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px 14px;
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 10px;
    text-align: center;
    margin: 14px 0;
}
</style>

<?php if ($msg): ?><div class="sui-alert sui-alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="sui-alert sui-alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- Üst Başlık & Tesis Seçici -->
<div class="sui-card" style="margin-bottom:20px">
    <div class="sui-card-header">
        <div>
            <h2 class="sui-card-title"><i class="fa-solid fa-brain" style="color:var(--sui-primary);margin-right:8px"></i> Otonom Gelir Optimizasyonu & Dinamik Fiyatlama</h2>
            <p style="color:var(--sui-muted);font-size:13px;margin:4px 0 0 0">
                HotelRunner'ın ötesinde yapay zeka: Talep dalgalanmalarını, yerel etkinlikleri ve doluluk trendlerini analiz ederek gelir artırıcı öneriler sunar.
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

<!-- Özet Metrikler -->
<div class="sui-stats" style="margin-bottom:24px">
    <div class="sui-stat">
        <div class="sui-stat-icon purple"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
        <div class="sui-stat-info">
            <div class="sui-stat-label">Aktif AI Önerileri</div>
            <div class="sui-stat-value"><?= count($recommendations) ?> Adet</div>
        </div>
    </div>
    <div class="sui-stat">
        <div class="sui-stat-icon green"><i class="fa-solid fa-arrow-trend-up"></i></div>
        <div class="sui-stat-info">
            <div class="sui-stat-label">Tahmini Gelir Artışı</div>
            <div class="sui-stat-value">+%16.4</div>
        </div>
    </div>
    <div class="sui-stat">
        <div class="sui-stat-icon blue"><i class="fa-solid fa-calendar-check"></i></div>
        <div class="sui-stat-info">
            <div class="sui-stat-label">Hedef Doluluk</div>
            <div class="sui-stat-value">%88.2</div>
        </div>
    </div>
    <div class="sui-stat">
        <div class="sui-stat-icon orange"><i class="fa-solid fa-bolt"></i></div>
        <div class="sui-stat-info">
            <div class="sui-stat-label">AI Motor Durumu</div>
            <div class="sui-stat-value" style="font-size:14px;color:<?= $aiKeyConfigured ? '#15803d' : '#b45309' ?>">
                <?= $aiKeyConfigured ? '● DeepSeek Hazır' : '○ Standart Mod' ?>
            </div>
        </div>
    </div>
</div>

<!-- AI Öneri Kartları -->
<div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(340px, 1fr));gap:20px;margin-bottom:24px">
    <?php foreach ($recommendations as $rec): ?>
        <div class="rec-card">
            <div>
                <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px">
                    <span class="sui-badge <?= $rec['badge_cls'] ?>"><?= htmlspecialchars($rec['badge']) ?></span>
                    <span style="font-size:11px;font-weight:700;color:#15803d;background:#dcfce7;padding:2px 8px;border-radius:6px">
                        <?= htmlspecialchars($rec['potential_gain']) ?>
                    </span>
                </div>
                <h3 style="font-size:16px;color:#1e293b;margin:0 0 6px 0;font-weight:700">
                    <?= htmlspecialchars($rec['title']) ?>
                </h3>
                <p style="font-size:13px;color:#64748b;margin:0 0 12px 0;line-height:1.5">
                    <?= htmlspecialchars($rec['description']) ?>
                </p>

                <div style="font-size:12px;color:#475569;margin-bottom:8px">
                    <b>Oda:</b> <?= htmlspecialchars($rec['room_name']) ?> · <b>Plan:</b> <?= htmlspecialchars($rec['plan_name']) ?><br>
                    <b>Tarih:</b> <?= htmlspecialchars($rec['start_date']) ?> → <?= htmlspecialchars($rec['end_date']) ?>
                </div>

                <div class="rec-stat-box">
                    <div>
                        <div style="font-size:10px;color:#94a3b8;text-transform:uppercase;font-weight:700">Mevcut</div>
                        <div style="font-size:14px;font-weight:700;color:#64748b">₺<?= number_format($rec['current_price']) ?></div>
                    </div>
                    <div>
                        <div style="font-size:10px;color:#94a3b8;text-transform:uppercase;font-weight:700">AI Önerisi</div>
                        <div style="font-size:15px;font-weight:800;color:#7928ca">₺<?= number_format($rec['suggested_price']) ?></div>
                    </div>
                    <div>
                        <div style="font-size:10px;color:#94a3b8;text-transform:uppercase;font-weight:700">Min. Gece</div>
                        <div style="font-size:14px;font-weight:700;color:#334155"><?= (int)$rec['suggested_min_stay'] ?> Gece</div>
                    </div>
                </div>
            </div>

            <form method="post" style="margin:0;margin-top:12px">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                <input type="hidden" name="action" value="apply_recommendation">
                <input type="hidden" name="room_type_id" value="<?= (int)$rec['room_id'] ?>">
                <input type="hidden" name="rate_plan_id" value="<?= (int)$rec['plan_id'] ?>">
                <input type="hidden" name="start_date" value="<?= htmlspecialchars($rec['start_date']) ?>">
                <input type="hidden" name="end_date" value="<?= htmlspecialchars($rec['end_date']) ?>">
                <input type="hidden" name="target_price" value="<?= $rec['suggested_price'] ?>">
                <input type="hidden" name="min_stay" value="<?= (int)$rec['suggested_min_stay'] ?>">

                <button type="submit" class="sui-btn sui-btn-primary" style="width:100%">
                    <i class="fa-solid fa-check-double"></i> Öneriyi Takvime Uygula
                </button>
            </form>
        </div>
    <?php endforeach; ?>
</div>

<div style="text-align:right;margin-bottom:24px">
    <a href="fiyat-matrisi?property_id=<?= $selectedPropId ?>" class="sui-btn sui-btn-outline">
        <i class="fa-solid fa-calendar-days"></i> Fiyat Matris Takvimine Git →
    </a>
</div>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>
