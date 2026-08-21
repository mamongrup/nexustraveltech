<?php
$footerLang = strtolower((string)($_GET['lang'] ?? $_COOKIE['nexus-language'] ?? 'tr'));
if (!in_array($footerLang, ['tr', 'en', 'de', 'ru', 'ar', 'fr'], true)) $footerLang = 'tr';

$footerLabels = [
    'tr' => ['copy' => 'Turizmin canlı bilgi ağı.', 'contact' => 'İletişim', 'privacy' => 'Gizlilik', 'cookies' => 'Çerezler'],
    'en' => ['copy' => 'The live information network for travel.', 'contact' => 'Contact', 'privacy' => 'Privacy', 'cookies' => 'Cookies'],
    'de' => ['copy' => 'Das Live-Informationsnetz für Reisen.', 'contact' => 'Kontakt', 'privacy' => 'Datenschutz', 'cookies' => 'Cookies'],
    'ru' => ['copy' => 'Живая информационная сеть туризма.', 'contact' => 'Контакты', 'privacy' => 'Конфиденциальность', 'cookies' => 'Куки'],
    'ar' => ['copy' => 'شبكة معلومات مباشرة للسفر.', 'contact' => 'اتصل بنا', 'privacy' => 'الخصوصية', 'cookies' => 'ملفات تعريف الارتباط'],
    'fr' => ['copy' => 'Le réseau d\'information live du voyage.', 'contact' => 'Contact', 'privacy' => 'Confidentialité', 'cookies' => 'Cookies'],
];
$fl = $footerLabels[$footerLang] ?? $footerLabels['tr'];
?>
<footer class="footer">
  <div class="shell">
    <a class="brand" href="/nexustraveltech/">N<span>&#8767;</span>XUS</a>
    <p data-i18n="footer_copy"><?= htmlspecialchars($fl['copy']) ?></p>
    <div class="footer-links">
      <a href="/nexustraveltech/iletisim" data-i18n="footer_contact"><?= htmlspecialchars($fl['contact']) ?></a>
      <a href="/nexustraveltech/gizlilik" data-i18n="footer_privacy"><?= htmlspecialchars($fl['privacy']) ?></a>
      <a href="/nexustraveltech/cerezler" data-i18n="footer_cookies"><?= htmlspecialchars($fl['cookies']) ?></a>
    </div>
    <span>nexustraveltech.com &copy; 2026</span>
  </div>
</footer>
<script defer src="/nexustraveltech/assets/i18n.js?v=<?= filemtime(__DIR__ . '/../assets/i18n.js') ?>"></script>
<?php require __DIR__ . '/../config/ai_public_widget.php'; ai_public_widget(); ?>

