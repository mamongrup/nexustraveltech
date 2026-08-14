<?php
/* Central SEO metadata: use $seo_page before including this partial. */
$seo_pages = [
  'home' => ['path' => '', 'title' => 'NEXUS TravelTech | Turizmin Canlı Bilgi Ağı', 'description' => 'NEXUS TravelTech; otel, villa, yat, araç ve turizm tedarikçilerinin canlı ürün, fiyat ve müsaitlik bilgisini acentelere anında ulaştıran B2B platformdur.', 'keywords' => 'traveltech, turizm teknolojileri, acente yazılımı, otel rezervasyon sistemi, tedarikçi yönetimi, XML API'],
  'platform' => ['path' => 'platform', 'title' => 'Turizm Bilgi Havuzu Platformu | NEXUS TravelTech', 'description' => 'NEXUS bilgi havuzu; turizm tedarikçilerinin güncel veri, fiyat ve kontenjanını tek altyapıda standartlaştırır, acentelere anlık ulaştırır.', 'keywords' => 'turizm bilgi havuzu, canlı envanter, turizm API, tedarikçi entegrasyonu, turizm dağıtım platformu'],
  'solutions' => ['path' => 'cozumler', 'title' => 'Tedarikçi, Acente ve API Çözümleri | NEXUS TravelTech', 'description' => 'NEXUS Supply, NEXUS Agency ve NEXUS Connect ile tedarikçi operasyonu, acente satışı ve turizm API/XML entegrasyonlarını tek ekosistemde yönetin.', 'keywords' => 'tedarikçi programı, acente programı, turizm XML, turizm API, otel programı, rezervasyon yazılımı'],
  'company' => ['path' => 'sirket', 'title' => 'Hakkımızda | NEXUS TravelTech', 'description' => 'NEXUS TravelTech, 30 yıllık otel, villa, yat, araç kiralama ve restoran işletmeciliği deneyimini turizm yazılımına taşıyan teknoloji şirketidir.', 'keywords' => 'turizm yazılım şirketi, traveltech türkiye, turizm teknolojisi, rezervasyon teknolojisi'],
  'contact' => ['path' => 'iletisim', 'title' => 'İletişim ve Partnerlik | NEXUS TravelTech', 'description' => 'NEXUS TravelTech pilot programı, tedarikçi çözümleri, acente yazılımı ve API/XML partnerlik seçenekleri için bizimle iletişime geçin.', 'keywords' => 'turizm teknoloji partnerliği, acente yazılımı demo, tedarikçi pilot programı'],
  'suppliers' => ['path' => 'tedarikciler', 'title' => 'Turizm Tedarikçi Yazılımı | NEXUS Supply', 'description' => 'Otel, villa, yat, araç kiralama, restoran ve tur operatörleri için ürün, fiyat, kontenjan ve dağıtım yönetim yazılımı.', 'keywords' => 'otel programı, villa kiralama yazılımı, yat kiralama yazılımı, turizm tedarikçi programı, kontenjan yönetimi'],
  'agencies' => ['path' => 'acenteler', 'title' => 'Seyahat Acentesi Yazılımı | NEXUS Agency', 'description' => 'Seyahat acenteleri için canlı envanter, hızlı teklif, rezervasyon ve operasyon yönetimini bir araya getiren B2B yazılım.', 'keywords' => 'seyahat acentesi yazılımı, acente programı, rezervasyon yönetimi, turizm satış yazılımı'],
  'connect' => ['path' => 'api-xml', 'title' => 'Turizm API ve XML Entegrasyonu | NEXUS Connect', 'description' => 'Kendi yazılımını kullanan acenteler için canlı turizm envanterine API ve XML ile erişim sağlayan bağlantı platformu.', 'keywords' => 'turizm API, turizm XML, otel XML entegrasyonu, acente API bağlantısı'],
  'travelers' => ['path' => 'gezginler', 'title' => 'Gezginler İçin Daha Hızlı Rezervasyon | NEXUS TravelTech', 'description' => 'NEXUS altyapısı sayesinde gezginler güncel fiyat, müsaitlik ve koşullarla daha hızlı ve güvenilir seyahat seçeneklerine ulaşır.', 'keywords' => 'hızlı rezervasyon, güncel otel fiyatları, canlı müsaitlik, güvenilir seyahat rezervasyonu'],
  'privacy' => ['path' => 'gizlilik', 'title' => 'Gizlilik Bildirimi | NEXUS TravelTech', 'description' => 'NEXUS TravelTech gizlilik bildirimi ve kişisel veri işleme yaklaşımı.', 'keywords' => 'nexus traveltech gizlilik'],
  'cookies' => ['path' => 'cerezler', 'title' => 'Çerez Bildirimi | NEXUS TravelTech', 'description' => 'NEXUS TravelTech çerez bildirimi ve tercih yönetimi.', 'keywords' => 'nexus traveltech çerezler'],
];
$seo = $seo_pages[$seo_page ?? 'home'];
$canonical = 'https://nexustraveltech.com' . ($seo['path'] ? '/' . $seo['path'] : '/');
?>
<meta name="description" content="<?= htmlspecialchars($seo['description'], ENT_QUOTES, 'UTF-8') ?>" />
<meta name="keywords" content="<?= htmlspecialchars($seo['keywords'], ENT_QUOTES, 'UTF-8') ?>" />
<meta name="robots" content="index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1" />
<link rel="canonical" href="<?= htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8') ?>" />
<meta property="og:locale" content="tr_TR" />
<meta property="og:type" content="website" />
<meta property="og:site_name" content="NEXUS TravelTech" />
<meta property="og:title" content="<?= htmlspecialchars($seo['title'], ENT_QUOTES, 'UTF-8') ?>" />
<meta property="og:description" content="<?= htmlspecialchars($seo['description'], ENT_QUOTES, 'UTF-8') ?>" />
<meta property="og:url" content="<?= htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8') ?>" />
<meta property="og:image" content="https://nexustraveltech.com/assets/nexus-og.png" />
<meta property="og:image:width" content="1730" />
<meta property="og:image:height" content="909" />
<meta property="og:image:alt" content="NEXUS TravelTech — Turizmin canlı bilgi ağı" />
<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:title" content="<?= htmlspecialchars($seo['title'], ENT_QUOTES, 'UTF-8') ?>" />
<meta name="twitter:description" content="<?= htmlspecialchars($seo['description'], ENT_QUOTES, 'UTF-8') ?>" />
<meta name="twitter:image" content="https://nexustraveltech.com/assets/nexus-og.png" />
<?php if (($seo_page ?? 'home') === 'home'): ?>
<script type="application/ld+json">{"@context":"https://schema.org","@type":"Organization","name":"NEXUS TravelTech","url":"https://nexustraveltech.com/","logo":"https://nexustraveltech.com/assets/nexus-og.png","description":"Turizmin canlı bilgi ağı.","sameAs":[]}</script>
<script type="application/ld+json">{"@context":"https://schema.org","@type":"WebSite","name":"NEXUS TravelTech","url":"https://nexustraveltech.com/","inLanguage":"tr-TR"}</script>
<?php endif; ?>
