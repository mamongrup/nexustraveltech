<?php
declare(strict_types=1);
$active_module = 'hotel_revenue';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/../config/pricing.php';

if (empty($_SESSION['supplier_csrf'])) {
    $_SESSION['supplier_csrf'] = bin2hex(random_bytes(32));
}
$u = $supplier_user;
$pdo = db();
$msg = '';
$err = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['supplier_csrf'], (string) ($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Güvenlik doğrulaması geçersiz.');
        }
        $a = $_POST['action'] ?? '';

        if ($a === 'run_ai_now') {
            $propId = (int) ($_POST['property_id'] ?? 0);
            if ($propId <= 0) throw new RuntimeException('Geçerli bir tesis seçin.');
            $chk = $pdo->prepare("SELECT id FROM properties WHERE id = ? AND supplier_id = ?");
            $chk->execute([$propId, $u['supplier_id']]);
            if (!$chk->fetch()) throw new RuntimeException('Tesis bulunamadı.');

            $res = run_dynamic_revenue_engine($propId, false);
            $msg = "AI Gelir Analizi tamamlandı: {$res['generated']} yeni dinamik fiyat önerisi üretildi.";
        }

        if ($a === 'apply_rec') {
            $id = (int) $_POST['id'];
            $q = $pdo->prepare("SELECT r.*, p.id prop_id FROM revenue_recommendations r JOIN properties p ON p.id=r.property_id WHERE r.id=? AND p.supplier_id=? AND r.status='new'");
            $q->execute([$id, $u['supplier_id']]);
            $r = $q->fetch();
            if (!$r) throw new RuntimeException('Öneri bulunamadı veya daha önce işlendi.');

            if ($r['rate_plan_id']) {
                $pdo->prepare('UPDATE inventory_calendar SET base_price=? WHERE rate_plan_id=? AND stay_date=?')->execute([(float)$r['recommended_value'], (int)$r['rate_plan_id'], $r['stay_date']]);
            } else {
                $pdo->prepare('UPDATE inventory_calendar SET base_price=? WHERE stay_date=? AND room_type_id IN (SELECT id FROM room_types WHERE property_id=?)')->execute([(float)$r['recommended_value'], $r['stay_date'], $r['property_id']]);
            }
            $pdo->prepare("UPDATE revenue_recommendations SET status='applied' WHERE id=?")->execute([$id]);
            if (function_exists('audit_log')) {
                audit_log('revenue.apply_recommendation', 'supplier', (int)$u['supplier_id'], ['recommendation_id' => $id, 'value' => $r['recommended_value'], 'stay_date' => $r['stay_date']]);
            }
            $msg = 'Öneri uygulandı: ' . $r['stay_date'] . ' tarihli fiyat ' . $r['recommended_value'] . ' ' . $r['currency'] . ' olarak takvime işlendi.';
        }

        if ($a === 'apply_all_recs') {
            $propId = (int) ($_POST['property_id'] ?? 0);
            $q = $pdo->prepare("SELECT r.* FROM revenue_recommendations r JOIN properties p ON p.id=r.property_id WHERE p.supplier_id=? AND r.status='new'" . ($propId > 0 ? " AND r.property_id=?" : ""));
            $q->execute($propId > 0 ? [$u['supplier_id'], $propId] : [$u['supplier_id']]);
            $allRecs = $q->fetchAll();
            $appliedCount = 0;

            foreach ($allRecs as $r) {
                if ($r['rate_plan_id']) {
                    $pdo->prepare('UPDATE inventory_calendar SET base_price=? WHERE rate_plan_id=? AND stay_date=?')->execute([(float)$r['recommended_value'], (int)$r['rate_plan_id'], $r['stay_date']]);
                } else {
                    $pdo->prepare('UPDATE inventory_calendar SET base_price=? WHERE stay_date=? AND room_type_id IN (SELECT id FROM room_types WHERE property_id=?)')->execute([(float)$r['recommended_value'], $r['stay_date'], $r['property_id']]);
                }
                $pdo->prepare("UPDATE revenue_recommendations SET status='applied' WHERE id=?")->execute([$r['id']]);
                $appliedCount++;
            }
            $msg = "{$appliedCount} adet dinamik fiyat önerisi tek tıkla onaylandı ve takvime/kanallara uygulandı.";
        }

        if ($a === 'dismiss_rec') {
            $id = (int) $_POST['id'];
            $q = $pdo->prepare("SELECT r.id FROM revenue_recommendations r JOIN properties p ON p.id=r.property_id WHERE r.id=? AND p.supplier_id=? AND r.status='new'");
            $q->execute([$id, $u['supplier_id']]);
            if (!$q->fetch()) throw new RuntimeException('Öneri bulunamadı veya daha önce işlendi.');
            $pdo->prepare("UPDATE revenue_recommendations SET status='dismissed' WHERE id=?")->execute([$id]);
            $msg = 'Öneri reddedildi.';
        }

        if ($a === 'tier') {
            $pdo->prepare('INSERT INTO loyalty_tiers(supplier_id,code,name,min_nights,min_revenue,stay_discount_percent,service_discount_percent,bonus_expiry_days) VALUES(?,?,?,?,?,?,?,?)')
                ->execute([$u['supplier_id'], strtoupper(trim((string)$_POST['code'])), trim((string)$_POST['name']), max(0, (int)$_POST['min_nights']), max(0, (float)$_POST['min_revenue']), max(0, (float)$_POST['stay_discount']), max(0, (float)$_POST['service_discount']), $_POST['expiry_days'] ?: null]);
            $msg = 'Sadakat seviyesi eklendi.';
        }

        if ($a === 'snapshot') {
            $pdo->prepare('INSERT INTO revenue_snapshots(property_id,snapshot_date,occupancy_percent,adr,revpar,pickup_count,cancellation_count) VALUES(?,?,?,?,?,?,?) ON CONFLICT(property_id,snapshot_date) DO UPDATE SET occupancy_percent=EXCLUDED.occupancy_percent,adr=EXCLUDED.adr,revpar=EXCLUDED.revpar,pickup_count=EXCLUDED.pickup_count,cancellation_count=EXCLUDED.cancellation_count')
                ->execute([(int)$_POST['property_id'], $_POST['date'], (float)$_POST['occupancy'], (float)$_POST['adr'], (float)$_POST['revpar'], (int)$_POST['pickup'], (int)$_POST['cancellations']]);
            $msg = 'Günlük veri kaydedildi.';
        }
    }
} catch (Throwable $e) {
    $err = $e->getMessage();
}

$q = $pdo->prepare("SELECT id, name FROM properties WHERE supplier_id=? ORDER BY name");
$q->execute([$u['supplier_id']]);
$properties = $q->fetchAll();

$q = $pdo->prepare('SELECT * FROM loyalty_tiers WHERE supplier_id=? ORDER BY min_nights');
$q->execute([$u['supplier_id']]);
$tiers = $q->fetchAll();

$q = $pdo->prepare("SELECT r.*, p.name property_name, rp.name rate_plan_name FROM revenue_recommendations r JOIN properties p ON p.id=r.property_id LEFT JOIN rate_plans rp ON rp.id=r.rate_plan_id WHERE p.supplier_id=? AND r.status='new' ORDER BY r.stay_date ASC LIMIT 100");
$q->execute([$u['supplier_id']]);
$newRecs = $q->fetchAll();

$q = $pdo->prepare("SELECT r.*, p.name property_name FROM revenue_recommendations r JOIN properties p ON p.id=r.property_id WHERE p.supplier_id=? AND r.status != 'new' ORDER BY r.stay_date DESC LIMIT 50");
$q->execute([$u['supplier_id']]);
$pastRecs = $q->fetchAll();

supply_start('Dinamik Gelir Yönetimi & AI Fiyat Motoru', $active_module);
?>
<section class="page-intro">
    <p>Doluluk oranına, rezervasyon ivmesine (pickup) ve kalan gün sayısına göre çalışan <strong>AI Revenue Engine 2.0</strong> ile tesislerinizin karlılığını otomatik maksimize edin.</p>
</section>

<?php if ($msg): ?><p class="save-success">✓ <?= htmlspecialchars($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="login-error"><?= htmlspecialchars($err) ?></p><?php endif; ?>

<section class="next-module" style="background:#f4f9f6;border:1px solid #bce2ce;padding:18px;border-radius:8px">
    <h2>🤖 AI Dinamik Gelir Analizi Çalıştır</h2>
    <p style="font-size:13px;color:#2c5e43;margin-bottom:12px">Tesisinizin gelecek 30 günlük doluluk ve rezervasyon hareketlerini anlık tarayarak karlı fiyat optimizasyonları üretir.</p>
    <form method="post" class="supply-form" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['supplier_csrf']) ?>">
        <input type="hidden" name="action" value="run_ai_now">
        <label style="flex:1;min-width:240px">Tesis Seçin:
            <select name="property_id" required>
                <?php foreach ($properties as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button style="background:#13593b;color:#fff;padding:10px 20px;border:none;border-radius:6px;font-weight:bold;cursor:pointer">⚡ Şimdi Analiz Et & Öneri Üret</button>
    </form>
</section>

<section class="next-module">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
        <h2>⚡ Bekleyen Dinamik Fiyat Önerileri (<?= count($newRecs) ?>)</h2>
        <?php if ($newRecs): ?>
            <form method="post" style="margin:0">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['supplier_csrf']) ?>">
                <input type="hidden" name="action" value="apply_all_recs">
                <button onclick="return confirm('Tüm yeni dinamik fiyat önerileri takvime ve dağıtım kanallarına uygulansın mı?')" style="background:#10211f;color:#d7ff48;padding:8px 16px;border:none;border-radius:6px;font-weight:bold;cursor:pointer">✓ Tümünü Onayla & Dağıt</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (!$newRecs): ?>
        <p class="muted">Şu an onay bekleyen yeni bir fiyat önerisi yok. Yukarıdaki butonla anlık analiz başlatabilirsiniz.</p>
    <?php else: ?>
        <div style="display:grid;gap:10px">
            <?php foreach ($newRecs as $r): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;background:#fff;padding:12px 16px;border:1px solid #e1e8e3;border-radius:6px;border-left:4px solid <?= $r['recommendation_type']==='raise_rate' ? '#13593b' : '#b26a00' ?>">
                    <div>
                        <b><?= htmlspecialchars($r['property_name']) ?></b> <?= $r['rate_plan_name'] ? '· <span style="color:#5c7065">'.htmlspecialchars($r['rate_plan_name']).'</span>' : '' ?>
                        <div style="font-size:13px;margin-top:3px">
                            <span style="background:#eef5f1;padding:2px 6px;border-radius:4px;font-weight:bold"><?= htmlspecialchars($r['stay_date']) ?></span>
                            <strong style="color:<?= $r['recommendation_type']==='raise_rate' ? '#13593b' : '#b26a00' ?>">
                                <?= number_format((float)$r['recommended_value'], 2) ?> <?= htmlspecialchars($r['currency']) ?>
                            </strong>
                            <span style="color:#666;font-size:12px">(<?= htmlspecialchars($r['reason']) ?> · Güven: %<?= (int)$r['confidence'] ?>)</span>
                        </div>
                    </div>
                    <div style="display:flex;gap:8px">
                        <form method="post" style="margin:0">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['supplier_csrf']) ?>">
                            <input type="hidden" name="action" value="apply_rec">
                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <button style="background:#13593b;color:#fff;border:none;padding:6px 14px;border-radius:4px;font-weight:600;cursor:pointer">Uygula</button>
                        </form>
                        <form method="post" style="margin:0">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['supplier_csrf']) ?>">
                            <input type="hidden" name="action" value="dismiss_rec">
                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <button style="background:#f1eceb;color:#8e2410;border:none;padding:6px 14px;border-radius:4px;cursor:pointer">Reddet</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="next-module">
    <h2>🏆 Sadakat & Misafir Seviyeleri (Loyalty Tiers)</h2>
    <form method="post" class="supply-form">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['supplier_csrf']) ?>">
        <input type="hidden" name="action" value="tier">
        <div class="form-row">
            <input name="code" placeholder="VIP / GOLD" required>
            <input name="name" placeholder="Gold Misafir Kulübü" required>
            <input type="number" name="min_nights" placeholder="Min. Gece" value="0">
            <input type="number" step="0.01" name="min_revenue" placeholder="Min. Harcama (EUR)" value="0">
            <input type="number" step="0.01" name="stay_discount" placeholder="Konaklama İndirim %" value="0">
            <input type="number" step="0.01" name="service_discount" placeholder="Ekstra Hizmet İndirim %" value="0">
            <input type="number" name="expiry_days" placeholder="Puan Geçerlilik Günü">
        </div>
        <button style="background:#10211f;color:#fff;padding:8px 16px;border:none;border-radius:6px">Sadakat Seviyesi Ekle</button>
    </form>
    <div style="margin-top:10px">
        <?php foreach ($tiers as $t): ?>
            <p style="background:#fff;padding:8px 12px;border:1px solid #e1e8e3;border-radius:4px">
                <b><?= htmlspecialchars($t['name']) ?> (<?= htmlspecialchars($t['code']) ?>)</b> · 
                %<?= htmlspecialchars($t['stay_discount_percent']) ?> konaklama indirimi · 
                %<?= htmlspecialchars($t['service_discount_percent']) ?> ekstra hizmet indirimi
            </p>
        <?php endforeach; ?>
    </div>
</section>

<?php supply_end(); ?>
