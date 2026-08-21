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
    
    // Sidebar menüsü — Modern Font Awesome 6 ikonları ve renk temaları
    $navItems = [
        ['section' => 'Genel'],
        ['label' => 'Dashboard', 'icon' => 'fa-solid fa-chart-pie', 'color' => 'purple', 'href' => $baseUri . '/admin/', 'key' => 'index'],
        ['label' => 'Kontrol Merkezi', 'icon' => 'fa-solid fa-sliders', 'color' => 'blue', 'href' => $baseUri . '/admin/kontrol-merkezi', 'key' => 'kontrol-merkezi'],
        ['label' => 'Uyarı Merkezi', 'icon' => 'fa-solid fa-bell', 'color' => 'orange', 'href' => $baseUri . '/admin/uyari-merkezi', 'key' => 'uyari-merkezi'],
        
        ['section' => 'Fiyat & Finans'],
        ['label' => 'Fiyat Matrisi', 'icon' => 'fa-solid fa-calendar-days', 'color' => 'orange', 'href' => $baseUri . '/admin/fiyat-matrisi', 'key' => 'fiyat-matrisi'],
        ['label' => 'Pricing Coach (Otopilot)', 'icon' => 'fa-solid fa-gauge-high', 'color' => 'teal', 'href' => $baseUri . '/admin/pricing-coach', 'key' => 'pricing-coach'],
        ['label' => 'AI Gelir Yöneticisi', 'icon' => 'fa-solid fa-brain', 'color' => 'purple', 'href' => $baseUri . '/admin/ai-gelir-yonetimi', 'key' => 'ai-gelir-yonetimi'],
        ['label' => 'LioX ERP & Finans', 'icon' => 'fa-solid fa-file-invoice-dollar', 'color' => 'green', 'href' => $baseUri . '/admin/liox-finans', 'key' => 'liox-finans'],

        ['section' => 'Yönetim'],
        ['label' => 'Misafir CRM & Sadakat', 'icon' => 'fa-solid fa-users', 'color' => 'purple', 'href' => $baseUri . '/admin/misafir-crm', 'key' => 'misafir-crm'],
        ['label' => 'Kat Hizmetleri (HK)', 'icon' => 'fa-solid fa-broom', 'color' => 'orange', 'href' => $baseUri . '/admin/kat-hizmetleri', 'key' => 'kat-hizmetleri'],
        ['label' => 'Tedarikçiler', 'icon' => 'fa-solid fa-hotel', 'color' => 'teal', 'href' => $baseUri . '/admin/tedarikci-ilanlari', 'key' => 'tedarikci-ilanlari'],
        ['label' => 'Acenteler', 'icon' => 'fa-solid fa-briefcase', 'color' => 'indigo', 'href' => $baseUri . '/admin/acenteler', 'key' => 'acenteler'],
        ['label' => 'İlan Onayları', 'icon' => 'fa-solid fa-clipboard-check', 'color' => 'green', 'href' => $baseUri . '/admin/tedarikci-onaylari', 'key' => 'tedarikci-onaylari'],
        
        ['section' => 'Dağıtım & Kanal'],
        ['label' => 'Dağıtım Sağlığı', 'icon' => 'fa-solid fa-network-wired', 'color' => 'purple', 'href' => $baseUri . '/admin/dagitim-sagligi', 'key' => 'dagitim-sagligi'],
        ['label' => 'Kanal Sihirbazı', 'icon' => 'fa-solid fa-wand-magic-sparkles', 'color' => 'teal', 'href' => $baseUri . '/admin/kanal-sihirbazi', 'key' => 'kanal-sihirbazi'],
        ['label' => 'Dex Pazarlama Hub', 'icon' => 'fa-solid fa-bullhorn', 'color' => 'pink', 'href' => $baseUri . '/admin/dijital-pazarlama', 'key' => 'dijital-pazarlama'],
        ['label' => 'Rezervasyon Widget', 'icon' => 'fa-solid fa-globe', 'color' => 'purple', 'href' => $baseUri . '/admin/rezervasyon-widget', 'key' => 'rezervasyon-widget'],
        ['label' => 'Hazırlık Özeti', 'icon' => 'fa-solid fa-chart-simple', 'color' => 'blue', 'href' => $baseUri . '/admin/hazirlik-ozet', 'key' => 'hazirlik-ozet'],
        ['label' => 'Yetim Eşleştirmeler', 'icon' => 'fa-solid fa-link-slash', 'color' => 'red', 'href' => $baseUri . '/admin/orphan-mappings', 'key' => 'orphan-mappings'],
        
        ['section' => 'Ayarlar & Katalog'],
        ['label' => 'Kategori & Nitelikler', 'icon' => 'fa-solid fa-shapes', 'color' => 'teal', 'href' => $baseUri . '/admin/kategori-ozellikleri', 'key' => 'kategori-ozellikleri'],
        ['label' => 'Ürün Şablonları', 'icon' => 'fa-solid fa-layer-group', 'color' => 'purple', 'href' => $baseUri . '/admin/urun-turleri', 'key' => 'urun-turleri'],
        ['label' => 'Katalog Yönetimi', 'icon' => 'fa-solid fa-list-check', 'color' => 'indigo', 'href' => $baseUri . '/admin/ozellik-listeleri', 'key' => 'ozellik-listeleri'],
        ['label' => 'E-posta Şablonları', 'icon' => 'fa-solid fa-envelope-open-text', 'color' => 'purple', 'href' => $baseUri . '/admin/eposta-sablonlari', 'key' => 'eposta-sablonlari'],
        ['label' => 'Zamanlayıcılar', 'icon' => 'fa-solid fa-stopwatch', 'color' => 'orange', 'href' => $baseUri . '/admin/timerlar', 'key' => 'timerlar'],
        
        ['section' => 'Güvenlik & İzleme'],
        ['label' => 'KBS Kimlik Bildirimi', 'icon' => 'fa-solid fa-id-card-clip', 'color' => 'teal', 'href' => $baseUri . '/admin/kbs-bildirim', 'key' => 'kbs-bildirim'],
        ['label' => 'Denetim Kayıtları', 'icon' => 'fa-solid fa-shield-halved', 'color' => 'blue', 'href' => $baseUri . '/admin/denetim-kayitlari', 'key' => 'denetim-kayitlari'],
        ['label' => 'Hata İzleme', 'icon' => 'fa-solid fa-triangle-exclamation', 'color' => 'red', 'href' => $baseUri . '/admin/hata-izleme', 'key' => 'hata-izleme'],
        ['label' => '2FA Ayarları', 'icon' => 'fa-solid fa-key', 'color' => 'green', 'href' => $baseUri . '/admin/2fa', 'key' => '2fa'],
        
        ['section' => 'AI & Sohbet'],
        ['label' => 'DeepSeek AI', 'icon' => 'fa-solid fa-brain', 'color' => 'purple', 'href' => $baseUri . '/admin/ai-ayarlari', 'key' => 'ai-ayarlari'],
        ['label' => 'Gemini AI', 'icon' => 'fa-solid fa-wand-magic-sparkles', 'color' => 'pink', 'href' => $baseUri . '/admin/gemini-ayarlari', 'key' => 'gemini-ayarlari'],
        ['label' => 'Ziyaretçi Sohbet', 'icon' => 'fa-solid fa-comments', 'color' => 'teal', 'href' => $baseUri . '/admin/ziyaretci-sohbet', 'key' => 'ziyaretci-sohbet'],
        
        ['section' => 'Sistem'],
        ['label' => 'Migration Durumu', 'icon' => 'fa-solid fa-database', 'color' => 'indigo', 'href' => $baseUri . '/admin/migration-durumu', 'key' => 'migration-durumu'],
        ['label' => 'SMS Yönetimi', 'icon' => 'fa-solid fa-comment-sms', 'color' => 'blue', 'href' => $baseUri . '/admin/sms-yonetimi', 'key' => 'sms-yonetimi'],
        ['label' => 'KVKK Veri Aracı', 'icon' => 'fa-solid fa-user-shield', 'color' => 'orange', 'href' => $baseUri . '/admin/kvkk', 'key' => 'kvkk'],
        ['label' => 'Kullanım Kılavuzu', 'icon' => 'fa-solid fa-book-bookmark', 'color' => 'green', 'href' => $baseUri . '/admin/kullanim-kilavuzu', 'key' => 'kullanim-kilavuzu'],
    ];
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?> | NEXUS Admin</title>
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
            <span>Çıkış Yap</span>
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
        <div class="sui-navbar-actions">
            <a href="<?= $baseUri ?>/admin/" class="sui-btn sui-btn-outline sui-btn-sm" title="Ana sayfa" style="width:36px;height:36px;padding:0;display:flex;align-items:center;justify-content:center;border-radius:10px;color:#67748e">
                <i class="fa-solid fa-house"></i>
            </a>
            <a href="<?= $baseUri ?>/admin/kontrol-merkezi" class="sui-btn sui-btn-outline sui-btn-sm" title="Kontrol merkezi" style="width:36px;height:36px;padding:0;display:flex;align-items:center;justify-content:center;border-radius:10px;color:#67748e">
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

