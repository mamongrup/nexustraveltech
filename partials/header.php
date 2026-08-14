<?php $active_page = $active_page ?? ''; ?>
<nav class="nav shell" aria-label="Nexus navigation">
  <div class="brand-cluster"><a class="brand" href="/nexustraveltech/" aria-label="Nexus ana sayfa">N<span>&#8767;</span>XUS</a><small>nexustraveltech.com</small></div>
  <div class="nav-links">
    <a class="<?= $active_page === 'platform' ? 'active' : '' ?>" href="/nexustraveltech/platform">Platform</a>
    <a class="<?= $active_page === 'solutions' ? 'active' : '' ?>" href="/nexustraveltech/cozumler">Çözümler</a>
    <a class="<?= $active_page === 'company' ? 'active' : '' ?>" href="/nexustraveltech/sirket">Şirket</a>
    <a href="/nexustraveltech/#erken-erisim" class="nav-cta"><span data-i18n="nav_access">Erken erişim</span> <span>&#8599;</span></a>
    <div class="locale-controls" aria-label="Dil ve para birimi seçimi">
      <select id="site-language" aria-label="Dil"><option value="tr">TR</option><option value="en">EN</option><option value="de">DE</option><option value="ru">RU</option><option value="ar">AR</option><option value="fr">FR</option></select>
      <select id="site-currency" aria-label="Para birimi"><option value="TRY">TRY</option><option value="EUR">EUR</option><option value="USD">USD</option><option value="GBP">GBP</option><option value="RUB">RUB</option><option value="AED">AED</option></select>
    </div>
  </div>
</nav>
