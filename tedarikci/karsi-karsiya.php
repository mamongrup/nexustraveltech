<?php
declare(strict_types=1);

$active_module = 'properties';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/../config/i18n.php';
require_once __DIR__ . '/../config/listing_integrity.php';

$u = $supplier_user;
$pdo = db();

$pid = (int) ($_GET['product'] ?? 0);

// Tek seferlik ekran: yalnızca tesis-ekle.php'nin oluşturduğu bayrakla açılır,
// bir kez gösterilip tüketilir; yeniden ziyaret edilirse tesisler listesine döner.
$once = isset($_SESSION['karsi_karsiya']) && (int) $_SESSION['karsi_karsiya'] === $pid;
if ($once) {
    unset($_SESSION['karsi_karsiya']);
}

$q = $pdo->prepare('SELECT * FROM properties WHERE id=? AND supplier_id=?');
$q->execute([$pid, $u['supplier_id']]);
$prop = $q->fetch();
if (!$prop) {
    header('Location: /nexustraveltech/tedarikci/tesisler');
    exit;
}
if (!$once) {
    header('Location: /nexustraveltech/tedarikci/tesisler');
    exit;
}

$rd = listing_readiness($prop);
$items = $rd['items'];
$done = array_values(array_filter($items, fn($i) => !empty($i['ok']) && empty($i['warn'])));
$warn = array_values(array_filter($items, fn($i) => !empty($i['ok']) && !empty($i['warn'])));
$miss = array_values(array_filter($items, fn($i) => empty($i['ok'])));

$type = (string) ($prop['property_type'] ?? 'hotel');
$editBase = $type === 'hotel'
    ? '/nexustraveltech/tedarikci/otel-detay?product=' . $pid
    : '/nexustraveltech/tedarikci/villa-detay?product=' . $pid;

// Bağlantı haritası — tesisler kartıyla aynı desen (bölüm çapaları + hedef sayfalar).
$__icalItem = null;
$__channelItem = null;
foreach ($items as $__ci) {
    if ($__ci['key'] === 'ical') { $__icalItem = $__ci; }
    elseif ($__ci['key'] === 'channel') { $__channelItem = $__ci; }
}
$icalLink = '/nexustraveltech/tedarikci/ical-takvimler?property=' . $pid
    . ($__icalItem && !empty($__icalItem['first_conn_id']) ? '#sync-' . (int) $__icalItem['first_conn_id'] : '#sync');
$chLink = '/nexustraveltech/tedarikci/dagitim-merkezi'
    . ($__channelItem && !empty($__channelItem['first_bad_id']) ? '#channel-retry-' . (int) $__channelItem['first_bad_id'] : '');
$kkLinks = [
    'rooms' => $editBase . '#sec-04',
    'rates' => '/nexustraveltech/tedarikci/fiyat-kontenjan?property=' . $pid,
    'inventory' => '/nexustraveltech/tedarikci/fiyat-kontenjan?property=' . $pid,
    'media' => $editBase . '#sec-05',
    'description' => $editBase . '#sec-02',
    'location' => $editBase . '#sec-01',
    'pool' => $editBase . '#sec-01',
    'home_port' => $editBase . '#sec-01',
    'crew' => $editBase . '#sec-01',
    'ical' => $icalLink,
    'channel' => $chLink,
    'rules' => '/nexustraveltech/tedarikci/satis-kurallari?property=' . $pid,
];
$kkSecTitles = [
    'rooms' => ($type === 'hotel' ? 'Oda envanteri' : 'Birim & fiyat') . ' #sec-04',
    'media' => 'Görseller #sec-05',
    'description' => 'Satış içeriği #sec-02',
    'location' => 'Kimlik & konum #sec-01',
    'pool' => 'Kimlik & konum #sec-01',
    'home_port' => 'Kimlik & konum #sec-01',
    'crew' => 'Kimlik & konum #sec-01',
    'rates' => 'Fiyat & kontenjan',
    'inventory' => 'Fiyat & kontenjan',
    'ical' => 'iCal takvimler',
    'channel' => 'Dağıtım merkezi',
    'rules' => 'Satış kuralları',
];
$kkSectionLabels = [
    'sec-01' => 'Kimlik & konum',
    'sec-02' => 'Satış içeriği',
    'sec-03' => $type === 'hotel' ? 'Olanaklar' : 'Özellikler & hizmetler',
    'sec-04' => $type === 'hotel' ? 'Oda envanteri' : 'Birim & fiyat',
    'sec-05' => 'Görseller',
    'sec-06' => 'Komisyon & tahsilat',
    'sec-07' => 'İptal & iade',
];
$linkTooltips = readiness_tooltips();

// "İlk eksik bölüme git" — eski yönlendirme sırasıyla aynı öncelik:
// location → description → media → rooms/inventory → rates; villa/yat'ta pool/liman/mürettebat + iCal.
$secOrder = ['location', 'description', 'media', 'rooms', 'inventory', 'rates'];
if ($type !== 'hotel') {
    $secOrder = array_merge($secOrder, ['pool', 'home_port', 'crew', 'ical']);
}
$firstMiss = null;
foreach ($secOrder as $__k) {
    foreach ($miss as $__m) {
        if ($__m['key'] === $__k) { $firstMiss = $__m; break 2; }
    }
}
// Yalnızca opsiyonel (rules) kalem eksikse CTA gösterilmez — çekirdek tamamdır.
if ($firstMiss === null) {
    foreach ($miss as $__m) {
        if ($__m['key'] !== 'rules') { $firstMiss = $__m; break; }
    }
}
// tesis-ekle'den gelen geçiş hedefi: ?first=sec-XX (ilk düzenleyici adımının bölümü) — CTA'yı ezer.
$firstOverride = (string) ($_GET['first'] ?? '');
if ($firstOverride !== '' && isset($kkSectionLabels[$firstOverride])) {
    $firstMiss = ['key' => 'override', 'label' => ($kkSectionLabels[$firstOverride] ?? $firstOverride), 'ok' => false];
    $firstLink = $editBase . '#' . $firstOverride;
} else {
    $firstLink = $firstMiss !== null ? ($kkLinks[$firstMiss['key']] ?? $editBase) : $editBase;
}

supply_start('Karşı karşıya — hazırlık özeti', $active_module);
?>
<section class="kk-wrap">
  <div class="kk-hero">
    <p class="crumb">KARŞI KARŞIYA / YAYIN HAZIRLIĞI</p>
    <h2><?= htmlspecialchars((string) $prop['name']) ?></h2>
    <p class="kk-sub">Bu ekran yalnızca ilan oluşturulduktan sonra <b>bir kez</b> gösterilir: hangi kalemler tamam, hangileri eksik — ve ilk eksik bölüme tek tıkla geçiş.</p>
    <div class="kk-score">
      <div class="readiness-bar kk-bar"><i style="width:<?= (int) $rd['score'] ?>%"></i></div>
      <b><?= (int) $rd['score'] ?>/100</b>
      <?php if ($rd['missing_count'] > 0): ?><small class="kk-scorecard"><?= (int) $rd['ok_count'] ?> kalem tamam — kalan <?= (int) $rd['missing_count'] ?> kalem tamamlanınca 100 olur.</small><?php endif; ?>
    </div>
    <?php if ($firstMiss !== null): ?>
      <div class="kk-actions">
        <a class="primary-button kk-cta" href="<?= htmlspecialchars($firstLink) ?>">İlk eksik bölüme git → <small><?= htmlspecialchars($firstMiss['label']) ?><?= isset($kkSecTitles[$firstMiss['key']]) ? ' · ' . htmlspecialchars($kkSecTitles[$firstMiss['key']]) : '' ?></small></a>
        <a class="ghost-button" href="/nexustraveltech/tedarikci/tesisler">Tesislerime dön</a>
      </div>
    <?php else: ?>
      <div class="kk-actions">
        <span class="kk-ready">✓ Tüm çekirdek kalemler tamam — yayına hazırsınız.</span>
        <a class="primary-button" href="/nexustraveltech/tedarikci/tesisler">Tesislerime dön →</a>
      </div>
    <?php endif; ?>
  </div>

  <div class="kk-cols">
    <?php if ($miss !== []): ?>
    <div class="kk-col kk-col-miss">
      <h3>✗ Eksik kalemler <b><?= count($miss) ?></b></h3>
      <ul>
        <?php foreach ($miss as $m): ?>
        <li>
          <div class="kk-row-text">
            <b><?= htmlspecialchars($m['label']) ?><?php if ($m['key'] === 'rules'): ?> <em>(opsiyonel)</em><?php endif; ?></b>
            <small><?= htmlspecialchars($m['detail']) ?></small>
            <?php if ($m['key'] !== 'rules'): ?> <em class="readiness-gain" title="Bu kalem tamamlanınca hazırlık skoruna eklenir">+<?= (int) ($m['weight'] ?? 0) ?></em><?php endif; ?>
            <?php if (isset($kkSecTitles[$m['key']])): ?> <em class="readiness-sec-hint"><?= htmlspecialchars($kkSecTitles[$m['key']]) ?></em><?php endif; ?>
          </div>
          <a title="<?= htmlspecialchars($linkTooltips[$m['key']] ?? '') ?>" href="<?= htmlspecialchars($kkLinks[$m['key']] ?? $editBase) ?>">Doldur →</a>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <?php if ($warn !== []): ?>
    <div class="kk-col kk-col-warn">
      <h3>⚠ Sarı uyarılar <b><?= count($warn) ?></b></h3>
      <ul>
        <?php foreach ($warn as $w): ?>
        <li>
          <div class="kk-row-text">
            <b><?= htmlspecialchars($w['label']) ?></b>
            <small><?= htmlspecialchars($w['detail']) ?></small>
            <?php if (isset($kkSecTitles[$w['key']])): ?> <em class="readiness-sec-hint"><?= htmlspecialchars($kkSecTitles[$w['key']]) ?></em><?php endif; ?>
          </div>
          <a title="<?= htmlspecialchars($linkTooltips[$w['key']] ?? '') ?>" href="<?= htmlspecialchars($kkLinks[$w['key']] ?? $editBase) ?>">İncele →</a>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <div class="kk-col kk-col-done">
      <h3>✓ Tamamlanan kalemler <b><?= count($done) ?></b></h3>
      <ul>
        <?php foreach ($done as $d): ?>
        <li>
          <div class="kk-row-text">
            <b><?= htmlspecialchars($d['label']) ?></b>
            <small><?= htmlspecialchars($d['detail']) ?></small>
          </div>
          <a class="kk-view" title="<?= htmlspecialchars($linkTooltips[$d['key']] ?? '') ?>" href="<?= htmlspecialchars($kkLinks[$d['key']] ?? $editBase) ?>">Gör →</a>
        </li>
        <?php endforeach; ?>
        <?php if ($done === []): ?><li class="kk-empty">Henüz tamamlanan kalem yok.</li><?php endif; ?>
      </ul>
    </div>
  </div>
</section>
<?php supply_end(); ?>
