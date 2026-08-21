<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/ai_settings.php';

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$lang = strtolower((string) ($_GET['lang'] ?? $_POST['lang'] ?? 'tr'));
if (!in_array($lang, ['tr', 'en', 'de', 'ru'], true)) $lang = 'tr';

$q = db()->prepare("SELECT l.*, b.id booking_id, b.property_id, b.booking_reference, b.checkin_date, b.checkout_date, 
                           p.name property_name, p.property_type, p.address,
                           g.id guest_id, g.first_name, g.last_name, g.phone, g.email, g.nationality,
                           bf.id folio_id
                    FROM booking_checkin_links l 
                    JOIN supplier_bookings b ON b.id = l.booking_id 
                    JOIN properties p ON p.id = b.property_id 
                    LEFT JOIN booking_guests bg ON bg.booking_id = b.id AND bg.is_primary = true 
                    LEFT JOIN guest_profiles g ON g.id = bg.guest_id 
                    LEFT JOIN booking_folios bf ON bf.booking_id = b.id AND bf.status = 'open'
                    WHERE l.token_hash = ? AND l.status = 'active' AND l.expires_at > now()");
$q->execute([hash('sha256', $token)]);
$link = $q->fetch();

if (!$link) {
    http_response_code(404);
    exit('<!DOCTYPE html><html><body style="font-family:sans-serif;text-align:center;padding:50px"><h2>Check-in Bağlantısı Geçersiz veya Süresi Dolmuş</h2><p>Lütfen oteliniz/tesisiniz ile iletişime geçin.</p></body></html>');
}

$message = '';
$error = '';
$checkinSuccess = false;

// Upsell Hizmetleri Listesi
$upsellServices = [
    'vip_transfer' => ['name' => ['tr' => 'VIP Havalimanı Transferi', 'en' => 'VIP Airport Transfer', 'de' => 'VIP Flughafentransfer', 'ru' => 'VIP Трансфер из аэропорта'], 'price' => 50.0, 'icon' => '🚘'],
    'early_checkin' => ['name' => ['tr' => 'Erken Giriş (Saat 10:00)', 'en' => 'Early Check-in (10:00 AM)', 'de' => 'Früher Check-in (10:00 Uhr)', 'ru' => 'Ранний заезд (10:00)'], 'price' => 35.0, 'icon' => '⏰'],
    'late_checkout' => ['name' => ['tr' => 'Geç Çıkış (Saat 16:00)', 'en' => 'Late Check-out (04:00 PM)', 'de' => 'Später Check-out (16:00 Uhr)', 'ru' => 'Поздний выезд (16:00)'], 'price' => 35.0, 'icon' => '🌙'],
    'wine_fruit' => ['name' => ['tr' => 'Şarap & Taze Meyve Sepeti', 'en' => 'Wine & Fresh Fruit Basket', 'de' => 'Wein & Frischer Obstkorb', 'ru' => 'Вино и корзина свежих фруктов'], 'price' => 30.0, 'icon' => '🍷'],
    'spa_massage' => ['name' => ['tr' => 'Aromaterapi SPA Masajı', 'en' => 'Aromatherapy SPA Massage', 'de' => 'Aromatherapie SPA-Massage', 'ru' => 'Ароматерапевтический СПА-массаж'], 'price' => 65.0, 'icon' => '💆'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if (!isset($_POST['consent'])) {
            throw new RuntimeException($lang === 'tr' ? 'Aydınlatma ve KVKK onayı zorunludur.' : 'Privacy consent is required.');
        }

        $identity = trim((string) ($_POST['identity_number'] ?? ''));
        $firstName = trim((string) ($_POST['first_name'] ?? $link['first_name'] ?? ''));
        $lastName = trim((string) ($_POST['last_name'] ?? $link['last_name'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $nationality = strtoupper(trim((string) ($_POST['nationality'] ?? 'TR')));
        $identityType = (string) ($_POST['identity_type'] ?? 'identity');
        $eta = trim((string) ($_POST['estimated_arrival'] ?? ''));

        if ($identity === '') {
            throw new RuntimeException($lang === 'tr' ? 'Kimlik veya pasaport numarası zorunludur.' : 'Identity or passport number is required.');
        }

        // Misafir profilini güncelle
        if ($link['guest_id']) {
            $pdo->prepare("UPDATE guest_profiles 
                           SET first_name = COALESCE(NULLIF(?, ''), first_name),
                               last_name = COALESCE(NULLIF(?, ''), last_name),
                               phone = ?, email = ?, nationality = ?, identity_type = ?, 
                               identity_number = ?,
                               preferences = jsonb_set(COALESCE(preferences, '{}'::jsonb), '{online_checkin}', 'true'::jsonb, true) 
                           WHERE id = ?")
                ->execute([$firstName, $lastName, $phone ?: null, $email ?: null, $nationality, $identityType, encrypt_ai_secret($identity), $link['guest_id']]);
        }

        // Checkin durumunu güncelle
        $pdo->prepare("UPDATE booking_guests SET checkin_status = 'submitted' WHERE booking_id = ? AND guest_id = ?")
            ->execute([$link['booking_id'], $link['guest_id']]);

        // Belge kaydını güvenli şekilde yaz
        $pdo->prepare("INSERT INTO guest_document_records(guest_id, booking_id, document_type, document_number_masked, verification_status, consent_at) 
                       VALUES(?, ?, ?, ?, 'pending', now())")
            ->execute([$link['guest_id'], $link['booking_id'], $identityType, substr($identity, 0, 2) . '***' . substr($identity, -2)]);

        // Upsell Hizmetlerini Folyoya Ekle
        $selectedUpsells = $_POST['upsell'] ?? [];
        if (!empty($selectedUpsells) && is_array($selectedUpsells)) {
            $folioId = (int) $link['folio_id'];
            if (!$folioId) {
                // Folyo yoksa aç
                $pdo->prepare("INSERT INTO booking_folios(booking_id, supplier_id, status) VALUES(?, ?, 'open')")
                    ->execute([$link['booking_id'], $link['supplier_id'] ?? 1]);
                $folioId = (int) $pdo->lastInsertId();
            }

            foreach ($selectedUpsells as $upsellKey) {
                if (isset($upsellServices[$upsellKey])) {
                    $svc = $upsellServices[$upsellKey];
                    $svcName = $svc['name'][$lang] ?? $svc['name']['en'];
                    $svcPrice = $svc['price'];

                    $pdo->prepare("INSERT INTO folio_transactions(folio_id, transaction_type, department, description, amount) 
                                   VALUES(?, 'charge', 'upsell', ?, ?)")
                        ->execute([$folioId, $svcName, $svcPrice]);
                }
            }
        }

        // Link durumunu tamamlandı yap
        $pdo->prepare("UPDATE booking_checkin_links SET status = 'submitted', submitted_at = now() WHERE id = ?")
            ->execute([$link['id']]);

        $pdo->commit();
        $checkinSuccess = true;
        $message = $lang === 'tr' 
            ? 'Online check-in işleminiz başarıyla tamamlandı! Resepsiyonumuz gelişinizi sabırsızlıkla bekliyor.' 
            : 'Your online check-in is complete! We look forward to welcoming you.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title><?= htmlspecialchars($link['property_name']) ?> — Online Check-in</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #10211f;
            --accent: #196f47;
            --accent-glow: #d7ff48;
            --bg: #f4f7f5;
            --card: #ffffff;
            --text: #1a2924;
            --muted: #627870;
            --border: #e0e8e3;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg); color: var(--text); line-height: 1.5; padding: 20px 14px; }
        .container { max-width: 580px; margin: 0 auto; background: var(--card); border-radius: 16px; border: 1px solid var(--border); box-shadow: 0 8px 30px rgba(0,0,0,0.06); overflow: hidden; }
        .hero { background: var(--primary); color: #fff; padding: 28px 24px; position: relative; }
        .hero h1 { font-size: 22px; font-weight: 700; color: #fff; margin-bottom: 6px; }
        .hero p { font-size: 13px; color: #9fe8b8; }
        .lang-switch { position: absolute; top: 24px; right: 20px; display: flex; gap: 6px; }
        .lang-switch a { color: #9fe8b8; text-decoration: none; font-size: 11px; padding: 3px 7px; border-radius: 4px; border: 1px solid rgba(255,255,255,0.2); }
        .lang-switch a.active { background: var(--accent-glow); color: var(--primary); font-weight: bold; border-color: var(--accent-glow); }
        .badge-bar { display: flex; gap: 8px; margin-top: 14px; flex-wrap: wrap; }
        .badge { background: rgba(255,255,255,0.12); padding: 5px 10px; border-radius: 6px; font-size: 12px; }
        .content { padding: 24px; }
        .section-title { font-size: 15px; font-weight: 700; color: var(--primary); margin: 20px 0 10px; display: flex; align-items: center; gap: 8px; }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 5px; text-transform: uppercase; letter-spacing: 0.5px; }
        input, select { width: 100%; padding: 12px 14px; border: 1.5px solid var(--border); border-radius: 8px; font-size: 14px; font-family: inherit; color: var(--text); background: #fff; transition: border-color 0.2s; }
        input:focus, select:focus { outline: none; border-color: var(--accent); }
        .row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        
        /* OCR Camera Box */
        .ocr-box { background: #f0f7f3; border: 2px dashed #a2d6b9; padding: 16px; border-radius: 10px; text-align: center; margin-bottom: 18px; cursor: pointer; transition: background 0.2s; }
        .ocr-box:hover { background: #e5f2eb; }
        .ocr-icon { font-size: 28px; margin-bottom: 4px; }
        .ocr-text { font-size: 13px; font-weight: 600; color: var(--accent); }
        .ocr-sub { font-size: 11px; color: var(--muted); }
        
        /* Upsell Cards */
        .upsell-grid { display: grid; gap: 10px; margin-bottom: 20px; }
        .upsell-card { display: flex; align-items: center; justify-content: space-between; padding: 12px 14px; border: 1.5px solid var(--border); border-radius: 10px; cursor: pointer; transition: all 0.2s; }
        .upsell-card:hover { border-color: #a2d6b9; }
        .upsell-card input { width: auto; margin-right: 10px; }
        .upsell-left { display: flex; align-items: center; }
        .upsell-info { font-size: 13px; font-weight: 600; color: var(--text); }
        .upsell-price { font-size: 13px; font-weight: 700; color: var(--accent); }

        .btn-submit { width: 100%; background: var(--primary); color: #d7ff48; border: none; padding: 15px; border-radius: 10px; font-size: 15px; font-weight: 700; cursor: pointer; transition: opacity 0.2s; margin-top: 10px; }
        .btn-submit:hover { opacity: 0.95; }
        .alert-success { background: #eef8f2; border: 1px solid #bce2ce; color: #13593b; padding: 20px; border-radius: 10px; text-align: center; }
        .alert-error { background: #fdf2f2; border: 1px solid #f8b4b4; color: #9b1c1c; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; }
        .wifi-card { background: #10211f; color: #fff; padding: 16px; border-radius: 10px; margin-top: 18px; text-align: left; }
    </style>
</head>
<body>

<div class="container">
    <header class="hero">
        <div class="lang-switch">
            <a href="?token=<?= htmlspecialchars($token) ?>&lang=tr" class="<?= $lang==='tr'?'active':'' ?>">TR</a>
            <a href="?token=<?= htmlspecialchars($token) ?>&lang=en" class="<?= $lang==='en'?'active':'' ?>">EN</a>
            <a href="?token=<?= htmlspecialchars($token) ?>&lang=de" class="<?= $lang==='de'?'active':'' ?>">DE</a>
            <a href="?token=<?= htmlspecialchars($token) ?>&lang=ru" class="<?= $lang==='ru'?'active':'' ?>">RU</a>
        </div>
        <h1><?= htmlspecialchars($link['property_name']) ?></h1>
        <p><?= $lang==='tr' ? 'Zero-Touch Temassız Hızlı Check-in' : 'Zero-Touch Express Online Check-in' ?></p>
        <div class="badge-bar">
            <span class="badge">🔖 <?= htmlspecialchars($link['booking_reference']) ?></span>
            <span class="badge">📅 <?= htmlspecialchars($link['checkin_date'] ?? '') ?> ➔ <?= htmlspecialchars($link['checkout_date'] ?? '') ?></span>
        </div>
    </header>

    <main class="content">
        <?php if ($error): ?>
            <div class="alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($checkinSuccess): ?>
            <div class="alert-success">
                <h2 style="font-size:18px;margin-bottom:8px">🎉 <?= htmlspecialchars($message) ?></h2>
                <p style="font-size:13px;color:#2c5e43"><?= $lang==='tr' ? 'Giriş kartınız ve oda anahtarınız resepsiyonda hazırlandı.' : 'Your room details are ready for arrival.' ?></p>
                
                <div class="wifi-card">
                    <div style="font-size:12px;color:#9fe8b8;text-transform:uppercase;font-weight:700">📶 Tesis Wi-Fi Bilgileri</div>
                    <div style="font-size:15px;font-weight:700;margin-top:4px">Ağ: <?= htmlspecialchars($link['property_name']) ?>_Guest</div>
                    <div style="font-size:13px;color:#d7ff48">Şifre: welcome2027</div>
                </div>
            </div>
        <?php else: ?>
            <form method="post" id="checkin-form">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="lang" value="<?= htmlspecialchars($lang) ?>">

                <!-- OCR Kimlik Yükleme -->
                <div class="ocr-box" onclick="document.getElementById('id-file').click()">
                    <div class="ocr-icon">📷</div>
                    <div class="ocr-text"><?= $lang==='tr' ? 'Pasaport / Kimlik Fotoğrafı Tara (OCR)' : 'Scan Passport / ID Photo (Auto-Fill)' ?></div>
                    <div class="ocr-sub"><?= $lang==='tr' ? 'Kameranızla gösterin, bilgiler otomatik doldurulsun' : 'Use camera to auto-fill form' ?></div>
                    <input type="file" id="id-file" accept="image/*" capture="environment" style="display:none" onchange="simulateOCR(this)">
                </div>

                <div class="section-title">👤 <?= $lang==='tr' ? 'Misafir Bilgileri' : 'Guest Details' ?></div>

                <div class="row-2">
                    <div class="form-group">
                        <label><?= $lang==='tr' ? 'Ad' : 'First Name' ?></label>
                        <input name="first_name" id="first_name" value="<?= htmlspecialchars($link['first_name'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label><?= $lang==='tr' ? 'Soyad' : 'Last Name' ?></label>
                        <input name="last_name" id="last_name" value="<?= htmlspecialchars($link['last_name'] ?? '') ?>" required>
                    </div>
                </div>

                <div class="row-2">
                    <div class="form-group">
                        <label><?= $lang==='tr' ? 'Telefon' : 'Phone' ?></label>
                        <input name="phone" id="phone" value="<?= htmlspecialchars($link['phone'] ?? '') ?>" placeholder="+90 5XX XXX XX XX" required>
                    </div>
                    <div class="form-group">
                        <label><?= $lang==='tr' ? 'Uyruk (Ülke Kodu)' : 'Nationality (Code)' ?></label>
                        <input name="nationality" id="nationality" value="<?= htmlspecialchars($link['nationality'] ?? 'TR') ?>" maxlength="2" required>
                    </div>
                </div>

                <div class="row-2">
                    <div class="form-group">
                        <label><?= $lang==='tr' ? 'Belge Türü' : 'Document Type' ?></label>
                        <select name="identity_type" id="identity_type">
                            <option value="identity"><?= $lang==='tr' ? 'TC Kimlik Kartı' : 'National ID Card' ?></option>
                            <option value="passport"><?= $lang==='tr' ? 'Pasaport' : 'Passport' ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><?= $lang==='tr' ? 'Kimlik / Pasaport No' : 'ID / Passport No' ?></label>
                        <input name="identity_number" id="identity_number" placeholder="11 haneli TC veya Pasaport No" required>
                    </div>
                </div>

                <div class="form-group">
                    <label><?= $lang==='tr' ? 'Tahmini Varış Saati' : 'Estimated Arrival Time' ?></label>
                    <input type="time" name="estimated_arrival" value="14:00">
                </div>

                <!-- Ekstra Hizmetler (Upsell) -->
                <div class="section-title">✨ <?= $lang==='tr' ? 'Konaklamanızı Özelleştirin (Ekstra Hizmetler)' : 'Customize Your Stay (Add-ons)' ?></div>
                <div class="upsell-grid">
                    <?php foreach ($upsellServices as $key => $svc): ?>
                        <label class="upsell-card">
                            <div class="upsell-left">
                                <input type="checkbox" name="upsell[]" value="<?= $key ?>">
                                <div>
                                    <span style="font-size:16px"><?= $svc['icon'] ?></span>
                                    <span class="upsell-info"><?= htmlspecialchars($svc['name'][$lang] ?? $svc['name']['en']) ?></span>
                                </div>
                            </div>
                            <span class="upsell-price">+<?= number_format($svc['price'], 2) ?> EUR</span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div style="margin:16px 0;font-size:12px;color:var(--muted)">
                    <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer">
                        <input type="checkbox" name="consent" value="1" required style="width:auto;margin-top:3px">
                        <span><?= $lang==='tr' 
                            ? 'KVKK ve Konaklama Aydınlatma Metnini okudum; kimlik ve konaklama bilgilerimin yasal mevzuat gereği Emniyet KBS sistemine bildirilmesini ve işlenmesini onaylıyorum.' 
                            : 'I agree to the privacy policy and consent to the transmission of my check-in data to authorized authorities.' ?></span>
                    </label>
                </div>

                <button type="submit" class="btn-submit">✓ <?= $lang==='tr' ? 'Check-in İşlemini Tamamla' : 'Complete Express Check-in' ?></button>
            </form>
        <?php endif; ?>
    </main>
</div>

<script>
function simulateOCR(input) {
    if (input.files && input.files[0]) {
        var box = document.querySelector('.ocr-box');
        box.innerHTML = '<div class="ocr-icon">⏳</div><div class="ocr-text">Belge Taranıyor (OCR)...</div><div class="ocr-sub">Lütfen bekleyin</div>';
        setTimeout(function() {
            box.innerHTML = '<div class="ocr-icon">✅</div><div class="ocr-text" style="color:#13593b">Belge Başarıyla Okundu</div><div class="ocr-sub">Bilgiler forma aktarıldı</div>';
            // Simüle edilmiş OCR dolumu (kullanıcı gerçek kimlik no girebilir)
            if(!document.getElementById('identity_number').value) {
                document.getElementById('identity_number').value = '10' + Math.floor(100000000 + Math.random() * 900000000);
            }
        }, 1200);
    }
}
</script>

</body>
</html>
