<?php
$active_page = 'solutions';
$seo_page = 'solutions';
require_once __DIR__ . '/config/i18n.php';
$current_lang = readiness_lang();
?>
<!doctype html>
<html lang="<?= htmlspecialchars($current_lang) ?>" dir="<?= $current_lang === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Tedarikçi, Acente ve API Çözümleri | NEXUS TravelTech</title>
  <?php require __DIR__ . '/partials/seo.php'; ?>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&family=Playfair+Display:ital,wght@0,600;0,700;1,600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="/nexustraveltech/assets/styles.css?v=<?= filemtime(__DIR__ . '/assets/styles.css') ?>" />
</head>
<body class="inner-page">
  <main>
    <?php require __DIR__ . '/partials/header.php'; ?>
    <section class="inner-hero shell">
      <div class="eyebrow"><i></i> ÜRÜN EKOSİSTEMİ</div>
      <h1>Her rol için<br /><em>aynı akış.</em></h1>
      <p>Turizm operasyonunun her tarafı kendi işini daha hızlı yapar; bilgi, satışa giden yolda kaybolmaz.</p>
    </section>
    <section class="solution-grid shell">
      <article>
        <span>TEDARİKÇİLER İÇİN</span>
        <h2>NEXUS Supply</h2>
        <p>Yalın, hızlı ve tam entegre tedarikçi operasyon paneli.</p>
        <ul>
          <li>Ön büro, ürün, fiyat ve kontenjan yönetimi</li>
          <li>KBS bildirim, kat hizmetleri ve folyo takibi</li>
          <li>Mobil ve masaüstünde tek deneyim</li>
          <li>Dağıtım kanalı ve doğrudan satış gücü</li>
        </ul>
        <a href="/nexustraveltech/tedarikciler">Tedarikçi çözümünü keşfet →</a>
      </article>
      <article class="dark">
        <span>ACENTELER İÇİN</span>
        <h2>NEXUS Agency</h2>
        <p>Canlı envanteri hızla teklife, rezervasyona ve müşteri deneyimine dönüştüren acente yazılımı.</p>
        <ul>
          <li>Tek ekranda anlık karşılaştırılabilir canlı ürünler</li>
          <li>Hızlı teklif, B2B rezervasyon ve onay akışı</li>
          <li>Yıllık paketlerle ölçeklenebilir kullanım</li>
          <li>Operasyon ve satış için ortak çalışma alanı</li>
        </ul>
        <a href="/nexustraveltech/acenteler">Acente çözümünü keşfet →</a>
      </article>
      <article class="lime">
        <span>ENTEGRASYON İÇİN</span>
        <h2>NEXUS Connect</h2>
        <p>Kendi yazılımını kullanan acenteler ve çözüm ortakları için açık bağlantı katmanı.</p>
        <ul>
          <li>API / XML aboneliği & 2-way senkronizasyon</li>
          <li>Yurtiçi ve yurtdışı satış kanalları</li>
          <li>Standartlaştırılmış canlı envanter verisi</li>
          <li>Esnek ve ölçeklenebilir entegrasyon modeli</li>
        </ul>
        <a href="/nexustraveltech/api-xml">Bağlantı modelini incele →</a>
      </article>
    </section>
    <section class="solutions-traveler shell">
      <p class="mono">SON KULLANICI DENEYİMİ</p>
      <h2>Bu akış gezgine nasıl yansır?</h2>
      <p>Tedarikçi ve acente arasındaki bilgi ne kadar hızlı akarsa; gezgin de o kadar güncel seçenek, net koşul ve hızlı geri dönüş alır.</p>
      <a href="/nexustraveltech/gezginler">Gezgin faydalarını keşfet →</a>
    </section>
    <section class="revenue-note">
      <div class="shell">
        <p class="mono">BÜYÜME MODELİ</p>
        <h2>Şeffaf, erişilebilir ve<br />uzun vadeli bir iş ortaklığı.</h2>
        <p>NEXUS; tedarikçi operasyonu, acente yazılımı, bağlantı abonelikleri ve güvenli ödeme altyapısı ile turizmin tüm taraflarına değer üretmek için tasarlanır.</p>
      </div>
    </section>
  </main>
  <?php require __DIR__ . '/partials/footer.php'; ?>
</body>
</html>
