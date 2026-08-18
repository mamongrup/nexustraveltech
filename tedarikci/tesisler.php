<?php
declare(strict_types=1);

$active_module = 'properties';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/../config/i18n.php';
require_once __DIR__ . '/../config/supplier_verification.php';
require_once __DIR__ . '/../config/listing_integrity.php';
require_once __DIR__ . '/../config/platform_settings.php';

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
        <?php if ($readiness['missing_count'] > 0): ?><p class="readiness-scorecard"><?= (int) $readiness['ok_count'] ?> kalem tamam — kalan <?= (int) $readiness['missing_count'] ?> kalem tamamlanınca 100 olur.</p><?php endif; ?>
<?php $__miss = array_filter($readiness['items'], fn($i) => !$i['ok']); if ($__miss): $__first = reset($__miss); $__badId = 0; $__connId = 0; foreach ($readiness['items'] as $__ci) { if ($__ci['key'] === 'channel' && !empty($__ci['first_bad_id'])) { $__badId = (int) $__ci['first_bad_id']; } if ($__ci['key'] === 'ical' && !empty($__ci['first_conn_id'])) { $__connId = (int) $__ci['first_conn_id']; } } $__jumpSec = ['rooms' => '#sec-04', 'media' => '#sec-05', 'description' => '#sec-02', 'location' => '#sec-01', 'pool' => '#sec-01', 'home_port' => '#sec-01', 'crew' => '#sec-01']; $__jumpLink = ($item['property_type'] === 'hotel' ? '/nexustraveltech/tedarikci/otel-detay?product=' : '/nexustraveltech/tedarikci/villa-detay?product=') . (int) $item['id'] . ($__jumpSec[$__first['key']] ?? ''); if (in_array($__first['key'], ['rates', 'inventory'], true)) { $__jumpLink = '/nexustraveltech/tedarikci/fiyat-kontenjan?property=' . (int) $item['id']; } elseif ($__first['key'] === 'ical') { $__jumpLink = '/nexustraveltech/tedarikci/ical-takvimler?property=' . (int) $item['id'] . ($__connId > 0 ? '#sync-' . $__connId : '#sync'); } elseif ($__first['key'] === 'channel') { $__jumpLink = '/nexustraveltech/tedarikci/dagitim-merkezi' . ($__badId > 0 ? '#channel-retry-' . $__badId : ''); } elseif ($__first['key'] === 'rules') { $__jumpLink = '/nexustraveltech/tedarikci/satis-kurallari?property=' . (int) $item['id']; } $__jumpLabel = listing_missing_jump_label($__first['key']); ?>
        <div class="readiness-critical-row">
        <button type="button" class="readiness-critical" data-target="readiness-all-<?= (int) $item['id'] ?>" title="Eksik kalemlerin tam listesini göster (açar/kapatır)">✗ <?= count($__miss) ?> eksik · en kritik: <?= htmlspecialchars($__first['label']) ?> →</button>
        <a class="readiness-jump" href="<?= htmlspecialchars($__jumpLink) ?>" title="İlk eksik kalemin bölümüne doğrudan git: <?= htmlspecialchars($__first['label']) ?>"><?= htmlspecialchars($__jumpLabel) ?> →</a>
        </div>
        <?php endif; ?>
        <div class="readiness-bar"><i style="width:<?= (int) $readiness['score'] ?>%"></i></div>
        <?php $missing = array_filter($readiness['items'], fn($i) => !$i['ok']); $warnings = array_filter($readiness['items'], fn($i) => !empty($i['warn'])); $__channelItem = null; $__icalItem = null; foreach ($readiness['items'] as $__ci) { if ($__ci['key'] === 'channel') { $__channelItem = $__ci; } elseif ($__ci['key'] === 'ical') { $__icalItem = $__ci; } } $icalLink = '/nexustraveltech/tedarikci/ical-takvimler?property=' . (int) $item['id'] . ($__icalItem && !empty($__icalItem['first_conn_id']) ? '#sync-' . (int) $__icalItem['first_conn_id'] : '#sync'); $warnLinks = ['ical' => $icalLink]; $editBase = $item['property_type'] === 'hotel' ? '/nexustraveltech/tedarikci/otel-detay?product=' . (int) $item['id'] : '/nexustraveltech/tedarikci/villa-detay?product=' . (int) $item['id']; $secAnchor = ['rooms' => '#sec-04', 'media' => '#sec-05', 'description' => '#sec-02', 'location' => '#sec-01', 'pool' => '#sec-01', 'home_port' => '#sec-01', 'crew' => '#sec-01']; $secTitles = ['rooms' => ($item['property_type'] === 'hotel' ? 'Oda envanteri' : 'Birim & fiyat'), 'media' => 'Görseller', 'description' => 'Satış içeriği', 'location' => 'Kimlik & konum', 'pool' => 'Kimlik & konum', 'home_port' => 'Kimlik & konum', 'crew' => 'Kimlik & konum']; $linkTooltips = readiness_tooltips(); $chLink = '/nexustraveltech/tedarikci/dagitim-merkezi' . ($__channelItem && !empty($__channelItem['first_bad_id']) ? '#channel-retry-' . (int) $__channelItem['first_bad_id'] : ''); $missLinks = ['rooms' => $editBase . $secAnchor['rooms'], 'rates' => '/nexustraveltech/tedarikci/fiyat-kontenjan?property=' . (int) $item['id'], 'inventory' => '/nexustraveltech/tedarikci/fiyat-kontenjan?property=' . (int) $item['id'], 'media' => $editBase . $secAnchor['media'], 'description' => $editBase . $secAnchor['description'], 'location' => $editBase . $secAnchor['location'], 'pool' => $editBase . $secAnchor['pool'], 'home_port' => $editBase . $secAnchor['home_port'], 'crew' => $editBase . $secAnchor['crew'], 'ical' => $icalLink, 'channel' => $chLink, 'rules' => '/nexustraveltech/tedarikci/satis-kurallari?property=' . (int) $item['id']]; ?>
        <?php if ($warnings): ?>
        <ul class="readiness-warn"><?php foreach ($warnings as $w): ?><li><a class="readiness-warn-link" title="<?= htmlspecialchars($linkTooltips[$w['key']] ?? '') ?>" href="<?= htmlspecialchars($warnLinks[$w['key']] ?? '/nexustraveltech/tedarikci/ical-takvimler') ?>">⚠ <?= htmlspecialchars($w['label']) ?> — <?= htmlspecialchars($w['detail']) ?><?php if (array_key_exists('age_days', $w) && $w['age_days'] !== null): ?> <em>(son senkron <?= (int) $w['age_days'] ?> gün önce)</em><?php endif; ?></a></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <?php if (!$missing && !$warnings): ?>
        <p class="readiness-ok">✓ Tüm kalemler tamam — yayına hazır.</p>
        <?php elseif (!$missing): ?>
        <p class="readiness-ok">✓ Çekirdek kalemler tamam — sarı uyarıya bakın.</p>
        <?php endif; ?>
        <?php $autoOpenAll = (bool) platform_setting('readiness_all_auto_open', false) && $readiness['score'] < max(1, (int) platform_setting('readiness_all_auto_open_threshold', 70)); ?>
        <button type="button" class="readiness-toggle" data-target="readiness-all-<?= (int) $item['id'] ?>">Tüm kalemler <?= $autoOpenAll ? '▴' : '▾' ?></button>
        <div id="readiness-all-<?= (int) $item['id'] ?>" class="readiness-all"<?= $autoOpenAll ? '' : ' hidden' ?>>
            <?php foreach ($readiness['items'] as $ri): $copyShort = static fn(string $u): string => basename((string) parse_url($u, PHP_URL_PATH)) . (parse_url($u, PHP_URL_FRAGMENT) !== null ? ' #' . (string) parse_url($u, PHP_URL_FRAGMENT) : ''); $riWarn = !empty($ri['warn']); $riCls = !$ri['ok'] ? 'missing' : ($riWarn ? 'warn' : 'ok'); $riIcon = !$ri['ok'] ? '✗' : ($riWarn ? '⚠' : '✓'); ?>
            <div class="readiness-all-row <?= $riCls ?>"><span class="readiness-all-icon"><?= $riIcon ?></span><div class="readiness-all-text"><b><?= htmlspecialchars($ri['label']) ?><?php if ($ri['key'] === 'rules' && !$ri['ok']): ?> <em>(opsiyonel)</em><?php endif; ?></b><small><?= htmlspecialchars($ri['detail']) ?></small><?php if (!$ri['ok'] && $ri['key'] !== 'rules'): ?> <em class="readiness-gain" title="Bu kalem tamamlanınca hazırlık skoruna eklenir">+<?= (int) ($ri['weight'] ?? 0) ?></em><?php endif; ?></div><?php if (!$ri['ok']): ?><a title="<?= htmlspecialchars($linkTooltips[$ri['key']] ?? '') ?>" href="<?= htmlspecialchars($missLinks[$ri['key']] ?? $editBase) ?>">Doldur →</a> <span class="ical-copy-row"><code title="<?= htmlspecialchars($missLinks[$ri['key']] ?? $editBase) ?>"><?= htmlspecialchars($copyShort((string) ($missLinks[$ri['key']] ?? $editBase))) ?></code><button type="button" class="ical-copy-btn" data-copy="<?= htmlspecialchars($missLinks[$ri['key']] ?? $editBase) ?>">Kopyala</button></span><?php elseif ($riWarn): ?><a title="<?= htmlspecialchars($linkTooltips[$ri['key']] ?? '') ?>" href="<?= htmlspecialchars($warnLinks[$ri['key']] ?? '/nexustraveltech/tedarikci/ical-takvimler') ?>">İncele →</a> <span class="ical-copy-row"><code title="<?= htmlspecialchars($warnLinks[$ri['key']] ?? '/nexustraveltech/tedarikci/ical-takvimler') ?>"><?= htmlspecialchars($copyShort((string) ($warnLinks[$ri['key']] ?? '/nexustraveltech/tedarikci/ical-takvimler'))) ?></code><button type="button" class="ical-copy-btn" data-copy="<?= htmlspecialchars($warnLinks[$ri['key']] ?? '/nexustraveltech/tedarikci/ical-takvimler') ?>">Kopyala</button></span><?php else: ?><a class="readiness-all-view" title="<?= htmlspecialchars($linkTooltips[$ri['key']] ?? '') ?>" href="<?= htmlspecialchars($missLinks[$ri['key']] ?? $editBase) ?>">Gör →</a> <span class="ical-copy-row"><code title="<?= htmlspecialchars($missLinks[$ri['key']] ?? $editBase) ?>"><?= htmlspecialchars($copyShort((string) ($missLinks[$ri['key']] ?? $editBase))) ?></code><button type="button" class="ical-copy-btn" data-copy="<?= htmlspecialchars($missLinks[$ri['key']] ?? $editBase) ?>">Kopyala</button></span><?php if (!$ri['ok'] && isset($secTitles[$ri['key']], $secAnchor[$ri['key']])): ?> <em class="readiness-sec-hint"><?= htmlspecialchars($secTitles[$ri['key']] . ' ' . $secAnchor[$ri['key']]) ?></em><?php endif; ?><?php endif; ?><?php if ($ri['key'] === 'media' && $ri['ok']): $mp = $pdo->prepare("SELECT file_path, is_cover FROM property_media WHERE property_id=? ORDER BY is_cover DESC, sort_order, id LIMIT 8"); $mp->execute([(int) $item['id']]); $mediaPrev = $mp->fetchAll(); if ($mediaPrev): ?><span class="readiness-media-preview" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;align-items:center"><?php foreach ($mediaPrev as $mediaIdx => $mv): $src = '/nexustraveltech/' . ltrim((string) $mv['file_path'], '/'); ?><span style="position:relative;display:inline-block"><?php if ($mv['is_cover']): ?><span style="position:absolute;left:0;bottom:0;background:#b26a00;color:#fff;font-size:8px;font-weight:700;padding:1px 4px;border-radius:0 4px 0 4px;letter-spacing:.04em;z-index:1">KAPAK</span><?php endif; ?><img src="<?= htmlspecialchars($src) ?>" alt="" loading="lazy" style="width:56px;height:56px;object-fit:cover;border-radius:6px;border:1px solid #e1e5de" title="<?= ($mediaIdx+1)?>/<?= count($mediaPrev) ?><?= $mv['is_cover'] ? ' · Kapak' : '' ?>"<?= $mv['is_cover'] ? ' style="width:56px;height:56px;object-fit:cover;border-radius:6px;border:2px solid #b26a00"' : '' ?>></span><?php endforeach; ?></span><a class="readiness-media-more" href="<?= htmlspecialchars($missLinks['media'] ?? $editBase) ?>" style="font-size:11px;color:#4a6d8c;text-decoration:none;border-bottom:1px dotted #4a6d8c">Tümü →</a></span><?php else: ?><span class="readiness-media-empty" style="display:block;margin-top:6px;font-size:12px;color:#8a6100">Görsel yok — ilan görsellerini ekleyin</span><?php endif; endif; ?></div>
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
        <?php $icalCardVisible = $icalUrls && (!(bool) platform_setting('ical_url_published_only', false) || $item['status'] === 'active'); if ($icalCardVisible): ?><button type="button" class="ghost-button ical-card-toggle" data-target="ical-card-<?= (int) $item['id'] ?>">iCal URL göster</button><?php endif; ?>
    </div>
    <?php if ($icalCardVisible): ?>
    <div id="ical-card-<?= (int) $item['id'] ?>" class="ical-card-box" hidden>
        <?php foreach ($icalUrls as $icalUrl): $icalQ = (string) parse_url($icalUrl, PHP_URL_QUERY); $icalShort = 'api/ical?' . mb_substr($icalQ, 0, 10) . '…' . mb_substr($icalQ, 30, 4) . '…'; ?>
        <span class="ical-copy-row"><code title="<?= htmlspecialchars($icalUrl) ?>"><?= htmlspecialchars($icalShort) ?></code><button type="button" class="ical-copy-btn" data-copy="<?= htmlspecialchars($icalUrl) ?>">Kopyala</button></span>
        <?php endforeach; ?>
        <button type="button" class="ical-help-toggle" data-target="ical-help-<?= (int) $item['id'] ?>">Dışa aktarma kanallarına ekle — adım adım ▾</button>
        <div id="ical-help-<?= (int) $item['id'] ?>" class="ical-help-box" hidden>
            <ol>
                <li>Yukarıdaki <b>Kopyala</b> butonuyla bu ilanın iCal URL'sini panoya alın.</li>
                <li><b>Airbnb</b> → Takvimler (Calendar) bölümünde "iCal içe aktar" alanına yapıştırın; müsaitlik tek yönlü bloklanır.</li>
                <li><b>Vrbo</b> → İlanınızın "Calendar & Pricing" sekmesindeki "Import calendar" kutusuna yapıştırın.</li>
                <li><b>Booking.com</b> → Yönetim paneli → Takvim senkronizasyonu (iCal) → "Dış takvim URL'si" alanına yapıştırın.</li>
                <li>Kanalın onayını bekleyin; NEXUS'ta bu kanaldan bloklar geldiğinde hazırlık kontrolündeki "Müsaitlik verisi" kalemi dolar.</li>
            </ol>
            <p class="conn-hint">İçe aktarma dışarıdan NEXUS'a geldiği için tek yönlüdür; NEXUS takviminizi bu URL üzerinden dışa verirsiniz.</p>
        </div>
    </div>
    <?php endif; ?>
</article>
<?php endforeach; ?>
</section>
<section class="next-module"><span>SONRAKİ MODÜL</span><h2>Fiyat, kontenjan ve satış kuralları</h2><p>Takvim bazlı fiyat, kapasite, stop-sale, minimum konaklama ve kanal dağıtımı bu yapının üzerine eklenecek.</p></section>
<script>document.querySelectorAll('.ical-card-toggle').forEach(function(b){b.addEventListener('click',function(){var box=document.getElementById(this.getAttribute('data-target'));if(box){box.hidden=!box.hidden;this.textContent=box.hidden?'iCal URL göster':'iCal URL gizle'}})});document.querySelectorAll('.ical-help-toggle').forEach(function(b){b.addEventListener('click',function(){var box=document.getElementById(this.getAttribute('data-target'));if(box){box.hidden=!box.hidden;this.textContent=box.hidden?'Dışa aktarma kanallarına ekle — adım adım ▾':'Dışa aktarma kanallarına ekle — adım adım ▴'}})});document.querySelectorAll('.readiness-toggle').forEach(function(b){b.addEventListener('click',function(){var box=document.getElementById(this.getAttribute('data-target'));if(box){box.hidden=!box.hidden;this.textContent=box.hidden?'Tüm kalemler ▾':'Tüm kalemler ▴';var cr=document.querySelector('.readiness-critical[data-target="'+this.getAttribute('data-target')+'"]');if(cr){cr.classList.toggle('open',!box.hidden);}}});});document.querySelectorAll('.readiness-critical').forEach(function(b){b.addEventListener('click',function(){var box=document.getElementById(this.getAttribute('data-target'));if(!box)return;box.hidden=!box.hidden;var tog=document.querySelector('.readiness-toggle[data-target="'+this.getAttribute('data-target')+'"]');if(tog){tog.textContent=box.hidden?'Tüm kalemler ▾':'Tüm kalemler ▴';}this.classList.toggle('open',!box.hidden);if(!box.hidden){setTimeout(function(){box.scrollIntoView({behavior:'smooth',block:'nearest'})},80);}});});;function icalFallbackCopy(text){var ta=document.createElement('textarea');ta.value=text;ta.style.position='fixed';ta.style.opacity='0';document.body.appendChild(ta);ta.select();try{document.execCommand('copy')}catch(e){}document.body.removeChild(ta)}document.querySelectorAll('.ical-copy-btn').forEach(function(b){if(!b.title)b.title=b.getAttribute('data-copy')||'';b.addEventListener('click',function(){var url=this.getAttribute('data-copy'),btn=this;function done(){var mob=window.innerWidth<601;btn.textContent=mob?'✓':'Kopyalandı!';btn.classList.add('copied');setTimeout(function(){btn.textContent=mob?(btn.dataset.icon||'⧉'):(btn.dataset.full||'Kopyala');btn.classList.remove('copied')},2000)}if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(url).then(done).catch(function(){icalFallbackCopy(url);done()})}else{icalFallbackCopy(url);done()}})});</script><?php supply_end(); ?>
