<?php
require_once __DIR__ . '/auth.php';
require_admin_login();

$active_page = 'entegrasyonlar';
$page_title = 'Donanım & Entegrasyonlar Merkezi';

$success = '';
$error = '';

// Form kaydetme simülasyonu
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $success = 'Entegrasyon ayarları başarıyla güncellendi ve test bağlantısı başarılı oldu!';
}

require_once __DIR__ . '/layout.php';
?>

<div class="admin-header-actions" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
  <div>
    <h1 style="font-size:24px;font-weight:800;color:var(--ink);margin:0 0 4px;">Donanım & Entegrasyonlar Merkezi</h1>
    <p style="font-size:14px;color:var(--muted);margin:0;">Kapı kilit, telefon santrali, VRF klima, hotspot, turnike ve bakanlık entegrasyonlarını yönetin.</p>
  </div>
  <div>
    <a href="/nexustraveltech/fiyat-listesi" target="_blank" class="button button-dark" style="font-size:13px;padding:8px 16px;">
      <i class="fa-solid fa-calculator" style="margin-right:6px;"></i> Fiyat Listesi & Modül Sihirbazı
    </a>
  </div>
</div>

<?php if ($success): ?>
  <div style="background:#e8f5e9;border:1px solid #c8e6c9;color:#2e7d32;padding:12px 18px;border-radius:8px;margin-bottom:20px;font-size:14px;display:flex;align-items:center;gap:10px;">
    <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?>
  </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(320px, 1fr));gap:20px;">

  <!-- 1. KAPI KİLİT ENTEGRASYONU -->
  <div class="admin-card" style="background:#fff;border:1px solid var(--line);border-radius:12px;padding:20px;">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
      <div style="width:40px;height:40px;border-radius:10px;background:#e8f5e9;color:#2e7d32;display:grid;place-items:center;font-size:18px;">
        <i class="fa-solid fa-key"></i>
      </div>
      <div>
        <h3 style="margin:0;font-size:16px;font-weight:700;">Kapı Kilit Entegrasyonu</h3>
        <small style="color:var(--muted);">VingCard, Salto, Adel, Tesa, Onity</small>
      </div>
    </div>
    <form method="POST">
      <div style="margin-bottom:12px;">
        <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Kilit Sistemi Sağlayıcısı</label>
        <select class="admin-input" style="width:100%;height:38px;border:1px solid var(--line);border-radius:6px;padding:0 10px;">
          <option value="vingcard">VingCard / Assa Abloy (TCP/IP)</option>
          <option value="salto">Salto Space Pro</option>
          <option value="adel">Adel Lock System</option>
          <option value="tesa">Tesa Hotel Lock</option>
        </select>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
        <div>
          <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Sunucu IP</label>
          <input type="text" class="admin-input" value="192.168.1.120" style="width:100%;height:38px;border:1px solid var(--line);border-radius:6px;padding:0 10px;">
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Port</label>
          <input type="text" class="admin-input" value="3001" style="width:100%;height:38px;border:1px solid var(--line);border-radius:6px;padding:0 10px;">
        </div>
      </div>
      <button type="submit" class="button button-dark" style="width:100%;padding:10px;font-size:13px;">Bağlantıyı Test Et & Kaydet</button>
    </form>
  </div>

  <!-- 2. HOTSPOT & 5651 LOGLAMA -->
  <div class="admin-card" style="background:#fff;border:1px solid var(--line);border-radius:12px;padding:20px;">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
      <div style="width:40px;height:40px;border-radius:10px;background:#e0f2f1;color:#00796b;display:grid;place-items:center;font-size:18px;">
        <i class="fa-solid fa-wifi"></i>
      </div>
      <div>
        <h3 style="margin:0;font-size:16px;font-weight:700;">Hotspot & 5651 Loglama</h3>
        <small style="color:var(--muted);">Mikrotik Router & Zaman Damgası</small>
      </div>
    </div>
    <form method="POST">
      <div style="margin-bottom:12px;">
        <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Mikrotik IP & API Port</label>
        <input type="text" class="admin-input" value="192.168.88.1:8728" style="width:100%;height:38px;border:1px solid var(--line);border-radius:6px;padding:0 10px;">
      </div>
      <div style="margin-bottom:12px;">
        <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Doğrulama Yöntemi</label>
        <select class="admin-input" style="width:100%;height:38px;border:1px solid var(--line);border-radius:6px;padding:0 10px;">
          <option>Oda Numarası + TCKN / Pasaport</option>
          <option>SMS Doğrulama Kodu (OTP)</option>
          <option>Oda No + Doğum Yılı</option>
        </select>
      </div>
      <button type="submit" class="button button-dark" style="width:100%;padding:10px;font-size:13px;">Hotspot Senkronize Et</button>
    </form>
  </div>

  <!-- 3. TELEFON SANTRALİ ENTEGRASYONU -->
  <div class="admin-card" style="background:#fff;border:1px solid var(--line);border-radius:12px;padding:20px;">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
      <div style="width:40px;height:40px;border-radius:10px;background:#ede7f6;color:#512da8;display:grid;place-items:center;font-size:18px;">
        <i class="fa-solid fa-phone-volume"></i>
      </div>
      <div>
        <h3 style="margin:0;font-size:16px;font-weight:700;">Santral Entegrasyonu</h3>
        <small style="color:var(--muted);">Telesis, Karel, Cisco, Asterisk IP PBX</small>
      </div>
    </div>
    <form method="POST">
      <div style="margin-bottom:12px;">
        <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Santral Markası</label>
        <select class="admin-input" style="width:100%;height:38px;border:1px solid var(--line);border-radius:6px;padding:0 10px;">
          <option>Telesis PX24 / X1</option>
          <option>Karel DS200 / IPV</option>
          <option>Asterisk / FreePBX SIP</option>
          <option>Cisco Unified Communications</option>
        </select>
      </div>
      <div style="margin-bottom:12px;">
        <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Görüşme Ücretlendirme (Tarife)</label>
        <input type="text" class="admin-input" value="Şehirlerarası: 1.5 TL/dk, Yurtdışı: 8 TL/dk" style="width:100%;height:38px;border:1px solid var(--line);border-radius:6px;padding:0 10px;">
      </div>
      <button type="submit" class="button button-dark" style="width:100%;padding:10px;font-size:13px;">Santral Test Et</button>
    </form>
  </div>

  <!-- 4. KTM BAKANLIK TURİZM VERİTABANI -->
  <div class="admin-card" style="background:#fff;border:1px solid var(--line);border-radius:12px;padding:20px;">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
      <div style="width:40px;height:40px;border-radius:10px;background:#fff3e0;color:#e65100;display:grid;place-items:center;font-size:18px;">
        <i class="fa-solid fa-landmark"></i>
      </div>
      <div>
        <h3 style="margin:0;font-size:16px;font-weight:700;">KTM Turizm Veritabanı & TGA</h3>
        <small style="color:var(--muted);">Kültür ve Turizm Bakanlığı Entegrasyonu</small>
      </div>
    </div>
    <form method="POST">
      <div style="margin-bottom:12px;">
        <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Tesis Belge No</label>
        <input type="text" class="admin-input" value="TR-ANT-2024-8841" style="width:100%;height:38px;border:1px solid var(--line);border-radius:6px;padding:0 10px;">
      </div>
      <div style="margin-bottom:12px;">
        <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Bakanlık Web Servis Kullanıcı / Şifre</label>
        <input type="password" class="admin-input" value="********" style="width:100%;height:38px;border:1px solid var(--line);border-radius:6px;padding:0 10px;">
      </div>
      <button type="submit" class="button button-dark" style="width:100%;padding:10px;font-size:13px;">Bakanlık Bağlantısını Doğrula</button>
    </form>
  </div>

  <!-- 5. ISITMA / SOĞUTMA (VRF KLİMA) ENTEGRASYONU -->
  <div class="admin-card" style="background:#fff;border:1px solid var(--line);border-radius:12px;padding:20px;">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
      <div style="width:40px;height:40px;border-radius:10px;background:#e1f5fe;color:#0288d1;display:grid;place-items:center;font-size:18px;">
        <i class="fa-solid fa-snowflake"></i>
      </div>
      <div>
        <h3 style="margin:0;font-size:16px;font-weight:700;">VRF Klima & Enerji Otomasyonu</h3>
        <small style="color:var(--muted);">Daikin, Mitsubishi, LG, VRF Bacnet</small>
      </div>
    </div>
    <form method="POST">
      <div style="margin-bottom:12px;">
        <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Protokol</label>
        <select class="admin-input" style="width:100%;height:38px;border:1px solid var(--line);border-radius:6px;padding:0 10px;">
          <option>BACnet IP</option>
          <option>Modbus TCP</option>
          <option>KNX Gateway</option>
        </select>
      </div>
      <div style="margin-bottom:12px;">
        <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Otomasyon Kuralı</label>
        <select class="admin-input" style="width:100%;height:38px;border:1px solid var(--line);border-radius:6px;padding:0 10px;">
          <option>Check-in: 23°C Başlat / Check-out: Kapat</option>
          <option>Balkon Kapısı Açılınca Klimayı Durdur</option>
        </select>
      </div>
      <button type="submit" class="button button-dark" style="width:100%;padding:10px;font-size:13px;">Enerji Otomasyonunu Kaydet</button>
    </form>
  </div>

  <!-- 6. TURNİKE & GEÇİŞ KONTROL -->
  <div class="admin-card" style="background:#fff;border:1px solid var(--line);border-radius:12px;padding:20px;">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
      <div style="width:40px;height:40px;border-radius:10px;background:#fce4ec;color:#c2185b;display:grid;place-items:center;font-size:18px;">
        <i class="fa-solid fa-person-walking-dashed-line-arrow-right"></i>
      </div>
      <div>
        <h3 style="margin:0;font-size:16px;font-weight:700;">Turnike & Geçiş Kontrol</h3>
        <small style="color:var(--muted);">SPA, Havuz, Yemekhane ve Personel Geçiş</small>
      </div>
    </div>
    <form method="POST">
      <div style="margin-bottom:12px;">
        <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Turnike Terminal IP</label>
        <input type="text" class="admin-input" value="192.168.1.150" style="width:100%;height:38px;border:1px solid var(--line);border-radius:6px;padding:0 10px;">
      </div>
      <div style="margin-bottom:12px;">
        <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Yetki Alanı</label>
        <select class="admin-input" style="width:100%;height:38px;border:1px solid var(--line);border-radius:6px;padding:0 10px;">
          <option>Her Şey Dahil (Bileklik / Oda Kartı)</option>
          <option>SPA Üyesi (Kontör / Seans)</option>
          <option>Personel PDKS (Giriş/Çıkış)</option>
        </select>
      </div>
      <button type="submit" class="button button-dark" style="width:100%;padding:10px;font-size:13px;">Turnike Entegrasyonunu Kaydet</button>
    </form>
  </div>

</div>
