<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

session_start();
if (empty($_SESSION['widget_csrf'])) {
    $_SESSION['widget_csrf'] = bin2hex(random_bytes(32));
}

$pdo = db();
$propId = (int)($_GET['property_id'] ?? ($_POST['property_id'] ?? 1));
$lang = (string)($_GET['lang'] ?? 'tr');

// Tesis Bilgilerini Çek
$propQ = $pdo->prepare("SELECT p.*, s.company_name FROM properties p JOIN suppliers s ON s.id=p.supplier_id WHERE p.id=? AND p.status='active'");
$propQ->execute([$propId]);
$property = $propQ->fetch();

if (!$property) {
    echo '<div style="font-family:sans-serif;padding:30px;text-align:center;color:#64748b">Tesis bulunamadı veya şu anda satışa kapalı.</div>';
    exit;
}

$msg = '';
$err = '';
$bookingRef = '';

// Form Arama Parametreleri
$checkIn = !empty($_GET['check_in']) ? (string)$_GET['check_in'] : date('Y-m-d', strtotime('+1 day'));
$checkOut = !empty($_GET['check_out']) ? (string)$_GET['check_out'] : date('Y-m-d', strtotime('+3 days'));
$adults = max(1, min(10, (int)($_GET['adults'] ?? 2)));

$nights = max(1, (int)round((strtotime($checkOut) - strtotime($checkIn)) / 86400));

// Rezervasyon Tamamlama Talebi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['widget_csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    $roomId = (int)($_POST['room_type_id'] ?? 0);
    $planId = (int)($_POST['rate_plan_id'] ?? 0);
    $guestName = trim((string)($_POST['guest_name'] ?? ''));
    $guestEmail = filter_var(trim((string)($_POST['guest_email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $guestPhone = trim((string)($_POST['guest_phone'] ?? ''));
    $guestNotes = trim((string)($_POST['guest_notes'] ?? ''));
    $totalAmount = (float)str_replace(',', '.', (string)($_POST['total_amount'] ?? 0));

    if ($roomId > 0 && $guestName !== '' && $guestEmail) {
        try {
            $bookingRef = 'NX-' . strtoupper(bin2hex(random_bytes(4)));
            $insBooking = $pdo->prepare("
                INSERT INTO supplier_bookings (supplier_id, property_id, booking_reference, status, check_in, check_out, total_amount, currency)
                VALUES (?, ?, ?, 'confirmed', ?, ?, ?, 'TRY')
            ");
            $insBooking->execute([
                $property['supplier_id'],
                $property['id'],
                $bookingRef,
                $checkIn,
                $checkOut,
                $totalAmount
            ]);
            $msg = "Tebrikler! Rezervasyonunuz başarıyla oluşturuldu. Referans No: " . $bookingRef;
        } catch (Throwable $e) {
            $err = "Rezervasyon oluşturulurken bir hata oluştu: " . $e->getMessage();
        }
    } else {
        $err = "Lütfen ad, soyad ve geçerli bir e-posta adresi girin.";
    }
}

// Müsait Oda Tiplerini ve Fiyatlarını Çek
$roomsQ = $pdo->prepare("
    SELECT r.*, 
           (SELECT file_path FROM property_media pm WHERE pm.property_id=r.property_id AND (pm.room_type_id=r.id OR pm.is_cover=true) ORDER BY pm.is_cover DESC, pm.sort_order LIMIT 1) as cover_photo
    FROM room_types r 
    WHERE r.property_id=? AND r.status='active' AND r.capacity_adults >= ?
    ORDER BY r.capacity_adults, r.id
");
$roomsQ->execute([$propId, $adults]);
$availableRooms = $roomsQ->fetchAll();

// Fiyat Planlarını Çek
$plansQ = $pdo->prepare("SELECT * FROM rate_plans WHERE property_id=? AND status='active'");
$plansQ->execute([$propId]);
$ratePlans = $plansQ->fetchAll();

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($property['name']) ?> — Doğrudan Rezervasyon</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary: #7928ca;
            --primary-end: #ff0080;
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --bg-light: #f8fafc;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--bg-light);
            color: var(--text-dark);
            padding: 16px;
            font-size: 14px;
        }
        .widget-wrap {
            max-width: 860px;
            margin: 0 auto;
            background: #fff;
            border-radius: 20px;
            border: 1px solid var(--border-color);
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.06);
            overflow: hidden;
        }
        .widget-header {
            background: linear-gradient(310deg, var(--primary), var(--primary-end));
            color: #fff;
            padding: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .widget-search-bar {
            padding: 18px 24px;
            background: #f1f5f9;
            border-bottom: 1px solid var(--border-color);
            display: grid;
            grid-template-columns: 1fr 1fr 120px auto;
            gap: 12px;
            align-items: flex-end;
        }
        .s-field label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .s-field input, .s-field select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            background: #fff;
            outline: none;
        }
        .s-field input:focus, .s-field select:focus {
            border-color: var(--primary);
        }
        .btn-search {
            padding: 11px 20px;
            background: linear-gradient(310deg, var(--primary), var(--primary-end));
            color: #fff;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: opacity 0.2s;
            height: 42px;
        }
        .btn-search:hover { opacity: 0.92; }
        .room-item {
            padding: 24px;
            border-bottom: 1px solid var(--border-color);
            display: grid;
            grid-template-columns: 200px 1fr 180px;
            gap: 20px;
            align-items: center;
        }
        .room-img {
            width: 100%;
            height: 130px;
            border-radius: 14px;
            background: #e2e8f0;
            object-fit: cover;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            font-size: 32px;
        }
        .room-info h3 {
            font-size: 17px;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .room-meta {
            display: flex;
            gap: 12px;
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 8px;
        }
        .price-box {
            text-align: right;
        }
        .price-total {
            font-size: 20px;
            font-weight: 800;
            color: var(--primary);
        }
        .price-sub {
            font-size: 11px;
            color: var(--text-muted);
            margin-bottom: 10px;
        }
        .btn-book {
            padding: 10px 16px;
            background: #0f172a;
            color: #fff;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            width: 100%;
            transition: background 0.2s;
        }
        .btn-book:hover { background: #334155; }
        .alert-box {
            padding: 14px 18px;
            margin: 18px 24px 0 24px;
            border-radius: 12px;
            font-weight: 600;
        }
        .alert-success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        @media (max-width: 640px) {
            .widget-search-bar { grid-template-columns: 1fr; }
            .room-item { grid-template-columns: 1fr; }
            .price-box { text-align: left; }
        }
    </style>
</head>
<body>

<div class="widget-wrap">
    <div class="widget-header">
        <div>
            <h1 style="font-size:20px;font-weight:800"><?= htmlspecialchars($property['name']) ?></h1>
            <div style="font-size:12px;opacity:0.9"><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($property['city'] ?? 'Türkiye') ?> · Doğrudan Rezervasyon</div>
        </div>
        <div style="font-size:11px;font-weight:700;background:rgba(255,255,255,0.2);padding:6px 12px;border-radius:20px">
            <i class="fa-solid fa-shield-halved"></i> En İyi Fiyat Garantisi
        </div>
    </div>

    <!-- Arama Çubuğu -->
    <form method="get" class="widget-search-bar">
        <input type="hidden" name="property_id" value="<?= (int)$propId ?>">
        <div class="s-field">
            <label><i class="fa-regular fa-calendar"></i> Giriş Tarihi</label>
            <input type="date" name="check_in" value="<?= htmlspecialchars($checkIn) ?>" required>
        </div>
        <div class="s-field">
            <label><i class="fa-regular fa-calendar"></i> Çıkış Tarihi</label>
            <input type="date" name="check_out" value="<?= htmlspecialchars($checkOut) ?>" required>
        </div>
        <div class="s-field">
            <label><i class="fa-solid fa-user-group"></i> Misafir</label>
            <select name="adults">
                <?php for ($a=1; $a<=8; $a++): ?>
                    <option value="<?= $a ?>" <?= $adults === $a ? 'selected' : '' ?>><?= $a ?> Yetişkin</option>
                <?php endfor; ?>
            </select>
        </div>
        <button type="submit" class="btn-search"><i class="fa-solid fa-magnifying-glass"></i> Ara</button>
    </form>

    <?php if ($msg): ?>
        <div class="alert-box alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <?php if ($err): ?>
        <div class="alert-box alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($err) ?></div>
    <?php endif; ?>

    <!-- Müsait Odalar -->
    <div style="padding:10px 0">
        <?php if (!$availableRooms): ?>
            <div style="padding:40px;text-align:center;color:var(--text-muted)">
                <i class="fa-regular fa-calendar-xmark" style="font-size:36px;margin-bottom:10px"></i>
                <p>Seçilen tarihler ve misafir sayısı için müsait oda bulunamadı. Lütfen farklı tarihler deneyin.</p>
            </div>
        <?php else: ?>
            <?php foreach ($availableRooms as $rm): 
                $defaultPlan = $ratePlans[0] ?? ['id' => 1, 'name' => 'Standart Plan'];
                // Örnek günlük fiyat hesaplama
                $dailyPrice = 2200; // Varsayılan
                $calcTotal = $dailyPrice * $nights;
            ?>
                <div class="room-item">
                    <?php if (!empty($rm['cover_photo'])): ?>
                        <img src="<?= htmlspecialchars($rm['cover_photo']) ?>" class="room-img" alt="<?= htmlspecialchars($rm['name']) ?>">
                    <?php else: ?>
                        <div class="room-img"><i class="fa-solid fa-hotel"></i></div>
                    <?php endif; ?>

                    <div class="room-info">
                        <h3><?= htmlspecialchars($rm['name']) ?></h3>
                        <div class="room-meta">
                            <span><i class="fa-solid fa-user"></i> Max <?= (int)$rm['capacity_adults'] ?> Kişi</span>
                            <span><i class="fa-solid fa-bed"></i> Rahat Yatak</span>
                            <span><i class="fa-solid fa-wifi"></i> Ücretsiz Wi-Fi</span>
                        </div>
                        <div style="font-size:12px;color:#15803d;font-weight:600">
                            <i class="fa-solid fa-check"></i> Ücretsiz İptal Seçeneği
                        </div>
                    </div>

                    <div class="price-box">
                        <div class="price-total">₺<?= number_format($calcTotal) ?></div>
                        <div class="price-sub"><?= $nights ?> Gece Toplam</div>
                        <button type="button" class="btn-book" onclick="openBookingForm(<?= (int)$rm['id'] ?>, '<?= htmlspecialchars($rm['name']) ?>', <?= (int)$defaultPlan['id'] ?>, <?= $calcTotal ?>)">
                            Hemen Ayırt
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Güven Damgası -->
    <div style="padding:14px;background:#f8fafc;border-top:1px solid var(--border-color);text-align:center;font-size:11px;color:var(--text-muted)">
        Güvenli Rezervasyon Altyapısı · Powered by <b>NEXUS Travel Tech</b>
    </div>
</div>

<!-- Basit Rezervasyon Form Modalı -->
<div id="bookingModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);backdrop-filter:blur(4px);z-index:9999;align-items:center;justify-content:center;padding:16px">
    <div style="background:#fff;border-radius:18px;max-width:480px;width:100%;padding:24px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.2)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
            <h3 style="font-size:16px;font-weight:800" id="modalRoomTitle">Rezervasyon Tamamla</h3>
            <button type="button" style="background:none;border:none;font-size:20px;cursor:pointer" onclick="closeBookingForm()">×</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['widget_csrf']) ?>">
            <input type="hidden" name="property_id" value="<?= (int)$propId ?>">
            <input type="hidden" name="room_type_id" id="modalRoomId" value="">
            <input type="hidden" name="rate_plan_id" id="modalPlanId" value="">
            <input type="hidden" name="total_amount" id="modalTotalAmount" value="">

            <div style="margin-bottom:12px">
                <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px">Ad Soyad</label>
                <input type="text" name="guest_name" required style="width:100%;padding:10px 12px;border:1px solid var(--border-color);border-radius:8px;font-family:inherit">
            </div>
            <div style="margin-bottom:12px">
                <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px">E-posta</label>
                <input type="email" name="guest_email" required style="width:100%;padding:10px 12px;border:1px solid var(--border-color);border-radius:8px;font-family:inherit">
            </div>
            <div style="margin-bottom:16px">
                <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px">Telefon</label>
                <input type="tel" name="guest_phone" placeholder="05XX XXX XX XX" required style="width:100%;padding:10px 12px;border:1px solid var(--border-color);border-radius:8px;font-family:inherit">
            </div>

            <div style="padding:12px;background:#f8fafc;border-radius:10px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center">
                <span style="font-size:13px;font-weight:600">Ödenecek Tutar:</span>
                <span style="font-size:18px;font-weight:800;color:var(--primary)" id="modalTotalDisplay">₺0</span>
            </div>

            <button type="submit" class="btn-search" style="width:100%">
                <i class="fa-solid fa-check"></i> Rezervasyonu Onayla
            </button>
        </form>
    </div>
</div>

<script>
function openBookingForm(roomId, roomName, planId, total) {
    document.getElementById('modalRoomId').value = roomId;
    document.getElementById('modalPlanId').value = planId;
    document.getElementById('modalTotalAmount').value = total;
    document.getElementById('modalRoomTitle').innerText = roomName + ' Rezervasyonu';
    document.getElementById('modalTotalDisplay').innerText = '₺' + Number(total).toLocaleString('tr-TR');
    document.getElementById('bookingModal').style.display = 'flex';
}
function closeBookingForm() {
    document.getElementById('bookingModal').style.display = 'none';
}
</script>

</body>
</html>
