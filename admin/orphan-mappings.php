<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/health.php';
require_admin();

if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));

// Tek satır temizleme handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_orphan'
    && hash_equals($_SESSION['admin_csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    $table = (string) ($_POST['table'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $allowed = ['channel_room_mappings', 'channel_rate_plan_mappings', 'channel_property_mappings', 'ical_connections'];
    if (in_array($table, $allowed, true) && $id > 0) {
        try {
            db()->prepare("DELETE FROM \"$table\" WHERE id=?")->execute([$id]);
            audit_log('orphan.delete_single', $table, $id, ['table' => $table]);
            $_SESSION['orphan_msg'] = "Kayıt #$id ($table) silindi.";
        } catch (Throwable $e) {
            $_SESSION['orphan_err'] = "Silme başarısız: " . $e->getMessage();
        }
    }
    header('Location: /nexustraveltech/admin/orphan-mappings');
    exit;
}

$msg = $_SESSION['orphan_msg'] ?? '';
$err = $_SESSION['orphan_err'] ?? '';
unset($_SESSION['orphan_msg'], $_SESSION['orphan_err']);

$pdo = db();

// Tüm yetim satırları topla
$orphans = [];
// 1) channel_room_mappings
try {
    $rows = $pdo->query("SELECT m.id, m.external_room_id AS code, m.status, m.suggestion_score,
        c.display_name AS channel_name, m.channel_connection_id AS conn_id,
        rt.name AS room_name, rp.name AS plan_name, m.property_id,
        p.name AS property_name
        FROM channel_room_mappings m
        LEFT JOIN room_types rt ON rt.id=m.room_type_id
        LEFT JOIN channel_connections c ON c.id=m.channel_connection_id
        LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id
        LEFT JOIN properties p ON p.id=m.property_id
        WHERE m.room_type_id>0 AND (rt.id IS NULL OR c.id IS NULL OR rt.property_id<>m.property_id
            OR (m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)))
        ORDER BY m.id")->fetchAll();
    foreach ($rows as $r) $orphans[] = array_merge($r, ['table' => 'channel_room_mappings', 'label' => 'Oda eşleştirmesi',
        'issue' => isset($r['room_name']) ? ($r['channel_name'] ? '' : 'kanal #' . $r['conn_id'] . ' (silindi)')
            : 'oda tipi #' . ($r['room_type_id'] ?? '?') . ' (silindi)']);
} catch (Throwable $e) {}

// 2) channel_rate_plan_mappings
try {
    $rows = $pdo->query("SELECT m.id, m.external_rate_plan_id AS code, m.status, m.suggestion_score,
        c.display_name AS channel_name, m.channel_connection_id AS conn_id,
        rp.name AS plan_name, rp.currency, m.property_id, p.name AS property_name
        FROM channel_rate_plan_mappings m
        LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id
        LEFT JOIN channel_connections c ON c.id=m.channel_connection_id
        LEFT JOIN properties p ON p.id=m.property_id
        WHERE (m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)) OR c.id IS NULL
        ORDER BY m.id")->fetchAll();
    foreach ($rows as $r) $orphans[] = array_merge($r, ['table' => 'channel_rate_plan_mappings', 'label' => 'Fiyat planı eşleştirmesi',
        'issue' => isset($r['plan_name']) ? ($r['channel_name'] ? '' : 'kanal #' . $r['conn_id'] . ' (silindi)')
            : 'plan #' . ($r['rate_plan_id'] ?? '?') . ' (silindi)']);
} catch (Throwable $e) {}

// 3) channel_property_mappings
try {
    $rows = $pdo->query("SELECT m.id, m.external_property_id AS code, m.status,
        c.display_name AS channel_name, m.channel_connection_id AS conn_id,
        p.name AS property_name, m.property_id
        FROM channel_property_mappings m
        LEFT JOIN properties p ON p.id=m.property_id
        LEFT JOIN channel_connections c ON c.id=m.channel_connection_id
        WHERE p.id IS NULL OR c.id IS NULL
        ORDER BY m.id")->fetchAll();
    foreach ($rows as $r) $orphans[] = array_merge($r, ['table' => 'channel_property_mappings', 'label' => 'Ürün eşleştirmesi',
        'issue' => isset($r['property_name']) ? ($r['channel_name'] ? '' : 'kanal #' . $r['conn_id'] . ' (silindi)')
            : 'ürün #' . $r['property_id'] . ' (silindi)']);
} catch (Throwable $e) {}

// 4) ical_connections
try {
    $rows = $pdo->query("SELECT c.id, c.label, c.status, c.direction, c.supplier_id, c.created_at,
        su.full_name AS supplier_name,
        (SELECT MAX(l.created_at) FROM ical_sync_logs l WHERE l.ical_connection_id=c.id) AS last_sync_at
        FROM ical_connections c
        LEFT JOIN properties p ON p.id=c.property_id
        LEFT JOIN supplier_users su ON su.supplier_id=c.supplier_id
        WHERE p.id IS NULL ORDER BY c.id")->fetchAll();
    foreach ($rows as $r) $orphans[] = array_merge($r, ['table' => 'ical_connections', 'label' => 'iCal bağlantısı', 'code' => $r['label'],
        'issue' => 'ürün silindi', 'property_name' => '— (silindi)']);
} catch (Throwable $e) {}

// Son 7 gün temizlik geçmişi
$history = [];
try {
    $histQ = $pdo->query("SELECT details, created_at, admin_username FROM admin_audit_logs
        WHERE action IN ('health.repair_orphan_cleanup', 'orphan.delete_single')
        AND created_at >= now() - interval '7 days' ORDER BY created_at DESC");
    $history = $histQ ? $histQ->fetchAll() : [];
} catch (Throwable $e) {}

$tableCounts = [];
foreach ($orphans as $o) {
    $t = $o['table'];
    $tableCounts[$t] = ($tableCounts[$t] ?? 0) + 1;
}
$totalOrphan = count($orphans);
?>
<!doctype html>
<html lang="tr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Yetim eşleştirmeler | NEXUS Admin</title>
<style>
body{margin:0;background:#f7f7f2;color:#10211f;font-family:Arial}
.wrap{width:min(950px,calc(100% - 32px));margin:40px auto}
.card{background:#fff;border:1px solid #e1e5de;padding:24px;margin-top:20px}
table{border-collapse:collapse;width:100%;font-size:13px}
th{text-align:left;padding:7px 10px;border:1px solid #e1e5de;background:#f4f6f1}
td{padding:6px 10px;border:1px solid #e1e5de}
.ok{color:#2e7d32}.err{color:#b0301a}.warn{color:#8a6100}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:bold}
.badge-red{background:#ffe2de;color:#b0301a}.badge-orange{background:#fdf3e0;color:#8a6100}.badge-green{background:#e6f8c7;color:#2e7d32}
del-btn{background:none;border:1px solid #d8ded8;padding:3px 10px;cursor:pointer;font-size:12px;border-radius:4px}
del-btn:hover{background:#ffe2de;border-color:#b0301a;color:#b0301a}
.msg{background:#e6f8c7;border:1px solid #bcd98a;padding:10px 14px;border-radius:8px;margin-bottom:14px}
.msg-err{background:#ffe2de;border:1px solid #e8b4b0}
</style>
</head>
<body>
<main class="wrap">
<a href="/nexustraveltech/admin/">← Panele dön</a>
<?php if ($msg): ?><div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="msg msg-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<section class="card">
<h1>🧹 Yetim eşleştirmeler</h1>
<p style="color:#64716d">Silinmiş oda tipi, fiyat planı, ürün veya iCare ait olan eşleştirme satırları. Tek tek silebilir veya <a href="/nexustraveltech/admin/approve-orphan-cleanup.php">toplu temizleme</a> ile topluca kaldırabilirsiniz.</p>

<?php if ($totalOrphan === 0): ?>
<p style="color:#2e7d32;font-weight:bold">✓ Yetim eşleştirme yok — tüm satırlar geçerli.</p>
<?php else: ?>
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
<?php foreach ($tableCounts as $tbl => $cnt): ?>
<span class="badge <?= $tbl === 'ical_connections' ? 'badge-orange' : 'badge-red' ?>"><?= htmlspecialchars(str_replace('channel_', '', $tbl)) ?>: <?= $cnt ?></span>
<?php endforeach; ?>
<span class="badge badge-red" style="background:#f4f6f1">Toplam: <?= $totalOrphan ?></span>
</div>

<table>
<tr><th>#</th><th>Tür</th><th>Kod / etiket</th><th>Durum</th><th>Ürün / kanal</th><th>Sorun</th><th></th></tr>
<?php foreach ($orphans as $o): ?>
<tr>
<td><?= (int) $o['id'] ?></td>
<td><?= htmlspecialchars($o['label']) ?></td>
<td><b><?= htmlspecialchars((string) ($o['code'] ?? '')) ?></b>
    <?php if (!empty($o['suggestion_score'])): ?>
    <span class="badge badge-orange">%<?= (int) $o['suggestion_score'] ?></span>
    <?php endif; ?>
</td>
<td><?= htmlspecialchars((string) ($o['status'] ?? '')) ?></td>
<td><?= htmlspecialchars((string) ($o['property_name'] ?? '')) ?>
    <?php if (!empty($o['channel_name'])): ?> · <span style="color:#64716d"><?= htmlspecialchars($o['channel_name']) ?></span><?php endif; ?>
    <?php if (!empty($o['supplier_name'])): ?> · <span style="color:#64716d"><?= htmlspecialchars($o['supplier_name']) ?></span><?php endif; ?>
</td>
<td class="err" style="font-size:12px"><?= htmlspecialchars((string) ($o['issue'] ?? '')) ?></td>
<td>
<form method="post" style="display:inline" onsubmit="return confirm('Bu kaydı silmek istediğinize emin misiniz?')">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
<input type="hidden" name="action" value="delete_orphan">
<input type="hidden" name="table" value="<?= htmlspecialchars($o['table']) ?>">
<input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
<button class="del-btn" title="Tek satırı sil">🗑 Sil</button>
</form>
</td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>
</section>

<?php if ($history): ?>
<section class="card">
<h2>📜 Son 7 gün — temizlik geçmişi</h2>
<table>
<tr><th>Tarih</th><th>Yönetici</th><th>Detay</th></tr>
<?php foreach (array_slice($history, 0, 20) as $hr): ?>
<tr>
<td style="white-space:nowrap"><?= htmlspecialchars(mb_substr((string) $hr['created_at'], 0, 16)) ?></td>
<td><?= htmlspecialchars((string) ($hr['admin_username'] ?? '')) ?></td>
<td style="font-size:12px"><?= htmlspecialchars(mb_substr((string) ($hr['details'] ?? ''), 0, 100)) ?></td>
</tr>
<?php endforeach; ?>
</table>
</section>

<?php
// 26 haftalık yetim trend grafiği
$orphanHist = (array) platform_setting('distribution_health_orphan_history', []);
if ($orphanHist !== []):
    ksort($orphanHist);
    $histMax = max(1, max($orphanHist));
    $histWeeks = array_slice($orphanHist, -26, null, true);
?>
<section class="card">
<h2>📈 Yetim trendi — son <?= count($histWeeks) ?> hafta</h2>
<p style="color:#64716d;font-size:12px">Haftalık dağıtım sağlığı özetindeki toplam yetim eşleştirme sayısı.</p>
<div style="display:flex;align-items:flex-end;gap:3px;height:80px;margin:10px 0">
<?php foreach ($histWeeks as $wk => $cnt): $h = max(2, (int) round($cnt / $histMax * 70)); ?>
<div title="<?= htmlspecialchars($wk) ?>: <?= $cnt ?> yetim" style="flex:1;background:<?= $cnt > 0 ? '#e8a33d' : '#e1e5de' ?>;height:<?= $h ?>px;border-radius:2px 2px 0 0;min-width:5px"></div>
<?php endforeach; ?>
</div>
<div style="display:flex;justify-content:space-between;font-size:10px;color:#64716d">
<span><?= htmlspecialchars(array_key_first($histWeeks)) ?></span>
<span><?= htmlspecialchars(array_key_last($histWeeks)) ?></span>
</div>
<div style="margin-top:8px;font-size:12px;color:#64716d">
<?php
$histTotal = array_sum($histWeeks);
$histAvg = count($histWeeks) > 0 ? round($histTotal / count($histWeeks), 1) : 0;
$histNonZero = count(array_filter($histWeeks, fn($v) => $v > 0));
echo htmlspecialchars((string) $histTotal) . ' satır haftalık toplam · ';
echo htmlspecialchars((string) $histAvg) . ' ortalama · ';
echo $histNonZero . '/' . count($histWeeks) . ' haftada temizlik';
if ($histMax > 0) echo ' · en yüksek: ' . $histMax;
?>
</div>
</section>

<?php
// Günlük kanal/ürün bazında yetim trendi
$dailyHist = (array) platform_setting('orphan_daily_channel_history', []);
if ($dailyHist !== []):
    ksort($dailyHist);
    // Kanal/ürün bazında toplam hesapla
    $dailyTotals = [];
    $dailyByChannel = []; // channel_name => [date => count]
    foreach ($dailyHist as $date => $entries) {
        $dayTotal = 0;
        foreach ($entries as $e) {
            $dayTotal += (int) ($e['total'] ?? 0);
            $ch = (string) ($e['channel'] ?? '—');
            if (!isset($dailyByChannel[$ch])) $dailyByChannel[$ch] = [];
            $dailyByChannel[$ch][$date] = ($dailyByChannel[$ch][$date] ?? 0) + (int) ($e['total'] ?? 0);
        }
        $dailyTotals[$date] = $dayTotal;
    }
    $dMax = max(1, max($dailyTotals));
?>
<section class="card">
<h2>📅 Günlük kanal bazında yetim — son <?= count($dailyTotals) ?> gün</h2>
<p style="color:#64716d;font-size:12px">Denetim görevinin her gün kaydettiği kanal/ürün bazında yetim sayıları.</p>
<div style="display:flex;align-items:flex-end;gap:2px;height:60px;margin:10px 0">
<?php foreach ($dailyTotals as $date => $cnt): $h = max(2, (int) round($cnt / $dMax * 50)); ?>
<div title="<?= htmlspecialchars($date) ?>: <?= $cnt ?> yetim" style="flex:1;background:<?= $cnt > 0 ? '#e8a33d' : '#e1e5de' ?>;height:<?= $h ?>px;border-radius:2px 2px 0 0;min-width:4px"></div>
<?php endforeach; ?>
</div>
<div style="display:flex;justify-content:space-between;font-size:10px;color:#64716d">
<span><?= htmlspecialchars(array_key_first($dailyTotals)) ?></span>
<span><?= htmlspecialchars(array_key_last($dailyTotals)) ?></span>
</div>
<?php
$dTotal = array_sum($dailyTotals);
$dAvg = count($dailyTotals) > 0 ? round($dTotal / count($dailyTotals), 1) : 0;
$dNonZero = count(array_filter($dailyTotals, fn($v) => $v > 0));
?>
<div style="margin-top:8px;font-size:12px;color:#64716d">
<?= $dTotal ?> satır günlük toplam · <?= $dAvg ?> ortalama · <?= $dNonZero ?>/<?= count($dailyTotals) ?> günde yetim
</div>

<?php if (count($dailyByChannel) > 0): ?>
<h3 style="margin:14px 0 6px;font-size:13px">Kanal / ürün bazında son durum</h3>
<table style="border-collapse:collapse;width:100%;font-size:12px">
<tr><th style="text-align:left;padding:4px 8px;border:1px solid #e1e5de;background:#f4f6f1">Kanal</th>
<th style="text-align:right;padding:4px 8px;border:1px solid #e1e5de;background:#f4f6f1">Son gün</th>
<th style="text-align:right;padding:4px 8px;border:1px solid #e1e5de;background:#f4f6f1">7 gün ort.</th>
<th style="text-align:right;padding:4px 8px;border:1px solid #e1e5de;background:#f4f6f1">30 gün</th></tr>
<?php
$lastDate = array_key_last($dailyTotals);
$weekAgo = date('Y-m-d', strtotime('-7 days'));
foreach ($dailyByChannel as $ch => $dates) {
    $lastVal = $dates[$lastDate] ?? 0;
    $weekDates = array_filter($dates, fn($d) => $d >= $weekAgo);
    $weekAvg = count($weekDates) > 0 ? round(array_sum($weekDates) / count($weekDates), 1) : 0;
    $monthTotal = array_sum($dates);
    $color = $lastVal > 0 ? '#b0301a' : '#2e7d32';
    echo '<tr><td style="padding:4px 8px;border:1px solid #e1e5de"><b>' . htmlspecialchars($ch) . '</b></td>';
    echo '<td style="padding:4px 8px;border:1px solid #e1e5de;text-align:right;color:' . $color . '"><b>' . $lastVal . '</b></td>';
    echo '<td style="padding:4px 8px;border:1px solid #e1e5de;text-align:right">' . $weekAvg . '</td>';
    echo '<td style="padding:4px 8px;border:1px solid #e1e5de;text-align:right">' . $monthTotal . '</td></tr>';
}
?>
</table>
<?php endif; ?>
</section>
<?php endif; ?><?php endif; ?>
<?php endif; ?>

</main>
</body>
</html>
