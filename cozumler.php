<?php
$active_page = 'solutions';
$seo_page = 'solutions';
require_once __DIR__ . '/config/i18n.php';
$current_lang = readiness_lang();
?>
<!doctype html>
<html lang="<?= htmlspecialchars($current_lang) ?>" dir="<?= $current_lang === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Tüm Turizm, Otel, POS & ERP Çözümleri | NEXUS TravelTech</title>
  <?php require __DIR__ . '/partials/seo.php'; ?>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500;600&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&family=Playfair+Display:ital,wght@0,600;0,700;1,600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
  <link rel="stylesheet" href="/nexustraveltech/assets/styles.css?v=<?= filemtime(__DIR__ . '/assets/styles.css') ?>" />
  <style>
    html {
      scroll-behavior: smooth;
    }
    .solutions-hero {
      padding: 70px 0 40px;
      text-align: center;
      background: linear-gradient(180deg, #f7f7f2 0%, #ffffff 100%);
      border-bottom: 1px solid #e7ece7;
    }
    .solutions-hero h1 {
      font-size: clamp(34px, 5vw, 56px);
      letter-spacing: -0.05em;
      line-height: 1.1;
      margin: 16px auto 14px;
      max-width: 880px;
    }
    .solutions-hero p {
      max-width: 680px;
      margin: 0 auto;
      font-size: 17.5px;
      color: var(--muted);
      line-height: 1.6;
    }
    .solutions-nav-tabs {
      display: flex;
      justify-content: center;
      gap: 12px;
      margin-top: 32px;
      flex-wrap: wrap;
    }
    .solutions-nav-tabs a {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 22px;
      background: #ffffff;
      border: 1px solid #d8ded8;
      border-radius: 99px;
      font-size: 14px;
      font-weight: 700;
      color: var(--ink);
      text-decoration: none;
      transition: all 0.2s;
    }
    .solutions-nav-tabs a:hover {
      background: var(--ink);
      color: #ffffff;
      border-color: var(--ink);
    }
    .category-section {
      padding: 60px 0;
      border-bottom: 1px solid #eef1ec;
    }
    .category-header {
      margin-bottom: 36px;
    }
    .category-header .cat-badge {
      display: inline-block;
      font: 700 11px "DM Mono", monospace;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      padding: 6px 14px;
      background: #eef4ee;
      color: #2b5443;
      border-radius: 99px;
      margin-bottom: 10px;
    }
    .category-header h2 {
      font-size: 32px;
      letter-spacing: -0.04em;
      margin: 0 0 8px;
    }
    .category-header p {
      color: var(--muted);
      font-size: 16px;
      margin: 0;
      max-width: 700px;
    }
    .module-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
      gap: 22px;
    }
    .module-card {
      background: #ffffff;
      border: 1px solid #e7ece7;
      border-radius: 14px;
      padding: 24px;
      transition: all 0.25s ease;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      scroll-margin-top: 100px;
    }
    .module-card:hover, .module-card:target {
      transform: translateY(-4px);
      box-shadow: 0 16px 36px rgba(7, 20, 18, 0.10);
      border-color: #2e7d32;
    }
    .module-card:target {
      background: #fbfdfb;
      border-width: 2px;
    }
    .module-icon {
      width: 44px;
      height: 44px;
      border-radius: 10px;
      display: grid;
      place-items: center;
      font-size: 18px;
      margin-bottom: 16px;
    }
    .module-card.hotel .module-icon { background: #e8f5e9; color: #2e7d32; }
    .module-card.pos .module-icon { background: #fff3e0; color: #e65100; }
    .module-card.erp .module-icon { background: #e3f2fd; color: #1565c0; }
    .module-card.special .module-icon { background: #f3e5f5; color: #7b1fa2; }
    
    .module-card h3 {
      font-size: 17.5px;
      margin: 0 0 8px;
      letter-spacing: -0.02em;
      color: #11201d;
    }
    .module-card p {
      font-size: 13.5px;
      color: #55625f;
      line-height: 1.55;
      margin: 0 0 16px;
    }
    .module-tags {
      display: flex;
      gap: 6px;
      flex-wrap: wrap;
      margin-top: auto;
    }
    .module-tags span {
      font: 11px "DM Mono", monospace;
      background: #f0f3f0;
      color: #3e4d49;
      padding: 3px 8px;
      border-radius: 4px;
    }
    .cta-banner {
      background: #071412;
      color: #ffffff;
      padding: 60px 0;
      text-align: center;
    }
    .cta-banner h2 {
      font-size: 32px;
      letter-spacing: -0.04em;
      margin: 0 0 12px;
    }
    .cta-banner p {
      color: #9badc2;
      max-width: 600px;
      margin: 0 auto 24px;
      font-size: 16px;
    }
  </style>
</head>
<body class="inner-page">
  <main>
    <?php require __DIR__ . '/partials/header.php'; ?>

    <section class="solutions-hero">
      <div class="shell">
        <div class="eyebrow"><i></i> NEXUS ÇÖZÜMLER DİZİNİ</div>
        <h1>Her Modül İçin<br /><em>Özelleşmiş Bulut Teknolojisi</em></h1>
        <p>Otel ön bürosundan restoran POS sistemine, muhasebeden kapı kilidine kadar tüm modüller tek ekranda ve entegre çalışır.</p>
        <div class="solutions-nav-tabs">
          <a href="#otel-cozumleri"><i class="fa-solid fa-hotel"></i> Otel Çözümleri</a>
          <a href="#pos-restoran"><i class="fa-solid fa-utensils"></i> POS & Restoran</a>
          <a href="#erp-finans"><i class="fa-solid fa-file-invoice-dollar"></i> ERP & Finans</a>
          <a href="#ozel-cozumler"><i class="fa-solid fa-shapes"></i> Diğer Çözümler</a>
          <a href="/nexustraveltech/fiyat-listesi" style="background:#071412;color:#d7ff48;border-color:#071412"><i class="fa-solid fa-calculator"></i> Teklif Sihirbazı</a>
        </div>
      </div>
    </section>

    <!-- 1. OTEL ÇÖZÜMLERİ -->
    <section id="otel-cozumleri" class="category-section">
      <div class="shell">
        <div class="category-header">
          <span class="cat-badge">01 / KONAKLAMA VE TESİS YÖNETİMİ</span>
          <h2>Otel ve Konaklama Modülleri</h2>
          <p>Rezervasyon, oda blokajı, kanal dağıtımı, emniyet bildirimi ve kat hizmetleri operasyonlarınızı kesintisiz yönetin.</p>
        </div>
        <div class="module-grid">
          <div class="module-card hotel" id="on-buro">
            <div>
              <div class="module-icon"><i class="fa-solid fa-desktop"></i></div>
              <h3>Ön Büro Modülü (PMS)</h3>
              <p>Oda planı, anlık doluluk grafiği, check-in / check-out, hızlı folyo yönetimi ve misafir kayıt işlemleri.</p>
            </div>
            <div class="module-tags"><span>Oda Planı</span><span>Folyo</span><span>Check-in</span></div>
          </div>
          <div class="module-card hotel" id="online-rezervasyon">
            <div>
              <div class="module-icon"><i class="fa-solid fa-calendar-check"></i></div>
              <h3>Online Rezervasyon Motoru (IBE)</h3>
              <p>Otelin kendi web sitesine yerleştirilen komisyonsuz, mobil uyumlu, anlık ödeme alan rezervasyon motoru.</p>
            </div>
            <div class="module-tags"><span>Komisyonsuz</span><span>Widget</span><span>Sanal POS</span></div>
          </div>
          <div class="module-card hotel" id="kanal-yonetimi">
            <div>
              <div class="module-icon"><i class="fa-solid fa-network-wired"></i></div>
              <h3>Kanal Yönetimi (Channel Manager)</h3>
              <p>Booking.com, Airbnb, Expedia, Agoda ve 100+ OTA ile 2 yönlü (2-Way) anlık fiyat ve kontenjan senkronizasyonu.</p>
            </div>
            <div class="module-tags"><span>2-Way Sync</span><span>OTA Entegrasyon</span><span>Overbooking Yok</span></div>
          </div>
          <div class="module-card hotel" id="dinamik-fiyat">
            <div>
              <div class="module-icon"><i class="fa-solid fa-brain"></i></div>
              <h3>Dinamik Fiyatlandırma & AI Gelir Yönetimi</h3>
              <p>Yapay zekâ destekli Pricing Coach ile doluluk oranına, sezona ve rakip trendlerine göre otomatik fiyat güncelleme.</p>
            </div>
            <div class="module-tags"><span>AI Coach</span><span>Gelir Maksimizasyonu</span><span>Otopilot</span></div>
          </div>
          <div class="module-card hotel" id="kbs-bildirim">
            <div>
              <div class="module-icon"><i class="fa-solid fa-shield-halved"></i></div>
              <h3>KBS Kimlik Bildirimi (Emniyet & Jandarma)</h3>
              <p>AKBS mevzuatına tam uyumlu; misafir giriş-çıkışlarında tek tıkla otomatik Emniyet ve Jandarma XML bildirimi.</p>
            </div>
            <div class="module-tags"><span>AKBS</span><span>Jandarma</span><span>Otomatik XML</span></div>
          </div>
          <div class="module-card hotel" id="mobil-checkin">
            <div>
              <div class="module-icon"><i class="fa-solid fa-id-card"></i></div>
              <h3>Mobil Kimlik Okur & Check-in Kiosk</h3>
              <p>Pasaport ve TC Kimlik kartlarını telefon kamerasından OCR ile okur; kuyruk beklemeden temassız self check-in sağlar.</p>
            </div>
            <div class="module-tags"><span>OCR Pasaport</span><span>Self Check-in</span><span>Kiosk</span></div>
          </div>
          <div class="module-card hotel" id="kat-hizmetleri">
            <div>
              <div class="module-icon"><i class="fa-solid fa-broom"></i></div>
              <h3>Kat Hizmetleri (Housekeeping)</h3>
              <p>Oda temizlik durumları (Temiz, Kirli, DND, Arızalı), kat görevlisi iş atamaları ve arıza takip kayıtları.</p>
            </div>
            <div class="module-tags"><span>Mobil HK</span><span>Arıza Takip</span><span>Oda Durumu</span></div>
          </div>
          <div class="module-card hotel" id="sadakat-crm">
            <div>
              <div class="module-icon"><i class="fa-solid fa-users-gear"></i></div>
              <h3>Sadakat ve Misafir CRM Yönetimi</h3>
              <p>Misafir geçmişi, konaklama tercihleri, VIP seviyeleri, sadakat puanları ve kara liste yönetimi.</p>
            </div>
            <div class="module-tags"><span>VIP Takip</span><span>Puan Sistemi</span><span>Misafir Geçmişi</span></div>
          </div>
          <div class="module-card hotel" id="whatsapp-api">
            <div>
              <div class="module-icon"><i class="fa-brands fa-whatsapp"></i></div>
              <h3>WhatsApp API & Akıllı Bildirimler</h3>
              <p>Rezervasyon onayı, yol tarifi, dijital oda anahtarı ve memnuniyet anketlerini otomatik WhatsApp mesajıyla iletin.</p>
            </div>
            <div class="module-tags"><span>WhatsApp Bot</span><span>Otomatik Onay</span><span>Anket</span></div>
          </div>
        </div>
      </div>
    </section>

    <!-- 2. POS & RESTORAN -->
    <section id="pos-restoran" class="category-section" style="background:#fafbfa">
      <div class="shell">
        <div class="category-header">
          <span class="cat-badge">02 / YİYECEK, İÇECEK VE AKTİVİTE</span>
          <h2>POS, Restoran ve SPA Çözümleri</h2>
          <p>Restoran, kafe, bar, SPA ve otel içi satış noktalarınızı tam entegre adisyon ve dijital menü altyapısıyla donatın.</p>
        </div>
        <div class="module-grid">
          <div class="module-card pos" id="restoran-pos">
            <div>
              <div class="module-icon"><i class="fa-solid fa-cash-register"></i></div>
              <h3>Restoran POS Satış Programı</h3>
              <p>Masa krokisi, dokunmatik hızlı sipariş, mutfak ekranı (KDS), parçalı ödeme ve odaya folyo transferi.</p>
            </div>
            <div class="module-tags"><span>Dokunmatik POS</span><span>Mutfak Ekranı</span><span>Odaya Aktarım</span></div>
          </div>
          <div class="module-card pos" id="online-masa">
            <div>
              <div class="module-icon"><i class="fa-solid fa-chair"></i></div>
              <h3>Masa & Online Restoran Rezervasyonu</h3>
              <p>Restoran ve alakart mekanlarınız için web üzerinden anlık masa rezervasyonu ve kapasite planlama.</p>
            </div>
            <div class="module-tags"><span>Masa Rezervasyon</span><span>Alakart</span><span>Kapasite</span></div>
          </div>
          <div class="module-card pos" id="qr-menu">
            <div>
              <div class="module-icon"><i class="fa-solid fa-qrcode"></i></div>
              <h3>Akıllı Dijital Menü (QR Menü)</h3>
              <p>Çok dilli, fotoğraflı, alerjen uyarıları içeren ve masadan temassız sipariş verilebilen yeni nesil QR menü.</p>
            </div>
            <div class="module-tags"><span>QR Sipariş</span><span>Çok Dilli Menü</span><span>Anlık Fiyat</span></div>
          </div>
          <div class="module-card pos" id="spa-fitness">
            <div>
              <div class="module-icon"><i class="fa-solid fa-spa"></i></div>
              <h3>SPA, Masaj ve Spor Salonu Yönetimi</h3>
              <p>Terapist randevu takvimi, masaj odası blokajı, üyelik paketleri ve seans takipli wellness otomasyonu.</p>
            </div>
            <div class="module-tags"><span>Terapist Takvimi</span><span>Seans Takip</span><span>Üyelik</span></div>
          </div>
          <div class="module-card pos" id="banket-etkinlik">
            <div>
              <div class="module-icon"><i class="fa-solid fa-champagne-glasses"></i></div>
              <h3>Satış, Ziyafet ve Banket Yönetimi</h3>
              <p>Düğün, kongre, toplantı salonu planlaması, etkinlik menüleri, sözleşme ve banket teklif yönetimi.</p>
            </div>
            <div class="module-tags"><span>Düğün & Kongre</span><span>Salon Planı</span><span>Etkinlik Teklifi</span></div>
          </div>
        </div>
      </div>
    </section>

    <!-- 3. ERP & FİNANS -->
    <section id="erp-finans" class="category-section">
      <div class="shell">
        <div class="category-header">
          <span class="cat-badge">03 / FİNANS, STOK VE İNSAN KAYNAKLARI</span>
          <h2>ERP, Ön Muhasebe ve Yönetim</h2>
          <p>Maliyetlerinizi kuruşu kuruşuna kontrol edin, e-Fatura kesin, stok ve personel operasyonlarınızı hatasız yürütün.</p>
        </div>
        <div class="module-grid">
          <div class="module-card erp" id="muhasebe">
            <div>
              <div class="module-icon"><i class="fa-solid fa-scale-balanced"></i></div>
              <h3>Muhasebe & Cari Yönetimi</h3>
              <p>Acente ve tedarikçi cari hesapları, gelir/gider tabloları, kasa/banka hareketleri ve resmi defter uyumluluğu.</p>
            </div>
            <div class="module-tags"><span>Cari Hesap</span><span>Gelir/Gider</span><span>Mali Müşavir</span></div>
          </div>
          <div class="module-card erp" id="e-donusum">
            <div>
              <div class="module-icon"><i class="fa-solid fa-file-invoice"></i></div>
              <h3>e-Fatura, e-Arşiv ve e-İrsaliye</h3>
              <p>GİB entegratörleri üzerinden tek tıkla e-Fatura ve e-Arşiv kesimi, konaklama vergisi hesaplama ve e-İrsaliye.</p>
            </div>
            <div class="module-tags"><span>GİB Uyumlu</span><span>Konaklama Vergisi</span><span>e-Arşiv</span></div>
          </div>
          <div class="module-card erp" id="stok-depo">
            <div>
              <div class="module-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
              <h3>Stok, Reçete ve Maliyet Yönetimi</h3>
              <p>Ana depo, kat ve bar depoları, yemek reçetesi üzerinden porsiyon maliyeti ve kritik stok uyarıları.</p>
            </div>
            <div class="module-tags"><span>Porsiyon Maliyeti</span><span>Depo Transfer</span><span>Sayım</span></div>
          </div>
          <div class="module-card erp" id="satin-alma">
            <div>
              <div class="module-icon"><i class="fa-solid fa-cart-flatbed"></i></div>
              <h3>Satın Alma & Sipariş Süreci</h3>
              <p>Departman talep fişleri, tedarikçi teklif karşılaştırması, onay mekanizması ve irsaliye girişleri.</p>
            </div>
            <div class="module-tags"><span>Talep Onay</span><span>Teklif Karşılaştırma</span><span>İrsaliye</span></div>
          </div>
          <div class="module-card erp" id="banka-entegrasyon">
            <div>
              <div class="module-icon"><i class="fa-solid fa-building-columns"></i></div>
              <h3>Banka & Sanal POS Entegrasyonları</h3>
              <p>Tüm bankalarla doğrudan sanal POS, 3D Secure güvenli tahsilat, taksit yönetimi ve otomatik hesap ekstresi.</p>
            </div>
            <div class="module-tags"><span>Çoklu Banka</span><span>3D Secure</span><span>Hesap Ekstresi</span></div>
          </div>
          <div class="module-card erp" id="ik-bordro">
            <div>
              <div class="module-icon"><i class="fa-solid fa-user-clock"></i></div>
              <h3>Personel, Bordro ve QR/NFC PDKS</h3>
              <p>Personel vardiya çizelgesi, puantaj, izin takibi, mobil QR/NFC ile personel devam kontrolü (PDKS).</p>
            </div>
            <div class="module-tags"><span>Vardiya</span><span>Puantaj</span><span>QR PDKS</span></div>
          </div>
        </div>
      </div>
    </section>

    <!-- 4. DİĞER ÇÖZÜMLER -->
    <section id="ozel-cozumler" class="category-section" style="background:#fafbfa">
      <div class="shell">
        <div class="category-header">
          <span class="cat-badge">04 / SEKTÖREL VE MEVZUAT UYUMLULUK</span>
          <h2>Özel Turizm & Entegrasyon Çözümleri</h2>
          <p>Yat işletmelerinden marinalara, tur operatörlerinden TGA bildirimlerine kadar genişletilmiş ekosistem.</p>
        </div>
        <div class="module-grid">
          <div class="module-card special" id="tur-operatoru">
            <div>
              <div class="module-icon"><i class="fa-solid fa-route"></i></div>
              <h3>Tur Operatörü & Paket Tur Yönetimi</h3>
              <p>Günübirlik turlar, haftalık paket geziler, rehber ve araç planlaması ile B2B acente kontenjan tahsisi.</p>
            </div>
            <div class="module-tags"><span>Paket Tur</span><span>Rehber Planı</span><span>Acente Kontenjanı</span></div>
          </div>
          <div class="module-card special" id="marina-yat">
            <div>
              <div class="module-icon"><i class="fa-solid fa-ship"></i></div>
              <h3>Marina & Yat İşletim Sistemi</h3>
              <p>Bağlama sözleşmeleri, çekek yeri planlaması, tekne su/elektrik sayaç okuma ve transit log işlemleri.</p>
            </div>
            <div class="module-tags"><span>Bağlama Planı</span><span>Sayaç Takip</span><span>Transit Log</span></div>
          </div>
          <div class="module-card special" id="villa-devremulk">
            <div>
              <div class="module-icon"><i class="fa-solid fa-house-chimney"></i></div>
              <h3>Villa & Devremülk / Devre Tatil Yönetimi</h3>
              <p>Müstakil villa portföyü, devre tatil dönem takvimleri, sahip/hissedar hakları ve temizlik periyotları.</p>
            </div>
            <div class="module-tags"><span>Hissedar Takvimi</span><span>Villa Portföyü</span><span>Dönem Yönetimi</span></div>
          </div>
          <div class="module-card special" id="tga-entegrasyon">
            <div>
              <div class="module-icon"><i class="fa-solid fa-landmark"></i></div>
              <h3>TGA (Turizm Tanıtım) Entegrasyonu</h3>
              <p>Kültür ve Turizm Bakanlığı ile TGA payı bildirimleri ve aylık resmi istatistik raporlama modülü.</p>
            </div>
            <div class="module-tags"><span>Bakanlık Uyumlu</span><span>TGA Payı</span><span>Resmi Rapor</span></div>
          </div>
          <div class="module-card special" id="hotspot-loglama">
            <div>
              <div class="module-icon"><i class="fa-solid fa-wifi"></i></div>
              <h3>Hotspot İnternet & 5651 Loglama</h3>
              <p>Oda numarası ve TCKN/Pasaport ile misafir Wi-Fi girişi, 5651 sayılı kanuna uygun zaman damgalı loglama.</p>
            </div>
            <div class="module-tags"><span>5651 Loglama</span><span>Oda Wi-Fi</span><span>Zaman Damgası</span></div>
          </div>
          <div class="module-card special" id="cloud-yedekleme">
            <div>
              <div class="module-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
              <h3>Cloud Sunucu & Otomatik Yedekleme</h3>
              <p>Yüksek güvenlikli bulut mimarisi, SSL şifreleme ve günlük otomatik Google Cloud / AWS yedekleme güvencesi.</p>
            </div>
            <div class="module-tags"><span>%99.9 Uptime</span><span>Günlük Yedek</span><span>SSL Güvenlik</span></div>
          </div>
        </div>
      </div>
    </section>

    <!-- ÇAĞRI BANNERI -->
    <section class="cta-banner">
      <div class="shell">
        <h2>Tüm Modülleri İhtiyacınıza Göre Seçin</h2>
        <p>İnteraktif teklif sihirbazımızla paketinizin fiyatını anında hesaplayın veya canlı demo talep edin.</p>
        <a href="/nexustraveltech/fiyat-listesi" class="button button-lime" style="padding:14px 28px;font-size:15px;font-weight:700">Fiyat Hesapla & Teklif Al →</a>
      </div>
    </section>

  </main>
  <?php require __DIR__ . '/partials/footer.php'; ?>
</body>
</html>
