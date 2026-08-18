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
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Aylık dönüşüm raporu | NEXUS Admin</title><style>body{margin:0;background:#f7f7f2;color:#10211f;font-family:Arial,sans-serif}.wrap{width:min(1080px,calc(100% - 32px));margin:40px auto}.top{display:flex;justify-content:space-between;gap:20px;align-items:center}.brand{font-size:28px;font-weight:800}.brand span{color:#e85f42}.back{color:#10211f}.card{background:#fff;border:1px solid #e1e5de;padding:20px;margin-top:16px}.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:16px}.kpi{background:#fff;border:1px solid #e1e5de;padding:14px}.kpi b{display:block;font-size:20px;margin-top:4px}.kpi span{font-size:11px;text-transform:uppercase;color:#64716d}table{width:100%;border-collapse:collapse;margin-top:10px}th,td{text-align:left;border-bottom:1px solid #e1e5de;padding:9px 10px;font-size:13px}th{font-size:11px;text-transform:uppercase;color:#64716d}.fx-hist{display:flex;align-items:flex-end;gap:3px;height:120px;margin-top:12px;overflow-x:auto}.fx-hist-col{display:flex;flex-direction:column;align-items:center;justify-content:flex-end;min-width:16px;flex:1}.fx-hist-bar{width:12px;border-radius:2px 2px 0 0;background:#0d7a4a}.fx-hist-day{font-size:9px;color:#64716d;margin-top:3px;white-space:nowrap}.fx-hist-val{font-size:9px;color:#0d7a4a;font-weight:700}.hist-block{display:none}.hist-block.active{display:block}.pair-select{padding:9px;border:1px solid #d8ded8;font:inherit;margin-top:8px;max-width:340px}.muted{color:#64716d;font-size:13px}@media(max-width:700px){.kpis{grid-template-columns:1fr 1fr}}</style></head><body><main class="wrap"><div class="top"><div><div class="brand">N<span>∿</span>XUS Admin</div><p>Aylık kur dönüşüm raporu — webhook fiyat dönüşümlerinin denetim özeti (son 30 gün)</p></div><div style="text-align:right"><a class="back" href="/nexustraveltech/admin/kur-yonetimi.php">← Kur yönetimine dön</a><br><a class="back" href="/nexustraveltech/admin/">Panele dön</a></div></div>
<div class="kpis"><div class="kpi"><span>Dönüşüm yapılan gün</span><b><?=count($dayKeys)?></b></div><div class="kpi"><span>Çift sayısı</span><b><?=count($pairs)?></b></div><div class="kpi"><span>Orijinal toplam</span><b><?=number_format($totalOrig, 2, ',', '.')?></b></div><div class="kpi"><span>Dönüştürülen toplam</span><b><?=number_format($totalConv, 2, ',', '.')?></b></div></div>
<section class="card"><h2 style="margin:0;font-size:18px">Birim çifti bazında toplam</h2>
<?php if (!$pairs): ?><p class="muted">Son 30 günde kur dönüşümü yapılmamış (channel_sync_logs.fx_audit boş). Webhook fiyatı farklı birimde geldiğinde dönüşüm kaydı burada görünür.</p>
<?php else: ?><table><tr><th>Çift</th><th>Fiyat sayısı</th><th>Orijinal tutar</th><th>Dönüştürülen tutar</th><th>Ortalama kur</th><th>Dönem</th></tr>
<?php foreach ($pairs as $key => $p): $avgRate = $p['count'] > 0 ? $p['rate_acc'] / $p['count'] : 0.0; ?>
<tr><td><b><?=htmlspecialchars($key)?></b></td><td><?=number_format($p['count'])?></td><td><?=number_format($p['original_total'], 2, ',', '.')?> <?=htmlspecialchars($p['from'])?></td><td><?=number_format($p['converted_total'], 2, ',', '.')?> <?=htmlspecialchars($p['to'])?></td><td><?=number_format($avgRate, 4, ',', '.')?></td><td><?=htmlspecialchars((string) $p['first_date'])?> → <?=htmlspecialchars((string) $p['last_date'])?></td></tr>
<?php endforeach; ?></table>
<p class="muted" style="margin-top:8px">Ortalama kur, fiyat sayısıyla ağırlıklandırılmıştır (birden çok günün kuru varsa gerçek ortalamaya yakındır).</p>
<h3 style="margin:18px 0 0;font-size:15px">Günlük ortalama kur zaman çizelgesi</h3>
<select class="pair-select" id="pair-select"><?php foreach ($pairs as $key => $p): ?><option value="<?=htmlspecialchars($key)?>" <?= $key === $selKey ? 'selected' : '' ?>><?=htmlspecialchars($key)?> (<?=number_format($p['converted_total'], 0, ',', '.')?> <?=htmlspecialchars($p['to'])?>)</option><?php endforeach; ?></select>
<?php foreach ($pairs as $key => $p): $series = []; foreach ($dayKeys as $d) { $series[$d] = $dayPair[$d][$key] ?? null; } $maxRate = 0.0; foreach ($series as $s) { if ($s && $s['count'] > 0) $maxRate = max($maxRate, $s['rate_acc'] / $s['count']); } $maxRate = $maxRate > 0 ? $maxRate : 1.0; ?>
<div class="hist-block <?= $key === $selKey ? 'active' : '' ?>" id="hist-<?=htmlspecialchars(preg_replace('/[^A-Z0-9]/', '_', $key))?>">
<div class="fx-hist"><?php foreach ($series as $d => $s): $avg = ($s && $s['count'] > 0) ? round($s['rate_acc'] / $s['count'], 4) : null; ?>
<div class="fx-hist-col" title="<?=htmlspecialchars($d . ($avg !== null ? ' · ortalama kur ' . number_format($avg, 4, ',', '.') . ' · ' . (int) $s['count'] . ' fiyat · ' . number_format($s['converted'], 2, ',', '.') . ' ' . htmlspecialchars($p['to']) : ' · dönüşüm yok'))?>"><?php if ($avg !== null): ?><span class="fx-hist-val"><?=number_format($avg, 2, ',', '.')?></span><div class="fx-hist-bar" style="height:<?=max(3, (int) round(($avg / $maxRate) * 90))?>px"></div><?php else: ?><div class="fx-hist-bar" style="height:2px;background:#e1e5de"></div><?php endif; ?><span class="fx-hist-day"><?=htmlspecialchars(substr($d, 8, 2))?>.<?=htmlspecialchars(substr($d, 5, 2))?></span></div><?php endforeach; ?></div>
</div>
<?php endforeach; ?>
<script>document.getElementById('pair-select').addEventListener('change',function(){var v=this.value;document.querySelectorAll('.hist-block').forEach(function(b){b.classList.remove('active')});var t=document.getElementById('hist-'+v.replace(/[^A-Z0-9]/g,'_'));if(t)t.classList.add('active');});<?php if ($selKey !== '' && $selKey === $reqPair): ?>(function(){var t=document.getElementById('hist-'+<?=json_encode(preg_replace('/[^A-Z0-9]/', '_', $selKey))?>);if(t){setTimeout(function(){t.scrollIntoView({behavior:'smooth',block:'nearest'})},120)}})();<?php endif; ?></script>
<?php endif; ?></section>
</main><?php require_once __DIR__ . '/../config/ai_widget.php'; ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); ?></body></html>
