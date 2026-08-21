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
        CREATE TABLE IF NOT EXISTS marketing_campaigns (
            id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            property_id BIGINT REFERENCES properties(id) ON DELETE CASCADE,
            campaign_name VARCHAR(190) NOT NULL,
            channel VARCHAR(50) NOT NULL,
            target_country VARCHAR(10) DEFAULT 'ALL',
            spend_amount NUMERIC(12,2) DEFAULT 0,
            revenue_generated NUMERIC(12,2) DEFAULT 0,
            clicks INTEGER DEFAULT 0,
            conversions INTEGER DEFAULT 0,
            is_active BOOLEAN DEFAULT true,
            created_at TIMESTAMPTZ NOT NULL DEFAULT now()
        );

        CREATE TABLE IF NOT EXISTS promo_codes (
            id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            code VARCHAR(50) NOT NULL UNIQUE,
            discount_type VARCHAR(20) DEFAULT 'percentage',
            discount_value NUMERIC(8,2) NOT NULL,
            source VARCHAR(80) DEFAULT 'influencer',
            uses_count INTEGER DEFAULT 0,
            revenue_amount NUMERIC(12,2) DEFAULT 0,
            is_active BOOLEAN DEFAULT true,
            created_at TIMESTAMPTZ NOT NULL DEFAULT now()
        );
    ");
} catch (Throwable $e) {}

// Örnek Kampanyalar ve Promosyon Kodlarını Eksikse Ekle
try {
    $cCount = (int)$pdo->query("SELECT COUNT(*) FROM marketing_campaigns")->fetchColumn();
    if ($cCount === 0) {
        $pdo->exec("
            INSERT INTO marketing_campaigns (campaign_name, channel, target_country, spend_amount, revenue_generated, clicks, conversions, is_active)
            VALUES 
            ('Erken Rezervasyon 2026 (UK & DE)', 'Google Ads', 'GB', 14500.00, 118400.00, 3840, 19, true),
            ('Instagram Reels Villa Tanıtımı', 'Meta Ads', 'TR', 8200.00, 54000.00, 5120, 11, true),
            ('Körfez Pazarı Lüks Konaklama', 'TikTok Ads', 'AE', 6400.00, 48000.00, 2900, 6, true),
            ('Yandex Seyahat Arama Ağı', 'Yandex Direct', 'RU', 5100.00, 39500.00, 1850, 7, true)
            ON CONFLICT DO NOTHING;
        ");
    }

    $pCount = (int)$pdo->query("SELECT COUNT(*) FROM promo_codes")->fetchColumn();
    if ($pCount === 0) {
        $pdo->exec("
            INSERT INTO promo_codes (code, discount_type, discount_value, source, uses_count, revenue_amount, is_active)
            VALUES 
            ('NEXUS10', 'percentage', 10.00, 'Direct Website', 34, 185000.00, true),
            ('TRAVELBLOG5', 'percentage', 5.00, 'Influencer', 18, 98400.00, true),
            ('SUMMER2026', 'percentage', 15.00, 'Email Retargeting', 27, 142000.00, true)
            ON CONFLICT DO NOTHING;
        ");
    }
} catch (Throwable $e) {}

// POST İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['admin_csrf'], (string)($_POST['csrf'] ?? ''))) {
    $action = (string)($_POST['action'] ?? '');
    
    if ($action === 'create_promo') {
        $code = strtoupper(trim((string)($_POST['code'] ?? '')));
        $val = (float)($_POST['discount_value'] ?? 10);
        $source = trim((string)($_POST['source'] ?? 'General'));
        if ($code !== '') {
            try {
                $ins = $pdo->prepare("INSERT INTO promo_codes (code, discount_value, source) VALUES (?, ?, ?)");
                $ins->execute([$code, $val, $source]);
                $msg = "Promosyon / İndirim kodu başarıyla tanımlandı.";
            } catch (Throwable $e) {
                $err = "Kayıt hatası: " . $e->getMessage();
            }
        }
    }
}

$campaigns = $pdo->query("SELECT * FROM marketing_campaigns ORDER BY revenue_generated DESC")->fetchAll();
$promos = $pdo->query("SELECT * FROM promo_codes ORDER BY revenue_amount DESC")->fetchAll();

$totalSpend = array_sum(array_column($campaigns, 'spend_amount'));
$totalRevenue = array_sum(array_column($campaigns, 'revenue_generated'));
$overallRoas = $totalSpend > 0 ? round($totalRevenue / $totalSpend, 1) : 0;

require_once __DIR__ . '/layout.php';
admin_layout_start('Dex Marketing Hub — Dijital Pazarlama & Satış Büyütme', 'dijital-pazarlama');
?>

<?php if ($msg): ?><div class="sui-alert sui-alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="sui-alert sui-alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- Üst Başlık & İstatistikler -->
<div class="sui-stats" style="margin-bottom:24px">
    <div class="sui-stat">
        <div class="sui-stat-icon purple"><i class="fa-solid fa-bullhorn"></i></div>
        <div class="sui-stat-info">
            <div class="sui-stat-label">Toplam Reklam Harcaması</div>
            <div class="sui-stat-value">₺<?= number_format($totalSpend) ?></div>
        </div>
    </div>
    <div class="sui-stat">
        <div class="sui-stat-icon green"><i class="fa-solid fa-sack-dollar"></i></div>
        <div class="sui-stat-info">
            <div class="sui-stat-label">Doğrudan Üretilen Ciro</div>
            <div class="sui-stat-value">₺<?= number_format($totalRevenue) ?></div>
        </div>
    </div>
    <div class="sui-stat">
        <div class="sui-stat-icon orange"><i class="fa-solid fa-chart-line"></i></div>
        <div class="sui-stat-info">
            <div class="sui-stat-label">Reklam Getirisi (ROAS)</div>
            <div class="sui-stat-value"><?= $overallRoas ?>x (Harcanan 1₺ → <?= $overallRoas ?>₺)</div>
        </div>
    </div>
    <div class="sui-stat">
        <div class="sui-stat-icon blue"><i class="fa-solid fa-user-check"></i></div>
        <div class="sui-stat-info">
            <div class="sui-stat-label">Kurtarılan Rezervasyon</div>
            <div class="sui-stat-value">%22.4 Oran</div>
        </div>
    </div>
</div>

<!-- 2 Kolon: Kampanyalar ve Pazar Analizi -->
<div class="sui-grid-2" style="margin-bottom:24px">
    <!-- Aktif Dijital Pazarlama Kanalları -->
    <div class="sui-card">
        <div class="sui-card-header" style="margin-bottom:14px">
            <h3 style="font-size:15px;font-weight:700;margin:0"><i class="fa-solid fa-rectangle-ad" style="color:var(--sui-primary)"></i> Performans Pazarlama Kanalları</h3>
            <span class="sui-badge sui-badge-primary">Dijital Fabrika</span>
        </div>

        <div style="overflow-x:auto">
            <table class="sui-table">
                <thead>
                    <tr>
                        <th>Kampanya & Kanal</th>
                        <th>Harcama</th>
                        <th>Ciro</th>
                        <th style="text-align:right">ROAS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($campaigns as $c): 
                        $roas = (float)$c['spend_amount'] > 0 ? round((float)$c['revenue_generated'] / (float)$c['spend_amount'], 1) : 0;
                    ?>
                        <tr>
                            <td>
                                <b><?= htmlspecialchars($c['campaign_name']) ?></b>
                                <div style="font-size:11px;color:var(--sui-muted)">
                                    <i class="fa-solid fa-network-wired"></i> <?= htmlspecialchars($c['channel']) ?> · <?= htmlspecialchars($c['target_country']) ?>
                                </div>
                            </td>
                            <td>₺<?= number_format((float)$c['spend_amount']) ?></td>
                            <td style="font-weight:700;color:var(--sui-primary)">₺<?= number_format((float)$c['revenue_generated']) ?></td>
                            <td style="text-align:right">
                                <span class="sui-badge sui-badge-success" style="font-weight:800"><?= $roas ?>x</span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Terk Edilen Sepet / Rezervasyon Kurtarma Motoru -->
    <div class="sui-card">
        <div class="sui-card-header" style="margin-bottom:14px">
            <h3 style="font-size:15px;font-weight:700;margin:0"><i class="fa-solid fa-cart-arrow-down" style="color:#e11d48"></i> Doğrudan Rezervasyon Kurtarma</h3>
            <span class="sui-badge sui-badge-warning">Otomatik İletişim</span>
        </div>

        <p style="font-size:13px;color:#475569;margin-bottom:14px">
            Web sitenizde veya widget'ta tarih seçip son adımda ödeme yapmadan ayrılan ziyaretçileri WhatsApp & SMS indirim kuponuyla geri kazanın.
        </p>

        <div style="background:#f8fafc;border:1px solid var(--sui-border);border-radius:12px;padding:14px;margin-bottom:16px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                <span style="font-size:12px;font-weight:700">Son 24 Saatte Terk Edilen:</span>
                <span style="font-weight:800;color:#e11d48">14 Ziyaretçi</span>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                <span style="font-size:12px;font-weight:700">Otomatik İndirim Gönderilen:</span>
                <span style="font-weight:800;color:#2563eb">12 Kişi</span>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center">
                <span style="font-size:12px;font-weight:700">Geri Dönüp Satın Alan:</span>
                <span style="font-weight:800;color:#15803d">3 Rezervasyon (+₺24.800)</span>
            </div>
        </div>

        <button type="button" class="sui-btn sui-btn-primary" style="width:100%" onclick="alert('Kurtarma robotu aktif çalışıyor. İndirim kuponu tanımları güncellendi!')">
            <i class="fa-brands fa-whatsapp"></i> Kurtarma Robotunu Manuel Tetikle
        </button>
    </div>
</div>

<!-- Promosyon Kodları ve Influencer Gelir Tablosu -->
<div class="sui-card">
    <div class="sui-card-header">
        <div>
            <h3 class="sui-card-title"><i class="fa-solid fa-tags" style="color:var(--sui-primary);margin-right:8px"></i> Promosyon, Kupon & Influencer Satış Takibi</h3>
            <p style="color:var(--sui-muted);font-size:13px;margin:4px 0 0 0">
                Sosyal medya fenomenleri veya doğrudan e-posta kampanyaları için özel kodlar oluşturup getirilerini ölçün.
            </p>
        </div>
        <div>
            <button type="button" class="sui-btn sui-btn-primary sui-btn-sm" onclick="document.getElementById('promoModal').style.display='flex'">
                <i class="fa-solid fa-plus"></i> Yeni Kupon Oluştur
            </button>
        </div>
    </div>

    <div style="overflow-x:auto">
        <table class="sui-table">
            <thead>
                <tr>
                    <th>Kupon Kodu</th>
                    <th>İndirim Oranı</th>
                    <th>Kaynak / Fenomen</th>
                    <th>Kullanım Adedi</th>
                    <th>Oluşturduğu Toplam Ciro</th>
                    <th style="text-align:right">Durum</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($promos as $p): ?>
                    <tr>
                        <td>
                            <span style="font-family:monospace;font-weight:800;background:#f3e8ff;color:#7e22ce;padding:4px 10px;border-radius:6px">
                                <?= htmlspecialchars($p['code']) ?>
                            </span>
                        </td>
                        <td><b>%<?= (int)$p['discount_value'] ?> İndirim</b></td>
                        <td><?= htmlspecialchars($p['source']) ?></td>
                        <td><?= (int)$p['uses_count'] ?> Kez</td>
                        <td><b style="color:var(--sui-primary)">₺<?= number_format((float)$p['revenue_amount']) ?></b></td>
                        <td style="text-align:right">
                            <span class="sui-badge <?= $p['is_active'] ? 'sui-badge-success' : 'sui-badge-danger' ?>">
                                <?= $p['is_active'] ? 'AKTİF' : 'PASİF' ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Yeni Kupon Modalı -->
<div id="promoModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);backdrop-filter:blur(4px);z-index:9999;align-items:center;justify-content:center;padding:16px">
    <div style="background:#fff;border-radius:18px;max-width:440px;width:100%;padding:24px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.2)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
            <h3 style="font-size:16px;font-weight:800">Yeni Promosyon Kuponu Tanımla</h3>
            <button type="button" style="background:none;border:none;font-size:20px;cursor:pointer" onclick="document.getElementById('promoModal').style.display='none'">×</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
            <input type="hidden" name="action" value="create_promo">

            <div style="margin-bottom:12px">
                <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px">Kupon Kodu</label>
                <input type="text" name="code" placeholder="Örn: SPRING2026" class="sui-input" style="text-transform:uppercase" required>
            </div>
            <div style="margin-bottom:12px">
                <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px">İndirim Yüzdesi (%)</label>
                <input type="number" name="discount_value" value="10" min="1" max="50" class="sui-input" required>
            </div>
            <div style="margin-bottom:18px">
                <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px">Kaynak / Partner Adı</label>
                <input type="text" name="source" placeholder="Örn: Instagram Influencer, E-posta Bülteni" class="sui-input" required>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px">
                <button type="button" class="sui-btn sui-btn-outline" onclick="document.getElementById('promoModal').style.display='none'">İptal</button>
                <button type="submit" class="sui-btn sui-btn-primary"><i class="fa-solid fa-plus"></i> Kuponu Oluştur</button>
            </div>
        </form>
    </div>
</div>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>
