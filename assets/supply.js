/* NEXUS Supply — ortak panel JS'i.
   Şu an tek görev: hazırlık kartlarındaki satır içi görsel önizlemelerine
   tıklanabilir büyütme (lightbox). Event delegation ile çalışır — sayfa
   yeniden render edilse bile bağlantı kopmaz. */
(function () {
  'use strict';
  var box = null;

  function ensureBox() {
    if (box) return;
    box = document.createElement('div');
    box.className = 'readiness-lightbox';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.innerHTML =
      '<div class="readiness-lightbox-backdrop"></div>' +
      '<figure class="readiness-lightbox-fig">' +
      '<img alt="">' +
      '<figcaption></figcaption>' +
      '<button type="button" class="readiness-lightbox-close" aria-label="Kapat">✕</button>' +
      '</figure>';
    box.addEventListener('click', function (e) {
      var t = e.target;
      if (
        t === box ||
        t.classList.contains('readiness-lightbox-backdrop') ||
        t.classList.contains('readiness-lightbox-close')
      ) {
        close();
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && box && box.parentNode) close();
    });
    document.body.appendChild(box);
  }

  function open(src, label) {
    ensureBox();
    var img = box.querySelector('img');
    img.src = src;
    img.alt = label || '';
    box.querySelector('figcaption').textContent = label || '';
    box.classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function close() {
    if (!box || !box.parentNode) return;
    box.classList.remove('open');
    document.body.style.overflow = '';
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || t.tagName !== 'IMG') return;
    var wrap = t.closest ? t.closest('.readiness-media-preview') : null;
    if (!wrap) return;
    var label = t.getAttribute('alt') || t.getAttribute('title') || '';
    open(t.getAttribute('src'), label);
  });
})();
