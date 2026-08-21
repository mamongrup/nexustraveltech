<?php 
$active_page = $active_page ?? ''; 
$lang = strtolower((string)($_GET['lang'] ?? $_COOKIE['nexus-language'] ?? 'tr'));
if (!in_array($lang, ['tr', 'en', 'de', 'ru', 'ar', 'fr'], true)) $lang = 'tr';

$navLabels = [
    'tr' => ['platform' => 'Platform', 'solutions' => 'Çözümler', 'company' => 'Şirket', 'access' => 'Erken erişim'],
    'en' => ['platform' => 'Platform', 'solutions' => 'Solutions', 'company' => 'Company', 'access' => 'Early Access'],
    'de' => ['platform' => 'Plattform', 'solutions' => 'Lösungen', 'company' => 'Unternehmen', 'access' => 'Frühzugang'],
    'ru' => ['platform' => 'Платформа', 'solutions' => 'Решения', 'company' => 'Компания', 'access' => 'Ранний доступ'],
    'ar' => ['platform' => 'المنصة', 'solutions' => 'الحلول', 'company' => 'الشركة', 'access' => 'الوصول المبكر'],
    'fr' => ['platform' => 'Plateforme', 'solutions' => 'Solutions', 'company' => 'Entreprise', 'access' => 'Accès anticipé'],
];
$nl = $navLabels[$lang] ?? $navLabels['tr'];
?>
<nav class="nav shell" aria-label="Nexus navigation">
  <div class="brand-cluster"><a class="brand" href="/nexustraveltech/" aria-label="Nexus ana sayfa">N<span>&#8767;</span>XUS</a><small>nexustraveltech.com</small></div>
  <div class="nav-links">
    <a class="<?= $active_page === 'platform' ? 'active' : '' ?>" href="/nexustraveltech/platform" data-i18n="nav_platform"><?= htmlspecialchars($nl['platform']) ?></a>
    <a class="<?= $active_page === 'solutions' ? 'active' : '' ?>" href="/nexustraveltech/cozumler" data-i18n="nav_solutions"><?= htmlspecialchars($nl['solutions']) ?></a>
    <a class="<?= $active_page === 'company' ? 'active' : '' ?>" href="/nexustraveltech/sirket" data-i18n="nav_company"><?= htmlspecialchars($nl['company']) ?></a>
    <a href="/nexustraveltech/#erken-erisim" class="nav-cta"><span data-i18n="nav_access"><?= htmlspecialchars($nl['access']) ?></span> <span>&#8599;</span></a>
    <div class="locale-controls" aria-label="Dil ve para birimi seçimi">
      <select id="site-language" aria-label="Dil">
        <option value="tr" <?= $lang === 'tr' ? 'selected' : '' ?>>TR</option>
        <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>>EN</option>
        <option value="de" <?= $lang === 'de' ? 'selected' : '' ?>>DE</option>
        <option value="ru" <?= $lang === 'ru' ? 'selected' : '' ?>>RU</option>
        <option value="ar" <?= $lang === 'ar' ? 'selected' : '' ?>>AR</option>
        <option value="fr" <?= $lang === 'fr' ? 'selected' : '' ?>>FR</option>
      </select>
      <select id="site-currency" aria-label="Para birimi"><option value="TRY">TRY</option><option value="EUR">EUR</option><option value="USD">USD</option><option value="GBP">GBP</option><option value="RUB">RUB</option><option value="AED">AED</option></select>
    </div>
  </div>
</nav>
