/**
 * Ortak kopyalama butonu işleyicisi — tüm tedarikçi sayfalarında kullanılır.
 * İdempotent: sayfada zaten inline handler varsa atlar.
 *
 * Desteklenen sınıflar:
 *   .ical-copy-btn    → data-copy URL'sini kopyalar (webhook, iCal, sayfa linki)
 *   .rule-copy-btn    → data-rule-json JSON'unu formatlanmış olarak kopyalar
 *   .room-sample-copy → En yakın .room-sample-box içindeki <pre> içeriğini kopyalar
 *
 * Mobilde (≤600px): yalnızca ⧉ ikonu; desktop'ta kısa etiket görünür.
 * Feedback: mobilde ✓, desktop'ta Kopyalandı! — 2sn sonra geri döner.
 */
(function () {
  'use strict';

  // İdempotans: sayfada zaten fallbackCopy tanımlıysa inline handler vardır — atla.
  if (typeof window.fallbackCopy === 'function' || typeof window.icalFallbackCopy === 'function') return;

  /* ── Eski tarayıcılar için clipboard fallback ── */
  function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (e) { /* noop */ }
    document.body.removeChild(ta);
  }
  // Global yap ki inline handler'lar da kullanabilsin (geriye dönük uyumluluk).
  window.fallbackCopy = fallbackCopy;

  function copyText(text, btn, origHTML, doneText) {
    function done() {
      var mob = window.innerWidth < 601;
      btn.textContent = mob ? '✓' : (doneText || 'Kopyalandı!');
      btn.classList.add('copied');
      setTimeout(function () {
        if (origHTML !== null) {
          btn.innerHTML = origHTML;
        } else {
          btn.textContent = mob ? (btn.dataset.icon || '⧉') : (btn.dataset.full || 'Kopyala');
        }
        btn.classList.remove('copied');
      }, 2000);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(function () {
        fallbackCopy(text); done();
      });
    } else {
      fallbackCopy(text); done();
    }
  }

  /* ── .ical-copy-btn / .copy-icon-btn: data-copy URL'sini kopyala ── */
  document.querySelectorAll('.ical-copy-btn, .copy-icon-btn').forEach(function (b) {
    if (!b.title) b.title = b.getAttribute('data-copy') || '';
    var origHTML = b.innerHTML;
    b.addEventListener('click', function () {
      copyText(this.getAttribute('data-copy') || '', this, origHTML);
    });
  });

  /* ── .rule-copy-btn: data-rule-json formatlanmış JSON kopyala ── */
  document.querySelectorAll('.rule-copy-btn').forEach(function (b) {
    b.addEventListener('click', function () {
      var raw = this.getAttribute('data-rule-json') || '';
      var txt = raw.replace(/&#39;/g, "'").replace(/&amp;/g, '&')
        .replace(/&lt;/g, '<').replace(/&gt;/g, '>');
      try { txt = JSON.stringify(JSON.parse(txt), null, 2); } catch (e) { /* raw */ }
      copyText(txt, this, null, 'Kopyalandı!');
    });
  });

  /* ── .room-sample-copy: <pre> içeriğini kopyala ── */
  document.querySelectorAll('.room-sample-copy').forEach(function (b) {
    b.addEventListener('click', function () {
      var box = this.closest('.room-sample-box');
      var pre = box ? box.querySelector('pre') : null;
      if (pre) copyText(pre.textContent, this, null, 'Kopyalandı!');
    });
  });
})();
