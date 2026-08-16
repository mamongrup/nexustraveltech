<?php
declare(strict_types=1);

/**
 * Yüzen AI asistan chatbox'ı — üç panelde de (admin/tedarikçi/acente) görünür.
 *
 * Kullanım (her panel sayfasında </body> etiketinden hemen önce):
 *   <?php require_once __DIR__.'/../config/ai_widget.php';
 *         ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); ?>
 *
 * Endpoint'ler (config/ai_assistant.php motoru üzerinden) soruları yanıtlar,
 * yönlendirir ve yalnızca güvenli işlemleri (okuma + küçük eylemler) yapar.
 */
function ai_widget(string $endpoint, string $sessionCsrfKey): void
{
    // CSRF anahtarı: sayfa ve endpoint aynı kuralda üretir.
    if (empty($_SESSION[$sessionCsrfKey])) {
        $_SESSION[$sessionCsrfKey] = bin2hex(random_bytes(32));
    }
    $token = (string) $_SESSION[$sessionCsrfKey];
    $url = htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8');
    ?>
<style>
#nexus-ai-fab{position:fixed;right:18px;bottom:18px;z-index:99990;width:56px;height:56px;border-radius:50%;border:0;cursor:pointer;background:#10211f;color:#d7ff48;font-weight:800;font-size:11px;font-family:Arial,sans-serif;box-shadow:0 6px 18px rgba(0,0,0,.35);display:grid;place-items:center;line-height:1.1;letter-spacing:.5px}
#nexus-ai-panel{position:fixed;right:18px;bottom:84px;z-index:99991;width:min(370px,calc(100vw - 28px));max-height:min(520px,calc(100vh - 110px));display:none;flex-direction:column;background:#fff;border:1px solid #d8ded8;border-radius:14px;box-shadow:0 14px 40px rgba(0,0,0,.28);font-family:Arial,sans-serif;overflow:hidden}
#nexus-ai-panel.open{display:flex}
#nexus-ai-head{background:#10211f;color:#fff;padding:12px 14px;display:flex;justify-content:space-between;align-items:center;gap:8px}
#nexus-ai-head b{font-size:14px;letter-spacing:.3px}
#nexus-ai-head small{display:block;color:#9fe8b8;font-weight:400;font-size:11px;margin-top:2px}
#nexus-ai-close{background:none;border:0;color:#fff;font-size:18px;cursor:pointer;line-height:1}
#nexus-ai-msgs{flex:1;overflow-y:auto;padding:12px;display:flex;flex-direction:column;gap:8px;min-height:120px;background:#f7f7f2}
#nexus-ai-msgs .m{max-width:86%;padding:8px 11px;border-radius:12px;font-size:13px;line-height:1.5;white-space:pre-wrap;word-break:break-word}
#nexus-ai-msgs .u{align-self:flex-end;background:#10211f;color:#fff;border-bottom-right-radius:3px}
#nexus-ai-msgs .b{align-self:flex-start;background:#fff;border:1px solid #e1e5de;border-bottom-left-radius:3px;color:#10211f}
#nexus-ai-msgs .hint{font-size:11px;color:#64716d;text-align:center}
#nexus-ai-foot{display:flex;gap:6px;padding:10px;border-top:1px solid #e1e5de;background:#fff}
#nexus-ai-input{flex:1;border:1px solid #d8ded8;border-radius:8px;padding:9px 10px;font:inherit;font-size:13px;outline:none}
#nexus-ai-input:focus{border-color:#10211f}
#nexus-ai-send{border:0;background:#0d7a4a;color:#fff;font-weight:700;border-radius:8px;padding:0 14px;cursor:pointer;font-size:13px}
#nexus-ai-send:disabled{opacity:.5;cursor:wait}
@media (max-width:520px){#nexus-ai-fab{right:12px;bottom:12px}#nexus-ai-panel{right:12px;bottom:76px}}
</style>
<button id="nexus-ai-fab" type="button" title="NEXUS AI asistan">NEXUS<br>AI</button>
<div id="nexus-ai-panel" aria-label="NEXUS AI asistan">
  <div id="nexus-ai-head"><div><b>NEXUS AI asistan</b><small>Soruları yanıtlar, yönlendirir, güvenli işlemleri yapar</small></div><button id="nexus-ai-close" type="button" aria-label="Kapat">✕</button></div>
  <div id="nexus-ai-msgs"><div class="m b">Merhaba 👋 Size nasıl yardımcı olabilirim? Örn. <i>“Bugün kaç misafir geliyor?”</i> veya <i>“Son hataları göster”</i> yazabilirsiniz.</div></div>
  <div id="nexus-ai-foot"><input id="nexus-ai-input" type="text" placeholder="Sorunuzu yazın…" autocomplete="off"><button id="nexus-ai-send" type="button">Gönder</button></div>
</div>
<script>
(function(){
  var TOKEN = <?= json_encode($token, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
  var URL   = <?= json_encode($endpoint, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
  var hist  = [];
  var panel = document.getElementById('nexus-ai-panel');
  var msgs  = document.getElementById('nexus-ai-msgs');
  var input = document.getElementById('nexus-ai-input');
  var send  = document.getElementById('nexus-ai-send');
  var fab   = document.getElementById('nexus-ai-fab');
  var close = document.getElementById('nexus-ai-close');
  if (!panel || !msgs || !input || !send || !fab) return;

  function addMsg(role, text){
    var d = document.createElement('div');
    d.className = 'm ' + role;
    d.textContent = text;
    msgs.appendChild(d);
    msgs.scrollTop = msgs.scrollHeight;
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
    if (!t || send.disabled) return;
    input.value = '';
    hist.push({role:'user', content:t});
    addMsg('u', t);
    send.disabled = true;
    fetch(URL, {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({csrf: TOKEN, messages: hist})
    })
    .then(function(r){ return r.json().catch(function(){ return {error:'Yanıt okunamadı (HTTP ' + r.status + ')'}; }); })
    .then(function(d){
      if (d.reply){ hist.push({role:'assistant', content:d.reply}); addMsg('b', d.reply); }
      else { addMsg('b', '⚠ ' + (d.error || 'Yanıt alınamadı.')); }
    })
    .catch(function(){ addMsg('b', '⚠ Bağlantı hatası. Lütfen tekrar deneyin.'); })
    .then(function(){ send.disabled = false; });
  }
  send.addEventListener('click', sendMsg);
  input.addEventListener('keydown', function(e){ if (e.key === 'Enter'){ e.preventDefault(); sendMsg(); } });
})();
</script>
    <?php
}
