<!doctype html>
<?php $seo_page = 'home'; ?>
<html lang="tr">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>NEXUS TravelTech | Turizmin Canlı Bilgi Ağı</title>
    <?php require __DIR__ . '/partials/seo.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&family=Playfair+Display:ital,wght@0,600;0,700;1,600&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="/nexustraveltech/assets/styles.css" />
    <script src="https://unpkg.com/htmx.org@2.0.4"></script>
  </head>
  <body>
    <main>
      <?php require __DIR__ . '/partials/header.php'; ?>

      <section id="ana" class="hero shell">
        <div class="eyebrow"><i></i> <span data-i18n="hero_eyebrow">Turizmin canlı bilgi ağı</span></div>
        <div class="hero-grid">
          <div>
            <h1 data-i18n-html="hero_title">NEXUS<br /><em>TravelTech.</em></h1>
            <p class="hero-copy" data-i18n="hero_copy">Tedarikçilerin güncel ürün, fiyat ve müsaitlik bilgisini tek bilgi havuzunda toplar. Acenteler doğru kaynağa anında ulaşır, müşteriye daha hızlı teklif ve rezervasyon sunar.</p>
            <div class="hero-actions">
              <a href="#erken-erisim" class="button button-dark"><span data-i18n="hero_cta">Pilot gruba katıl</span> <span>&#8594;</span></a>
              <a href="#nasil-calisir" class="text-link"><span data-i18n="hero_secondary">Sistemi keşfet</span> <span>&#8595;</span></a>
            </div>
            <div class="market-note"><span data-i18n="market_note">Aktif pazar görünümü</span><div class="market-currencies" aria-label="Desteklenen para birimleri"><b data-currency-option="TRY">TRY</b><b data-currency-option="EUR">EUR</b><b data-currency-option="USD">USD</b><b data-currency-option="GBP">GBP</b><b data-currency-option="RUB">RUB</b><b data-currency-option="AED">AED</b></div></div>
          </div>
          <div class="signal-board" aria-label="Nexus canlı envanter görünümü">
            <div class="board-top"><span data-i18n="board_title">CANLI ENVANTER</span><b><i></i> <span data-i18n="board_status">Güncel</span></b></div>
            <div class="signal-card large">
              <div class="signal-icon hotel">&#8962;</div>
              <div><small data-i18n="board_stay">Konaklama</small><strong data-i18n-html="board_offer">Akdeniz kıyısında<br />3 gece</strong></div>
              <div class="availability"><span data-i18n="board_rooms">12 oda</span><b data-i18n="board_available">Müsait</b></div>
            </div>
            <div class="signal-card">
              <div class="signal-icon transfer">&#8635;</div>
              <div><small data-i18n="category_transfer">Transfer</small><strong>Dalaman &#8594; Fethiye</strong></div>
              <div class="live-dot"></div>
            </div>
            <div class="signal-card">
              <div class="signal-icon boat">&#9873;</div>
              <div><small data-i18n="board_experience">Deneyim</small><strong data-i18n="board_boat">Göcek günlük tekne</strong></div>
              <div class="live-dot"></div>
            </div>
            <div class="pulse-line"><span></span><span></span><span></span><span></span><span></span></div>
            <div class="board-footer"><span data-i18n="flow_supplier">TEDARİKÇİ</span><b>&#8594;</b><span data-i18n="flow_pool">BİLGİ HAVUZU</span><b>&#8594;</b><span data-i18n="flow_agency">ACENTE</span></div>
          </div>
        </div>
        <div class="hero-foot"><span>OTEL</span><span>VİLLA</span><span>YAT</span><span>TUR</span><span>TRANSFER</span><span>ARAÇ</span><span>UÇUŞ</span></div>
      </section>

      <section id="nasil-calisir" class="flow-section">
        <div class="shell">
          <div class="section-intro"><div class="eyebrow"><i></i> <span data-i18n="flow_eyebrow">Tek kaynaktan güvenilir veri</span></div><h2 data-i18n-html="flow_title">Satış hızını artıran<br />üçlü akış.</h2></div>
          <div class="flow-grid">
            <article><div class="step">01</div><h3 data-i18n="supplier_title">Tedarikçi</h3><p data-i18n="supplier_copy">Ürününü, fiyatını, müsaitliğini ve kurallarını tek panelden yönetir.</p><span class="line-icon">&#8627;</span></article>
            <article class="accent"><div class="step">02</div><h3 data-i18n="pool_title">Bilgi havuzu</h3><p data-i18n="pool_copy">Veriyi standartlaştırır, doğrular ve her an güncel tutar.</p><span class="line-icon">&#8627;</span></article>
            <article><div class="step">03</div><h3 data-i18n="agency_title">Acente & müşteri</h3><p data-i18n="agency_copy">Canlı envantere hızla ulaşır; teklifi ve rezervasyonu gecikmeden tamamlar.</p><span class="line-icon">&#8594;</span></article>
          </div>
        </div>
      </section>

      <section id="kimler-icin" class="audience shell">
        <div class="audience-copy"><div class="eyebrow"><i></i> <span data-i18n="audience_eyebrow">Herkes için daha az sürtünme</span></div><h2 data-i18n-html="audience_title">Turizm, daha<br /><em>akıcı</em> çalışır.</h2><p data-i18n="audience_copy">Her rol, aynı doğru veriden farklı bir değer üretir.</p></div>
        <div class="audience-list">
          <article><span>01</span><div><h3 data-i18n="audience_suppliers_title">Tedarikçiler</h3><p data-i18n="audience_suppliers_copy">Operasyonunu yönetir, boş kapasitesini daha çok satış kanalına açar.</p></div><b>&#8599;</b></article>
          <article><span>02</span><div><h3 data-i18n="audience_agencies_title">Acenteler</h3><p data-i18n="audience_agencies_copy">Farklı XML ve API maliyetlerine girmeden canlı envantere erişir.</p></div><b>&#8599;</b></article>
          <article><span>03</span><div><h3 data-i18n="audience_travelers_title">Gezginler</h3><p data-i18n="audience_travelers_copy">Daha güncel seçenekleri, doğru koşullarla hızlıca karşılaştırır ve alır.</p></div><b>&#8599;</b></article>
        </div>
      </section>

      <section class="value-band"><div class="shell"><p data-i18n="value_kicker">Bir ilan sitesi değil.</p><h2 data-i18n-html="value_title">Turizmin güncel veri, dağıtım<br />ve satış altyapısı.</h2></div></section>

      <section class="explore shell" aria-label="Nexus hakkında daha fazlası">
        <div class="section-intro"><div class="eyebrow"><i></i> NEXUS EKOSİSTEMİ</div><h2>Bilgiyi değil,<br /><em>hareketi</em> yönetiyoruz.</h2></div>
        <div class="explore-grid">
          <a href="/nexustraveltech/platform" class="explore-card"><span>01 / PLATFORM</span><h3>Tek bilgi havuzu</h3><p>Farklı tedarikçilerin canlı verisini ortak bir dilde birleştiren dağıtım katmanı.</p><b>Platformu keşfet →</b></a>
          <a href="/nexustraveltech/cozumler" class="explore-card dark"><span>02 / ÇÖZÜMLER</span><h3>Her oyuncu için akış</h3><p>Tedarikçi paneli, acente yazılımı ve açık API ile uçtan uca bağlantı.</p><b>Çözümleri incele →</b></a>
          <a href="/nexustraveltech/sirket" class="explore-card lime"><span>03 / ŞİRKET</span><h3>Neden NEXUS?</h3><p>Turizmi daha hızlı, daha şeffaf ve daha bağlantılı hale getirme hedefi.</p><b>Hikâyemizi oku →</b></a>
        </div>
      </section>

      <section id="erken-erisim" class="signup shell">
        <div><div class="eyebrow"><i></i> <span data-i18n="pilot_eyebrow">Pilot program</span></div><h2 data-i18n-html="pilot_title">İlk bağlananlardan<br />olun.</h2><p data-i18n="pilot_copy">Otel ve villa tedarikçileri ile acenteler için pilot grubumuzu oluşturuyoruz.</p></div>
        <form class="signup-form" id="early-access-form" action="/nexustraveltech/api/lead.php" method="post">
          <input type="hidden" name="language" id="lead-language" value="tr" />
          <input type="hidden" name="currency" id="lead-currency" value="TRY" />
          <label><span data-i18n="form_email">İş e-postanız</span><input type="email" name="email" placeholder="ornek@sirketiniz.com" data-i18n-placeholder="form_placeholder" required /></label>
          <label><span data-i18n="form_role">Rolünüz</span><select name="role"><option data-i18n="role_supplier">Tedarikçi</option><option data-i18n="role_agency">Acente</option><option data-i18n="role_operator">Turizm işletmesi</option><option data-i18n="role_other">Diğer</option></select></label>
          <button class="button button-lime" type="submit"><span data-i18n="form_submit">Erken erişim iste</span> <span>&#8594;</span></button>
          <div id="form-result" aria-live="polite"></div>
        </form>
      </section>
    </main>
    <?php require __DIR__ . '/partials/footer.php'; ?>
    <script>
      document.addEventListener("DOMContentLoaded", () => {
        const form = document.querySelector("#early-access-form");
        const result = document.querySelector("#form-result");
        if (!form || !result) return;
        form.addEventListener("submit", async (event) => {
          event.preventDefault();
          result.innerHTML = '<p class="form-success">Kaydediliyor...</p>';
          try {
            const language = document.querySelector("#site-language");
            const currency = document.querySelector("#site-currency");
            document.querySelector("#lead-language").value = language ? language.value : "tr";
            document.querySelector("#lead-currency").value = currency ? currency.value : "TRY";
            const response = await fetch(form.action, {
              method: "POST",
              body: new FormData(form),
              headers: { "Accept": "application/json" }
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || "Kayit alinamadi.");
            result.innerHTML = '<p class="form-success"><span>&#10003;</span> Talebiniz alindi. Ilk pilot grubu icin sizinle iletisime gececegiz.</p>';
            form.reset();
          } catch (error) {
            result.innerHTML = '<p class="form-success">Kayit sirasinda sorun olustu. Lutfen tekrar deneyin.</p>';
          }
        });
      });
    </script>  </body>
</html>
