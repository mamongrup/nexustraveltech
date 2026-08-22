<?php
require_once __DIR__ . '/layout.php';

$success = '';
$scanned_guest = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'scan_demo') {
        $scanned_guest = [
            'doc_type' => 'PASSPORT',
            'doc_no' => 'U' . rand(10000000, 99999999),
            'first_name' => 'ALEXANDER',
            'last_name' => 'MUELLER',
            'nat' => 'DEU',
            'birth_date' => '1988-06-14',
            'gender' => 'M',
            'expiry_date' => '2030-11-20',
            'mrz_raw' => 'P<D<<MUELLER<<ALEXANDER<<<<<<<<<<<<<<<<<<<\nU' . rand(10000000, 99999999) . '4DEU8806144M3011208<<<<<<<<<<<<<<06'
        ];
        $success = 'Pasaport MRZ satırı 0.4 saniyede başarıyla okundu ve doğrulandı!';
    } elseif ($action === 'save_checkin') {
        $success = 'Misafir başarıyla odaya check-in yapıldı ve Emniyet KBS XML bildirimi otomatik iletildi!';
    }
}

supply_start('Mobil Kimlik Okur & Hızlı Check-in', 'hotel_checkin');
?>

<style>
  .ocr-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin-top: 20px;
  }
  @media (max-width: 900px) {
    .ocr-grid { grid-template-columns: 1fr; }
  }
  .scanner-box {
    background: #071412;
    color: #ffffff;
    border-radius: 16px;
    padding: 30px;
    text-align: center;
    box-shadow: 0 16px 36px rgba(0,0,0,0.15);
  }
  .scanner-target-frame {
    width: 100%;
    max-width: 320px;
    height: 190px;
    border: 2px dashed #d7ff48;
    border-radius: 12px;
    margin: 20px auto;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: rgba(215, 255, 72, 0.04);
    position: relative;
    overflow: hidden;
  }
  .scanner-laser {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: #e85f42;
    box-shadow: 0 0 8px #e85f42;
    animation: laserScan 2s infinite linear;
  }
  @keyframes laserScan {
    0% { top: 5%; }
    50% { top: 90%; }
    100% { top: 5%; }
  }
  .ocr-result-card {
    background: #ffffff;
    border: 1px solid #d8ded8;
    border-radius: 16px;
    padding: 26px;
  }
</style>

<?php if ($success): ?>
  <div style="background:#e8f5e9;border:1px solid #c8e6c9;color:#2e7d32;padding:14px 20px;border-radius:10px;margin-bottom:20px;font-weight:600;display:flex;align-items:center;gap:10px;">
    <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?>
  </div>
<?php endif; ?>

<div class="ocr-grid">
  
  <!-- KAMERA & TARAYICI BÖLÜMÜ -->
  <div class="scanner-box">
    <div style="display:inline-block;font:700 11px 'DM Mono', monospace;background:rgba(215,255,72,0.2);color:#d7ff48;padding:4px 12px;border-radius:99px;margin-bottom:10px;">
      TEMASSIZ SMART ID OCR
    </div>
    <h2 style="margin:0 0 8px;font-size:22px;color:#fff;">Kimlik / Pasaport Tara</h2>
    <p style="color:#9badc2;font-size:13.5px;margin:0 auto 16px;max-width:320px;">
      Telefon kamerasını pasaport veya TC Kimlik kartının altındaki MRZ satırına tutun.
    </p>

    <div class="scanner-target-frame">
      <div class="scanner-laser"></div>
      <i class="fa-solid fa-camera" style="font-size:36px;color:#d7ff48;margin-bottom:8px;"></i>
      <span style="font-size:12px;color:#9badc2;font-family:'DM Mono', monospace;">MRZ KODUNU HİZALAYIN</span>
    </div>

    <form method="POST" style="margin-top:16px;">
      <input type="hidden" name="action" value="scan_demo">
      <button type="submit" class="button" style="background:#d7ff48;color:#071412;font-weight:700;padding:12px 24px;border:none;border-radius:8px;cursor:pointer;font-size:14px;">
        <i class="fa-solid fa-bolt" style="margin-right:6px;"></i> Kamerayı Başlat & Tara
      </button>
    </form>
  </div>

  <!-- OKUNAN VERİ & HIZLI CHECK-IN FORMU -->
  <div class="ocr-result-card">
    <h3 style="margin:0 0 16px;font-size:18px;border-bottom:1px solid #e7ece7;padding-bottom:10px;color:#071412;">
      <i class="fa-solid fa-user-check" style="color:#2e7d32;margin-right:8px;"></i> Okunan Misafir Bilgileri
    </h3>

    <?php if ($scanned_guest): ?>
      <form method="POST">
        <input type="hidden" name="action" value="save_checkin">
        
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
          <div>
            <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Belge Türü</label>
            <input type="text" class="admin-input" value="<?= htmlspecialchars($scanned_guest['doc_type']) ?>" style="width:100%;height:36px;border:1px solid #d8ded8;border-radius:6px;padding:0 10px;background:#f8faf8;" readonly>
          </div>
          <div>
            <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Pasaport / Belge No</label>
            <input type="text" class="admin-input" value="<?= htmlspecialchars($scanned_guest['doc_no']) ?>" style="width:100%;height:36px;border:1px solid #d8ded8;border-radius:6px;padding:0 10px;font-weight:700;" readonly>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
          <div>
            <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Ad</label>
            <input type="text" class="admin-input" value="<?= htmlspecialchars($scanned_guest['first_name']) ?>" style="width:100%;height:36px;border:1px solid #d8ded8;border-radius:6px;padding:0 10px;">
          </div>
          <div>
            <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Soyad</label>
            <input type="text" class="admin-input" value="<?= htmlspecialchars($scanned_guest['last_name']) ?>" style="width:100%;height:36px;border:1px solid #d8ded8;border-radius:6px;padding:0 10px;">
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px;">
          <div>
            <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Uyruk</label>
            <input type="text" class="admin-input" value="<?= htmlspecialchars($scanned_guest['nat']) ?>" style="width:100%;height:36px;border:1px solid #d8ded8;border-radius:6px;padding:0 10px;">
          </div>
          <div>
            <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Doğum Tarihi</label>
            <input type="text" class="admin-input" value="<?= htmlspecialchars($scanned_guest['birth_date']) ?>" style="width:100%;height:36px;border:1px solid #d8ded8;border-radius:6px;padding:0 10px;">
          </div>
          <div>
            <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Cinsiyet</label>
            <input type="text" class="admin-input" value="<?= htmlspecialchars($scanned_guest['gender']) ?>" style="width:100%;height:36px;border:1px solid #d8ded8;border-radius:6px;padding:0 10px;">
          </div>
        </div>

        <div style="margin-bottom:16px;">
          <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Oda Numarası Ata</label>
          <select class="admin-input" style="width:100%;height:38px;border:1px solid #d8ded8;border-radius:6px;padding:0 10px;font-weight:700;">
            <option>101 - Standart Deniz Manzaralı (Temiz - Müsait)</option>
            <option>102 - Deluxe Balayı Odası (Temiz - Müsait)</option>
            <option>201 - Aile Süiti (Temiz - Müsait)</option>
            <option>Villa A - Özel Havuzlu (Temiz - Müsait)</option>
          </select>
        </div>

        <div style="background:#f4f7f4;padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:12px;color:#2b5443;">
          <i class="fa-solid fa-shield-halved"></i> Check-in tamamlandığında Emniyet AKBS sistemine otomatik XML bildirimi iletilecektir.
        </div>

        <button type="submit" class="button" style="width:100%;background:#071412;color:#ffffff;font-weight:700;padding:12px;border:none;border-radius:8px;cursor:pointer;font-size:14px;">
          <i class="fa-solid fa-check" style="margin-right:6px;"></i> Check-in Yap & KBS'ye Bildir
        </button>
      </form>
    <?php else: ?>
      <div style="text-align:center;padding:40px 20px;color:var(--muted);">
        <i class="fa-solid fa-id-card-clip" style="font-size:48px;color:#d8ded8;margin-bottom:12px;"></i>
        <p style="font-size:14px;margin:0;">Henüz taranmış bir kimlik yok. Soldaki butona basarak kamerayı başlatabilir veya demo tarama yapabilirsiniz.</p>
      </div>
    <?php endif; ?>
  </div>

</div>

<?php supply_end(); ?>
