<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/supplier_auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/layout.php';

$supplier_user = require_supplier();
$supplierId = (int)$supplier_user['supplier_id'];
$pdo = db();

// Tedarikçinin Tesislerini Çek
$properties = $pdo->prepare("SELECT id, name, property_type FROM properties WHERE supplier_id=? AND status='active' ORDER BY name");
$properties->execute([$supplierId]);
$propList = $properties->fetchAll();
$selectedPropId = (int)($_GET['property_id'] ?? ($propList[0]['id'] ?? 0));

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'nexustraveltech.com';
$widgetUrl = $scheme . '://' . $host . '/widget/booking-engine.php?property_id=' . $selectedPropId;
$embedScriptUrl = $scheme . '://' . $host . '/widget/embed.js';

supply_start('Rezervasyon Widget & Web Sitesi Entegrasyonu', 'widget_embed');
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<style>
.widget-card { background: #fff; border-radius: 14px; border: 1px solid #e2e8f0; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom: 20px; }
.code-box { background: #0f172a; color: #38bdf8; font-family: monospace; font-size: 13px; padding: 14px; border-radius: 10px; position: relative; overflow-x: auto; margin-top: 8px; }
.copy-btn { position: absolute; right: 10px; top: 10px; background: rgba(255,255,255,0.15); color: #fff; border: none; padding: 5px 10px; border-radius: 6px; font-size: 12px; cursor: pointer; }
.copy-btn:hover { background: rgba(255,255,255,0.3); }
</style>

<div class="widget-card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <div>
            <h2 style="font-size:18px;font-weight:800;margin:0;color:#0f172a">
                <i class="fa-solid fa-globe" style="color:#7928ca;margin-right:6px"></i> Sıfır Komisyonlu Doğrudan Rezervasyon Motoru
            </h2>
            <p style="font-size:13px;color:#64748b;margin:4px 0 0 0">
                Kendi otel veya villa web sitenize tek satır kod ile gömün; komisyonsuz doğrudan rezervasyon alın.
            </p>
        </div>

        <form method="get" style="margin:0">
            <select name="property_id" style="padding:8px 12px;border:1px solid #cbd5e1;border-radius:8px;font-weight:600" onchange="this.form.submit()">
                <?php foreach ($propList as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= $selectedPropId === (int)$p['id'] ? 'selected' : '' ?>>
                        🏢 <?= htmlspecialchars($p['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">
    <!-- Seçenek 1: Tek Satır JavaScript -->
    <div class="widget-card">
        <h3 style="font-size:15px;font-weight:700;margin:0 0 8px 0">
            <i class="fa-brands fa-js" style="color:#eab308;margin-right:4px"></i> 1. Yöntem: Sayfa İçi Gömme (Önerilen)
        </h3>
        <p style="font-size:12px;color:#64748b">Web sitenizin istediğiniz yerine aşağıdaki HTML kodunu yapıştırın:</p>

        <div class="code-box">
            <button class="copy-btn" onclick="navigator.clipboard.writeText(this.nextElementSibling.innerText);alert('Kod kopyalandı!')">Kopyala</button>
            <code>&lt;div id="nexus-booking-widget" data-property-id="<?= $selectedPropId ?>"&gt;&lt;/div&gt;
&lt;script src="<?= htmlspecialchars($embedScriptUrl) ?>" defer&gt;&lt;/script&gt;</code>
        </div>
    </div>

    <!-- Seçenek 2: iFrame / Doğrudan Link -->
    <div class="widget-card">
        <h3 style="font-size:15px;font-weight:700;margin:0 0 8px 0">
            <i class="fa-solid fa-link" style="color:#2563eb;margin-right:4px"></i> 2. Yöntem: iFrame veya Buton Linki
        </h3>
        <p style="font-size:12px;color:#64748b">Doğrudan bağımsız rezervasyon sayfasına yönlendirmek için:</p>

        <div class="code-box">
            <button class="copy-btn" onclick="navigator.clipboard.writeText(this.nextElementSibling.innerText);alert('URL kopyalandı!')">Kopyala</button>
            <code><?= htmlspecialchars($widgetUrl) ?></code>
        </div>
    </div>
</div>

<!-- Canlı Önizleme -->
<div class="widget-card">
    <h3 style="font-size:15px;font-weight:700;margin:0 0 14px 0">
        <i class="fa-solid fa-display" style="color:#16a34a;margin-right:4px"></i> Canlı Rezervasyon Motoru Önizlemesi
    </h3>
    <iframe src="<?= htmlspecialchars($widgetUrl) ?>" style="width:100%;height:560px;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.05)"></iframe>
</div>

<?php supply_end(); ?>
