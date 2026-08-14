<?php
header('Content-Type: application/xml; charset=UTF-8');
$pages = ['', 'platform', 'cozumler', 'tedarikciler', 'acenteler', 'api-xml', 'gezginler', 'sirket', 'iletisim', 'gizlilik', 'cerezler'];
?><<?php echo '?xml version="1.0" encoding="UTF-8"?'; ?>>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($pages as $path): ?>
  <url><loc>https://nexustraveltech.com/<?= htmlspecialchars($path, ENT_XML1, 'UTF-8') ?></loc><changefreq><?= $path === '' ? 'weekly' : 'monthly' ?></changefreq><priority><?= $path === '' ? '1.0' : '0.8' ?></priority></url>
<?php endforeach; ?>
</urlset>
