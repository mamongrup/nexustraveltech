<?php
declare(strict_types=1);

$active_module = 'properties';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/../config/supplier_verification.php';
require_once __DIR__ . '/../config/listing_integrity.php';

$u = $supplier_user;
$pdo = db();
$allowedTypes = supplier_allowed_product_types((int) $u['supplier_id']);
$message = '';
$error = '';

if (empty($_SESSION['supplier_csrf'])) {
    $_SESSION['supplier_csrf'] = bin2hex(random_bytes(32));
}

// --- Yayına al / Duraklat ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['supplier_csrf'], (string) ($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Güvenlik doğrulaması geçersiz.');
        }
        $action = $_POST['action'] ?? '';
        $propertyId = (int) ($_POST['property_id'] ?? 0);
        $q = $pdo->prepare('SELECT * FROM properties WHERE id=? AND supplier_id=? FOR UPDATE');
        $q->execute([$propertyId, $u['supplier_id']]);
        $property = $q->fetch();
        if (!$property) {
            throw new RuntimeException('Ürün bulunamadı veya size ait değil.');
        }
        if ($action === 'publish') {
            $readiness = listing_readiness($property);
            if (!$readiness['ready']) {
                $missing = array_map(fn($i) => $i['label'], array_filter($readiness['items'], fn($i) => !$i['ok']));
                throw new RuntimeException('Ürün yayına alınamadı — eksik kalemler: ' . implode(', ', $missing) . '.');
            }
            $pdo->prepare("UPDATE properties SET status='active' WHERE id=?")->execute([$propertyId]);
            record_audit_event('supplier', (int) $u['id'], 'publish', 'property', $propertyId, ['name' => $property['name']]);
            $message = 'Ürün yayına alındı. Acente müsaitlik sorgularında artık görünür.';
        } elseif ($action === 'pause') {
            if ($property['status'] !== 'active') {
                throw new RuntimeException('Yalnızca yayındaki ürünler duraklatılabilir.');
            }
            $pdo->prepare("UPDATE properties SET status='paused' WHERE id=?")->execute([$propertyId]);
            record_audit_event('supplier', (int) $u['id'], 'pause', 'property', $propertyId, ['name' => $property['name']]);
            $message = 'Ürün duraklatıldı. Acente sorgularında artık gösterilmez.';
        } else {
            throw new RuntimeException('Geçersiz işlem.');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$q = $pdo->prepare("SELECT p.*, (SELECT COUNT(*) FROM room_types r WHERE r.property_id=p.id) rooms, (SELECT COUNT(*) FROM rate_plans rp WHERE rp.property_id=p.id AND rp.status='active') rates FROM properties p WHERE p.supplier_id=? ORDER BY p.id");
$q->execute([$u['supplier_id']]);
$items = $q->fetchAll();

supply_start('Tesisler & ürünler', $active_module);
?>
<section class="page-intro"><p>Dağıtıma açacağınız ürün, birim ve fiyat yapısını burada yönetirsiniz. Yayına almak için ilanın hazırlık kontrolünden geçmesi gerekir.</p><?php if ($allowedTypes): ?><a class="primary-button" href="/nexustraveltech/tedarikci/tesis-ekle">+ Yeni ürün</a><?php else: ?><a class="primary-button" href="/nexustraveltech/tedarikci/hesap-dogrulama">Önce hesap doğrulamasını tamamla →</a><?php endif; ?></section>
<?php if (!$allowedTypes): ?><p class="login-error">Yeni ilan açmak için yönetici kimlik ve ürün yetkisi onayı gereklidir.</p><?php endif; ?>
<?php if ($message): ?><p class="save-success">✓ <?= htmlspecialchars($message) ?></p><?php endif; ?>
<?php if ($error): ?><p class="login-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<?php if (isset($_GET['created'])): ?><p class="save-success">✓ Ürün taslak olarak oluşturuldu. Şimdi detaylarını ekleyebilirsiniz.</p><?php endif; ?>
<section class="property-list">
<?php foreach ($items as $item):
    $readiness = listing_readiness($item);
    $status = $item['status'];
    $statusLabel = ['draft' => 'Taslak', 'active' => 'Yayında', 'paused' => 'Duraklatıldı'][$status] ?? $status;
    $statusClass = $status === 'active' ? 'active-status' : ($status === 'paused' ? 'paused-status' : 'draft-status');
    $icalItem = null;
    foreach ($readiness['items'] as $ri) { if ($ri['key'] === 'ical') { $icalItem = $ri; break; } }
    $icalUrls = $icalItem && !empty($icalItem['urls']) ? $icalItem['urls'] : [];
?>
<article class="property-card">
    <div class="property-code"><?= strtoupper(htmlspecialchars($item['property_type'])) ?></div>
    <div class="property-main">
        <h2><?= htmlspecialchars($item['name']) ?></h2>
        <p><?= htmlspecialchars($item['city'] ?: 'Konum girilmedi') ?> · <?= htmlspecialchars($item['country_code']) ?></p>
    </div>
    <div><span>BİRİM TİPİ</span><b><?= $item['rooms'] ?></b></div>
    <div><span>FİYAT PLANI</span><b><?= $item['rates'] ?></b></div>
    <div><span>DURUM</span><b class="status <?= $statusClass ?>"><?= $statusLabel ?></b></div>
    <div class="readiness-block">
        <div class="readiness-head"><span>YAYIN HAZIRLIĞI</span><b><?= $readiness['score'] ?>/100</b></div>
        <div class="readiness-bar"><i style="width:<?= (int) $readiness['score'] ?>%"></i></div>
        <?php $missing = array_filter($readiness['items'], fn($i) => !$i['ok']); $warnings = array_filter($readiness['items'], fn($i) => !empty($i['warn'])); $warnLinks = ['ical' => '/nexustraveltech/tedarikci/ical-takvimler']; $editBase = $item['property_type'] === 'hotel' ? '/nexustraveltech/tedarikci/otel-detay?product=' . (int) $item['id'] : '/nexustraveltech/tedarikci/villa-detay?product=' . (int) $item['id']; $missLinks = ['rooms' => $editBase, 'rates' => '/nexustraveltech/tedarikci/fiyat-kontenjan?property=' . (int) $item['id'], 'inventory' => '/nexustraveltech/tedarikci/fiyat-kontenjan?property=' . (int) $item['id'], 'media' => $editBase, 'description' => $editBase, 'location' => $editBase, 'pool' => $editBase, 'home_port' => $editBase, 'crew' => $editBase, 'ical' => '/nexustraveltech/tedarikci/ical-takvimler', 'rules' => '/nexustraveltech/tedarikci/satis-kurallari?property=' . (int) $item['id']]; ?>
        <?php if ($warnings): ?>
        <ul class="readiness-warn"><?php foreach ($warnings as $w): ?><li><a class="readiness-warn-link" href="<?= htmlspecialchars($warnLinks[$w['key']] ?? '/nexustraveltech/tedarikci/ical-takvimler') ?>">⚠ <?= htmlspecialchars($w['label']) ?> — <?= htmlspecialchars($w['detail']) ?><?php if (array_key_exists('age_days', $w) && $w['age_days'] !== null): ?> <em>(son senkron <?= (int) $w['age_days'] ?> gün önce)</em><?php endif; ?></a></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <?php if ($missing): ?>
        <ul class="readiness-missing"><?php foreach ($missing as $m): ?><li><a class="readiness-miss-link" href="<?= htmlspecialchars($missLinks[$m['key']] ?? $editBase) ?>">✗ <?= htmlspecialchars($m['label']) ?><?php if ($m['key'] === 'rules'): ?> <em>(opsiyonel)</em><?php endif; ?> →</a></li><?php endforeach; ?></ul>
        <?php elseif (!$warnings): ?>
        <p class="readiness-ok">✓ Tüm kalemler tamam — yayına hazır.</p>
        <?php else: ?>
        <p class="readiness-ok">✓ Çekirdek kalemler tamam — sarı uyarıya bakın.</p>
        <?php endif; ?>
        <button type="button" class="readiness-toggle" data-target="readiness-all-<?= (int) $item['id'] ?>">Tüm kalemler ▾</button>
        <div id="readiness-all-<?= (int) $item['id'] ?>" class="readiness-all" hidden>
            <?php $allLinks = ['ical' => '/nexustraveltech/tedarikci/ical-takvimler']; foreach ($readiness['items'] as $ri): $riWarn = !empty($ri['warn']); $riCls = !$ri['ok'] ? 'missing' : ($riWarn ? 'warn' : 'ok'); $riIcon = !$ri['ok'] ? '✗' : ($riWarn ? '⚠' : '✓'); ?>
            <div class="readiness-all-row <?= $riCls ?>"><span class="readiness-all-icon"><?= $riIcon ?></span><div class="readiness-all-text"><b><?= htmlspecialchars($ri['label']) ?></b><small><?= htmlspecialchars($ri['detail']) ?></small></div><?php if (!$ri['ok']): ?><a href="<?= htmlspecialchars($allLinks[$ri['key']] ?? ($item['property_type'] === 'hotel' ? '/nexustraveltech/tedarikci/otel-detay?product=' . (int) $item['id'] : '/nexustraveltech/tedarikci/villa-detay?product=' . (int) $item['id'])) ?>">Doldur →</a><?php elseif ($riWarn): ?><a href="<?= htmlspecialchars($allLinks[$ri['key']] ?? '/nexustraveltech/tedarikci/ical-takvimler') ?>">İncele →</a><?php endif; ?></div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="property-actions">
        <?php if ($item['property_type'] === 'hotel'): ?><a href="/nexustraveltech/tedarikci/otel-detay?product=<?= (int) $item['id'] ?>">İlanı tamamla →</a><?php else: ?><a href="/nexustraveltech/tedarikci/villa-detay?product=<?= (int) $item['id'] ?>">İlanı tamamla →</a><?php endif; ?>
        <?php if ($status === 'active'): ?>
        <form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['supplier_csrf']) ?>"><input type="hidden" name="action" value="pause"><input type="hidden" name="property_id" value="<?= (int) $item['id'] ?>"><button class="ghost-button" onclick="return confirm('Ürün duraklatılsın mı? Acente sorgularında görünmez olur.');">Duraklat</button></form>
        <?php elseif ($readiness['ready']): ?>
        <form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['supplier_csrf']) ?>"><input type="hidden" name="action" value="publish"><input type="hidden" name="property_id" value="<?= (int) $item['id'] ?>"><button class="primary-button">Yayına al</button></form>
        <?php else: ?>
        <span class="ghost-button disabled" title="Eksik kalemler tamamlanmadan yayına alınamaz.">Yayına al</span>
        <?php endif; ?>
        <?php if ($icalUrls): ?><button type="button" class="ghost-button ical-card-toggle" data-target="ical-card-<?= (int) $item['id'] ?>">iCal URL göster</button><?php endif; ?>
    </div>
    <?php if ($icalUrls): ?>
    <div id="ical-card-<?= (int) $item['id'] ?>" class="ical-card-box" hidden>
        <?php foreach ($icalUrls as $icalUrl): $icalQ = (string) parse_url($icalUrl, PHP_URL_QUERY); $icalShort = 'api/ical?' . mb_substr($icalQ, 0, 10) . '…' . mb_substr($icalQ, 30, 4) . '…'; ?>
        <span class="ical-copy-row"><code title="<?= htmlspecialchars($icalUrl) ?>"><?= htmlspecialchars($icalShort) ?></code><button type="button" class="ical-copy-btn" data-copy="<?= htmlspecialchars($icalUrl) ?>">Kopyala</button></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</article>
<?php endforeach; ?>
</section>
<section class="next-module"><span>SONRAKİ MODÜL</span><h2>Fiyat, kontenjan ve satış kuralları</h2><p>Takvim bazlı fiyat, kapasite, stop-sale, minimum konaklama ve kanal dağıtımı bu yapının üzerine eklenecek.</p></section>
<script>document.querySelectorAll('.ical-card-toggle').forEach(function(b){b.addEventListener('click',function(){var box=document.getElementById(this.getAttribute('data-target'));if(box){box.hidden=!box.hidden;this.textContent=box.hidden?'iCal URL göster':'iCal URL gizle'}})});document.querySelectorAll('.readiness-toggle').forEach(function(b){b.addEventListener('click',function(){var box=document.getElementById(this.getAttribute('data-target'));if(box){box.hidden=!box.hidden;this.textContent=box.hidden?'Tüm kalemler ▾':'Tüm kalemler ▴'}})});function icalFallbackCopy(text){var ta=document.createElement('textarea');ta.value=text;ta.style.position='fixed';ta.style.opacity='0';document.body.appendChild(ta);ta.select();try{document.execCommand('copy')}catch(e){}document.body.removeChild(ta)}document.querySelectorAll('.ical-copy-btn').forEach(function(b){b.addEventListener('click',function(){var url=this.getAttribute('data-copy'),btn=this;function done(){btn.textContent='Kopyalandı ✓';btn.classList.add('copied');setTimeout(function(){btn.textContent='Kopyala';btn.classList.remove('copied')},1600)}if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(url).then(done).catch(function(){icalFallbackCopy(url);done()})}else{icalFallbackCopy(url);done()}})});</script><?php supply_end(); ?>
