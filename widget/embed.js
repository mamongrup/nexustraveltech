/**
 * NEXUS Travel Tech — Embeddable Booking Engine Script
 * Kullanım: <script src="https://nexustraveltech.com/widget/embed.js" data-property="1"></script>
 */
(function() {
    var script = document.currentScript || (function() {
        var scripts = document.getElementsByTagName('script');
        return scripts[scripts.length - 1];
    })();

    var propertyId = script.getAttribute('data-property') || '1';
    var baseUrl = script.src.replace('/widget/embed.js', '');

    // Floating Rezervasyon Butonu
    var btn = document.createElement('div');
    btn.innerHTML = '<div style="position:fixed;bottom:24px;right:24px;z-index:999999;background:linear-gradient(310deg,#7928ca,#ff0080);color:#fff;padding:14px 22px;border-radius:50px;box-shadow:0 10px 25px rgba(121,40,202,0.4);font-family:sans-serif;font-size:14px;font-weight:bold;cursor:pointer;display:flex;align-items:center;gap:8px;transition:transform 0.2s" onmouseover="this.style.transform=\'scale(1.05)\'" onmouseout="this.style.transform=\'scale(1)\'">' +
        '<span>🏨 Online Rezervasyon Yap</span>' +
        '</div>';

    document.body.appendChild(btn);

    // Modal Iframe Kapsayıcısı
    var modal = document.createElement('div');
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.7);backdrop-filter:blur(4px);z-index:9999999;display:none;align-items:center;justify-content:center;padding:16px;';
    
    var modalContent = document.createElement('div');
    modalContent.style.cssText = 'background:#fff;border-radius:20px;width:100%;max-width:900px;height:85vh;position:relative;overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);';

    var closeBtn = document.createElement('button');
    closeBtn.innerText = '×';
    closeBtn.style.cssText = 'position:absolute;top:14px;right:18px;background:none;border:none;font-size:26px;color:#fff;cursor:pointer;z-index:10;font-weight:bold;';
    closeBtn.onclick = function() { modal.style.display = 'none'; };

    var iframe = document.createElement('iframe');
    iframe.src = baseUrl + '/widget/booking-engine.php?property_id=' + propertyId;
    iframe.style.cssText = 'width:100%;height:100%;border:none;';

    modalContent.appendChild(closeBtn);
    modalContent.appendChild(iframe);
    modal.appendChild(modalContent);
    document.body.appendChild(modal);

    btn.onclick = function() {
        modal.style.display = 'flex';
    };
})();
