<?php
$active_page = 'pricing';
$seo_page = 'pricing';
require_once __DIR__ . '/config/i18n.php';
$current_lang = readiness_lang();
?>
<!doctype html>
<html lang="<?= htmlspecialchars($current_lang) ?>" dir="<?= $current_lang === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>NEXUS Modül Seçim & Fiyatlandırma Sihirbazı</title>
  <?php require __DIR__ . '/partials/seo.php'; ?>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500;600&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&family=Playfair+Display:ital,wght@0,600;0,700;1,600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
  <link rel="stylesheet" href="/nexustraveltech/assets/styles.css?v=<?= filemtime(__DIR__ . '/assets/styles.css') ?>" />
  <style>
    .pricing-app-hero {
      padding: 60px 0 35px;
      text-align: center;
      background: linear-gradient(180deg, #f7f7f2 0%, #ffffff 100%);
      border-bottom: 1px solid #e7ece7;
    }
    .pricing-app-hero h1 {
      font-size: clamp(32px, 4.5vw, 50px);
      letter-spacing: -0.04em;
      line-height: 1.15;
      margin: 14px auto 10px;
      max-width: 860px;
    }
    .pricing-app-hero p {
      max-width: 680px;
      margin: 0 auto;
      font-size: 16.5px;
      color: var(--muted);
    }
    .pricing-layout {
      display: grid;
      grid-template-columns: 1fr 350px;
      gap: 32px;
      padding: 40px 0 80px;
      align-items: start;
    }
    @media (max-width: 992px) {
      .pricing-layout { grid-template-columns: 1fr; }
    }
    .cat-accordion {
      margin-bottom: 18px;
      border: 1px solid #e2e8e2;
      border-radius: 12px;
      overflow: hidden;
      background: #ffffff;
    }
    .cat-header {
      background: #f8faf8;
      padding: 16px 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      cursor: pointer;
      user-select: none;
      border-bottom: 1px solid transparent;
      transition: all 0.2s;
    }
    .cat-header.active {
      border-bottom-color: #e2e8e2;
    }
    .cat-header.hotel-hdr { border-left: 5px solid #2e7d32; }
    .cat-header.erp-hdr { border-left: 5px solid #1565c0; }
    .cat-header.pos-hdr { border-left: 5px solid #e65100; }
    .cat-header.integ-hdr { border-left: 5px solid #00838f; }
    .cat-header.hard-hdr { border-left: 5px solid #455a64; }
    .cat-header.other-hdr { border-left: 5px solid #7b1fa2; }
    .cat-header-title {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 16px;
      font-weight: 700;
      color: var(--ink);
    }
    .cat-count {
      font: 600 11px "DM Mono", monospace;
      padding: 3px 8px;
      border-radius: 99px;
      background: #e7ece7;
      color: #3b4e49;
    }
    .module-item {
      padding: 12px 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 1px solid #f1f4f1;
      transition: background 0.15s;
    }
    .module-item:last-child { border-bottom: none; }
    .module-item:hover { background: #fafcfa; }
    .module-left {
      display: flex;
      align-items: center;
      gap: 12px;
      flex: 1;
      cursor: pointer;
    }
    .module-left input[type="checkbox"] {
      width: 18px;
      height: 18px;
      cursor: pointer;
      accent-color: var(--ink);
    }
    .module-info h4 {
      margin: 0;
      font-size: 14px;
      font-weight: 600;
      color: #1a2a27;
    }
    .module-info small {
      color: #72827e;
      font-size: 12px;
      display: block;
      margin-top: 2px;
    }
    .module-right {
      display: flex;
      align-items: center;
      gap: 14px;
    }
    .module-price {
      font: 700 14px "DM Mono", monospace;
      color: #071412;
      min-width: 70px;
      text-align: right;
    }
    .summary-card {
      position: sticky;
      top: 90px;
      background: #071412;
      color: #ffffff;
      border-radius: 16px;
      padding: 24px;
      box-shadow: 0 20px 40px rgba(7, 20, 18, 0.18);
    }
    .summary-card h3 {
      margin: 0 0 14px;
      font-size: 19px;
      letter-spacing: -0.02em;
      border-bottom: 1px solid rgba(255, 255, 255, 0.14);
      padding-bottom: 10px;
    }
    .summary-row {
      display: flex;
      justify-content: space-between;
      font-size: 13px;
      color: #9badc2;
      margin-bottom: 8px;
    }
    .summary-total {
      margin-top: 18px;
      border-top: 2px solid rgba(255, 255, 255, 0.18);
      padding-top: 14px;
      display: flex;
      justify-content: space-between;
      align-items: baseline;
    }
    .summary-total span { font-size: 14px; font-weight: 600; }
    .summary-total b { font: 800 26px "DM Mono", monospace; color: #d7ff48; }
    .btn-quote {
      width: 100%;
      margin-top: 18px;
      padding: 13px;
      background: #d7ff48;
      color: #071412;
      border: none;
      border-radius: 8px;
      font: 700 14.5px "DM Sans", sans-serif;
      cursor: pointer;
      transition: opacity 0.2s;
    }
    .btn-quote:hover { opacity: 0.92; }
    .quote-note {
      font-size: 11px;
      color: #7b91a8;
      text-align: center;
      margin-top: 10px;
      line-height: 1.4;
    }
  </style>
</head>
<body class="inner-page">
  <main>
    <?php require __DIR__ . '/partials/header.php'; ?>

    <section class="pricing-app-hero">
      <div class="shell">
        <div class="eyebrow"><i></i> TÜM MODÜL, ENTEGRASYON VE DONANIMLAR</div>
        <h1>NEXUS Modül Seçim & Fiyatlandırma Sihirbazı</h1>
        <p>Otel, POS, ERP, Donanım ve Entegrasyon modüllerini dilediğiniz gibi seçin, anında şeffaf fiyatlandırın.</p>
      </div>
    </section>

    <div class="shell">
      <div class="pricing-layout">
        
        <!-- MODÜL SEÇİM LİSTESİ -->
        <div class="modules-container">
          
          <!-- 1. OTEL MODÜLLERİ (23 Modül) -->
          <div class="cat-accordion">
            <div class="cat-header hotel-hdr active" onclick="toggleAcc(this)">
              <div class="cat-header-title">
                <i class="fa-solid fa-hotel" style="color:#2e7d32"></i>
                <span>Otel ve Konaklama Modülleri</span>
                <span class="cat-count">23 Modül</span>
              </div>
              <i class="fa-solid fa-chevron-down"></i>
            </div>
            <div class="cat-body">
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="45" onchange="calcTotal()"><div class="module-info"><h4>NEXUS Ön Büro Yönetimi Yazılımı (PMS)</h4><small>Oda planı, folyo, check-in/out, günlük durum</small></div></label><div class="module-right"><span class="module-price">€45 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="35" onchange="calcTotal()"><div class="module-info"><h4>Kanal Yönetimi & Online Rezervasyon Yazılımı</h4><small>2-Way OTA entegrasyonu & web rezervasyon motoru</small></div></label><div class="module-right"><span class="module-price">€35 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="20" onchange="calcTotal()"><div class="module-info"><h4>E-Uygulama Yazılımı (e-Fatura / e-Arşiv)</h4><small>GİB entegratörleriyle anlık resmi faturalandırma</small></div></label><div class="module-right"><span class="module-price">€20 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="15" onchange="calcTotal()"><div class="module-info"><h4>Sanal POS Entegrasyonu Yazılımı</h4><small>Tüm bankalarla 3D Secure güvenli tahsilat</small></div></label><div class="module-right"><span class="module-price">€15 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="25" onchange="calcTotal()"><div class="module-info"><h4>Mobil Kimlik Okur ve Check-in Standart</h4><small>Aylık 1.000 kimlik & pasaport OCR okuma</small></div></label><div class="module-right"><span class="module-price">€25 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="45" onchange="calcTotal()"><div class="module-info"><h4>Mobil Kimlik Okur ve Check-in Pro</h4><small>Sınırsız OCR pasaport/kimlik okuma & hızlı check-in</small></div></label><div class="module-right"><span class="module-price">€45 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="18" onchange="calcTotal()"><div class="module-info"><h4>Görev Yönetimi & Kat Hizmetleri (HK)</h4><small>Oda temizlik durumları, arıza bildirimleri ve iş atamaları</small></div></label><div class="module-right"><span class="module-price">€18 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="22" onchange="calcTotal()"><div class="module-info"><h4>CRM ve Sadakat Yazılımı</h4><small>Misafir geçmişi, VIP yönetimi ve sadakat puanları</small></div></label><div class="module-right"><span class="module-price">€22 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="15" onchange="calcTotal()"><div class="module-info"><h4>NEXUS Manager (Yönetici Mobil Uygulaması)</h4><small>Anlık doluluk, ciro ve operasyonel bildirimler</small></div></label><div class="module-right"><span class="module-price">€15 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="15" onchange="calcTotal()"><div class="module-info"><h4>NEXUS Operator (Operasyon Mobil Uygulaması)</h4><small>Resepsiyon ve saha personeli hızlı kontrol paneli</small></div></label><div class="module-right"><span class="module-price">€15 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="30" onchange="calcTotal()"><div class="module-info"><h4>Standart Web Sitesi Yazılımı</h4><small>Otel için modern, SEO ve mobil uyumlu web sitesi</small></div></label><div class="module-right"><span class="module-price">€30 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="60" onchange="calcTotal()"><div class="module-info"><h4>Özel Tasarım Web Sitesi & Bilet Portalı</h4><small>Özel tasarım arayüz ve deneyim bilet satış motoru</small></div></label><div class="module-right"><span class="module-price">€60 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="25" onchange="calcTotal()"><div class="module-info"><h4>Çağrı Merkezi Yazılımı</h4><small>Santral entegrasyonu ve telefonla anlık rezervasyon</small></div></label><div class="module-right"><span class="module-price">€25 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="20" onchange="calcTotal()"><div class="module-info"><h4>İtibar Yönetimi Yazılımı</h4><small>Google, TripAdvisor ve OTA misafir yorum analizi</small></div></label><div class="module-right"><span class="module-price">€20 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="35" onchange="calcTotal()"><div class="module-info"><h4>Misafir Mobil Uygulaması (Otele Özel)</h4><small>App Store ve Google Play'de otelinize özel uygulama</small></div></label><div class="module-right"><span class="module-price">€35 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="18" onchange="calcTotal()"><div class="module-info"><h4>Web Tabanlı Misafir Uygulaması (PWA)</h4><small>Uygulama indirmeden QR ile açılan misafir portalı</small></div></label><div class="module-right"><span class="module-price">€18 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="10" onchange="calcTotal()"><div class="module-info"><h4>Karbon Nötrleme Yazılımı</h4><small>Konaklama karbon ayak izi hesaplama ve yeşil otel sertifikası</small></div></label><div class="module-right"><span class="module-price">€10 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="20" onchange="calcTotal()"><div class="module-info"><h4>Kimlikokur Yazılımı & KBS Emniyet Sync</h4><small>Emniyet AKBS ve Jandarma anlık XML bildirimi</small></div></label><div class="module-right"><span class="module-price">€20 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="35" onchange="calcTotal()"><div class="module-info"><h4>Check-in Kiosk Yazılımı</h4><small>Resepsiyonda self-servis tablet ve dokunmatik ekran girişi</small></div></label><div class="module-right"><span class="module-price">€35 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="40" onchange="calcTotal()"><div class="module-info"><h4>Tur Operatörü Yazılımı</h4><small>Acentelere kontenjan tahsisi ve grup rezervasyonları</small></div></label><div class="module-right"><span class="module-price">€40 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="25" onchange="calcTotal()"><div class="module-info"><h4>Online Check-in Yazılımı</h4><small>Misafirin gelmeden önce link üzerinden form doldurması</small></div></label><div class="module-right"><span class="module-price">€25 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="30" onchange="calcTotal()"><div class="module-info"><h4>Dinamik Fiyatlandırma (AI Pricing Coach)</h4><small>Doluluk ve rakip analizli yapay zekâ fiyat optimizasyonu</small></div></label><div class="module-right"><span class="module-price">€30 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="20" onchange="calcTotal()"><div class="module-info"><h4>WhatsApp API & Otomatik Bildirim</h4><small>Rezervasyon ve konaklama bildirimleri WhatsApp hattından</small></div></label><div class="module-right"><span class="module-price">€20 /ay</span></div></div>
            </div>
          </div>

          <!-- 2. ERP & FİNANS (25 Modül) -->
          <div class="cat-accordion">
            <div class="cat-header erp-hdr" onclick="toggleAcc(this)">
              <div class="cat-header-title">
                <i class="fa-solid fa-file-invoice-dollar" style="color:#1565c0"></i>
                <span>ERP, Muhasebe ve İK Modülleri</span>
                <span class="cat-count">25 Modül</span>
              </div>
              <i class="fa-solid fa-chevron-down"></i>
            </div>
            <div class="cat-body" style="display:none">
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="35" onchange="calcTotal()"><div class="module-info"><h4>NEXUS Muhasebe Yazılımı</h4><small>Cari hesaplar, kasa/banka ve gelir-gider raporları</small></div></label><div class="module-right"><span class="module-price">€35 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="25" onchange="calcTotal()"><div class="module-info"><h4>Stok & Reçete Yazılımı</h4><small>Depo hareketleri, reçeteli hammadde ve porsiyon maliyeti</small></div></label><div class="module-right"><span class="module-price">€25 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="30" onchange="calcTotal()"><div class="module-info"><h4>İnsan Kaynakları & Bordro Yazılımı</h4><small>Personel özlük dosyaları, bordro ve izin yönetimi</small></div></label><div class="module-right"><span class="module-price">€30 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="20" onchange="calcTotal()"><div class="module-info"><h4>Satın Alma Yazılımı</h4><small>Talep onay süreçleri, teklif toplama ve sipariş fişleri</small></div></label><div class="module-right"><span class="module-price">€20 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="18" onchange="calcTotal()"><div class="module-info"><h4>Demirbaş & Zimmet Yazılımı</h4><small>Tesis demirbaş listesi, oda zimmetleri ve amortisman</small></div></label><div class="module-right"><span class="module-price">€18 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="25" onchange="calcTotal()"><div class="module-info"><h4>Üretim ve Maliyet Yazılımı</h4><small>Mutfak ve imalat reçeteleri, fire ve maliyet analizi</small></div></label><div class="module-right"><span class="module-price">€25 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="15" onchange="calcTotal()"><div class="module-info"><h4>Yedekleme PMS Yazılımı</h4><small>Günlük otomatik bulut ve veri tabanı yedekleme</small></div></label><div class="module-right"><span class="module-price">€15 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="15" onchange="calcTotal()"><div class="module-info"><h4>Yedekleme ERP Yazılımı</h4><small>Finans ve muhasebe kayıtları için anlık güvenli arşiv</small></div></label><div class="module-right"><span class="module-price">€15 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="40" onchange="calcTotal()"><div class="module-info"><h4>Premium Sunucu PMS Yazılımı</h4><small>Yüksek hızlı dedicated bulut sunucu tahsisi</small></div></label><div class="module-right"><span class="module-price">€40 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="40" onchange="calcTotal()"><div class="module-info"><h4>Premium Sunucu ERP Yazılımı</h4><small>İzole edilmiş yüksek güvenlikli ERP altyapısı</small></div></label><div class="module-right"><span class="module-price">€40 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="22" onchange="calcTotal()"><div class="module-info"><h4>Satış Pazarlama Yazılımı</h4><small>Lead takibi, sözleşmeler ve acente komisyon oranları</small></div></label><div class="module-right"><span class="module-price">€22 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="15" onchange="calcTotal()"><div class="module-info"><h4>İK Personel Portalı (Mobil Uygulama)</h4><small>Personel izin isteme, bordro görüntüleme ve vardiya</small></div></label><div class="module-right"><span class="module-price">€15 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="20" onchange="calcTotal()"><div class="module-info"><h4>QR / NFC PDKS Uygulaması</h4><small>Giriş-çıkış saatlerini mobil QR veya NFC ile okuma</small></div></label><div class="module-right"><span class="module-price">€20 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="25" onchange="calcTotal()"><div class="module-info"><h4>Denetim Yönetimi (Mobil Uygulama)</h4><small>Departman denetimleri, puanlama ve kontrol listeleri</small></div></label><div class="module-right"><span class="module-price">€25 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="35" onchange="calcTotal()"><div class="module-info"><h4>Okul / Yurt Yönetim Yazılımı</h4><small>Öğrenci oda tahsisi, aidat ve giriş-çıkış takibi</small></div></label><div class="module-right"><span class="module-price">€35 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="18" onchange="calcTotal()"><div class="module-info"><h4>Stok Mobil Uygulaması (Barkodlu)</h4><small>Depo sayımı ve transferlerini telefon kamerasıyla yapma</small></div></label><div class="module-right"><span class="module-price">€18 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="35" onchange="calcTotal()"><div class="module-info"><h4>Kalite ve Doküman Yönetimi Tüm Paket</h4><small>ISO standartları, talimatlar ve revizyon takibi</small></div></label><div class="module-right"><span class="module-price">€35 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="15" onchange="calcTotal()"><div class="module-info"><h4>DÖİF Yönetimi</h4><small>Düzeltici ve önleyici faaliyet takip modülü</small></div></label><div class="module-right"><span class="module-price">€15 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="18" onchange="calcTotal()"><div class="module-info"><h4>Demirbaş Mobil Uygulama (Barkodlu / RFID)</h4><small>RFID veya barkod ile hızlı oda demirbaş sayımı</small></div></label><div class="module-right"><span class="module-price">€18 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="25" onchange="calcTotal()"><div class="module-info"><h4>Market & Satış Noktası Yazılımı</h4><small>Otel içi market ve butik satışları için hızlı barkodlu POS</small></div></label><div class="module-right"><span class="module-price">€25 /ay</span></div></div>
            </div>
          </div>

          <!-- 3. RESTORAN & POS (7 Modül) -->
          <div class="cat-accordion">
            <div class="cat-header pos-hdr" onclick="toggleAcc(this)">
              <div class="cat-header-title">
                <i class="fa-solid fa-utensils" style="color:#e65100"></i>
                <span>Restoran, POS ve SPA Modülleri</span>
                <span class="cat-count">7 Modül</span>
              </div>
              <i class="fa-solid fa-chevron-down"></i>
            </div>
            <div class="cat-body" style="display:none">
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="35" onchange="calcTotal()"><div class="module-info"><h4>NEXUS POS Satış Programı</h4><small>Dokunmatik adisyon, masa krokisi, mutfak ekranı</small></div></label><div class="module-right"><span class="module-price">€35 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="25" onchange="calcTotal()"><div class="module-info"><h4>Yemeksepeti / Getir / Online Sipariş Entegrasyonu</h4><small>Dış siparişleri tek ekranda POS ve mutfağa aktarma</small></div></label><div class="module-right"><span class="module-price">€25 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="18" onchange="calcTotal()"><div class="module-info"><h4>Akıllı Dijital Menü (QR Menü)</h4><small>Çok dilli, fotoğraflı ve masadan sipariş özellikli</small></div></label><div class="module-right"><span class="module-price">€18 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="20" onchange="calcTotal()"><div class="module-info"><h4>Restoran & Masa Online Rezervasyon</h4><small>Alakart mekanlar için online masa ayırma motoru</small></div></label><div class="module-right"><span class="module-price">€20 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="25" onchange="calcTotal()"><div class="module-info"><h4>SPA, Masaj ve Spor Salonu Yönetimi</h4><small>Terapist randevuları, masaj odası blokajı ve üyelik</small></div></label><div class="module-right"><span class="module-price">€25 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="22" onchange="calcTotal()"><div class="module-info"><h4>Satış, Ziyafet ve Banket Yönetimi</h4><small>Düğün, toplantı salonu planı ve banket menüleri</small></div></label><div class="module-right"><span class="module-price">€22 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="15" onchange="calcTotal()"><div class="module-info"><h4>Odaya Folyo Aktarım Entegrasyonu</h4><small>Restoran ve SPA harcamalarını tek tıkla oda hesabına yazma</small></div></label><div class="module-right"><span class="module-price">€15 /ay</span></div></div>
            </div>
          </div>

          <!-- 4. ENTEGRASYONLAR (20 Modül) -->
          <div class="cat-accordion">
            <div class="cat-header integ-hdr" onclick="toggleAcc(this)">
              <div class="cat-header-title">
                <i class="fa-solid fa-plug-circle-bolt" style="color:#00838f"></i>
                <span>Entegrasyon ve API Çözümleri</span>
                <span class="cat-count">20 Modül</span>
              </div>
              <i class="fa-solid fa-chevron-down"></i>
            </div>
            <div class="cat-body" style="display:none">
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="25" onchange="calcTotal()"><div class="module-info"><h4>Hotspot Yazılımı & 5651 Loglama</h4><small>Oda no ve TCKN/Pasaport ile misafir Wi-Fi girişi</small></div></label><div class="module-right"><span class="module-price">€25 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="20" onchange="calcTotal()"><div class="module-info"><h4>WhatsApp Entegrasyonu Yazılımı</h4><small>Otomatik onay, yol tarifi ve anket mesajları</small></div></label><div class="module-right"><span class="module-price">€20 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="25" onchange="calcTotal()"><div class="module-info"><h4>Kapı Kilit Entegrasyonu (VingCard, Salto, Adel vb.)</h4><small>Resepsiyondan tek tıkla RFID kart ve mobil dijital anahtar kodlama</small></div></label><div class="module-right"><span class="module-price">€25 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="20" onchange="calcTotal()"><div class="module-info"><h4>IP / Pay TV Entegrasyonu</h4><small>Oda TV'sine misafir karşılama ve oda hesabı görüntüleme</small></div></label><div class="module-right"><span class="module-price">€20 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="30" onchange="calcTotal()"><div class="module-info"><h4>Yapay Zeka Entegrasyonu (İtibar & Analiz AI)</h4><small>OTA ve Google yorumlarını yapay zekâ ile anlama ve yanıtlama</small></div></label><div class="module-right"><span class="module-price">€30 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="25" onchange="calcTotal()"><div class="module-info"><h4>KTM API Entegrasyonu (Bakanlık Turizm Veritabanı)</h4><small>Kültür ve Turizm Bakanlığı Konaklama Merkezi Veritabanı aktarımı</small></div></label><div class="module-right"><span class="module-price">€25 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="20" onchange="calcTotal()"><div class="module-info"><h4>Telefon Santral Entegrasyonu (Telesis, Karel vb.)</h4><small>Oda telefon görüşmelerini otomatik folyoya yazma & uyandırma</small></div></label><div class="module-right"><span class="module-price">€20 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="25" onchange="calcTotal()"><div class="module-info"><h4>Isıtma / Soğutma (VRF Klima) Entegrasyonu</h4><small>Check-in olunca odayı iklimlendirme, check-out'ta enerji tasarrufu</small></div></label><div class="module-right"><span class="module-price">€25 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="20" onchange="calcTotal()"><div class="module-info"><h4>Turnike & Geçiş Kontrol Entegrasyonu</h4><small>SPA, havuz veya yemekhane geçiş turnike entegrasyonu</small></div></label><div class="module-right"><span class="module-price">€20 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="15" onchange="calcTotal()"><div class="module-info"><h4>Hotspot API & Dış Yazılım Bağlantısı</h4><small>Harici hotspot donanımlarının NEXUS veri tabanına erişimi</small></div></label><div class="module-right"><span class="module-price">€15 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="25" onchange="calcTotal()"><div class="module-info"><h4>Kommo & Harici CRM Entegrasyonu</h4><small>Potansiyel müşteri ve satış hunisi (pipeline) senkronizasyonu</small></div></label><div class="module-right"><span class="module-price">€25 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="20" onchange="calcTotal()"><div class="module-info"><h4>Sosyal Medya & Lead Entegrasyonu</h4><small>Instagram / Facebook reklamlarından gelen rezervasyon talepleri</small></div></label><div class="module-right"><span class="module-price">€20 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="35" onchange="calcTotal()"><div class="module-info"><h4>Web Servis & Açık REST API Yazılımı</h4><small>Otelinizin kendi yazılımları için tam kapsamlı JSON/REST API</small></div></label><div class="module-right"><span class="module-price">€35 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="20" onchange="calcTotal()"><div class="module-info"><h4>Banka Hesap Ekstresi & POS Entegrasyonu</h4><small>Banka hesap hareketlerinin anlık muhasebe fişine dönüşmesi</small></div></label><div class="module-right"><span class="module-price">€20 /ay</span></div></div>
            </div>
          </div>

          <!-- 5. DONANIM & KURULUM (10 Modül) -->
          <div class="cat-accordion">
            <div class="cat-header hard-hdr" onclick="toggleAcc(this)">
              <div class="cat-header-title">
                <i class="fa-solid fa-server" style="color:#455a64"></i>
                <span>Donanım ve Kurulum Paketleri</span>
                <span class="cat-count">10 Ürün</span>
              </div>
              <i class="fa-solid fa-chevron-down"></i>
            </div>
            <div class="cat-body" style="display:none">
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="150" onchange="calcTotal()"><div class="module-info"><h4>Tek Seferlik Kurulum ve Personel Eğitimi</h4><small>Tüm sistemin canlıya alınması, menü/oda yüklemesi ve eğitim</small></div></label><div class="module-right"><span class="module-price">€150 Tek</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="220" onchange="calcTotal()"><div class="module-info"><h4>Kimlikokur A6 Pasaport & Kimlik Tarayıcı</h4><small>Yüksek hızlı optik pasaport ve kimlik tarayıcı donanımı</small></div></label><div class="module-right"><span class="module-price">€220 Tek</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="140" onchange="calcTotal()"><div class="module-info"><h4>Kimlik Okur Swipe Cihazı (MRZ)</h4><small>Manyetik ve optik MRZ satır okuyucu cihaz</small></div></label><div class="module-right"><span class="module-price">€140 Tek</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="180" onchange="calcTotal()"><div class="module-info"><h4>Mikrotik Router 101 - 200 Kullanıcı (RB4011)</h4><small>Orta ölçekli tesisler için 5651 uyumlu hotspot router</small></div></label><div class="module-right"><span class="module-price">€180 Tek</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="350" onchange="calcTotal()"><div class="module-info"><h4>Mikrotik Router 201 - 500 Kullanıcı (RB1100AHx4)</h4><small>Büyük ölçekli tatil köyleri için kurumsal router</small></div></label><div class="module-right"><span class="module-price">€350 Tek</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="25" onchange="calcTotal()"><div class="module-info"><h4>Cloud Sunucu Hizmet Bedeli (Small / 50 Oda)</h4><small>Dedicated SSD bulut sunucu, günlük yedekleme</small></div></label><div class="module-right"><span class="module-price">€25 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="45" onchange="calcTotal()"><div class="module-info"><h4>Cloud Sunucu Hizmet Bedeli (Medium / 200 Oda)</h4><small>Yüksek RAM, CDN hızlandırmalı bulut sunucu</small></div></label><div class="module-right"><span class="module-price">€45 /ay</span></div></div>
            </div>
          </div>

          <!-- 6. DİĞER & SEKTÖREL ÇÖZÜMLER (10 Modül) -->
          <div class="cat-accordion">
            <div class="cat-header other-hdr" onclick="toggleAcc(this)">
              <div class="cat-header-title">
                <i class="fa-solid fa-shapes" style="color:#7b1fa2"></i>
                <span>Sektörel ve Özel Turizm Çözümleri</span>
                <span class="cat-count">10 Modül</span>
              </div>
              <i class="fa-solid fa-chevron-down"></i>
            </div>
            <div class="cat-body" style="display:none">
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="30" onchange="calcTotal()"><div class="module-info"><h4>SPA ve Kulüp Üyelik Yazılımı</h4><small>Üye kartları, turnike geçişleri ve kontörlü paketler</small></div></label><div class="module-right"><span class="module-price">€30 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="35" onchange="calcTotal()"><div class="module-info"><h4>Park & Aquapark Otomasyonu</h4><small>Bileklik / RFID ile bilet satışı ve turnike geçişi</small></div></label><div class="module-right"><span class="module-price">€35 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="25" onchange="calcTotal()"><div class="module-info"><h4>Ev Sahibi Portalı (Villa & Airbnb Owner)</h4><small>Mülk sahiplerinin kendi takvimlerini ve kazançlarını görmesi</small></div></label><div class="module-right"><span class="module-price">€25 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="30" onchange="calcTotal()"><div class="module-info"><h4>Devre Tatil & Devremülk Yönetimi</h4><small>Dönem takvimleri, aidat ve devir işlemleri</small></div></label><div class="module-right"><span class="module-price">€30 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="35" onchange="calcTotal()"><div class="module-info"><h4>Marina & Yat İşletim Sistemi</h4><small>Bağlama planı, çekek alanı ve su/elektrik sayaç okuma</small></div></label><div class="module-right"><span class="module-price">€35 /ay</span></div></div>
              <div class="module-item"><label class="module-left"><input type="checkbox" data-price="20" onchange="calcTotal()"><div class="module-info"><h4>TGA (Turizm Tanıtım) Bildirimi</h4><small>Aylık TGA payı resmi hesaplama ve beyanname</small></div></label><div class="module-right"><span class="module-price">€20 /ay</span></div></div>
            </div>
          </div>

        </div>

        <!-- SAĞ PANEL: CANLI TEKLİF ÖZETİ -->
        <div class="summary-card">
          <h3>Teklif Özeti</h3>
          <div class="summary-row">
            <span>Seçilen Modül Sayısı:</span>
            <strong id="selected-count" style="color:#ffffff">0</strong>
          </div>
          <div class="summary-row">
            <span>Para Birimi:</span>
            <strong style="color:#ffffff">EUR (€)</strong>
          </div>
          <div class="summary-row">
            <span>Bulut Altyapısı:</span>
            <strong style="color:#d7ff48">DAHİL</strong>
          </div>

          <div class="summary-total">
            <span>Tahmini Tutar:</span>
            <b id="total-price">€0</b>
          </div>

          <button class="btn-quote" onclick="openOfferModal()">Bu Paketi Seç & Demo İste →</button>
          <p class="quote-note">Yıllık ödemelerde %20 indirim uygulanır. Tüm modüller 6 dilde aktif ve bulut tabanlıdır.</p>
        </div>

      </div>
    </div>
  </main>
  <?php require __DIR__ . '/partials/footer.php'; ?>

  <script>
    function toggleAcc(header) {
      const body = header.nextElementSibling;
      const isOpen = body.style.display !== 'none';
      body.style.display = isOpen ? 'none' : 'block';
      header.classList.toggle('active', !isOpen);
      const icon = header.querySelector('.fa-chevron-down, .fa-chevron-up');
      if (icon) {
        icon.className = isOpen ? 'fa-solid fa-chevron-down' : 'fa-solid fa-chevron-up';
      }
    }

    function calcTotal() {
      const checkboxes = document.querySelectorAll('.module-item input[type="checkbox"]');
      let total = 0;
      let count = 0;
      checkboxes.forEach(cb => {
        if (cb.checked) {
          total += parseFloat(cb.getAttribute('data-price') || 0);
          count++;
        }
      });
      document.querySelector('#selected-count').textContent = count;
      document.querySelector('#total-price').textContent = '€' + total;
    }

    function openOfferModal() {
      const count = document.querySelector('#selected-count').textContent;
      const total = document.querySelector('#total-price').textContent;
      if (count === '0') {
        alert('Lütfen teklif oluşturmak için en az bir modül seçiniz.');
        return;
      }
      window.location.href = '/nexustraveltech/#erken-erisim';
    }
  </script>
</body>
</html>
