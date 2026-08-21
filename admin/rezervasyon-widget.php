<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_admin();

$pdo = db();
$properties = $pdo->query("SELECT id, name, city, property_type FROM properties WHERE status='active' ORDER BY name")->fetchAll();
$selectedPropId = (int)($_GET['property_id'] ?? ($properties[0]['id'] ?? 1));

// Tesis Bilgisi
$propQ = $pdo->prepare("SELECT * FROM properties WHERE id=?");
$propQ->execute([$selectedPropId]);
$currentProp = $propQ->fetch();

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'nexustraveltech.com') . '/nexustraveltech';
$widgetUrl = $baseUrl . '/widget/booking-engine.php?property_id=' . $selectedPropId;
$embedScriptCode = '<script src="' . $baseUrl . '/widget/embed.js" data-property="' . $selectedPropId . '"></script>';
$iframeCode = '<iframe src="' . $widgetUrl . '" width="100%" height="750" frameborder="0" style="border-radius:16px;border:1px solid #e2e8f0;"></iframe>';

require_once __DIR__ . '/layout.php';
admin_layout_start('Online Rezervasyon Widget & Embed', 'booking-engine');
?>

<div class="sui-card" style="margin-bottom:20px">
    <div class="sui-card-header">
        <div>
            <h2 class="sui-card-title"><i class="fa-solid fa-globe" style="color:var(--sui-primary);margin-right:8px"></i> Doğrudan Rezervasyon Motoru (Booking Engine)</h2>
            <p style="color:var(--sui-muted);font-size:13px;margin:4px 0 0 0">
                Otel, villa ve yat sahiplerinin kendi web sitelerinde veya sosyal medya profillerinde sıfır komisyonla rezervasyon almasını sağlayan bağımsız rezervasyon motoru.
            </p>
        </div>
        <div>
            <form method="get" style="margin:0">
                <select name="property_id" class="sui-input" style="font-weight:600;min-width:220px" onchange="this.form.submit()">
                    <?php foreach ($properties as $pr): ?>
                        <option value="<?= (int)$pr['id'] ?>" <?= $selectedPropId === (int)$pr['id'] ? 'selected' : '' ?>>
                            🏢 <?= htmlspecialchars($pr['name']) ?> (<?= htmlspecialchars($pr['city'] ?? 'TR') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>
</div>

<!-- Entegrasyon & Kod Kutuları -->
<div class="sui-grid-2" style="margin-bottom:24px">
    <div class="sui-card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
            <h3 style="font-size:15px;font-weight:700;margin:0"><i class="fa-solid fa-code" style="color:#7928ca"></i> 1. Tek Satır Script (Yüzen Rezervasyon Butonu)</h3>
            <button type="button" class="sui-btn sui-btn-outline sui-btn-sm" onclick="copyCode('scriptCode')">
                <i class="fa-solid fa-copy"></i> Kopyala
            </button>
        </div>
        <p style="font-size:12px;color:var(--sui-muted);margin-bottom:10px">
            Web sitenizin `&lt;body&gt;` etiketinin hemen öncesine yapıştırın. Sitede sağ altta otomatik açılır buton çıkartır.
        </p>
        <textarea id="scriptCode" class="sui-input" readonly style="font-family:monospace;font-size:12px;height:55px;resize:none"><?= htmlspecialchars($embedScriptCode) ?></textarea>
    </div>

    <div class="sui-card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
            <h3 style="font-size:15px;font-weight:700;margin:0"><i class="fa-solid fa-window-maximize" style="color:#2d3748"></i> 2. Sayfa İçi Gömülü (iFrame) Kodu</h3>
            <button type="button" class="sui-btn sui-btn-outline sui-btn-sm" onclick="copyCode('iframeCode')">
                <i class="fa-solid fa-copy"></i> Kopyala
            </button>
        </div>
        <p style="font-size:12px;color:var(--sui-muted);margin-bottom:10px">
            Web sitenizde "Rezervasyon Yap" sayfasının içerisine tam ekran yerleştirmek için kullanın.
        </p>
        <textarea id="iframeCode" class="sui-input" readonly style="font-family:monospace;font-size:12px;height:55px;resize:none"><?= htmlspecialchars($iframeCode) ?></textarea>
    </div>
</div>

<!-- Canlı Önizleme Çerçevesi -->
<div class="sui-card" style="padding:0;overflow:hidden">
    <div style="padding:14px 20px;background:#f8fafc;border-bottom:1px solid var(--sui-border);display:flex;justify-content:space-between;align-items:center">
        <div style="font-size:13px;font-weight:700;color:#334155">
            <i class="fa-solid fa-eye" style="color:var(--sui-primary);margin-right:6px"></i> Canlı Widget Önizlemesi — <?= htmlspecialchars($currentProp['name'] ?? 'Tesis') ?>
        </div>
        <div>
            <a href="<?= htmlspecialchars($widgetUrl) ?>" target="_blank" class="sui-btn sui-btn-outline sui-btn-sm">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> Yeni Sekmede Aç
            </a>
        </div>
    </div>
    <div style="padding:20px;background:#f1f5f9">
        <iframe src="<?= htmlspecialchars($widgetUrl) ?>" style="width:100%;height:680px;border:none;border-radius:14px;box-shadow:0 4px 15px rgba(0,0,0,0.06);background:#fff"></iframe>
    </div>
</div>

<script>
function copyCode(id) {
    var copyText = document.getElementById(id);
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(copyText.value);
    alert('Kod panoya kopyalandı!');
}
</script>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>
