<?php
declare(strict_types=1);
// Admin ortak layout — sidebar + navbar + Soft UI teması

function admin_layout_start(string $pageTitle = 'NEXUS Admin', string $activePage = ''): void
{
    require_once __DIR__ . '/../config/auth.php';
    require_admin();
    
    // Aktif sayfayı belirle
    $currentPage = $activePage ?: basename($_SERVER['SCRIPT_NAME'], '.php');
    $baseUri = (strpos($_SERVER['REQUEST_URI'] ?? '', '/nexustraveltech') === 0) ? '/nexustraveltech' : '';
    
    // Dil belirleme
    $lang = strtolower((string)($_GET['lang'] ?? $_COOKIE['nexus-admin-lang'] ?? $_SESSION['admin_lang'] ?? 'tr'));
    if (!in_array($lang, ['tr', 'en', 'de', 'ru', 'ar', 'fr'], true)) $lang = 'tr';
    $_SESSION['admin_lang'] = $lang;

    // Admin menü çeviri sözlüğü
    $i18nAdmin = [
        'tr' => [
            'sec_general' => 'Genel', 'dashboard' => 'Dashboard', 'control_center' => 'Kontrol Merkezi', 'alert_center' => 'Uyarı Merkezi',
            'sec_price_finance' => 'Fiyat & Finans', 'price_matrix' => 'Fiyat Matrisi', 'pricing_coach' => 'Pricing Coach (Otopilot)', 'ai_rev_mgmt' => 'AI Gelir Yöneticisi', 'liox_finance' => 'LioX ERP & Finans',
            'sec_management' => 'Yönetim', 'guest_crm' => 'Misafir CRM & Sadakat', 'housekeeping' => 'Kat Hizmetleri (HK)', 'suppliers' => 'Tedarikçiler', 'agencies' => 'Acenteler', 'approvals' => 'İlan Onayları',
            'sec_dist_channel' => 'Dağıtım & Kanal', 'dist_health' => 'Dağıtım Sağlığı', 'channel_wizard' => 'Kanal Sihirbazı', 'dex_marketing' => 'Dex Pazarlama Hub', 'booking_widget' => 'Rezervasyon Widget', 'prep_summary' => 'Hazırlık Özeti', 'orphan_mappings' => 'Yetim Eşleştirmeler',
            'sec_settings_cat' => 'Ayarlar & Katalog', 'cat_attributes' => 'Kategori & Nitelikler', 'prod_templates' => 'Ürün Şablonları', 'catalog_mgmt' => 'Katalog Yönetimi', 'email_templates' => 'E-posta Şablonları', 'timers' => 'Zamanlayıcılar',
            'sec_sec_monitor' => 'Güvenlik & İzleme', 'kbs' => 'KBS Kimlik Bildirimi', 'audit_logs' => 'Denetim Kayıtları', 'error_logs' => 'Hata İzleme', 'two_fa' => '2FA Ayarları',
            'sec_ai_chat' => 'AI & Sohbet', 'deepseek_ai' => 'DeepSeek AI', 'gemini_ai' => 'Gemini AI', 'visitor_chat' => 'Ziyaretçi Sohbet',
            'sec_system' => 'Sistem', 'migration_status' => 'Migration Durumu', 'sms_mgmt' => 'SMS Yönetimi', 'kvkk_tool' => 'KVKK Veri Aracı', 'user_guide' => 'Kullanım Kılavuzu',
            'logout' => 'Çıkış Yap', 'home' => 'Ana sayfa'
        ],
        'en' => [
            'sec_general' => 'General', 'dashboard' => 'Dashboard', 'control_center' => 'Control Center', 'alert_center' => 'Alert Center',
            'sec_price_finance' => 'Pricing & Finance', 'price_matrix' => 'Price Matrix', 'pricing_coach' => 'Pricing Coach (Autopilot)', 'ai_rev_mgmt' => 'AI Revenue Manager', 'liox_finance' => 'LioX ERP & Finance',
            'sec_management' => 'Management', 'guest_crm' => 'Guest CRM & Loyalty', 'housekeeping' => 'Housekeeping (HK)', 'suppliers' => 'Suppliers', 'agencies' => 'Agencies', 'approvals' => 'Listing Approvals',
            'sec_dist_channel' => 'Distribution & Channels', 'dist_health' => 'Distribution Health', 'channel_wizard' => 'Channel Wizard', 'dex_marketing' => 'Dex Marketing Hub', 'booking_widget' => 'Booking Widget', 'prep_summary' => 'Readiness Summary', 'orphan_mappings' => 'Orphan Mappings',
            'sec_settings_cat' => 'Settings & Catalog', 'cat_attributes' => 'Category & Attributes', 'prod_templates' => 'Product Templates', 'catalog_mgmt' => 'Catalog Management', 'email_templates' => 'Email Templates', 'timers' => 'Schedulers / Timers',
            'sec_sec_monitor' => 'Security & Monitoring', 'kbs' => 'KBS Police ID Report', 'audit_logs' => 'Audit Logs', 'error_logs' => 'Error Tracking', 'two_fa' => '2FA Settings',
            'sec_ai_chat' => 'AI & Chat', 'deepseek_ai' => 'DeepSeek AI', 'gemini_ai' => 'Gemini AI', 'visitor_chat' => 'Visitor Live Chat',
            'sec_system' => 'System', 'migration_status' => 'Migration Status', 'sms_mgmt' => 'SMS Management', 'kvkk_tool' => 'GDPR / Privacy Tool', 'user_guide' => 'User Manual',
            'logout' => 'Log Out', 'home' => 'Home'
        ],
        'de' => [
            'sec_general' => 'Allgemein', 'dashboard' => 'Dashboard', 'control_center' => 'Kontrollzentrum', 'alert_center' => 'Warnmeldungen',
            'sec_price_finance' => 'Preise & Finanzen', 'price_matrix' => 'Preismatrix', 'pricing_coach' => 'Pricing Coach (Autopilot)', 'ai_rev_mgmt' => 'AI Revenue Manager', 'liox_finance' => 'LioX ERP & Finanzen',
            'sec_management' => 'Verwaltung', 'guest_crm' => 'Gäste-CRM & Treue', 'housekeeping' => 'Housekeeping (HK)', 'suppliers' => 'Anbieter', 'agencies' => 'Agenturen', 'approvals' => 'Inserat-Freigaben',
            'sec_dist_channel' => 'Vertrieb & Kanäle', 'dist_health' => 'Vertriebs-Status', 'channel_wizard' => 'Kanal-Assistent', 'dex_marketing' => 'Dex Marketing Hub', 'booking_widget' => 'Buchungs-Widget', 'prep_summary' => 'Bereitschaftsübersicht', 'orphan_mappings' => 'Nicht zugewiesene Mappings',
            'sec_settings_cat' => 'Einstellungen & Katalog', 'cat_attributes' => 'Kategorien & Attribute', 'prod_templates' => 'Produktvorlagen', 'catalog_mgmt' => 'Katalogverwaltung', 'email_templates' => 'E-Mail-Vorlagen', 'timers' => 'Timer & Cronjobs',
            'sec_sec_monitor' => 'Sicherheit & Überwachung', 'kbs' => 'KBS Identitätsmeldung', 'audit_logs' => 'Audit-Protokolle', 'error_logs' => 'Fehlerüberwachung', 'two_fa' => '2FA-Einstellungen',
            'sec_ai_chat' => 'KI & Chat', 'deepseek_ai' => 'DeepSeek KI', 'gemini_ai' => 'Gemini KI', 'visitor_chat' => 'Besucher-Livechat',
            'sec_system' => 'System', 'migration_status' => 'Migrationsstatus', 'sms_mgmt' => 'SMS-Verwaltung', 'kvkk_tool' => 'DSGVO-Tool', 'user_guide' => 'Benutzerhandbuch',
            'logout' => 'Abmelden', 'home' => 'Startseite'
        ],
        'ru' => [
            'sec_general' => 'Общее', 'dashboard' => 'Дашборд', 'control_center' => 'Центр управления', 'alert_center' => 'Центр уведомлений',
            'sec_price_finance' => 'Цены и Финансы', 'price_matrix' => 'Матрица цен', 'pricing_coach' => 'Pricing Coach (Автопилот)', 'ai_rev_mgmt' => 'AI Управление доходами', 'liox_finance' => 'LioX ERP и Финансы',
            'sec_management' => 'Управление', 'guest_crm' => 'CRM гостей и лояльность', 'housekeeping' => 'Хаускипинг (HK)', 'suppliers' => 'Поставщики', 'agencies' => 'Агентства', 'approvals' => 'Одобрение объявлений',
            'sec_dist_channel' => 'Дистрибуция и каналы', 'dist_health' => 'Здоровье дистрибуции', 'channel_wizard' => 'Мастер каналов', 'dex_marketing' => 'Dex Маркетинг Хаб', 'booking_widget' => 'Виджет бронирования', 'prep_summary' => 'Сводка готовности', 'orphan_mappings' => 'Несвязанные элементы',
            'sec_settings_cat' => 'Настройки и каталог', 'cat_attributes' => 'Категории и свойства', 'prod_templates' => 'Шаблоны продуктов', 'catalog_mgmt' => 'Управление каталогом', 'email_templates' => 'Шаблоны email', 'timers' => 'Таймеры и планировщики',
            'sec_sec_monitor' => 'Безопасность и мониторинг', 'kbs' => 'KBS Уведомление полиции', 'audit_logs' => 'Журнал аудита', 'error_logs' => 'Мониторинг ошибок', 'two_fa' => 'Настройки 2FA',
            'sec_ai_chat' => 'ИИ и Чат', 'deepseek_ai' => 'DeepSeek ИИ', 'gemini_ai' => 'Gemini ИИ', 'visitor_chat' => 'Чат с посетителями',
            'sec_system' => 'Система', 'migration_status' => 'Статус миграций', 'sms_mgmt' => 'Управление SMS', 'kvkk_tool' => 'Конфиденциальность (GDPR)', 'user_guide' => 'Руководство пользователя',
            'logout' => 'Выйти', 'home' => 'Главная'
        ],
        'ar' => [
            'sec_general' => 'عام', 'dashboard' => 'لوحة التحكم', 'control_center' => 'مركز التحكم', 'alert_center' => 'مركز التنبيهات',
            'sec_price_finance' => 'الأسعار والمالية', 'price_matrix' => 'مصفوفة الأسعار', 'pricing_coach' => 'مدرب التسعير (الطيار الآلي)', 'ai_rev_mgmt' => 'مدير الإيرادات بالذكاء الاصطناعي', 'liox_finance' => 'LioX ERP والمالية',
            'sec_management' => 'الإدارة', 'guest_crm' => 'إدارة علاقات الضيوف والولاء', 'housekeeping' => 'خدمة الغرف (HK)', 'suppliers' => 'الموردون', 'agencies' => 'الوكالات', 'approvals' => 'الموافقات على الإعلانات',
            'sec_dist_channel' => 'التوزيع والقنوات', 'dist_health' => 'صحة التوزيع', 'channel_wizard' => 'معالج القنوات', 'dex_marketing' => 'مركز Dex للتسويق', 'booking_widget' => 'أداة الحجز المباشر', 'prep_summary' => 'ملخص الجاهزية', 'orphan_mappings' => 'الربط غير المكتمل',
            'sec_settings_cat' => 'الإعدادات والكتالوج', 'cat_attributes' => 'الفئات والخصائص', 'prod_templates' => 'نماذج المنتجات', 'catalog_mgmt' => 'إدارة الكتالوج', 'email_templates' => 'قوالب البريد', 'timers' => 'المؤقتات والمهام المجدولة',
            'sec_sec_monitor' => 'الأمان والمراقبة', 'kbs' => 'نظام KBS لإخطار الهوية', 'audit_logs' => 'سجلات التدقيق', 'error_logs' => 'تتبع الأخطاء', 'two_fa' => 'إعدادات 2FA',
            'sec_ai_chat' => 'الذكاء الاصطناعي والمحادثة', 'deepseek_ai' => 'DeepSeek AI', 'gemini_ai' => 'Gemini AI', 'visitor_chat' => 'محادثة الزوار المباشرة',
            'sec_system' => 'النظام', 'migration_status' => 'حالة الترحيل', 'sms_mgmt' => 'إدارة الرسائل القصيرة', 'kvkk_tool' => 'أداة الخصوصية وبيانات KVKK', 'user_guide' => 'دليل الاستخدام',
            'logout' => 'تسجيل الخروج', 'home' => 'الرئيسية'
        ],
        'fr' => [
            'sec_general' => 'Général', 'dashboard' => 'Tableau de bord', 'control_center' => 'Centre de contrôle', 'alert_center' => 'Centre d\'alertes',
            'sec_price_finance' => 'Tarifs & Finances', 'price_matrix' => 'Grille tarifaire', 'pricing_coach' => 'Pricing Coach (Autopilote)', 'ai_rev_mgmt' => 'Gestionnaire de revenus IA', 'liox_finance' => 'LioX ERP & Finances',
            'sec_management' => 'Gestion', 'guest_crm' => 'CRM Invités & Fidélité', 'housekeeping' => 'Gouvernance (HK)', 'suppliers' => 'Fournisseurs', 'agencies' => 'Agences', 'approvals' => 'Approbations d\'annonces',
            'sec_dist_channel' => 'Distribution & Canaux', 'dist_health' => 'Santé de la distribution', 'channel_wizard' => 'Assistant de canaux', 'dex_marketing' => 'Hub Marketing Dex', 'booking_widget' => 'Widget de réservation', 'prep_summary' => 'Résumé de préparation', 'orphan_mappings' => 'Mappages orphelins',
            'sec_settings_cat' => 'Paramètres & Catalogue', 'cat_attributes' => 'Catégories & Attributs', 'prod_templates' => 'Modèles de produits', 'catalog_mgmt' => 'Gestion du catalogue', 'email_templates' => 'Modèles d\'e-mails', 'timers' => 'Planificateurs & Timers',
            'sec_sec_monitor' => 'Sécurité & Surveillance', 'kbs' => 'Notification d\'identité KBS', 'audit_logs' => 'Journaux d\'audit', 'error_logs' => 'Suivi des erreurs', 'two_fa' => 'Paramètres 2FA',
            'sec_ai_chat' => 'IA & Chat', 'deepseek_ai' => 'IA DeepSeek', 'gemini_ai' => 'IA Gemini', 'visitor_chat' => 'Chat en direct visiteurs',
            'sec_system' => 'Système', 'migration_status' => 'État des migrations', 'sms_mgmt' => 'Gestion des SMS', 'kvkk_tool' => 'Outil RGPD / Confidentialité', 'user_guide' => 'Guide d\'utilisation',
            'logout' => 'Déconnexion', 'home' => 'Accueil'
        ]
    ];
    $t = $i18nAdmin[$lang] ?? $i18nAdmin['tr'];
    
    // Sidebar menüsü — Modern Font Awesome 6 ikonları ve renk temaları
    $navItems = [
        ['section' => $t['sec_general']],
        ['label' => $t['dashboard'], 'icon' => 'fa-solid fa-chart-pie', 'color' => 'purple', 'href' => $baseUri . '/admin/', 'key' => 'index'],
        ['label' => $t['control_center'], 'icon' => 'fa-solid fa-sliders', 'color' => 'blue', 'href' => $baseUri . '/admin/kontrol-merkezi', 'key' => 'kontrol-merkezi'],
        ['label' => $t['alert_center'], 'icon' => 'fa-solid fa-bell', 'color' => 'orange', 'href' => $baseUri . '/admin/uyari-merkezi', 'key' => 'uyari-merkezi'],
        
        ['section' => $t['sec_price_finance']],
        ['label' => $t['price_matrix'], 'icon' => 'fa-solid fa-calendar-days', 'color' => 'orange', 'href' => $baseUri . '/admin/fiyat-matrisi', 'key' => 'fiyat-matrisi'],
        ['label' => $t['pricing_coach'], 'icon' => 'fa-solid fa-gauge-high', 'color' => 'teal', 'href' => $baseUri . '/admin/pricing-coach', 'key' => 'pricing-coach'],
        ['label' => $t['ai_rev_mgmt'], 'icon' => 'fa-solid fa-brain', 'color' => 'purple', 'href' => $baseUri . '/admin/ai-gelir-yonetimi', 'key' => 'ai-gelir-yonetimi'],
        ['label' => $t['liox_finance'], 'icon' => 'fa-solid fa-file-invoice-dollar', 'color' => 'green', 'href' => $baseUri . '/admin/liox-finans', 'key' => 'liox-finans'],

        ['section' => $t['sec_management']],
        ['label' => $t['guest_crm'], 'icon' => 'fa-solid fa-users', 'color' => 'purple', 'href' => $baseUri . '/admin/misafir-crm', 'key' => 'misafir-crm'],
        ['label' => $t['housekeeping'], 'icon' => 'fa-solid fa-broom', 'color' => 'orange', 'href' => $baseUri . '/admin/kat-hizmetleri', 'key' => 'kat-hizmetleri'],
        ['label' => 'Restoran POS & QR Menü', 'icon' => 'fa-solid fa-utensils', 'color' => 'teal', 'href' => $baseUri . '/admin/liox-finans?tab=pos', 'key' => 'liox-finans-pos'],
        ['label' => 'SPA & Masaj Yönetimi', 'icon' => 'fa-solid fa-spa', 'color' => 'pink', 'href' => $baseUri . '/admin/liox-finans?tab=spa', 'key' => 'liox-finans-spa'],
        ['label' => $t['suppliers'], 'icon' => 'fa-solid fa-hotel', 'color' => 'teal', 'href' => $baseUri . '/admin/tedarikci-ilanlari', 'key' => 'tedarikci-ilanlari'],
        ['label' => $t['agencies'], 'icon' => 'fa-solid fa-briefcase', 'color' => 'indigo', 'href' => $baseUri . '/admin/acenteler', 'key' => 'acenteler'],
        ['label' => $t['approvals'], 'icon' => 'fa-solid fa-clipboard-check', 'color' => 'green', 'href' => $baseUri . '/admin/tedarikci-onaylari', 'key' => 'tedarikci-onaylari'],
        
        ['section' => $t['sec_dist_channel']],
        ['label' => $t['dist_health'], 'icon' => 'fa-solid fa-network-wired', 'color' => 'purple', 'href' => $baseUri . '/admin/dagitim-sagligi', 'key' => 'dagitim-sagligi'],
        ['label' => $t['channel_wizard'], 'icon' => 'fa-solid fa-wand-magic-sparkles', 'color' => 'teal', 'href' => $baseUri . '/admin/kanal-sihirbazi', 'key' => 'kanal-sihirbazi'],
        ['label' => $t['dex_marketing'], 'icon' => 'fa-solid fa-bullhorn', 'color' => 'pink', 'href' => $baseUri . '/admin/dijital-pazarlama', 'key' => 'dijital-pazarlama'],
        ['label' => $t['booking_widget'], 'icon' => 'fa-solid fa-globe', 'color' => 'purple', 'href' => $baseUri . '/admin/rezervasyon-widget', 'key' => 'rezervasyon-widget'],
        ['label' => $t['prep_summary'], 'icon' => 'fa-solid fa-chart-simple', 'color' => 'blue', 'href' => $baseUri . '/admin/hazirlik-ozet', 'key' => 'hazirlik-ozet'],
        ['label' => $t['orphan_mappings'], 'icon' => 'fa-solid fa-link-slash', 'color' => 'red', 'href' => $baseUri . '/admin/orphan-mappings', 'key' => 'orphan-mappings'],
        
        ['section' => $t['sec_settings_cat']],
        ['label' => $t['cat_attributes'], 'icon' => 'fa-solid fa-shapes', 'color' => 'teal', 'href' => $baseUri . '/admin/kategori-ozellikleri', 'key' => 'kategori-ozellikleri'],
        ['label' => $t['prod_templates'], 'icon' => 'fa-solid fa-layer-group', 'color' => 'purple', 'href' => $baseUri . '/admin/urun-turleri', 'key' => 'urun-turleri'],
        ['label' => $t['catalog_mgmt'], 'icon' => 'fa-solid fa-list-check', 'color' => 'indigo', 'href' => $baseUri . '/admin/ozellik-listeleri', 'key' => 'ozellik-listeleri'],
        ['label' => $t['email_templates'], 'icon' => 'fa-solid fa-envelope-open-text', 'color' => 'purple', 'href' => $baseUri . '/admin/eposta-sablonlari', 'key' => 'eposta-sablonlari'],
        ['label' => $t['timers'], 'icon' => 'fa-solid fa-stopwatch', 'color' => 'orange', 'href' => $baseUri . '/admin/timerlar', 'key' => 'timerlar'],
        
        ['section' => $t['sec_sec_monitor']],
        ['label' => $t['kbs'], 'icon' => 'fa-solid fa-id-card-clip', 'color' => 'teal', 'href' => $baseUri . '/admin/kbs-bildirim', 'key' => 'kbs-bildirim'],
        ['label' => $t['audit_logs'], 'icon' => 'fa-solid fa-shield-halved', 'color' => 'blue', 'href' => $baseUri . '/admin/denetim-kayitlari', 'key' => 'denetim-kayitlari'],
        ['label' => $t['error_logs'], 'icon' => 'fa-solid fa-triangle-exclamation', 'color' => 'red', 'href' => $baseUri . '/admin/hata-izleme', 'key' => 'hata-izleme'],
        ['label' => $t['two_fa'], 'icon' => 'fa-solid fa-key', 'color' => 'green', 'href' => $baseUri . '/admin/2fa', 'key' => '2fa'],
        
        ['section' => $t['sec_ai_chat']],
        ['label' => $t['deepseek_ai'], 'icon' => 'fa-solid fa-brain', 'color' => 'purple', 'href' => $baseUri . '/admin/ai-ayarlari', 'key' => 'ai-ayarlari'],
        ['label' => $t['gemini_ai'], 'icon' => 'fa-solid fa-wand-magic-sparkles', 'color' => 'pink', 'href' => $baseUri . '/admin/gemini-ayarlari', 'key' => 'gemini-ayarlari'],
        ['label' => $t['visitor_chat'], 'icon' => 'fa-solid fa-comments', 'color' => 'teal', 'href' => $baseUri . '/admin/ziyaretci-sohbet', 'key' => 'ziyaretci-sohbet'],
        
        ['section' => $t['sec_system']],
        ['label' => $t['migration_status'], 'icon' => 'fa-solid fa-database', 'color' => 'indigo', 'href' => $baseUri . '/admin/migration-durumu', 'key' => 'migration-durumu'],
        ['label' => $t['sms_mgmt'], 'icon' => 'fa-solid fa-comment-sms', 'color' => 'blue', 'href' => $baseUri . '/admin/sms-yonetimi', 'key' => 'sms-yonetimi'],
        ['label' => $t['kvkk_tool'], 'icon' => 'fa-solid fa-user-shield', 'color' => 'orange', 'href' => $baseUri . '/admin/kvkk', 'key' => 'kvkk'],
        ['label' => $t['user_guide'], 'icon' => 'fa-solid fa-book-bookmark', 'color' => 'green', 'href' => $baseUri . '/admin/kullanim-kilavuzu', 'key' => 'kullanim-kilavuzu'],
    ];
?>
<!doctype html>
<html lang="<?= htmlspecialchars($lang) ?>" dir="<?= $lang === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?> | NEXUS Admin</title>
    <link rel="icon" type="image/svg+xml" href="<?= $baseUri ?>/assets/favicon.svg">
    <link rel="icon" type="image/png" href="<?= $baseUri ?>/assets/favicon.png">
    <link rel="apple-touch-icon" href="<?= $baseUri ?>/assets/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?= $baseUri ?>/assets/admin-softui.css">
    <style>
        /* Kesintisiz Soft UI açık tema garantisi */
        html, body, body.sui {
            background-color: #f8f9fa !important;
            color: #2d3748 !important;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
            margin: 0;
            padding: 0;
        }
        .sui-main {
            background-color: #f8f9fa !important;
        }
        /* Soft UI İkon Kutuları */
        .sui-icon-box {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            flex-shrink: 0;
            transition: all 0.2s ease;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
        }
        .sui-icon-box.purple { background: linear-gradient(310deg, #7928ca, #ff0080); color: #fff; }
        .sui-icon-box.blue   { background: linear-gradient(310deg, #2152ff, #21d4fd); color: #fff; }
        .sui-icon-box.green  { background: linear-gradient(310deg, #17ad37, #98ec2d); color: #fff; }
        .sui-icon-box.orange { background: linear-gradient(310deg, #f53939, #fbcf33); color: #fff; }
        .sui-icon-box.red    { background: linear-gradient(310deg, #ea0606, #ff667c); color: #fff; }
        .sui-icon-box.teal   { background: linear-gradient(310deg, #0d9488, #2dd4bf); color: #fff; }
        .sui-icon-box.indigo { background: linear-gradient(310deg, #4f46e5, #818cf8); color: #fff; }
        .sui-icon-box.pink   { background: linear-gradient(310deg, #d63384, #f06292); color: #fff; }

        /* Aktif menü satırı — Beyaz yazı ve şık mor-pembe gradient */
        .sui-nav-item.active {
            background: linear-gradient(310deg, #7928ca, #ff0080) !important;
            color: #ffffff !important;
            font-weight: 700 !important;
            box-shadow: 0 10px 20px -6px rgba(121, 40, 202, 0.45) !important;
        }
        .sui-nav-item.active span {
            color: #ffffff !important;
        }
        .sui-nav-item.active .sui-icon-box {
            background: #ffffff !important;
            color: #7928ca !important;
            box-shadow: 0 4px 12px 0 rgba(0,0,0,0.15) !important;
            transform: scale(1.05);
        }
        .sui-nav-item {
            color: #67748e;
            font-weight: 500;
            padding: 10px 16px;
            margin: 3px 14px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .sui-nav-item:hover:not(.active) {
            background-color: #f8f9fa;
            color: #2d3748;
        }

        /* Navbar açık metin renkleri */
        .sui-breadcrumb a { color: #8392ab !important; text-decoration: none; font-size: 12px; }
        .sui-breadcrumb span { color: #344767 !important; font-weight: 600; font-size: 12px; }
        .sui-page-title { color: #344767 !important; font-size: 20px; font-weight: 700; margin: 4px 0 0 0; }
    </style>
</head>
<body class="sui">

<!-- Mobile toggle -->
<button onclick="document.querySelector('.sui-sidebar').classList.toggle('open');document.querySelector('.sui-backdrop').classList.toggle('show')" style="display:none;position:fixed;top:16px;left:16px;z-index:1001;width:42px;height:42px;border-radius:12px;border:none;background:#fff;box-shadow:var(--sui-shadow);font-size:18px;cursor:pointer" id="sui-menu-btn">
    <i class="fa-solid fa-bars"></i>
</button>
<div class="sui-backdrop" onclick="document.querySelector('.sui-sidebar').classList.remove('open');this.classList.remove('show')"></div>

<!-- Sidebar -->
<aside class="sui-sidebar">
    <a href="<?= $baseUri ?>/admin/" class="sui-sidebar-logo">
        <span style="font-size:22px;font-weight:900;background:linear-gradient(135deg,#7928ca,#ff0080);-webkit-background-clip:text;-webkit-text-fill-color:transparent">N∿XUS</span>
        <span style="font-size:11px;color:var(--sui-muted);font-weight:600;letter-spacing:0.5px">ADMIN</span>
    </a>
    <hr>
    <nav>
        <?php foreach ($navItems as $item): ?>
            <?php if (isset($item['section'])): ?>
                <div class="sui-nav-section"><?= htmlspecialchars($item['section']) ?></div>
            <?php else: ?>
                <a href="<?= htmlspecialchars($item['href']) ?>" class="sui-nav-item <?= $currentPage === ($item['key'] ?? '') ? 'active' : '' ?>">
                    <div class="sui-icon-box <?= $item['color'] ?? 'purple' ?>">
                        <i class="<?= $item['icon'] ?>"></i>
                    </div>
                    <span><?= htmlspecialchars($item['label']) ?></span>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    
    <!-- Sidebar bottom -->
    <div style="margin-top:auto;padding:12px 20px;border-top:1px solid var(--sui-border)">
        <a href="<?= $baseUri ?>/admin/logout" style="display:flex;align-items:center;gap:10px;padding:8px 0;color:var(--sui-muted);text-decoration:none;font-size:13px;font-weight:600">
            <div class="sui-icon-box red" style="width:26px;height:26px;font-size:11px">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
            </div>
            <span><?= htmlspecialchars($t['logout']) ?></span>
        </a>
    </div>
</aside>

<!-- Main Content -->
<main class="sui-main">
    <!-- Navbar -->
    <nav class="sui-navbar">
        <div>
            <div class="sui-breadcrumb">
                <a href="<?= $baseUri ?>/admin/">Admin</a>
                <span class="sep" style="margin:0 4px;color:#d2d6da">/</span>
                <span><?= htmlspecialchars($pageTitle) ?></span>
            </div>
            <h1 class="sui-page-title"><?= htmlspecialchars($pageTitle) ?></h1>
        </div>
        <div class="sui-navbar-actions" style="display:flex;align-items:center;gap:10px">
            <!-- Dil Seçici -->
            <div style="position:relative;display:inline-block">
                <select onchange="window.location.search='?lang='+this.value;document.cookie='nexus-admin-lang='+this.value+';path=/;max-age=31536000'" style="padding:6px 12px;border-radius:10px;border:1px solid #d2d6da;background:#fff;font-size:12px;font-weight:700;color:#344767;cursor:pointer;outline:none;box-shadow:0 2px 4px rgba(0,0,0,0.04)">
                    <option value="tr" <?= $lang === 'tr' ? 'selected' : '' ?>>🇹🇷 TR</option>
                    <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>>🇬🇧 EN</option>
                    <option value="de" <?= $lang === 'de' ? 'selected' : '' ?>>🇩🇪 DE</option>
                    <option value="ru" <?= $lang === 'ru' ? 'selected' : '' ?>>🇷🇺 RU</option>
                    <option value="ar" <?= $lang === 'ar' ? 'selected' : '' ?>>🇸🇦 AR</option>
                    <option value="fr" <?= $lang === 'fr' ? 'selected' : '' ?>>🇫🇷 FR</option>
                </select>
            </div>
            <a href="<?= $baseUri ?>/admin/" class="sui-btn sui-btn-outline sui-btn-sm" title="<?= htmlspecialchars($t['home']) ?>" style="width:36px;height:36px;padding:0;display:flex;align-items:center;justify-content:center;border-radius:10px;color:#67748e">
                <i class="fa-solid fa-house"></i>
            </a>
            <a href="<?= $baseUri ?>/admin/kontrol-merkezi" class="sui-btn sui-btn-outline sui-btn-sm" title="<?= htmlspecialchars($t['control_center']) ?>" style="width:36px;height:36px;padding:0;display:flex;align-items:center;justify-content:center;border-radius:10px;color:#67748e">
                <i class="fa-solid fa-gear"></i>
            </a>
        </div>
    </nav>
    
    <!-- Sayfa içeriği buraya gelecek -->
<?php
}

function admin_layout_end(): void {
?>
</main>

<script>
// Responsive sidebar
(function(){
    var mq = window.matchMedia('(max-width: 1199px)');
    function check(e) {
        var btn = document.getElementById('sui-menu-btn');
        if (btn) btn.style.display = e.matches ? 'block' : 'none';
        if (!e.matches) {
            document.querySelector('.sui-sidebar')?.classList.remove('open');
            document.querySelector('.sui-backdrop')?.classList.remove('show');
        }
    }
    mq.addListener(check);
    check(mq);
})();
</script>
</body>
</html>
<?php
}

