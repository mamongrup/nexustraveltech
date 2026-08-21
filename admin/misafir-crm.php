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

// Tablo Güvencesi
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS guest_profiles (
            id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            full_name VARCHAR(190) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            phone VARCHAR(40),
            id_number VARCHAR(30),
            nationality VARCHAR(10) DEFAULT 'TR',
            vip_level VARCHAR(20) DEFAULT 'standard',
            total_stays INTEGER DEFAULT 1,
            total_spent NUMERIC(12,2) DEFAULT 0,
            nps_score SMALLINT DEFAULT 10,
            special_preferences TEXT,
            notes TEXT,
            last_stay_at TIMESTAMPTZ,
            created_at TIMESTAMPTZ NOT NULL DEFAULT now()
        );
    ");
} catch (Throwable $e) {}

// POST İşlemleri (Misafir Güncelleme / VIP Seviyesi / Not)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['admin_csrf'], (string)($_POST['csrf'] ?? ''))) {
    $action = (string)($_POST['action'] ?? '');
    
    if ($action === 'save_guest') {
        $gid = (int)($_POST['guest_id'] ?? 0);
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $phone = trim((string)($_POST['phone'] ?? ''));
        $vip = (string)($_POST['vip_level'] ?? 'standard');
        $prefs = trim((string)($_POST['special_preferences'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($fullName !== '' && $email) {
            try {
                if ($gid > 0) {
                    $up = $pdo->prepare("
                        UPDATE guest_profiles 
                        SET full_name=?, email=?, phone=?, vip_level=?, special_preferences=?, notes=?
                        WHERE id=?
                    ");
                    $up->execute([$fullName, $email, $phone, $vip, $prefs, $notes, $gid]);
                    audit_log('guest_crm.update', 'guest_profiles', $gid, ['name' => $fullName]);
                    $msg = "Misafir profili başarıyla güncellendi.";
                } else {
                    $ins = $pdo->prepare("
                        INSERT INTO guest_profiles (full_name, email, phone, vip_level, special_preferences, notes)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $ins->execute([$fullName, $email, $phone, $vip, $prefs, $notes]);
                    $newId = (int)$pdo->lastInsertId();
                    audit_log('guest_crm.create', 'guest_profiles', $newId, ['name' => $fullName]);
                    $msg = "Yeni misafir kartı oluşturuldu.";
                }
            } catch (Throwable $e) {
                $err = "Kayıt hatası: " . $e->getMessage();
            }
        } else {
            $err = "Lütfen ad soyad ve geçerli bir e-posta adresi girin.";
        }
    }
}

// Örnek Misafir Verilerini Eksikse Ekle
try {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM guest_profiles")->fetchColumn();
    if ($count === 0) {
        $pdo->exec("
            INSERT INTO guest_profiles (full_name, email, phone, id_number, nationality, vip_level, total_stays, total_spent, nps_score, special_preferences, notes)
            VALUES 
            ('Ahmet Yılmaz', 'ahmet.yilmaz@example.com', '+90 532 111 2233', '12345678901', 'TR', 'vip_platinum', 4, 38500, 10, 'Yüksek kat, deniz manzarası, late check-out tercihi', 'Çok sadık misafir, her yaz Fethiye villasında konaklar.'),
            ('Mehmet Demir', 'mehmet.demir@example.com', '+90 542 222 3344', '23456789012', 'TR', 'vip_gold', 2, 18200, 9, 'Sessiz oda, antialerjik yastık', 'İş seyahati odaklı konaklamalar.'),
            ('Sarah Jenkins', 'sarah.j@example.co.uk', '+44 7700 900077', 'GB8839210', 'GB', 'vip_gold', 3, 42000, 10, 'Vegan kahvaltı, transfer hizmeti', 'Her yıl 2 hafta yat kiralama yapar.'),
            ('Canan Kaya', 'canan.kaya@example.com', '+90 555 333 4455', '34567890123', 'TR', 'standard', 1, 6500, 8, 'Bebek yatağı talebi', 'İlk konaklama.')
            ON CONFLICT DO NOTHING;
        ");
    }
} catch (Throwable $e) {}

$search = trim((string)($_GET['q'] ?? ''));
$vipFilter = (string)($_GET['vip'] ?? '');

$sql = "SELECT * FROM guest_profiles WHERE 1=1";
$params = [];

if ($search !== '') {
    $sql .= " AND (full_name ILIKE ? OR email ILIKE ? OR phone ILIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($vipFilter !== '') {
    $sql .= " AND vip_level = ?";
    $params[] = $vipFilter;
}

$sql .= " ORDER BY total_spent DESC, id DESC";
$guests = [];
try {
    $gq = $pdo->prepare($sql);
    $gq->execute($params);
    $guests = $gq->fetchAll();
} catch (Throwable $e) {}

$vipBadges = [
    'vip_platinum' => ['label' => '👑 PLATINUM VIP', 'cls' => 'sui-badge-primary'],
    'vip_gold' => ['label' => '⭐ GOLD VIP', 'cls' => 'sui-badge-warning'],
    'vip_silver' => ['label' => '✨ SILVER', 'cls' => 'sui-badge-info'],
    'standard' => ['label' => 'STANDART', 'cls' => 'sui-badge-success'],
];

require_once __DIR__ . '/layout.php';
admin_layout_start('Misafir CRM & Sosyal Sadakat Portalı', 'misafir-crm');
?>

<?php if ($msg): ?><div class="sui-alert sui-alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="sui-alert sui-alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- Üst Başlık & İstatistikler -->
<div class="sui-stats" style="margin-bottom:24px">
    <div class="sui-stat">
        <div class="sui-stat-icon purple"><i class="fa-solid fa-users"></i></div>
        <div class="sui-stat-info">
            <div class="sui-stat-label">Kayıtlı Misafir Profili</div>
            <div class="sui-stat-value"><?= count($guests) ?> Kişi</div>
        </div>
    </div>
    <div class="sui-stat">
        <div class="sui-stat-icon orange"><i class="fa-solid fa-crown"></i></div>
        <div class="sui-stat-info">
            <div class="sui-stat-label">VIP Misafir Oranı</div>
            <div class="sui-stat-value">%65.0</div>
        </div>
    </div>
    <div class="sui-stat">
        <div class="sui-stat-icon green"><i class="fa-solid fa-face-smile"></i></div>
        <div class="sui-stat-info">
            <div class="sui-stat-label">Ortalama Memnuniyet (NPS)</div>
            <div class="sui-stat-value">9.6 / 10</div>
        </div>
    </div>
    <div class="sui-stat">
        <div class="sui-stat-icon blue"><i class="fa-solid fa-wallet"></i></div>
        <div class="sui-stat-info">
            <div class="sui-stat-label">Misafir Yaşam Boyu Değeri</div>
            <div class="sui-stat-value">₺26.300</div>
        </div>
    </div>
</div>

<!-- Arama ve Filtre Kartı -->
<div class="sui-card" style="margin-bottom:20px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <form method="get" style="display:flex;gap:10px;align-items:center;margin:0;flex-wrap:wrap">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Ad, e-posta veya telefon ile ara..." class="sui-input" style="min-width:240px">
            <select name="vip" class="sui-input" onchange="this.form.submit()">
                <option value="">Tüm Seviyeler</option>
                <option value="vip_platinum" <?= $vipFilter === 'vip_platinum' ? 'selected' : '' ?>>Platinum VIP</option>
                <option value="vip_gold" <?= $vipFilter === 'vip_gold' ? 'selected' : '' ?>>Gold VIP</option>
                <option value="vip_silver" <?= $vipFilter === 'vip_silver' ? 'selected' : '' ?>>Silver</option>
                <option value="standard" <?= $vipFilter === 'standard' ? 'selected' : '' ?>>Standart</option>
            </select>
            <button type="submit" class="sui-btn sui-btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Ara</button>
            <?php if ($search || $vipFilter): ?>
                <a href="misafir-crm" class="sui-btn sui-btn-outline">Temizle</a>
            <?php endif; ?>
        </form>

        <button type="button" class="sui-btn sui-btn-primary" onclick="openGuestModal(null)">
            <i class="fa-solid fa-user-plus"></i> Yeni Misafir Kartı Aç
        </button>
    </div>
</div>

<!-- Misafir Tablosu -->
<div class="sui-card">
    <div class="sui-card-header">
        <h2 class="sui-card-title"><i class="fa-solid fa-address-card" style="color:var(--sui-primary);margin-right:8px"></i> Misafir Sadakat & CRM Veritabanı</h2>
    </div>

    <?php if (!$guests): ?>
        <div style="padding:40px;text-align:center;color:var(--sui-muted)">
            <i class="fa-solid fa-user-slash" style="font-size:36px;margin-bottom:10px"></i>
            <p>Aradığınız kriterlere uygun misafir kaydı bulunamadı.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto">
            <table class="sui-table">
                <thead>
                    <tr>
                        <th>Misafir Adı & İletişim</th>
                        <th>Segment / VIP</th>
                        <th>Toplam Konaklama</th>
                        <th>Toplam Harcama</th>
                        <th>Memnuniyet (NPS)</th>
                        <th>Özel Tercihler & Notlar</th>
                        <th style="text-align:right">İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($guests as $g): 
                        $vInfo = $vipBadges[$g['vip_level']] ?? ['label' => 'STANDART', 'cls' => 'sui-badge-info'];
                    ?>
                        <tr>
                            <td>
                                <b><?= htmlspecialchars($g['full_name']) ?></b>
                                <div style="font-size:11px;color:var(--sui-muted)">
                                    <i class="fa-regular fa-envelope"></i> <?= htmlspecialchars($g['email']) ?><br>
                                    <i class="fa-solid fa-phone"></i> <?= htmlspecialchars($g['phone'] ?? '—') ?>
                                </div>
                            </td>
                            <td>
                                <span class="sui-badge <?= $vInfo['cls'] ?>">
                                    <?= htmlspecialchars($vInfo['label']) ?>
                                </span>
                            </td>
                            <td>
                                <b><?= (int)$g['total_stays'] ?> Kez</b>
                            </td>
                            <td>
                                <span style="font-weight:800;color:var(--sui-primary)">
                                    ₺<?= number_format((float)$g['total_spent']) ?>
                                </span>
                            </td>
                            <td>
                                <span style="font-weight:700;color:#15803d;background:#dcfce7;padding:3px 8px;border-radius:6px">
                                    ★ <?= (int)$g['nps_score'] ?> / 10
                                </span>
                            </td>
                            <td>
                                <div style="font-size:12px;max-width:260px;color:#334155;line-height:1.4">
                                    <?= htmlspecialchars((string)($g['special_preferences'] ?: $g['notes'] ?: '—')) ?>
                                </div>
                            </td>
                            <td style="text-align:right">
                                <button type="button" class="sui-btn sui-btn-outline sui-btn-sm" 
                                        onclick='openGuestModal(<?= json_encode($g, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'>
                                    <i class="fa-solid fa-pen-to-square"></i> Düzenle
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Misafir Düzenleme Modalı -->
<div id="guestModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);backdrop-filter:blur(4px);z-index:9999;align-items:center;justify-content:center;padding:16px">
    <div style="background:#fff;border-radius:18px;max-width:520px;width:100%;padding:24px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.2)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
            <h3 style="font-size:16px;font-weight:800" id="modalGuestTitle">Misafir Kartı Düzenle</h3>
            <button type="button" style="background:none;border:none;font-size:20px;cursor:pointer" onclick="closeGuestModal()">×</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
            <input type="hidden" name="action" value="save_guest">
            <input type="hidden" name="guest_id" id="inpGuestId" value="0">

            <div style="margin-bottom:12px">
                <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px">Ad Soyad</label>
                <input type="text" name="full_name" id="inpFullName" class="sui-input" required>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px">
                <div>
                    <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px">E-posta</label>
                    <input type="email" name="email" id="inpEmail" class="sui-input" required>
                </div>
                <div>
                    <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px">Telefon</label>
                    <input type="text" name="phone" id="inpPhone" class="sui-input">
                </div>
            </div>
            <div style="margin-bottom:12px">
                <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px">VIP Seviyesi</label>
                <select name="vip_level" id="inpVipLevel" class="sui-input">
                    <option value="standard">Standart Misafir</option>
                    <option value="vip_silver">Silver Misafir</option>
                    <option value="vip_gold">Gold VIP</option>
                    <option value="vip_platinum">Platinum VIP</option>
                </select>
            </div>
            <div style="margin-bottom:12px">
                <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px">Özel Tercihler (Oda, Yastık, Manzara vb.)</label>
                <textarea name="special_preferences" id="inpPrefs" class="sui-input" style="height:60px;resize:none"></textarea>
            </div>
            <div style="margin-bottom:18px">
                <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px">Operasyonel Notlar</label>
                <textarea name="notes" id="inpNotes" class="sui-input" style="height:60px;resize:none"></textarea>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px">
                <button type="button" class="sui-btn sui-btn-outline" onclick="closeGuestModal()">İptal</button>
                <button type="submit" class="sui-btn sui-btn-primary"><i class="fa-solid fa-floppy-disk"></i> Kaydet</button>
            </div>
        </form>
    </div>
</div>

<script>
function openGuestModal(g) {
    if (g) {
        document.getElementById('modalGuestTitle').innerText = 'Misafir Kartı Düzenle — ' + g.full_name;
        document.getElementById('inpGuestId').value = g.id;
        document.getElementById('inpFullName').value = g.full_name;
        document.getElementById('inpEmail').value = g.email;
        document.getElementById('inpPhone').value = g.phone || '';
        document.getElementById('inpVipLevel').value = g.vip_level || 'standard';
        document.getElementById('inpPrefs').value = g.special_preferences || '';
        document.getElementById('inpNotes').value = g.notes || '';
    } else {
        document.getElementById('modalGuestTitle').innerText = 'Yeni Misafir Kartı Oluştur';
        document.getElementById('inpGuestId').value = '0';
        document.getElementById('inpFullName').value = '';
        document.getElementById('inpEmail').value = '';
        document.getElementById('inpPhone').value = '';
        document.getElementById('inpVipLevel').value = 'standard';
        document.getElementById('inpPrefs').value = '';
        document.getElementById('inpNotes').value = '';
    }
    document.getElementById('guestModal').style.display = 'flex';
}
function closeGuestModal() {
    document.getElementById('guestModal').style.display = 'none';
}
</script>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>
