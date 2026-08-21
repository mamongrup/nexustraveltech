<?php
declare(strict_types=1);
// Admin ortak layout — sidebar + navbar + Soft UI teması
// Kullanım: require_once __DIR__.'/layout.php'; (sayfanın en başına)
// then: admin_layout_start('Sayfa Adı', 'kontrol-merkezi');

function admin_layout_start(string $pageTitle = 'NEXUS Admin', string $activePage = ''): void
{
    require_once __DIR__ . '/../config/auth.php';
    require_admin();
    
    // Aktif sayfayı belirle
    $currentPage = $activePage ?: basename($_SERVER['SCRIPT_NAME'], '.php');
    $baseUri = (strpos($_SERVER['REQUEST_URI'] ?? '', '/nexustraveltech') === 0) ? '/nexustraveltech' : '';
    
    // Sidebar menüsü
    $navItems = [
        ['section' => 'Genel'],
        ['label' => 'Dashboard', 'icon' => '📊', 'href' => $baseUri . '/admin/', 'key' => 'index'],
        ['label' => 'Kontrol Merkezi', 'icon' => '⚙️', 'href' => $baseUri . '/admin/kontrol-merkezi', 'key' => 'kontrol-merkezi'],
        ['label' => 'Uyarı Merkezi', 'icon' => '🔔', 'href' => $baseUri . '/admin/uyari-merkezi', 'key' => 'uyari-merkezi'],
        ['section' => 'Yönetim'],
        ['label' => 'Tedarikçiler', 'icon' => '🏨', 'href' => $baseUri . '/admin/tedarikci-ilanlari', 'key' => 'tedarikci-ilanlari'],
        ['label' => 'Acenteler', 'icon' => '🧳', 'href' => $baseUri . '/admin/acenteler', 'key' => 'acenteler'],
        ['label' => 'İlan Onayları', 'icon' => '📋', 'href' => $baseUri . '/admin/tedarikci-onaylari', 'key' => 'tedarikci-onaylari'],
        ['section' => 'Dağıtım & Kanal'],
        ['label' => 'Dağıtım Sağlığı', 'icon' => '📈', 'href' => $baseUri . '/admin/dagitim-sagligi', 'key' => 'dagitim-sagligi'],
        ['label' => 'Hazırlık Özeti', 'icon' => '📊', 'href' => $baseUri . '/admin/hazirlik-ozet', 'key' => 'hazirlik-ozet'],
        ['label' => 'Yetim Eşleştirmeler', 'icon' => '🔄', 'href' => $baseUri . '/admin/orphan-mappings', 'key' => 'orphan-mappings'],
        ['section' => 'Ayarlar'],
        ['label' => 'Ürün Şablonları', 'icon' => '📦', 'href' => $baseUri . '/admin/urun-turleri', 'key' => 'urun-turleri'],
        ['label' => 'Katalog Yönetimi', 'icon' => '📑', 'href' => $baseUri . '/admin/ozellik-listeleri', 'key' => 'ozellik-listeleri'],
        ['label' => 'E-posta Şablonları', 'icon' => '✉️', 'href' => $baseUri . '/admin/eposta-sablonlari', 'key' => 'eposta-sablonlari'],
        ['label' => 'Zamanlayıcılar', 'icon' => '⏱', 'href' => $baseUri . '/admin/timerlar', 'key' => 'timerlar'],
        ['section' => 'Güvenlik & İzleme'],
        ['label' => 'Denetim Kayıtları', 'icon' => '📝', 'href' => $baseUri . '/admin/denetim-kayitlari', 'key' => 'denetim-kayitlari'],
        ['label' => 'Hata İzleme', 'icon' => '🐛', 'href' => $baseUri . '/admin/hata-izleme', 'key' => 'hata-izleme'],
        ['label' => '2FA Ayarları', 'icon' => '🔐', 'href' => $baseUri . '/admin/2fa', 'key' => '2fa'],
        ['section' => 'AI & Sohbet'],
        ['label' => 'DeepSeek AI', 'icon' => '🤖', 'href' => $baseUri . '/admin/ai-ayarlari', 'key' => 'ai-ayarlari'],
        ['label' => 'Gemini AI', 'icon' => '🎨', 'href' => $baseUri . '/admin/gemini-ayarlari', 'key' => 'gemini-ayarlari'],
        ['label' => 'Ziyaretçi Sohbet', 'icon' => '💬', 'href' => $baseUri . '/admin/ziyaretci-sohbet', 'key' => 'ziyaretci-sohbet'],
        ['section' => 'Sistem'],
        ['label' => 'Migration Durumu', 'icon' => '🗄', 'href' => $baseUri . '/admin/migration-durumu', 'key' => 'migration-durumu'],
        ['label' => 'SMS Yönetimi', 'icon' => '📱', 'href' => $baseUri . '/admin/sms-yonetimi', 'key' => 'sms-yonetimi'],
        ['label' => 'KVKK Veri Aracı', 'icon' => '🗂', 'href' => $baseUri . '/admin/kvkk', 'key' => 'kvkk'],
        ['label' => 'Kullanım Kılavuzu', 'icon' => '📖', 'href' => $baseUri . '/admin/kullanim-kilavuzu', 'key' => 'kullanim-kilavuzu'],
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
</head>
<body class="sui">

<!-- Mobile toggle -->
<button onclick="document.querySelector('.sui-sidebar').classList.toggle('open');document.querySelector('.sui-backdrop').classList.toggle('show')" style="display:none;position:fixed;top:16px;left:16px;z-index:1001;width:42px;height:42px;border-radius:12px;border:none;background:#fff;box-shadow:var(--sui-shadow);font-size:18px;cursor:pointer" id="sui-menu-btn">
    <i class="fas fa-bars"></i>
</button>
<div class="sui-backdrop" onclick="document.querySelector('.sui-sidebar').classList.remove('open');this.classList.remove('show')"></div>

<!-- Sidebar -->
<aside class="sui-sidebar">
    <a href="/nexustraveltech/admin/" class="sui-sidebar-logo">
        <span style="font-size:22px;font-weight:900;background:linear-gradient(135deg,#7928ca,#ff0080);-webkit-background-clip:text;-webkit-text-fill-color:transparent">N∿XUS</span>
        <span style="font-size:11px;color:var(--sui-muted);font-weight:500">Admin Panel</span>
    </a>
    <hr>
    <nav>
        <?php foreach ($navItems as $item): ?>
            <?php if (isset($item['section'])): ?>
                <div class="sui-nav-section"><?= htmlspecialchars($item['section']) ?></div>
            <?php else: ?>
                <a href="<?= htmlspecialchars($item['href']) ?>" class="sui-nav-item <?= $currentPage === ($item['key'] ?? '') ? 'active' : '' ?>">
                    <span class="nav-icon"><?= $item['icon'] ?></span>
                    <span><?= htmlspecialchars($item['label']) ?></span>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    
    <!-- Sidebar bottom -->
    <div style="margin-top:auto;padding:12px 20px;border-top:1px solid var(--sui-border)">
        <a href="/nexustraveltech/admin/logout" style="display:flex;align-items:center;gap:8px;padding:8px 0;color:var(--sui-muted);text-decoration:none;font-size:13px;font-weight:500">
            <i class="fas fa-sign-out-alt"></i>
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
                <a href="/nexustraveltech/admin/">Admin</a>
                <span class="sep">/</span>
                <span><?= htmlspecialchars($pageTitle) ?></span>
            </div>
            <h1 class="sui-page-title"><?= htmlspecialchars($pageTitle) ?></h1>
        </div>
        <div class="sui-navbar-actions">
            <a href="/nexustraveltech/admin/" class="sui-btn sui-btn-outline sui-btn-sm" title="Ana sayfa">
                <i class="fas fa-home"></i>
            </a>
            <a href="/nexustraveltech/admin/kontrol-merkezi" class="sui-btn sui-btn-outline sui-btn-sm" title="Kontrol merkezi">
                <i class="fas fa-cog"></i>
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
