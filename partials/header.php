<?php 
$active_page = $active_page ?? ''; 
$lang = strtolower((string)($_GET['lang'] ?? $_COOKIE['nexus-language'] ?? 'tr'));
if (!in_array($lang, ['tr', 'en', 'de', 'ru', 'ar', 'fr'], true)) $lang = 'tr';

$navLabels = [
    'tr' => ['platform' => 'Platform', 'solutions' => 'Çözümler', 'company' => 'Şirket', 'access' => 'Erken erişim'],
    'en' => ['platform' => 'Platform', 'solutions' => 'Solutions', 'company' => 'Company', 'access' => 'Early Access'],
    'de' => ['platform' => 'Plattform', 'solutions' => 'Lösungen', 'company' => 'Unternehmen', 'access' => 'Frühzugang'],
    'ru' => ['platform' => 'Платформа', 'solutions' => 'Решения', 'company' => 'Компания', 'access' => 'Ранний доступ'],
    'ar' => ['platform' => 'المنصة', 'solutions' => 'الحلول', 'company' => 'الشركة', 'access' => 'الوصول المبكر'],
    'fr' => ['platform' => 'Plateforme', 'solutions' => 'Solutions', 'company' => 'Entreprise', 'access' => 'Accès anticipé'],
];
$nl = $navLabels[$lang] ?? $navLabels['tr'];
?>
<nav class="nav shell" aria-label="Nexus navigation">
  <div class="brand-cluster"><a class="brand" href="/nexustraveltech/" aria-label="Nexus ana sayfa">N<span>&#8767;</span>XUS</a><small>nexustraveltech.com</small></div>
  <div class="nav-links">
    <a class="<?= $active_page === 'platform' ? 'active' : '' ?>" href="/nexustraveltech/platform" data-i18n="nav_platform"><?= htmlspecialchars($nl['platform']) ?></a>
    <div class="nav-item-dropdown">
      <a class="nav-dropdown-toggle <?= $active_page === 'solutions' ? 'active' : '' ?>" href="/nexustraveltech/cozumler" data-i18n="nav_solutions"><?= htmlspecialchars($nl['solutions']) ?></a>
      <div class="nav-dropdown-menu">
        <div class="nav-dropdown-col">
          <h4>🏨 Otel Çözümleri</h4>
          <a href="/nexustraveltech/cozumler#on-buro">Ön Büro (PMS) & Oda Yönetimi</a>
          <a href="/nexustraveltech/cozumler#online-rezervasyon">Online Rezervasyon Motoru (IBE)</a>
          <a href="/nexustraveltech/cozumler#kanal-yonetimi">Kanal Yöneticisi (OTA Sync)</a>
          <a href="/nexustraveltech/cozumler#dinamik-fiyat">Dinamik Fiyatlandırma (AI)</a>
          <a href="/nexustraveltech/cozumler#kbs-bildirim">KBS Kimlik Bildirimi (Emniyet)</a>
          <a href="/nexustraveltech/cozumler#mobil-checkin">Mobil Kimlik Okur & Check-in</a>
          <a href="/nexustraveltech/cozumler#kat-hizmetleri">Kat Hizmetleri (Housekeeping)</a>
          <a href="/nexustraveltech/cozumler#sadakat-crm">Misafir CRM & Sadakat Yönetimi</a>
          <a href="/nexustraveltech/cozumler#whatsapp-api">WhatsApp API & Bildirimler</a>
        </div>
        <div class="nav-dropdown-col">
          <h4>🍽️ POS & Restoran</h4>
          <a href="/nexustraveltech/cozumler#restoran-pos">Restoran POS Satış Programı</a>
          <a href="/nexustraveltech/cozumler#online-masa">Masa & Online Rezervasyon</a>
          <a href="/nexustraveltech/cozumler#qr-menu">Akıllı Dijital Menü (QR Menü)</a>
          <a href="/nexustraveltech/cozumler#spa-fitness">SPA & Spor Salonu Yönetimi</a>
          <a href="/nexustraveltech/cozumler#banket-etkinlik">Satış, Ziyafet & Banket</a>
        </div>
        <div class="nav-dropdown-col">
          <h4>💼 ERP & Finans</h4>
          <a href="/nexustraveltech/cozumler#muhasebe">Muhasebe & Cari Yönetimi</a>
          <a href="/nexustraveltech/cozumler#e-donusum">e-Fatura, e-Arşiv, e-İrsaliye</a>
          <a href="/nexustraveltech/cozumler#stok-depo">Stok, Maliyet & Demirbaş</a>
          <a href="/nexustraveltech/cozumler#satin-alma">Satın Alma & Sipariş Süreci</a>
          <a href="/nexustraveltech/cozumler#banka-entegrasyon">Banka & Sanal POS Entegrasyonu</a>
          <a href="/nexustraveltech/cozumler#ik-bordro">Personel, Bordro & QR PDKS</a>
        </div>
        <div class="nav-dropdown-col">
          <h4>🌐 Özel Çözümler</h4>
          <a href="/nexustraveltech/cozumler#tur-operatoru">Tur Operatörü & Paket Tur</a>
          <a href="/nexustraveltech/cozumler#marina-yat">Marina & Yat İşletim Sistemi</a>
          <a href="/nexustraveltech/cozumler#villa-devremulk">Villa & Devremülk Yönetimi</a>
          <a href="/nexustraveltech/cozumler#tga-entegrasyon">TGA (Turizm Tanıtım) Bildirimi</a>
          <a href="/nexustraveltech/cozumler#hotspot-loglama">Hotspot & 5651 Loglama</a>
          <a href="/nexustraveltech/cozumler#cloud-yedekleme">Cloud Sunucu & Güvenli Yedek</a>
        </div>
        <div class="nav-dropdown-footer">
          <span>Tüm modüller 6 dilde, mobil ve bulut tabanlı çalışır.</span>
          <a href="/nexustraveltech/cozumler">Tüm Çözümleri İncele →</a>
        </div>
      </div>
    </div>
    <a class="<?= $active_page === 'company' ? 'active' : '' ?>" href="/nexustraveltech/sirket" data-i18n="nav_company"><?= htmlspecialchars($nl['company']) ?></a>
    <a href="/nexustraveltech/#erken-erisim" class="nav-cta"><span data-i18n="nav_access"><?= htmlspecialchars($nl['access']) ?></span> <span>&#8599;</span></a>
    <div class="locale-controls" aria-label="Dil ve para birimi seçimi">
      <select id="site-language" aria-label="Dil">
        <option value="tr" <?= $lang === 'tr' ? 'selected' : '' ?>>TR</option>
        <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>>EN</option>
        <option value="de" <?= $lang === 'de' ? 'selected' : '' ?>>DE</option>
        <option value="ru" <?= $lang === 'ru' ? 'selected' : '' ?>>RU</option>
        <option value="ar" <?= $lang === 'ar' ? 'selected' : '' ?>>AR</option>
        <option value="fr" <?= $lang === 'fr' ? 'selected' : '' ?>>FR</option>
      </select>
      <select id="site-currency" aria-label="Para birimi"><option value="TRY">TRY</option><option value="EUR">EUR</option><option value="USD">USD</option><option value="GBP">GBP</option><option value="RUB">RUB</option><option value="AED">AED</option></select>
    </div>
  </div>
</nav>
