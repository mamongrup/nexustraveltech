<?php
declare(strict_types=1);

/**
 * Kamuya açık (önyüz) AI asistan chatbox'ı — oturum gerekmez.
 *
 * Kullanım: ortak footer'da (partials/footer.php) </body>'den hemen önce:
 *   <?php require __DIR__ . '/../config/ai_public_widget.php'; ai_public_widget(); ?>
 *
 * Endpoint: api/public-chat.php (IP hız sınırlamalı, kayıt tutar).
 */
function ai_public_widget(): void
{
    // Ortam duyarlı API adresi: yerel (Laragon, /nexustraveltech öneki) ve üretim (kök) farklıdır.
    $apiUrl = (str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), '/nexustraveltech/') ? '/nexustraveltech' : '') . '/api/public-chat.php';
    ?>
<style>
#nxpub-fab{position:fixed;right:18px;bottom:18px;z-index:99990;width:58px;height:58px;border-radius:50%;border:0;cursor:pointer;background:#10211f;color:#d7ff48;font-weight:800;font-size:11px;font-family:'DM Sans',Arial,sans-serif;box-shadow:0 8px 22px rgba(0,0,0,.38);display:grid;place-items:center;line-height:1.15;letter-spacing:.5px;transition:transform .15s}
#nxpub-fab:hover{transform:translateY(-2px)}
#nxpub-panel{position:fixed;right:18px;bottom:86px;z-index:99991;width:min(372px,calc(100vw - 28px));max-height:min(540px,calc(100vh - 112px));display:none;flex-direction:column;background:#fff;border:1px solid #e1e5de;border-radius:16px;box-shadow:0 18px 48px rgba(0,0,0,.3);font-family:'DM Sans',Arial,sans-serif;overflow:hidden}
#nxpub-panel.open{display:flex}
#nxpub-head{background:#10211f;color:#fff;padding:13px 15px;display:flex;justify-content:space-between;align-items:center;gap:8px}
#nxpub-head b{font-size:15px;letter-spacing:.3px}
#nxpub-head b span{color:#d7ff48}
#nxpub-head small{display:block;color:#9fe8b8;font-weight:400;font-size:11px;margin-top:2px}
#nxpub-close{background:none;border:0;color:#fff;font-size:18px;cursor:pointer;line-height:1}
#nxpub-msgs{flex:1;overflow-y:auto;padding:13px;display:flex;flex-direction:column;gap:9px;min-height:130px;background:#f7f7f2}
#nxpub-msgs .m{max-width:87%;padding:9px 12px;border-radius:13px;font-size:13px;line-height:1.55;white-space:pre-wrap;word-break:break-word}
#nxpub-msgs .m a{color:#0d7a4a;font-weight:600;text-decoration:underline}
#nxpub-msgs .u{align-self:flex-end;background:#10211f;color:#fff;border-bottom-right-radius:3px}
#nxpub-msgs .b{align-self:flex-start;background:#fff;border:1px solid #e1e5de;border-bottom-left-radius:3px;color:#10211f}
#nxpub-foot{display:flex;gap:7px;padding:11px;border-top:1px solid #e1e5de;background:#fff}
#nxpub-input{flex:1;border:1px solid #d8ded8;border-radius:9px;padding:10px 11px;font:inherit;font-size:13px;outline:none}
#nxpub-input:focus{border-color:#10211f}
#nxpub-send{border:0;background:#0d7a4a;color:#fff;font-weight:700;border-radius:9px;padding:0 16px;cursor:pointer;font-size:13px}
#nxpub-send:disabled{opacity:.55;cursor:wait}
@media (max-width:520px){#nxpub-fab{right:12px;bottom:12px}#nxpub-panel{right:12px;bottom:78px}}
</style>
<button id="nxpub-fab" type="button" title="NEXUS AI asistan — sorularınızı yanıtlar">NEXUS<br>AI</button>
<div id="nxpub-panel" aria-label="NEXUS AI asistan">
  <div id="nxpub-head"><div><b>NEXUS <span>AI</span> asistan</b><small>Platform hakkında sorularınızı yanıtlar, sizi doğru sayfaya yönlendirir</small></div><button id="nxpub-close" type="button" aria-label="Kapat">✕</button></div>
  <div id="nxpub-msgs"><div class="m b">Merhaba 👋 NEXUS TravelTech hakkında size nasıl yardımcı olabilirim? Örn. <i>“Tedarikçi olarak nasıl katılırım?”</i>, <i>“API entegrasyonu var mı?”</i> yazabilirsiniz.</div></div>
  <div id="nxpub-foot"><input id="nxpub-input" type="text" placeholder="Sorunuzu yazın…" autocomplete="off"><button id="nxpub-send" type="button">Gönder</button></div>
</div>
<script>
(function(){
  var URL = <?= json_encode($apiUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
  var hist = [];
  var busy = false;
  var panel = document.getElementById('nxpub-panel');
  var msgs  = document.getElementById('nxpub-msgs');
  var input = document.getElementById('nxpub-input');
  var send  = document.getElementById('nxpub-send');
  var fab   = document.getElementById('nxpub-fab');
  var close = document.getElementById('nxpub-close');
  if (!panel || !msgs || !input || !send || !fab) return;

  function addMsg(role, html){
    var d = document.createElement('div');
    d.className = 'm ' + role;
    d.innerHTML = html;
    msgs.appendChild(d);
    msgs.scrollTop = msgs.scrollHeight;
  }
  function esc(s){
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function linkify(s){
    return esc(s).replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>');
  }
  function toggle(open){
    if (open === undefined) open = !panel.classList.contains('open');
    panel.classList.toggle('open', open);
    if (open) input.focus();
  }
  fab.addEventListener('click', function(){ toggle(); });
  close.addEventListener('click', function(){ toggle(false); });
  function sendMsg(){
    var t = input.value.trim();
    if (!t || busy) return;
    input.value = '';
    hist.push({role:'user', content:t});
    addMsg('u', esc(t));
    busy = true; send.disabled = true;
    fetch(URL, {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({message: t, history: hist.slice(0, -1)})
    })
    .then(function(r){ return r.json().catch(function(){ return {error:'Yanıt okunamadı (HTTP ' + r.status + ')'}; }); })
    .then(function(d){
      if (d.reply){ hist.push({role:'assistant', content:d.reply}); addMsg('b', linkify(d.reply)); }
      else { addMsg('b', '⚠ ' + esc(d.error || 'Yanıt alınamadı.')); }
    })
    .catch(function(){ addMsg('b', '⚠ Bağlantı hatası. Lütfen tekrar deneyin.'); })
    .then(function(){ busy = false; send.disabled = false; });
  }
  send.addEventListener('click', sendMsg);
  input.addEventListener('keydown', function(e){ if (e.key === 'Enter'){ e.preventDefault(); sendMsg(); } });
})();
</script>
    <?php
}
