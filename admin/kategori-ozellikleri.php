<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/audit.php';
require_admin();

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$pdo = db();
$msg = '';
$err = '';

// Tablo Güvencesi: Kategori Nitelikleri & Özellik Kataloğu
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS category_attributes (
            id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            category_code VARCHAR(50) NOT NULL, -- hotel, villa, yacht, tour, activity, event, other
            group_name VARCHAR(100) NOT NULL,    -- Genel, Ekipman, Dahil Hizmetler, Güvenlik, Teknik vb.
            attribute_name VARCHAR(190) NOT NULL,
            attribute_type VARCHAR(30) DEFAULT 'boolean', -- boolean, text, select, number
            options_json JSONB DEFAULT '[]'::jsonb,
            is_required BOOLEAN DEFAULT false,
            is_filter BOOLEAN DEFAULT true,
            sort_order INTEGER DEFAULT 10,
            is_active BOOLEAN DEFAULT true,
            created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
            UNIQUE(category_code, group_name, attribute_name)
        );
    ");
} catch (Throwable $e) {}

// Varsayılan Kategori Özelliklerini Eksikse Ekle
$defaultAttributes = [
    // 1. Otel
    ['hotel', 'Genel Özellikler', 'Açık Yüzme Havuzu', 'boolean', '[]', true],
    ['hotel', 'Genel Özellikler', 'Kapalı Termal Havuz', 'boolean', '[]', true],
    ['hotel', 'Genel Özellikler', 'Spa & Masaj', 'boolean', '[]', true],
    ['hotel', 'Genel Özellikler', 'Denize Sıfır / Özel Plaj', 'boolean', '[]', true],
    ['hotel', 'Yeme & İçme', 'Her Şey Dahil (All Inclusive)', 'boolean', '[]', true],
    ['hotel', 'Yeme & İçme', 'A La Carte Restoran', 'boolean', '[]', true],

    // 2. Villa
    ['villa', 'Havuz & Bahçe', 'Özel Müstakil Havuz', 'boolean', '[]', true],
    ['villa', 'Havuz & Bahçe', 'Muhafazakar / Korunaklı Havuz', 'boolean', '[]', true],
    ['villa', 'Havuz & Bahçe', 'Isıtmalı Havuz', 'boolean', '[]', true],
    ['villa', 'İç Mekan & Konfor', 'Jakuzi', 'boolean', '[]', true],
    ['villa', 'İç Mekan & Konfor', 'Sauna & Hamam', 'boolean', '[]', true],
    ['villa', 'Konum & Manzara', 'Deniz Manzarası', 'boolean', '[]', true],

    // 3. Yat & Tekne
    ['yacht', 'Teknik & Kapasite', 'Kaptan Dahil', 'boolean', '[]', true],
    ['yacht', 'Teknik & Kapasite', 'Mürettebat / Aşçı Dahil', 'boolean', '[]', true],
    ['yacht', 'Teknik & Kapasite', 'Yakıt Dahil', 'boolean', '[]', true],
    ['yacht', 'Su Sporları & Ekipman', 'Şnorkel & Palet', 'boolean', '[]', true],
    ['yacht', 'Su Sporları & Ekipman', 'Paddleboard (SUP)', 'boolean', '[]', true],
    ['yacht', 'Su Sporları & Ekipman', 'SeaBob / Jet Ski', 'boolean', '[]', true],

    // 4. Tur
    ['tour', 'Tur Detayları', 'Profesyonel Rehber (TUREB)', 'boolean', '[]', true],
    ['tour', 'Tur Detayları', 'Öğle Yemeği Dahil', 'boolean', '[]', true],
    ['tour', 'Tur Detayları', 'Otelden Klimalı Transfer', 'boolean', '[]', true],
    ['tour', 'Tur Detayları', 'Müze & Örenyeri Giriş Biletleri', 'boolean', '[]', true],
    ['tour', 'Tur Detayları', 'Seyahat Sağlık Sigortası', 'boolean', '[]', true],

    // 5. Aktivite (Yamaç Paraşütü, Dalış, Rafting, Safari vb.)
    ['activity', 'Güvenlik & Ekipman', 'Profesyonel Pilot / Eğitmen Eşliğinde', 'boolean', '[]', true],
    ['activity', 'Güvenlik & Ekipman', 'Kask & Güvenlik Ekipmanları Dahil', 'boolean', '[]', true],
    ['activity', 'Medya & Çekim', 'GoPro 4K Fotoğraf & Video Çekimi', 'boolean', '[]', true],
    ['activity', 'Hizmetler', 'Zirveye / Parkura Transfer', 'boolean', '[]', true],
    ['activity', 'Gereksinimler', 'Minimum Yaş Sınırı: 6+', 'boolean', '[]', true],

    // 6. Etkinlik (Konser, Festival, Workshop vb.)
    ['event', 'Etkinlik Detayları', 'VIP Oturma / Lounge Erişimi', 'boolean', '[]', true],
    ['event', 'Etkinlik Detayları', 'İkram & Karşılama Kokteyli', 'boolean', '[]', true],
    ['event', 'Etkinlik Detayları', 'Otopark / Vale Hizmeti', 'boolean', '[]', true],
    ['event', 'Etkinlik Detayları', 'Özel Sanatçı / Şef Katılımı', 'boolean', '[]', true],

    // 7. Diğer (Transfer, Özel Şoför vb.)
    ['other', 'Hizmet Kapsamı', 'Mercedes Vito / VIP Araç', 'boolean', '[]', true],
    ['other', 'Hizmet Kapsamı', 'Ücretsiz İçecek & Wi-Fi', 'boolean', '[]', true],
    ['other', 'Hizmet Kapsamı', 'Uçuş Takibi & Havalimanı Karşılama', 'boolean', '[]', true],
];

try {
    $cQ = (int)$pdo->query("SELECT COUNT(*) FROM category_attributes")->fetchColumn();
    if ($cQ === 0) {
        $insStmt = $pdo->prepare("
            INSERT INTO category_attributes (category_code, group_name, attribute_name, attribute_type, options_json, is_active)
            VALUES (?, ?, ?, ?, ?::jsonb, ?)
            ON CONFLICT DO NOTHING
        ");
        foreach ($defaultAttributes as $da) {
            $insStmt->execute([$da[0], $da[1], $da[2], $da[3], $da[4], $da[5]]);
        }
    }
} catch (Throwable $e) {}

// POST: Yeni Özellik Ekle / Sil / Durum Değiştir
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['admin_csrf'], (string)($_POST['csrf'] ?? ''))) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add_attribute') {
        $cat = trim((string)($_POST['category_code'] ?? 'hotel'));
        $grp = trim((string)($_POST['group_name'] ?? 'Genel'));
        $name = trim((string)($_POST['attribute_name'] ?? ''));
        $type = (string)($_POST['attribute_type'] ?? 'boolean');

        if ($name !== '' && $grp !== '') {
            try {
                $ins = $pdo->prepare("
                    INSERT INTO category_attributes (category_code, group_name, attribute_name, attribute_type)
                    VALUES (?, ?, ?, ?)
                    ON CONFLICT (category_code, group_name, attribute_name)
                    DO UPDATE SET is_active=true
                ");
                $ins->execute([$cat, $grp, $name, $type]);
                audit_log('category_attributes.add', 'category_attributes', (int)$pdo->lastInsertId(), ['name' => $name, 'category' => $cat]);
                $msg = "✓ Yeni nitelik/özellik [$cat > $grp > $name] başarıyla eklendi.";
            } catch (Throwable $e) {
                $err = "Ekleme hatası: " . $e->getMessage();
            }
        } else {
            $err = "Lütfen özellik adı ve grup adını eksiksiz girin.";
        }
    }

    if ($action === 'toggle_attr') {
        $aid = (int)($_POST['id'] ?? 0);
        if ($aid > 0) {
            $pdo->prepare("UPDATE category_attributes SET is_active = NOT is_active WHERE id=?")->execute([$aid]);
            $msg = "Özellik durumu güncellendi.";
        }
    }

    if ($action === 'delete_attr') {
        $aid = (int)($_POST['id'] ?? 0);
        if ($aid > 0) {
            $pdo->prepare("DELETE FROM category_attributes WHERE id=?")->execute([$aid]);
            $msg = "Özellik silindi.";
        }
    }
}

$categories = [
    'hotel' => ['title' => '🏨 Otel & Konaklama', 'color' => 'purple', 'badge' => 'Otel'],
    'villa' => ['title' => '🏡 Villa & Tatil Evi', 'color' => 'orange', 'badge' => 'Villa'],
    'yacht' => ['title' => '⛵ Yat & Tekne', 'color' => 'blue', 'badge' => 'Yat'],
    'tour' => ['title' => '🗺️ Tur & Gezi', 'color' => 'teal', 'badge' => 'Tur'],
    'activity' => ['title' => '🪂 Aktivite & Spor', 'color' => 'pink', 'badge' => 'Aktivite'],
    'event' => ['title' => '🎟️ Etkinlik & Konser', 'color' => 'green', 'badge' => 'Etkinlik'],
    'other' => ['title' => '🚗 Transfer & Diğer', 'color' => 'indigo', 'badge' => 'Diğer'],
];

$selectedCat = (string)($_GET['cat'] ?? 'hotel');
if (!isset($categories[$selectedCat])) $selectedCat = 'hotel';

$attrList = [];
try {
    $aq = $pdo->prepare("SELECT * FROM category_attributes WHERE category_code=? ORDER BY group_name, sort_order, id");
    $aq->execute([$selectedCat]);
    $attrList = $aq->fetchAll();
} catch (Throwable $e) {}

// Gruplara göre toparla
$groupedAttrs = [];
foreach ($attrList as $a) {
    $gName = $a['group_name'] ?: 'Genel';
    $groupedAttrs[$gName][] = $a;
}

require_once __DIR__ . '/layout.php';
admin_layout_start('Ürün Kategorileri & Nitelik Yönetimi', 'urun-turleri');
?>

<?php if ($msg): ?><div class="sui-alert sui-alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="sui-alert sui-alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- Üst Başlık & Kategori Seçici Tablar -->
<div class="sui-card" style="margin-bottom:24px">
    <div class="sui-card-header">
        <div>
            <h2 class="sui-card-title"><i class="fa-solid fa-shapes" style="color:var(--sui-primary);margin-right:8px"></i> 7 Ana Ürün Kategorisi Nitelik & Özellik Matrisi</h2>
            <p style="color:var(--sui-muted);font-size:13px;margin:4px 0 0 0">
                Otel, Villa, Yat, Tur, Aktivite, Etkinlik ve Transfer kategorilerinin ilan formlarında çıkacak tüm özellik ve niteliklerini buradan dinamik olarak yönetebilirsiniz.
            </p>
        </div>
        <div>
            <button type="button" class="sui-btn sui-btn-primary" onclick="document.getElementById('attrModal').style.display='flex'">
                <i class="fa-solid fa-plus"></i> Bu Kategoriye Nitelik Ekle
            </button>
        </div>
    </div>

    <!-- Kategori Tab Butonları -->
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px">
        <?php foreach ($categories as $cKey => $cVal): ?>
            <a href="?cat=<?= $cKey ?>" class="sui-btn <?= $selectedCat === $cKey ? 'sui-btn-primary' : 'sui-btn-outline' ?>" style="font-size:13px">
                <?= $cVal['title'] ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Nitelikler Listesi -->
<?php if (!$groupedAttrs): ?>
    <div class="sui-card" style="text-align:center;padding:40px;color:var(--sui-muted)">
        <i class="fa-solid fa-layer-group" style="font-size:36px;margin-bottom:10px"></i>
        <p>Bu kategori için henüz tanımlanmış nitelik bulunmuyor.</p>
    </div>
<?php else: ?>
    <?php foreach ($groupedAttrs as $grpName => $items): ?>
        <div class="sui-card" style="margin-bottom:20px">
            <div class="sui-card-header" style="margin-bottom:12px">
                <h3 style="font-size:15px;font-weight:700;margin:0">
                    <i class="fa-solid fa-folder-open" style="color:var(--sui-primary);margin-right:6px"></i> <?= htmlspecialchars($grpName) ?> (<?= count($items) ?> Özellik)
                </h3>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(280px, 1fr));gap:10px">
                <?php foreach ($items as $it): ?>
                    <div style="background:#f8fafc;border:1px solid var(--sui-border);border-radius:10px;padding:10px 14px;display:flex;justify-content:space-between;align-items:center">
                        <div>
                            <b style="font-size:13px;color:#1e293b"><?= htmlspecialchars($it['attribute_name']) ?></b>
                            <div style="font-size:11px;color:var(--sui-muted)">Tür: <?= htmlspecialchars($it['attribute_type']) ?></div>
                        </div>

                        <div style="display:flex;gap:6px;align-items:center">
                            <form method="post" style="margin:0">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                                <input type="hidden" name="action" value="toggle_attr">
                                <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                <button type="submit" class="sui-badge <?= $it['is_active'] ? 'sui-badge-success' : 'sui-badge-danger' ?>" style="border:none;cursor:pointer">
                                    <?= $it['is_active'] ? 'AKTİF' : 'PASİF' ?>
                                </button>
                            </form>

                            <form method="post" style="margin:0" onsubmit="return confirm('Bu özelliği silmek istediğinize emin misiniz?')">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                                <input type="hidden" name="action" value="delete_attr">
                                <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                <button type="submit" class="sui-btn sui-btn-outline sui-btn-sm" style="padding:2px 6px;color:#dc2626;border-color:#fca5a5">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Yeni Nitelik Ekleme Modalı -->
<div id="attrModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);backdrop-filter:blur(4px);z-index:9999;align-items:center;justify-content:center;padding:16px">
    <div style="background:#fff;border-radius:18px;max-width:460px;width:100%;padding:24px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.2)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
            <h3 style="font-size:16px;font-weight:800">Yeni Kategori Niteliği Ekle</h3>
            <button type="button" style="background:none;border:none;font-size:20px;cursor:pointer" onclick="document.getElementById('attrModal').style.display='none'">×</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
            <input type="hidden" name="action" value="add_attribute">
            <input type="hidden" name="category_code" value="<?= htmlspecialchars($selectedCat) ?>">

            <div style="margin-bottom:12px">
                <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px">Kategori</label>
                <input type="text" value="<?= $categories[$selectedCat]['title'] ?>" class="sui-input" disabled style="background:#f1f5f9">
            </div>

            <div style="margin-bottom:12px">
                <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px">Grup Adı</label>
                <input type="text" name="group_name" placeholder="Örn: Güvenlik, Yeme & İçme, Ekipman" class="sui-input" required>
            </div>

            <div style="margin-bottom:12px">
                <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px">Nitelik / Özellik Adı</label>
                <input type="text" name="attribute_name" placeholder="Örn: Rehber Dahil, GoPro Çekimi" class="sui-input" required>
            </div>

            <div style="margin-bottom:18px">
                <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px">Alan Türü</label>
                <select name="attribute_type" class="sui-input">
                    <option value="boolean">Seçim Kutusu (Var / Yok - On/Off)</option>
                    <option value="text">Metin Girişi (Yazı)</option>
                    <option value="number">Sayısal Değer</option>
                </select>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px">
                <button type="button" class="sui-btn sui-btn-outline" onclick="document.getElementById('attrModal').style.display='none'">İptal</button>
                <button type="submit" class="sui-btn sui-btn-primary"><i class="fa-solid fa-plus"></i> Özelliği Ekle</button>
            </div>
        </form>
    </div>
</div>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>
