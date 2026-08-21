<?php

declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/supplier_auth.php';
require __DIR__ . '/../config/database.php';

// Erişim: admin tüm kanalları görür; tedarikçi oturumu yalnızca kendi kanallarının
// dönüşümlerini görür (dağıtım merkezi Doğrula sonucundaki kur bağlantısıyla gelir).
admin_session();
$isAdmin = ($_SESSION['admin_logged_in'] ?? false) === true;
$supplierId = 0;
if (!$isAdmin) {
    $su = supplier_user();
    if (!$su) { header('Location: /nexustraveltech/admin/login'); exit; }
    $supplierId = (int) ($su['supplier_id'] ?? 0);
}

$pdo = db();

// Son 30 günün başarılı webhook işlemlerindeki kur dönüşüm denetimleri (channel_sync_logs.fx_audit).
// [{from,to,rate,count,original_total,converted_total,first_date,last_date}] — satır başına bir dizi.
// Tedarikçi oturumu yalnızca kendi kanal bağlantılarının satırlarını görür.
$fxSql = "SELECT id, created_at, fx_audit FROM channel_sync_logs
     WHERE direction='pull' AND fx_audit IS NOT NULL AND fx_audit <> '[]'::jsonb
       AND created_at >= now() - interval '30 days'";
$fxArgs = [];
if ($supplierId > 0) {
    $fxSql .= " AND channel_connection_id IN (SELECT id FROM channel_connections WHERE supplier_id=?)";
    $fxArgs[] = $supplierId;
}
$fxSql .= " ORDER BY id";
$fxSt = $pdo->prepare($fxSql);
$fxSt->execute($fxArgs);
$rows = $fxSt->fetchAll();

$pairs = [];       // FROM->TO => aggregate
$dayPair = [];     // date => FROM->TO => ['count','converted','rate_acc']
foreach ($rows as $r) {
    $fx = json_decode((string) $r['fx_audit'], true);
    if (!is_array($fx)) {
        continue;
    }
    foreach ($fx as $f) {
        if (!is_array($f)) continue;
        $from = strtoupper((string) ($f['from'] ?? ''));
        $to = strtoupper((string) ($f['to'] ?? ''));
        $rate = (float) ($f['rate'] ?? 0);
        $cnt = max(0, (int) ($f['count'] ?? 0));
        $orig = (float) ($f['original_total'] ?? 0);
        $conv = (float) ($f['converted_total'] ?? 0);
        if ($from === '' || $to === '' || $from === $to) continue;
        $key = $from . '->' . $to;
        if (!isset($pairs[$key])) {
            $pairs[$key] = ['from' => $from, 'to' => $to, 'count' => 0, 'original_total' => 0.0, 'converted_total' => 0.0, 'rate_acc' => 0.0, 'first_date' => (string) ($f['first_date'] ?? ''), 'last_date' => (string) ($f['last_date'] ?? '')];
        }
        $pairs[$key]['count'] += $cnt;
        $pairs[$key]['original_total'] += $orig;
        $pairs[$key]['converted_total'] += $conv;
        $pairs[$key]['rate_acc'] += $rate * $cnt; // ağırlıklı ortalama kur
        if ($pairs[$key]['first_date'] === '' || (string) ($f['first_date'] ?? '') < $pairs[$key]['first_date']) {
            $pairs[$key]['first_date'] = (string) ($f['first_date'] ?? '');
        }
        if ((string) ($f['last_date'] ?? '') > $pairs[$key]['last_date']) {
            $pairs[$key]['last_date'] = (string) ($f['last_date'] ?? '');
        }
        // Gün bazında ortalama kur zaman çizelgesi için. Girdi bazlı kur kaydı
        // (rates_by_date) varsa her tarih için O tarihte kullanılan kur dikkate alınır;
        // yoksa logun oluşturulduğu gün + ortalama kur ile geriye dönük uyum korunur.
        $rbd = (array) ($f['rates_by_date'] ?? []);
        if ($rbd !== []) {
            foreach ($rbd as $rd => $rv) {
                $rd = (string) $rd;
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rd)) continue;
                $day = $rd;
                if (!isset($dayPair[$day])) $dayPair[$day] = [];
                if (!isset($dayPair[$day][$key])) $dayPair[$day][$key] = ['count' => 0, 'converted' => 0.0, 'rate_acc' => 0.0];
                // Aynı gün içinde birden çok satır varsa kur ortalaması üzerinden işlenir.
                $dayPair[$day][$key]['count'] += $cnt;
                $dayPair[$day][$key]['converted'] += $conv;
                $dayPair[$day][$key]['rate_acc'] += (float) $rv * $cnt;
            }
        } else {
            $day = substr((string) ($r['created_at'] ?? ''), 0, 10);
            if ($day === '') continue;
            if (!isset($dayPair[$day])) $dayPair[$day] = [];
            if (!isset($dayPair[$day][$key])) $dayPair[$day][$key] = ['count' => 0, 'converted' => 0.0, 'rate_acc' => 0.0];
            $dayPair[$day][$key]['count'] += $cnt;
            $dayPair[$day][$key]['converted'] += $conv;
            $dayPair[$day][$key]['rate_acc'] += $rate * $cnt;
        }
    }
}
ksort($pairs);
ksort($dayPair);
// En çok dönüştürülen çift varsayılan seçilir.
$topKey = '';
$topConv = -1.0;
foreach ($pairs as $key => $p) {
    if ($p['converted_total'] > $topConv) {
        $topConv = $p['converted_total'];
        $topKey = $key;
    }
}
// ?pair=FROM->TO — dağıtım merkezi Doğrula sonucundaki kur bağlantısı bu çiftin
// günlük ortalama kur grafiğini açık getirir (çift yoksa en çok dönüştürülen açılır).
$selKey = $topKey;
$reqPair = strtoupper(trim((string) ($_GET['pair'] ?? '')));
if ($reqPair !== '' && isset($pairs[$reqPair])) {
    $selKey = $reqPair;
}
$totalOrig = 0.0;
$totalConv = 0.0;
foreach ($pairs as $p) {
    $totalOrig += $p['original_total'];
    $totalConv += $p['converted_total'];
}
$dayKeys = array_keys($dayPair);
?>
<?php
require_once __DIR__ . '/layout.php';
admin_layout_start('Aylık Kur Dönüşüm & Webhook Raporu', 'kur-yonetimi');
?>

<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:14px;margin-bottom:24px">
    <div class="sui-card" style="padding:16px">
        <div style="font-size:12px;color:var(--sui-muted);font-weight:600">Dönüşüm Yapılan Gün</div>
        <div style="font-size:24px;font-weight:800;margin-top:4px"><?= count($dayKeys) ?></div>
    </div>
    <div class="sui-card" style="padding:16px">
        <div style="font-size:12px;color:var(--sui-primary);font-weight:600">Aktif Çift Sayısı</div>
        <div style="font-size:24px;font-weight:800;color:var(--sui-primary);margin-top:4px"><?= count($pairs) ?></div>
    </div>
    <div class="sui-card" style="padding:16px">
        <div style="font-size:12px;color:var(--sui-muted);font-weight:600">Orijinal Toplam Hacim</div>
        <div style="font-size:24px;font-weight:800;margin-top:4px"><?= number_format($totalOrig, 2, ',', '.') ?></div>
    </div>
    <div class="sui-card" style="padding:16px">
        <div style="font-size:12px;color:var(--sui-success);font-weight:600">Dönüştürülen Toplam</div>
        <div style="font-size:24px;font-weight:800;color:var(--sui-success);margin-top:4px"><?= number_format($totalConv, 2, ',', '.') ?></div>
    </div>
</div>

<div class="sui-card">
    <div class="sui-card-header">
        <div>
            <h2 class="sui-card-title">📊 Para Birimi Çifti Bazında Toplamlar (Son 30 Gün)</h2>
            <p style="color:var(--sui-muted);font-size:13px;margin:4px 0 0 0">
                Channel manager senkronizasyonlarında farklı kurlardan gelen fiyatların otomatik çevrim özeti.
            </p>
        </div>
        <a href="kur-yonetimi" class="sui-btn sui-btn-outline sui-btn-sm">← Kur Yönetimi</a>
    </div>

    <?php if (!$pairs): ?>
        <p style="color:var(--sui-muted);padding:20px;text-align:center">Son 30 günde kur dönüşümü yapılmamış.</p>
    <?php else: ?>
        <div style="overflow-x:auto">
            <table class="sui-table">
                <thead>
                    <tr>
                        <th>Döviz Çifti</th>
                        <th>İşlem Sayısı</th>
                        <th>Orijinal Tutar</th>
                        <th>Dönüştürülen Tutar</th>
                        <th>Ağırlıklı Ort. Kur</th>
                        <th>Tarih Aralığı</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pairs as $key => $p): $avgRate = $p['count'] > 0 ? $p['rate_acc'] / $p['count'] : 0.0; ?>
                        <tr>
                            <td><b><?= htmlspecialchars($key) ?></b></td>
                            <td><?= number_format($p['count']) ?></td>
                            <td><?= number_format($p['original_total'], 2, ',', '.') ?> <?= htmlspecialchars($p['from']) ?></td>
                            <td><span class="sui-badge sui-badge-success"><?= number_format($p['converted_total'], 2, ',', '.') ?> <?= htmlspecialchars($p['to']) ?></span></td>
                            <td style="font-weight:700"><?= number_format($avgRate, 4, ',', '.') ?></td>
                            <td style="font-size:12px;color:var(--sui-muted)"><?= htmlspecialchars((string) $p['first_date']) ?> → <?= htmlspecialchars((string) $p['last_date']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>

