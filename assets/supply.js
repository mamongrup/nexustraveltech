/* NEXUS Supply — ortak panel JS'i.
   Hazırlık kartlarındaki satır içi görsel önizlemelerine
   tıklanabilir büyütme (lightbox) + ok tuşlarıyla koleksiyon navigasyonu.
   URL hash (#media-N) ile paylaşılabilir bağlantı desteği.
   Event delegation ile çalışır. */
(function () {
  'use strict';
  var box = null;
  var currentImages = [];   // [{src, alt}, ...]
  var currentIndex = 0;
  var currentContainerId = ''; // hangi konteynırda olduğumuz

  /** URL hash'ini güncelle (popState tetiklemez). */
  function setHash(hash) {
    if (window.location.hash === hash) return;
    history.replaceState(null, '', hash || window.location.pathname + window.location.search);
  }

  /** Mevcut hash'ten konteynır + indis bilgisini çöz. */
  function parseHash() {
    var m = (window.location.hash || '').match(/^#media-(\w+)-(\d+)$/);
    if (!m) return null;
    return { containerId: m[1], index: parseInt(m[2], 10) };
  }

  function ensureBox() {
    if (box) return;
    box = document.createElement('div');
    box.className = 'readiness-lightbox';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.innerHTML =
      '<div class="readiness-lightbox-backdrop"></div>' +
      '<figure class="readiness-lightbox-fig">' +
      '<button type="button" class="readiness-lightbox-prev" aria-label="Önceki görsel">‹</button>' +
      '<img alt="">' +
      '<button type="button" class="readiness-lightbox-next" aria-label="Sonraki görsel">›</button>' +
      '<figcaption></figcaption>' +
      '<span class="readiness-lightbox-counter"></span>' +
      '<button type="button" class="readiness-lightbox-close" aria-label="Kapat">✕</button>' +
      '</figure>';
    box.addEventListener('click', function (e) {
      var t = e.target;
      if (t === box || t.classList.contains('readiness-lightbox-backdrop') || t.classList.contains('readiness-lightbox-close')) {
        close();
      } else if (t.classList.contains('readiness-lightbox-prev')) {
        navigate(-1);
      } else if (t.classList.contains('readiness-lightbox-next')) {
        navigate(1);
      }
    });
    document.addEventListener('keydown', function (e) {
      if (!box || !box.classList.contains('open')) return;
      if (e.key === 'Escape') { close(); return; }
      if (e.key === 'ArrowLeft') { e.preventDefault(); navigate(-1); }
      if (e.key === 'ArrowRight') { e.preventDefault(); navigate(1); }
    });
    document.body.appendChild(box);
  }

  function showImage(index) {
    if (index < 0 || index >= currentImages.length) return;
    currentIndex = index;
    var item = currentImages[index];
    var img = box.querySelector('img');
    img.src = item.src;
    img.alt = item.alt || '';
    box.querySelector('figcaption').textContent = item.alt || '';
    var counter = box.querySelector('.readiness-lightbox-counter');
    counter.textContent = (index + 1) + ' / ' + currentImages.length;
    var prevBtn = box.querySelector('.readiness-lightbox-prev');
    var nextBtn = box.querySelector('.readiness-lightbox-next');
    prevBtn.style.display = currentImages.length > 1 ? '' : 'none';
    nextBtn.style.display = currentImages.length > 1 ? '' : 'none';
    // URL hash güncelle (paylaşılabilir bağlantı)
    if (currentContainerId) setHash('#media-' + currentContainerId + '-' + index);
  }

  function navigate(delta) {
    if (currentImages.length <= 1) return;
    var next = currentIndex + delta;
    if (next < 0) next = currentImages.length - 1;
    if (next >= currentImages.length) next = 0;
    showImage(next);
  }

  function openFromCollection(images, startIndex) {
    ensureBox();
    currentImages = images;
    showImage(startIndex || 0);
    box.classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function open(src, label) {
    openFromCollection([{src: src, alt: label || ''}], 0);
  }

  function close() {
    if (!box || !box.parentNode) return;
    box.classList.remove('open');
    document.body.style.overflow = '';
    setHash('');
    currentImages = [];
    currentIndex = 0;
    currentContainerId = '';
  }

  /** Konteynıra benzersiz ID ata (hash için). */
  function ensureContainerId(el) {
    if (!el.id) el.id = 'media-box-' + Math.random().toString(36).slice(2, 8);
    return el.id;
  }

  /** Konteynırdaki görselleri koleksiyona dönüştür. */
  function collectImages(wrap) {
    var allImgs = wrap.querySelectorAll('img');
    var images = [];
    for (var i = 0; i < allImgs.length; i++) {
      var imgEl = allImgs[i];
      images.push({
        src: imgEl.getAttribute('src'),
        alt: imgEl.getAttribute('alt') || imgEl.getAttribute('title') || ''
      });
    }
    return images;
  }

  /** Hash'ten otomatik aç — sayfa yüklendiğinde #media-XX-N varsa lightbox'ı aç. */
  function openFromHash() {
    var parsed = parseHash();
    if (!parsed) return;
    var container = document.getElementById(parsed.containerId);
    if (!container) return;
    var images = collectImages(container);
    if (images.length === 0 || parsed.index >= images.length) return;
    currentContainerId = parsed.containerId;
    openFromCollection(images, parsed.index);
  }

  // Görsel tıklama — koleksiyon lightbox
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || t.tagName !== 'IMG') return;
    var wrap = t.closest ? t.closest('.readiness-media-preview, .media-list, .media-gallery') : null;
    if (!wrap) return;

    var containerId = ensureContainerId(wrap);
    var images = collectImages(wrap);
    var startIndex = 0;
    for (var i = 0; i < images.length; i++) {
      if (images[i].src === t.getAttribute('src')) { startIndex = i; break; }
    }
    if (images.length === 0) return;
    currentContainerId = containerId;
    openFromCollection(images, startIndex);
  });

  // Sayfa yüklendiğinde hash'ten lightbox aç
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', openFromHash);
  } else {
    openFromHash();
  }

  /* ── Kaydırma ipucu: taşma varsa sağ kenarda ok işareti ── */
  function initScrollHints() {
    var els = document.querySelectorAll('.readiness-media-preview');
    for (var i = 0; i < els.length; i++) {
      (function (el) {
        var label = document.createElement('span');
        label.className = 'scroll-hint-label';
        el.appendChild(label);

        function getVisibleImg() {
          var imgs = el.querySelectorAll('img');
          var mid = el.scrollLeft + el.clientWidth / 2;
          var best = null, bestDist = Infinity;
          for (var j = 0; j < imgs.length; j++) {
            var elR = el.getBoundingClientRect();
            var r = imgs[j].getBoundingClientRect();
            var imgMid = (r.left + r.right) / 2 - elR.left + el.scrollLeft;
            var dist = Math.abs(imgMid - mid);
            if (dist < bestDist) { bestDist = dist; best = imgs[j]; }
          }
          return best;
        }

        function check() {
          var hasOverflow = el.scrollWidth > el.clientWidth + 2;
          el.classList.toggle('scroll-hint', hasOverflow);
          if (hasOverflow) {
            var atEnd = el.scrollLeft + el.clientWidth >= el.scrollWidth - 4;
            el.classList.toggle('scrolled', atEnd);
            var vis = getVisibleImg();
            if (vis && !atEnd) {
              var desc = vis.getAttribute('data-desc') || '';
              var total = el.querySelectorAll('img').length;
              var idx = Array.prototype.indexOf.call(el.querySelectorAll('img'), vis) + 1;
              var txt = idx + '/' + total;
              if (desc) txt += ' \u00b7 ' + desc;
              label.textContent = txt;
              label.classList.add('visible');
            } else {
              label.classList.remove('visible');
            }
          } else {
            label.classList.remove('visible');
          }
        }
        el.addEventListener('scroll', check, { passive: true });
        check();
        if (typeof ResizeObserver !== 'undefined') {
          new ResizeObserver(check).observe(el);
        }
      })(els[i]);
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initScrollHints);
  } else {
    initScrollHints();
  }

  /* ── .kk-resume sticky: stuck gölgesi ── */
  function initStickyBand() {
    var bands = document.querySelectorAll('.kk-resume');
    if (!bands.length || typeof IntersectionObserver === 'undefined') return;
    var sentinel = document.createElement('div');
    sentinel.style.height = '1px';
    sentinel.style.margin = '0';
    sentinel.style.padding = '0';
    sentinel.style.pointerEvents = 'none';
    bands[0].parentNode.insertBefore(sentinel, bands[0]);
    new IntersectionObserver(function(entries) {
      entries.forEach(function(e) {
        bands.forEach(function(b) {
          b.classList.toggle('stuck', !e.isIntersecting);
        });
      });
    }, { threshold: [0] }).observe(sentinel);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initStickyBand);
  } else {
    initStickyBand();
  }

  /* ── Ortak "kaldığın yerden devam et" bandı ── */
  window.initResumeBand = function(sectionMap) {
    var hash = location.hash || '';
    if (!hash || hash.length < 2) return;
    var key = hash.replace(/^#/, '');
    var label = sectionMap[key];
    if (!label) return;
    var sec = document.getElementById(key);
    if (!sec) return;
    var bar = document.createElement('div');
    bar.className = 'kk-resume';
    bar.setAttribute('role', 'status');
    bar.innerHTML = '<span class="kk-resume-ico">↩</span><span class="kk-resume-txt"><b>Kaldığın yerden devam et:</b> ' + label + '</span><button type="button" class="kk-resume-go">Git →</button><button type="button" class="kk-resume-x" title="Kapat" aria-label="Kapat">×</button>';
    var host = document.querySelector('.supply-top');
    (host ? host.parentNode.insertBefore(bar, host.nextSibling) : document.body.insertBefore(bar, document.body.firstChild));
    function jump() { sec.scrollIntoView({behavior:'smooth',block:'start'}); sec.classList.add('kk-sec-flash'); setTimeout(function(){sec.classList.remove('kk-sec-flash')},2400); }
    bar.querySelector('.kk-resume-go').addEventListener('click', jump);
    bar.querySelector('.kk-resume-x').addEventListener('click', function(){bar.remove()});
    if (location.hash === hash) { sec.classList.add('kk-sec-flash'); setTimeout(function(){sec.classList.remove('kk-sec-flash')},1800); }
    initStickyBand();
  };
})();
