<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/listing_integrity.php';
require_admin();

$pdo = db();

// Tüm aktif ilanları çek
$props = $pdo->query("
    SELECT p.id, p.name, p.property_type, p.status, p.city, s.company_name,
           (SELECT COUNT(*) FROM room_types r WHERE r.property_id=p.id AND r.status='active') AS room_count
    FROM properties p
    JOIN suppliers s ON s.id = p.supplier_id
    WHERE p.status IN ('active','draft')
    ORDER BY p.property_type, p.name
")->fetchAll();

// Hazırlık hesapla
$results = [];
foreach ($props as $p) {
    $rd = listing_readiness($p);
    // En ağır eksik kalemi bul
    $heaviestMiss = null;
    $heaviestWeight = 0;
    foreach ($rd['items'] as $item) {
        if (empty($item['ok']) && $item['key'] !== 'rules' && (int) ($item['weight'] ?? 0) > $heaviestWeight) {
            $heaviestWeight = (int) ($item['weight'] ?? 0);
            $heaviestMiss = $item;
        }
    }
    $results[] = [
        'prop' => $p,
        'score' => $rd['score'],
        'ok_count' => $rd['ok_count'],
        'missing_count' => $rd['missing_count'],
        'heaviest_miss' => $heaviestMiss,
        'heaviest_weight' => $heaviestWeight,
    ];
}

// Sıralama: skora göre artan (en kötü üstte)
usort($results, fn($a, $b) => $a['score'] <=> $b['score']);

// İstatistikler
$total = count($results);
$avgScore = $total > 0 ? (int) round(array_sum(array_column($results, 'score')) / $total) : 0;
$readyCount = count(array_filter($results, fn($r) => $r['score'] >= 100));
$lowCount = count(array_filter($results, fn($r) => $r['score'] < 50));
$typeLabel = fn($t) => ['hotel' => 'Otel', 'villa' => 'Villa', 'yacht' => 'Yat'][$t] ?? $t;

?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Hazırlık Özeti | NEXUS Admin</title>
<style>
body{font-family:Arial;background:#f7f7f2;color:#10211f;margin:0}
.w{width:min(1100px,calc(100% - 32px));margin:35px auto}
.c{background:#fff;border:1px solid #e1e5de;padding:20px;margin:16px 0;border-radius:8px}
table{width:100%;border-collapse:collapse;margin-top:10px}
th{text-align:left;padding:8px 10px;border-bottom:2px solid #e1e5de;font-size:11px;text-transform:uppercase;color:#64716d;cursor:pointer;user-select:none}
th:hover{color:#10211f}
th.sorted{color:#10211f;border-bottom-color:#5f9008}
td{padding:8px 10px;border-bottom:1px solid #eef0ea;font-size:13px}
.score-bar{display:inline-block;width:50px;height:6px;border-radius:3px;background:#e1e5de;overflow:hidden;vertical-align:middle;margin-right:6px}
.score-bar i{display:block;height:100%;border-radius:3px}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700}
.badge-high{background:#e6f8c7;color:#2c7a1f}
.badge-mid{background:#fff8e1;color:#8a6100}
.badge-low{background:#ffe2de;color:#b0301a}
.miss-label{font-size:12px;color:#64716d}
.stats{display:flex;gap:16px;flex-wrap:wrap;margin:12px 0}
.stat{background:#f4f6f1;border:1px solid #e1e5de;border-radius:8px;padding:12px 18px;font-size:13px}
.stat b{font-size:20px;display:block;margin-bottom:2px}
a{color:#405b13;text-decoration:none}
a:hover{text-decoration:underline}
</style>
</head>
<body>
<main class="w">
<a href="/nexustraveltech/admin/">← Panele dön</a>
<h1>📊 Hazırlık Özeti — Tüm İlanlar</h1>
<p style="color:#64716d;font-size:13px">Tüm tedarikçilerin ilanları için hazırlık skorları ve en kritik eksik kalemler. Skora tıklayarak ilanın hazırlık paneline gidin.</p>

<div class="stats">
  <div class="stat"><b><?= $total ?></b>Toplam ilan</div>
  <div class="stat"><b style="color:#2c7a1f"><?= $readyCount ?></b>Yayına hazır</div>
  <div class="stat"><b style="color:#8a6100"><?= $total - $readyCount ?></b>Eksik var</div>
  <div class="stat"><b style="color:#b0301a"><?= $lowCount ?></b>Kritik (< 50)</div>
  <div class="stat"><b><?= $avgScore ?></b>Ortalama skor</div>
</div>

<div class="c">
<h2 style="margin-top:0">İlan Listesi</h2>
<table>
<thead>
<tr>
  <th data-sort="name">İlan</th>
  <th data-sort="type">Tür</th>
  <th data-sort="supplier">Tedarikçi</th>
  <th data-sort="score" class="sorted">Skor ↓</th>
  <th>En ağır eksik</th>
  <th data-sort="ok">Tamam</th>
  <th data-sort="miss">Eksik</th>
</tr>
</thead>
<tbody>
<?php foreach ($results as $r): ?>
<?php
$p = $r['prop'];
$score = $r['score'];
$color = $score >= 100 ? '#2c7a1f' : ($score >= 70 ? '#5f9008' : '#b0301a');
$badgeCls = $score >= 100 ? 'badge-high' : ($score >= 70 ? 'badge-mid' : 'badge-low');
$editBase = $p['property_type'] === 'hotel'
    ? '/nexustraveltech/tedarikci/otel-detay?product=' . (int) $p['id']
    : '/nexustraveltech/tedarikci/villa-detay?product=' . (int) $p['id'];
?>
<tr>
  <td>
    <a href="<?= htmlspecialchars($editBase) ?>" target="_blank" title="<?= htmlspecialchars($p['name']) ?>">
      <b><?= htmlspecialchars($p['name']) ?></b>
    </a>
    <small style="color:#999">#<?= (int) $p['id'] ?></small>
  </td>
  <td><?= $typeLabel($p['property_type']) ?></td>
  <td><small><?= htmlspecialchars($p['company_name']) ?></small></td>
  <td>
    <span class="score-bar"><i style="width:<?= $score ?>%;background:<?= $color ?>"></i></span>
    <span class="badge <?= $badgeCls ?>"><?= $score ?></span>
  </td>
  <td>
    <?php if ($r['heaviest_miss']): ?>
      <span class="miss-label" title="<?= htmlspecialchars($r['heaviest_miss']['detail'] ?? '') ?>">
        <?= htmlspecialchars($r['heaviest_miss']['label']) ?>
        <small style="color:<?= $color ?>">(+<?= $r['heaviest_weight'] ?>)</small>
      </span>
    <?php elseif ($score >= 100): ?>
      <span style="color:#2c7a1f;font-size:12px">✓ Tamam</span>
    <?php else: ?>
      <span class="miss-label">—</span>
    <?php endif; ?>
  </td>
  <td style="text-align:center;color:#2c7a1f"><?= $r['ok_count'] ?></td>
  <td style="text-align:center;<?= $r['missing_count'] > 0 ? 'color:#b0301a' : '' ?>"><?= $r['missing_count'] ?></td>
</tr>
<?php endforeach; ?>
<?php if ($results === []): ?>
<tr><td colspan="7" style="text-align:center;color:#999;padding:20px">Henüz ilan yok.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</main>
</body>
</html>
