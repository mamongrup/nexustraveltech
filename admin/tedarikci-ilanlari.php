<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_admin();

// Tedarikçi ilan görünümü — özellik silme onay ekranındaki "N ilan" bağlantısının hedefi.
// Yalnızca salt okunur: hangi ilanların etkilendiğini tedarikçi kimliğiyle hızlıca görüntüler.

$supplierId = (int) ($_GET['supplier_id'] ?? 0);
$typeLabel = fn($t) => ['hotel' => 'Otel', 'villa' => 'Villa', 'yacht' => 'Yat'][$t] ?? $t;
$statusLabel = ['draft' => 'Taslak', 'active' => 'Yayında', 'paused' => 'Duraklatıldı'];

$pdo = db();
$supplier = null;
$props = [];
$error = '';

if ($supplierId > 0) {
    $q = $pdo->prepare('SELECT s.*, (SELECT COUNT(*) FROM supplier_users u WHERE u.supplier_id=s.id) AS user_count FROM suppliers s WHERE s.id=?');
    $q->execute([$supplierId]);
    $supplier = $q->fetch();
    if ($supplier) {
        $pq = $pdo->prepare("SELECT p.id, p.name, p.property_type, p.status, p.city, p.created_at,
                (SELECT COUNT(*) FROM room_types r WHERE r.property_id=p.id) AS room_count,
                (SELECT COUNT(*) FROM rate_plans rp WHERE rp.property_id=p.id) AS plan_count
             FROM properties p WHERE p.supplier_id=? ORDER BY p.property_type, p.name");
        $pq->execute([$supplierId]);
        $props = $pq->fetchAll();
    } else {
        $error = 'Tedarikçi bulunamadı.';
    }
} else {
    $error = 'Geçersiz tedarikçi kimliği.';
}
$byType = ['hotel' => 0, 'villa' => 0, 'yacht' => 0];
$byStatus = ['draft' => 0, 'active' => 0, 'paused' => 0];
foreach ($props as $p) {
    if (isset($byType[$p['property_type']])) $byType[$p['property_type']]++;
    if (isset($byStatus[$p['status']])) $byStatus[$p['status']]++;
}
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tedarikçi ilanları | NEXUS Admin</title><style>body{font-family:Arial;background:#f7f7f2;color:#10211f;margin:0}.w{width:min(1000px,calc(100% - 30px));margin:35px auto}.c{background:#fff;border:1px solid #ddd;padding:18px;margin:16px 0;border-radius:8px}.meta{color:#64716d;font-size:13px;line-height:1.6}.badge{display:inline-block;padding:2px 9px;border-radius:12px;font-size:12px;font-weight:bold;background:#eef0ea;color:#405b13;margin-left:6px}.badge.on{background:#e6f8c7}.badge.off{background:#ffe2de;color:#9d3b1c}table{width:100%;border-collapse:collapse;margin-top:10px}th{text-align:left;padding:8px 10px;border-bottom:1px solid #e1e5de;font-size:11px;text-transform:uppercase;color:#64716d}td{padding:9px 10px;border-bottom:1px solid #eef0ea;font-size:14px}.mini{display:inline-flex;gap:8px;flex-wrap:wrap;margin:10px 0 0}.mini div{background:#f4f6f1;border:1px solid #e1e5de;border-radius:8px;padding:8px 14px;font-size:13px}</style></head><body><main class="w"><a href="/nexustraveltech/admin/ozellik-listeleri">← Katalog & sınıflandırma yönetimi</a>
<?php if ($error): ?><div class="c" style="border-color:#f3c4ba;background:#fff7f5"><?= htmlspecialchars($error) ?></div>
<?php elseif ($supplier): ?>
<h1>🏢 <?= htmlspecialchars((string) $supplier['company_name']) ?></h1>
<p class="meta">Tedarikçi #<?= (int) $supplier['id'] ?> · <?= htmlspecialchars($statusLabel[$supplier['status']] ?? (string) $supplier['status']) ?><span class="badge <?= $supplier['status'] === 'active' ? 'on' : 'off' ?>"><?= htmlspecialchars((string) $supplier['status']) ?></span><br>Kayıtlı ilan: <b><?= count($props) ?></b> (<?= implode(' · ', array_map(fn($t) => $typeLabel($t) . ': ' . $byType[$t], array_keys($byType))) ?> <?php if (array_sum($byStatus) > 0): ?>— <?= implode(' · ', array_map(fn($s) => ($statusLabel[$s] ?? $s) . ': ' . $byStatus[$s], array_keys($byStatus))) ?><?php endif; ?>)</p>
<div class="c"><h2 style="margin-top:0">İlanlar</h2>
<?php if (!$props): ?><p class="meta">Bu tedarikçinin kayıtlı ilanı yok.</p><?php else: ?>
<table><tr><th>İlan</th><th>Tür</th><th>Durum</th><th>Şehir</th><th>Oda tipi</th><th>Fiyat planı</th><th>Eklenme</th></tr>
<?php foreach ($props as $p): ?><tr><td><b><?= htmlspecialchars((string) $p['name']) ?></b></td><td><?= $typeLabel($p['property_type']) ?></td><td><span class="badge <?= $p['status'] === 'active' ? 'on' : 'off' ?>"><?= htmlspecialchars($statusLabel[$p['status']] ?? (string) $p['status']) ?></span></td><td><?= htmlspecialchars((string) ($p['city'] ?? '—')) ?></td><td><?= (int) $p['room_count'] ?></td><td><?= (int) $p['plan_count'] ?></td><td><?= htmlspecialchars(mb_substr((string) $p['created_at'], 0, 10)) ?></td></tr><?php endforeach; ?>
</table>
<div class="mini"><div>Otel <b><?= (int) $byType['hotel'] ?></b></div><div>Villa <b><?= (int) $byType['villa'] ?></b></div><div>Yat <b><?= (int) $byType['yacht'] ?></b></div><div>Yayında <b><?= (int) $byStatus['active'] ?></b></div><div>Taslak <b><?= (int) $byStatus['draft'] ?></b></div><div>Duraklatılmış <b><?= (int) $byStatus['paused'] ?></b></div></div>
<?php endif; ?>
<p class="meta" style="margin-top:14px"><a href="/nexustraveltech/tedarikci/" target="_blank" rel="noopener" style="color:#405b13">Tedarikçi panelini ayrı sekmede aç ↗</a> <small>(panel oturumu gerektirir)</small></p>
</div>
<?php endif; ?>
</main></body></html>
