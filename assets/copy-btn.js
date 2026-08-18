/**
 * Ortak kopyalama butonu işleyicisi — tüm tedarikçi sayfalarında kullanılır.
 * İdempotent: sayfada zaten inline handler varsa atlar.
 *
 * Özellikler:
 *   - Özel tooltip: native title yerinedata-copy içeriğini kırpılmadan gösterir
 *   - Mobilde (≤600px): yalnızca ⧉ ikonu
 *   - Feedback: mobilde ✓, desktop'ta Kopyalandı! — 2sn sonra geri döner
 *
 * Desteklenen sınıflar:
 *   .ical-copy-btn / .copy-icon-btn → data-copy URL'sini kopyalar
 *   .copy-link                      → data-copy URL'sine navigasyon + kopyalama
 *   .rule-copy-btn                  → data-rule-json formatlanmış JSON kopyalar
 *   .room-sample-copy               → <pre> içeriğini kopyalar
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
  window.fallbackCopy = fallbackCopy;

  /* ── Ortak kopyalama fonksiyonu ── */
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

  /* ── Token maskeleme: URL'deki token=... parametresini gizle ── */
  function maskUrlToken(url) {
    if (!url) return url;
    return url.replace(/(token=)([^&]{4})([^&]{0,})([^&]{4})(.*)/g, function (m, pfx, head, mid, tail, rest) {
      return pfx + head + '\u2022'.repeat(Math.min(mid.length, 12)) + tail + rest;
    });
  }

  /* ══════════════════════════════════════════════════════════
     ÖZEL ARAÇ İPUCU (tooltip) — native title yerine
     ══════════════════════════════════════════════════════════ */
  var tip = null; // Ortak tooltip elementi (lazy create).

  function ensureTip() {
    if (tip) return tip;
    tip = document.createElement('div');
    tip.className = 'copy-tooltip';
    tip.setAttribute('aria-hidden', 'true');
    document.body.appendChild(tip);
    return tip;
  }

  function showTip(el, text) {
    if (!text) return;
    var t = ensureTip();
    t.textContent = text;
    t.classList.add('show');
    // Pozisyon: elemanın hemen üstünde, yatayda ortala.
    var r = el.getBoundingClientRect();
    var scrollY = window.pageYOffset || document.documentElement.scrollTop;
    var scrollX = window.pageXOffset || document.documentElement.scrollLeft;
    t.style.left = '0';
    t.style.top = '0';
    t.style.visibility = 'hidden';
    t.style.display = 'block';
    var tw = t.offsetWidth;
    var th = t.offsetHeight;
    var left = r.left + scrollX + (r.width / 2) - (tw / 2);
    var top = r.top + scrollY - th - 8;
    // Ekran sınırları kontrolü.
    if (left < 8) left = 8;
    if (left + tw > window.innerWidth - 8) left = window.innerWidth - tw - 8;
    if (top < scrollY + 4) top = r.bottom + scrollY + 8; // Alt alta düşsün.
    t.style.left = left + 'px';
    t.style.top = top + 'px';
    t.style.visibility = '';
  }

  function hideTip() {
    if (tip) { tip.classList.remove('show'); tip.style.display = 'none'; }
  }

  /* ── Tooltip bağlanacak elemanlar ── */
  function bindTip(el, getText) {
    el.removeAttribute('title'); // Native tooltip'i devre dışı bırak.
    el.style.position = 'relative';
    el.addEventListener('mouseenter', function () { showTip(el, getText()); });
    el.addEventListener('mouseleave', hideTip);
    el.addEventListener('focus', function () { showTip(el, getText()); });
    el.addEventListener('blur', hideTip);
  }

  /* ══════════════════════════════════════════════════════════
     HANDLER'LAR
     ══════════════════════════════════════════════════════════ */

  /* ── .ical-copy-btn / .copy-icon-btn: data-copy URL'sini kopyala ── */
  document.querySelectorAll('.ical-copy-btn, .copy-icon-btn').forEach(function (b) {
    var origHTML = b.innerHTML;
    var url = b.getAttribute('data-copy') || '';
    bindTip(b, function () { return maskUrlToken(url); });
    b.addEventListener('click', function (e) {
      e.preventDefault();
      copyText(url, b, origHTML);
    });
  });

  /* ── .copy-link: data-copy URL'sine navigasyon + kopyalama ── */
  document.querySelectorAll('.copy-link').forEach(function (a) {
    var url = a.getAttribute('href') || a.getAttribute('data-copy') || '';
    bindTip(a, function () { return url; });
    // Link default navigasyonunu korur (href zaten ayarlı).
  });

  /* ── .rule-copy-btn: data-rule-json formatlanmış JSON kopyala ── */
  document.querySelectorAll('.rule-copy-btn').forEach(function (b) {
    var raw = b.getAttribute('data-rule-json') || '';
    var txt = raw.replace(/&#39;/g, "'").replace(/&amp;/g, '&')
      .replace(/&lt;/g, '<').replace(/&gt;/g, '>');
    try { txt = JSON.stringify(JSON.parse(txt), null, 2); } catch (e) { /* raw */ }
    bindTip(b, function () { return txt; });
    b.addEventListener('click', function (e) {
      e.preventDefault();
      copyText(txt, b, null, 'Kopyalandı!');
    });
  });

  /* ── .room-sample-copy: <pre> içeriğini kopyala ── */
  document.querySelectorAll('.room-sample-copy').forEach(function (b) {
    bindTip(b, function () {
      var box = b.closest('.room-sample-box');
      var pre = box ? box.querySelector('pre') : null;
      return pre ? pre.textContent : '';
    });
    b.addEventListener('click', function (e) {
      e.preventDefault();
      var box = this.closest('.room-sample-box');
      var pre = box ? box.querySelector('pre') : null;
      if (pre) copyText(pre.textContent, this, null, 'Kopyalandı!');
    });
  });
})();
