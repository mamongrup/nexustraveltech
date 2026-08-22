<?php 
$active_page = $active_page ?? ''; 
$lang = strtolower((string)($_GET['lang'] ?? $_COOKIE['nexus-language'] ?? 'tr'));
if (!in_array($lang, ['tr', 'en', 'de', 'ru', 'ar', 'fr'], true)) $lang = 'tr';

$navLabels = [
    'tr' => [
        'platform' => 'Platform', 'hotel' => 'Otel', 'pos' => 'POS', 'erp' => 'ERP', 'other' => 'Diğer Çözümler', 'company' => 'Şirket', 'access' => 'Erken erişim',
        'hotel_pms' => 'Ön Büro (PMS) & Oda Yönetimi', 'hotel_ibe' => 'Online Rezervasyon Motoru', 'hotel_cm' => 'Kanal Yönetimi (Channel Manager)',
        'hotel_rev' => 'Dinamik Fiyatlandırma (AI)', 'hotel_kbs' => 'KBS Kimlik Bildirimi', 'hotel_checkin' => 'Mobil Kimlik Okur & Check-in',
        'hotel_hk' => 'Kat Hizmetleri (Housekeeping)', 'hotel_crm' => 'Sadakat ve CRM Yönetimi', 'hotel_wa' => 'WhatsApp API & Bildirimler',
        'pos_rest' => 'Restoran POS Yönetim Programı', 'pos_table' => 'Masa & Online Rezervasyon', 'pos_qr' => 'Akıllı Dijital Menü (QR Menü)',
        'pos_spa' => 'SPA ve Spor Salonu Yönetimi', 'pos_banquet' => 'Satış ve Banket Yönetimi',
        'erp_acc' => 'Muhasebe & Cari Yönetimi', 'erp_einv' => 'e-Fatura, e-Arşiv, e-İrsaliye', 'erp_stock' => 'Stok, Reçete ve Maliyet',
        'erp_buy' => 'Satın Alma Programı', 'erp_bank' => 'Banka Entegrasyonları', 'erp_hr' => 'Personel, Bordro & QR PDKS',
        'oth_tour' => 'Tur Operatörü & Paket Tur', 'oth_marina' => 'Marina & Yat İşletim Sistemi', 'oth_villa' => 'Villa & Devremülk Yönetimi',
        'oth_tga' => 'TGA (Turizm Tanıtım) Entegrasyonu', 'oth_wifi' => 'Hotspot & 5651 Loglama', 'oth_cloud' => 'Cloud Sunucu & Yedekleme'
    ],
    'en' => [
        'platform' => 'Platform', 'hotel' => 'Hotel', 'pos' => 'POS', 'erp' => 'ERP', 'other' => 'Other Solutions', 'company' => 'Company', 'access' => 'Early Access',
        'hotel_pms' => 'Front Desk (PMS) & Rooms', 'hotel_ibe' => 'Online Booking Engine', 'hotel_cm' => 'Channel Manager (OTA)',
        'hotel_rev' => 'Dynamic Pricing (AI)', 'hotel_kbs' => 'Police ID (KBS) Reporting', 'hotel_checkin' => 'Mobile OCR & Self Check-in',
        'hotel_hk' => 'Housekeeping Management', 'hotel_crm' => 'Loyalty & Guest CRM', 'hotel_wa' => 'WhatsApp API & Notifications',
        'pos_rest' => 'Restaurant POS System', 'pos_table' => 'Table & Online Booking', 'pos_qr' => 'Smart Digital QR Menu',
        'pos_spa' => 'SPA & Fitness Management', 'pos_banquet' => 'Banquet & Events Sales',
        'erp_acc' => 'Accounting & Ledger', 'erp_einv' => 'e-Invoice & Fiscal Compliance', 'erp_stock' => 'Stock, Recipe & Cost Control',
        'erp_buy' => 'Procurement & Purchasing', 'erp_bank' => 'Bank & Virtual POS Gateway', 'erp_hr' => 'Payroll & QR Attendance',
        'oth_tour' => 'Tour Operator & Packages', 'oth_marina' => 'Marina & Yacht Management', 'oth_villa' => 'Villa & Timeshare Management',
        'oth_tga' => 'TGA Tourism Agency Sync', 'oth_wifi' => 'Hotspot & Law Compliance', 'oth_cloud' => 'Cloud Server & Auto Backup'
    ],
    'de' => [
        'platform' => 'Plattform', 'hotel' => 'Hotel', 'pos' => 'POS', 'erp' => 'ERP', 'other' => 'Weitere Lösungen', 'company' => 'Unternehmen', 'access' => 'Frühzugang',
        'hotel_pms' => 'Front Office (PMS) & Zimmer', 'hotel_ibe' => 'Online-Buchungsmaschine', 'hotel_cm' => 'Channel Manager (OTA)',
        'hotel_rev' => 'Dynamische Preisgestaltung', 'hotel_kbs' => 'Meldebehörde (KBS) Sync', 'hotel_checkin' => 'Mobiler Check-in & OCR',
        'hotel_hk' => 'Housekeeping-Verwaltung', 'hotel_crm' => 'Gäste-CRM & Loyalität', 'hotel_wa' => 'WhatsApp API & Chat',
        'pos_rest' => 'Restaurant POS Kassensystem', 'pos_table' => 'Tisch- & Online-Reservierung', 'pos_qr' => 'Digitales QR-Menü',
        'pos_spa' => 'SPA & Fitness-Verwaltung', 'pos_banquet' => 'Bankett & Event-Verkauf',
        'erp_acc' => 'Finanzbuchhaltung', 'erp_einv' => 'e-Rechnung & Konformität', 'erp_stock' => 'Warenwirtschaft & Rezeptur',
        'erp_buy' => 'Einkaufsmanagement', 'erp_bank' => 'Banken- & POS-Integration', 'erp_hr' => 'Personal & Zeiterfassung',
        'oth_tour' => 'Reiseveranstalter-System', 'oth_marina' => 'Marina- & Yacht-Verwaltung', 'oth_villa' => 'Villen- & Ferienhaus-Verwaltung',
        'oth_tga' => 'Tourismusverband-Schnittstelle', 'oth_wifi' => 'Hotspot & WLAN-Logging', 'oth_cloud' => 'Cloud-Server & Backup'
    ],
    'ru' => [
        'platform' => 'Платформа', 'hotel' => 'Отель', 'pos' => 'POS', 'erp' => 'ERP', 'other' => 'Другие решения', 'company' => 'Компания', 'access' => 'Ранний доступ',
        'hotel_pms' => 'Служба приема (PMS) и номера', 'hotel_ibe' => 'Модуль онлайн-бронирования', 'hotel_cm' => 'Менеджер каналов (Channel Manager)',
        'hotel_rev' => 'Динамическое ценообразование', 'hotel_kbs' => 'Передача данных в полицию (KBS)', 'hotel_checkin' => 'Мобильный скан и чек-ин',
        'hotel_hk' => 'Управление уборкой (Housekeeping)', 'hotel_crm' => 'Программа лояльности и CRM', 'hotel_wa' => 'WhatsApp API и уведомления',
        'pos_rest' => 'POS-система ресторана', 'pos_table' => 'Бронирование столов', 'pos_qr' => 'Цифровое QR-меню',
        'pos_spa' => 'SPA и фитнес-центр', 'pos_banquet' => 'Банкеты и мероприятия',
        'erp_acc' => 'Бухгалтерский и складской учет', 'erp_einv' => 'Электронные счета и фактуры', 'erp_stock' => 'Калькуляция и склад',
        'erp_buy' => 'Управление закупками', 'erp_bank' => 'Банковские шлюзы и эквайринг', 'erp_hr' => 'Кадры и учет рабочего времени',
        'oth_tour' => 'Туроператор и пакетные туры', 'oth_marina' => 'Управление мариной и яхтами', 'oth_villa' => 'Управление виллами',
        'oth_tga' => 'Интеграция с Минтуризма (TGA)', 'oth_wifi' => 'Hotspot и авторизация Wi-Fi', 'oth_cloud' => 'Облачный сервер и бэкапы'
    ],
    'ar' => [
        'platform' => 'المنصة', 'hotel' => 'الفندق', 'pos' => 'نقاط البيع', 'erp' => 'ERP', 'other' => 'حلول أخرى', 'company' => 'الشركة', 'access' => 'الوصول المبكر',
        'hotel_pms' => 'المكتب الأمامي والغرف', 'hotel_ibe' => 'محرك الحجز عبر الإنترنت', 'hotel_cm' => 'إدارة القنوات',
        'hotel_rev' => 'التسعير الديناميكي الذكي', 'hotel_kbs' => 'نظام تسجيل النزلاء الأمني', 'hotel_checkin' => 'تسجيل الدخول الذاتي',
        'hotel_hk' => 'إدارة التدبير المنزلي', 'hotel_crm' => 'إدارة علاقات الضيوف والولاء', 'hotel_wa' => 'خدمة واتساب والإشعارات',
        'pos_rest' => 'برنامج نقاط بيع المطاعم', 'pos_table' => 'حجز الطاولات الإلكتروني', 'pos_qr' => 'قائمة طعام QR الرقمية',
        'pos_spa' => 'إدارة السبا والنوادي الصحية', 'pos_banquet' => 'إدارة المبيعات والحفلات',
        'erp_acc' => 'المحاسبة والحسابات المالية', 'erp_einv' => 'الفواتير الإلكترونية المعتمدة', 'erp_stock' => 'إدارة المخزون والتكاليف',
        'erp_buy' => 'إدارة المشتريات والطلبات', 'erp_bank' => 'بوابات الدفع البنكية', 'erp_hr' => 'إدارة الموظفين والرواتب',
        'oth_tour' => 'إدارة برامج منظمي الرحلات', 'oth_marina' => 'إدارة المراسي واليخوت', 'oth_villa' => 'إدارة الفلل والشاليهات',
        'oth_tga' => 'الربط مع هيئة تنشيط السياحة', 'oth_wifi' => 'تسجيل الدخول للإنترنت', 'oth_cloud' => 'النسخ الاحتياطي السحابي'
    ],
    'fr' => [
        'platform' => 'Plateforme', 'hotel' => 'Hôtel', 'pos' => 'POS', 'erp' => 'ERP', 'other' => 'Autres solutions', 'company' => 'Entreprise', 'access' => 'Accès anticipé',
        'hotel_pms' => 'Réception (PMS) & Chambres', 'hotel_ibe' => 'Moteur de réservation en ligne', 'hotel_cm' => 'Channel Manager (OTA)',
        'hotel_rev' => 'Tarification dynamique (IA)', 'hotel_kbs' => 'Déclaration d\'identité (KBS)', 'hotel_checkin' => 'Check-in mobile & OCR',
        'hotel_hk' => 'Gestion de l\'entretien (Gouvernante)', 'hotel_crm' => 'CRM Invités & Fidélité', 'hotel_wa' => 'API WhatsApp & Alertes',
        'pos_rest' => 'Logiciel POS Restaurant', 'pos_table' => 'Réservation de tables', 'pos_qr' => 'Menu numérique QR Code',
        'pos_spa' => 'Gestion SPA & Fitness', 'pos_banquet' => 'Gestion des banquets & ventes',
        'erp_acc' => 'Comptabilité & Grand livre', 'erp_einv' => 'Facturation électronique', 'erp_stock' => 'Stocks, Recettes & Coûts',
        'erp_buy' => 'Gestion des achats', 'erp_bank' => 'Passerelle bancaire & POS virtuel', 'erp_hr' => 'Personnel, Paie & Présence',
        'oth_tour' => 'Tour-opérateur & Forfaits', 'oth_marina' => 'Gestion portuaire & Yachts', 'oth_villa' => 'Gestion de villas & Résidences',
        'oth_tga' => 'Intégration Ministère Tourisme', 'oth_wifi' => 'Hotspot & Journalisation Wi-Fi', 'oth_cloud' => 'Serveur Cloud & Sauvegarde'
    ],
];
$nl = $navLabels[$lang] ?? $navLabels['tr'];
?>
<style>
  /* Header Dropdown Menü Stilleri */
  .nav {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 16px !important;
    position: relative !important;
  }
  .nav-links {
    display: flex !important;
    align-items: center !important;
    gap: 18px !important;
  }
  .nexus-dropdown {
    position: relative;
    display: inline-block;
  }
  .nexus-drop-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: var(--ink);
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    padding: 8px 4px;
    cursor: pointer;
    background: transparent;
    border: none;
  }
  .nexus-drop-btn:hover {
    color: #e85f42;
  }
  .nexus-drop-btn::after {
    content: "▾";
    font-size: 10px;
    transition: transform 0.2s;
  }
  .nexus-dropdown:hover .nexus-drop-btn::after {
    transform: rotate(180deg);
  }
  .nexus-drop-content {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    min-width: 255px;
    background: #ffffff;
    border: 1px solid rgba(16, 33, 31, 0.12);
    border-radius: 12px;
    box-shadow: 0 16px 36px rgba(7, 20, 18, 0.14);
    padding: 8px 0;
    z-index: 9999;
  }
  .nexus-dropdown:hover .nexus-drop-content {
    display: block;
    animation: nDropFade 0.15s ease;
  }
  @keyframes nDropFade {
    from { opacity: 0; transform: translateY(-4px); }
    to { opacity: 1; transform: translateY(0); }
  }
  .nexus-drop-content a {
    display: block;
    padding: 8px 16px;
    font-size: 13px;
    font-weight: 500;
    color: #2c3e3a;
    text-decoration: none;
    line-height: 1.35;
    transition: background 0.15s, color 0.15s;
  }
  .nexus-drop-content a:hover {
    background: #f1f6f1;
    color: #071412;
    font-weight: 700;
  }
</style>

<nav class="nav shell" aria-label="Nexus navigation">
  <div class="brand-cluster"><a class="brand" href="/nexustraveltech/" aria-label="Nexus ana sayfa">N<span>&#8767;</span>XUS</a><small>nexustraveltech.com</small></div>
  <div class="nav-links">
    <a class="<?= $active_page === 'platform' ? 'active' : '' ?>" href="/nexustraveltech/platform" data-i18n="nav_platform"><?= htmlspecialchars($nl['platform']) ?></a>
    
    <!-- 1. OTEL -->
    <div class="nexus-dropdown">
      <a href="/nexustraveltech/cozumler#otel-cozumleri" class="nexus-drop-btn"><?= htmlspecialchars($nl['hotel']) ?></a>
      <div class="nexus-drop-content">
        <a href="/nexustraveltech/modul?slug=on-buro"><?= htmlspecialchars($nl['hotel_pms']) ?></a>
        <a href="/nexustraveltech/modul?slug=online-rezervasyon"><?= htmlspecialchars($nl['hotel_ibe']) ?></a>
        <a href="/nexustraveltech/modul?slug=kanal-yonetimi"><?= htmlspecialchars($nl['hotel_cm']) ?></a>
        <a href="/nexustraveltech/modul?slug=dinamik-fiyat"><?= htmlspecialchars($nl['hotel_rev']) ?></a>
        <a href="/nexustraveltech/modul?slug=kbs-bildirim"><?= htmlspecialchars($nl['hotel_kbs']) ?></a>
        <a href="/nexustraveltech/modul?slug=mobil-kimlik-okur"><?= htmlspecialchars($nl['hotel_checkin']) ?></a>
        <a href="/nexustraveltech/modul?slug=kat-hizmetleri"><?= htmlspecialchars($nl['hotel_hk']) ?></a>
        <a href="/nexustraveltech/modul?slug=sadakat-crm"><?= htmlspecialchars($nl['hotel_crm']) ?></a>
        <a href="/nexustraveltech/modul?slug=whatsapp-api"><?= htmlspecialchars($nl['hotel_wa']) ?></a>
      </div>
    </div>

    <!-- 2. POS -->
    <div class="nexus-dropdown">
      <a href="/nexustraveltech/cozumler#pos-restoran" class="nexus-drop-btn"><?= htmlspecialchars($nl['pos']) ?></a>
      <div class="nexus-drop-content">
        <a href="/nexustraveltech/modul?slug=restoran-pos"><?= htmlspecialchars($nl['pos_rest']) ?></a>
        <a href="/nexustraveltech/modul?slug=online-masa"><?= htmlspecialchars($nl['pos_table']) ?></a>
        <a href="/nexustraveltech/modul?slug=qr-menu"><?= htmlspecialchars($nl['pos_qr']) ?></a>
        <a href="/nexustraveltech/modul?slug=spa-fitness"><?= htmlspecialchars($nl['pos_spa']) ?></a>
        <a href="/nexustraveltech/cozumler#banket-etkinlik"><?= htmlspecialchars($nl['pos_banquet']) ?></a>
      </div>
    </div>

    <!-- 3. ERP -->
    <div class="nexus-dropdown">
      <a href="/nexustraveltech/cozumler#erp-finans" class="nexus-drop-btn"><?= htmlspecialchars($nl['erp']) ?></a>
      <div class="nexus-drop-content">
        <a href="/nexustraveltech/modul?slug=muhasebe"><?= htmlspecialchars($nl['erp_acc']) ?></a>
        <a href="/nexustraveltech/modul?slug=e-donusum"><?= htmlspecialchars($nl['erp_einv']) ?></a>
        <a href="/nexustraveltech/cozumler#stok-depo"><?= htmlspecialchars($nl['erp_stock']) ?></a>
        <a href="/nexustraveltech/cozumler#satin-alma"><?= htmlspecialchars($nl['erp_buy']) ?></a>
        <a href="/nexustraveltech/cozumler#banka-entegrasyon"><?= htmlspecialchars($nl['erp_bank']) ?></a>
        <a href="/nexustraveltech/cozumler#ik-bordro"><?= htmlspecialchars($nl['erp_hr']) ?></a>
      </div>
    </div>

    <!-- 4. DİĞER ÇÖZÜMLER -->
    <div class="nexus-dropdown">
      <a href="/nexustraveltech/cozumler#ozel-cozumler" class="nexus-drop-btn"><?= htmlspecialchars($nl['other']) ?></a>
      <div class="nexus-drop-content">
        <a href="/nexustraveltech/modul?slug=tur-operatoru"><?= htmlspecialchars($nl['oth_tour']) ?></a>
        <a href="/nexustraveltech/modul?slug=marina-yat"><?= htmlspecialchars($nl['oth_marina']) ?></a>
        <a href="/nexustraveltech/modul?slug=villa-devremulk"><?= htmlspecialchars($nl['oth_villa']) ?></a>
        <a href="/nexustraveltech/modul?slug=tga-entegrasyon"><?= htmlspecialchars($nl['oth_tga']) ?></a>
        <a href="/nexustraveltech/modul?slug=hotspot-loglama"><?= htmlspecialchars($nl['oth_wifi']) ?></a>
        <a href="/nexustraveltech/cozumler#cloud-yedekleme"><?= htmlspecialchars($nl['oth_cloud']) ?></a>
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
      <select id="site-currency" aria-label="Para birimi">
        <option value="TRY">TRY</option>
        <option value="EUR">EUR</option>
        <option value="USD">USD</option>
        <option value="GBP">GBP</option>
        <option value="RUB">RUB</option>
        <option value="AED">AED</option>
      </select>
    </div>
  </div>
</nav>
