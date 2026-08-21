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
<?php
require_once __DIR__ . '/layout.php';
admin_layout_start('İlan Hazırlık ve Kalite Özeti', 'hazirlik-ozet');
?>

<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:14px;margin-bottom:24px">
    <div class="sui-card" style="padding:16px">
        <div style="font-size:12px;color:var(--sui-muted);font-weight:600">Toplam İlan</div>
        <div style="font-size:24px;font-weight:800;margin-top:4px"><?= $total ?></div>
    </div>
    <div class="sui-card" style="padding:16px">
        <div style="font-size:12px;color:var(--sui-success);font-weight:600">Yayına Hazır (%100)</div>
        <div style="font-size:24px;font-weight:800;color:var(--sui-success);margin-top:4px"><?= $readyCount ?></div>
    </div>
    <div class="sui-card" style="padding:16px">
        <div style="font-size:12px;color:var(--sui-warning);font-weight:600">Eksik Kalem Var</div>
        <div style="font-size:24px;font-weight:800;color:var(--sui-warning);margin-top:4px"><?= $total - $readyCount ?></div>
    </div>
    <div class="sui-card" style="padding:16px">
        <div style="font-size:12px;color:var(--sui-danger);font-weight:600">Kritik Seviye (< %50)</div>
        <div style="font-size:24px;font-weight:800;color:var(--sui-danger);margin-top:4px"><?= $lowCount ?></div>
    </div>
    <div class="sui-card" style="padding:16px">
        <div style="font-size:12px;color:var(--sui-primary);font-weight:600">Ortalama Skor</div>
        <div style="font-size:24px;font-weight:800;color:var(--sui-primary);margin-top:4px">%<?= $avgScore ?></div>
    </div>
</div>

<div class="sui-card">
    <div class="sui-card-header">
        <h2 class="sui-card-title">📊 Tesis & İlan Hazırlık Denetimi</h2>
    </div>
    <div style="overflow-x:auto">
        <table class="sui-table">
            <thead>
                <tr>
                    <th>İlan / Tesis Adı</th>
                    <th>Tür</th>
                    <th>Tedarikçi</th>
                    <th>Hazırlık Skoru</th>
                    <th>Kritik Eksik Kalem</th>
                    <th style="text-align:center">Tamam</th>
                    <th style="text-align:center">Eksik</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $r): 
                    $p = $r['prop'];
                    $score = $r['score'];
                    $badgeCls = $score >= 80 ? 'sui-badge-success' : ($score >= 50 ? 'sui-badge-warning' : 'sui-badge-danger');
                ?>
                    <tr>
                        <td>
                            <b><?= htmlspecialchars($p['name']) ?></b>
                            <div style="font-size:11px;color:var(--sui-muted)">#<?= (int) $p['id'] ?> · <?= htmlspecialchars((string) ($p['city'] ?? '—')) ?></div>
                        </td>
                        <td><?= $typeLabel($p['property_type']) ?></td>
                        <td><?= htmlspecialchars($p['company_name']) ?></td>
                        <td>
                            <span class="sui-badge <?= $badgeCls ?>">%<?= $score ?></span>
                        </td>
                        <td>
                            <?php if ($r['heaviest_miss']): ?>
                                <span style="font-size:12px;color:var(--sui-muted)">
                                    <?= htmlspecialchars($r['heaviest_miss']['label']) ?>
                                    <b style="color:var(--sui-danger)">(+<?= $r['heaviest_weight'] ?> puan)</b>
                                </span>
                            <?php elseif ($score >= 100): ?>
                                <span style="color:var(--sui-success);font-size:12px;font-weight:600">✓ Eksiksiz Hazır</span>
                            <?php else: ?>
                                <span style="color:var(--sui-muted);font-size:12px">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;color:var(--sui-success);font-weight:600"><?= $r['ok_count'] ?></td>
                        <td style="text-align:center;color:var(--sui-danger);font-weight:600"><?= $r['missing_count'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($results === []): ?>
                    <tr><td colspan="7" style="text-align:center;color:var(--sui-muted);padding:20px">Henüz kayıtlı ilan bulunmuyor.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>

