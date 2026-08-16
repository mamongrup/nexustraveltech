<?php
declare(strict_types=1);
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../config/payments.php';

$token = (string)($_GET['token'] ?? '');
$result = null;
$error = '';
if ($token === '') {
    $error = 'Ödeme bağlantısı eksik.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $result = record_payment_link_payment($token, 'test_card');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$link = $token !== '' ? payment_link_by_token($token) : null;
?>
<!doctype html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Güvenli ödeme | NEXUS</title>
<style>body{margin:0;background:#071412;color:#fff;font-family:Arial,sans-serif;min-height:100vh;display:grid;place-items:center}.card{width:min(440px,calc(100% - 32px));background:#fff;color:#10211f;padding:30px;border:1px solid rgba(255,255,255,.18)}h1{margin:0 0 6px;font-size:26px}.brand{font-weight:800;color:#e85f42}.muted{color:#64716d;font-size:14px;line-height:1.6}.amount{font-size:34px;font-weight:800;margin:14px 0 4px}.row{display:flex;justify-content:space-between;border-top:1px solid #eef1ec;padding:10px 0;font-size:14px}.ok{background:#e6f8c7;color:#10211f;padding:12px;margin-top:14px}.er{background:#ffe2de;color:#8e2410;padding:12px;margin-top:14px}button{width:100%;border:0;background:#10211f;color:#fff;font-weight:800;padding:14px;margin-top:16px;cursor:pointer}button.test{background:#0d7a4a}img.qr{display:block;margin:14px auto;width:160px;height:160px;border:1px solid #e5e5e5;padding:6px}input.url{width:100%;box-sizing:border-box;border:1px solid #d8ded8;padding:9px;font-size:12px;margin-top:8px}</style>
</head><body><div class="card">
<p class="brand">N∿XUS <small>GÜVENLİ ÖDEME</small></p>
<h1>Ödeme sayfası</h1>
<?php if ($result): ?>
  <div class="ok"><b>✓ Ödeme tamamlandı.</b><br>Referans: <?=htmlspecialchars($result['reference'])?><br>Tutar: <?=number_format($result['amount'],2)?> <?=htmlspecialchars($result['currency'])?><?= $result['test_mode']?'<br><small>Bu bir test ödemesidir; gerçek kart çekilmedi.</small>':'' ?></div>
<?php elseif ($link): ?>
  <p class="muted">Aşağıdaki tutarı güvenle ödeyebilirsiniz. Ödeme, rezervasyonunuzun folyosuna işlenir.</p>
  <div class="amount"><?=number_format((float)$link['amount'],2)?> <small><?=htmlspecialchars($link['currency'])?></small></div>
  <div class="row"><span class="muted">Rezervasyon</span><b>#<?=htmlspecialchars((string)$link['booking_id'])?></b></div>
  <div class="row"><span class="muted">Son ödeme tarihi</span><b><?=htmlspecialchars((string)($link['expires_at']?:'Belirsiz'))?></b></div>
  <?php if ($link['test_mode']): ?>
    <p class="muted" style="background:#fff8e1;padding:10px">🧪 <b>Test modu:</b> Ödeme geçidi sözleşmesi imzalanana kadar tahsilat simüle edilir; gerçek kart bilgisi istenmez.</p>
    <form method="post"><button class="test" type="submit">Test ödemesini tamamla</button></form>
  <?php else: ?>
    <p class="muted">Ödeme geçidi yakında etkinleştirilecek; kart ödemesi için kısa süre içinde yönlendirileceksiniz.</p>
  <?php endif; ?>
  <img class="qr" src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&amp;data=<?=rawurlencode(payment_link_url($token))?>" alt="Ödeme QR kodu">
  <p class="muted" style="text-align:center">QR'ı okutarak bu sayfayı telefonunuzda açın.</p>
  <input class="url" readonly value="<?=htmlspecialchars(payment_link_url($token))?>">
<?php else: ?>
  <p class="er"><?=htmlspecialchars($error!==''?$error:'Ödeme bağlantısı bulunamadı veya süresi dolmuş.')?></p>
<?php endif; ?>
<p class="muted" style="margin-top:18px;font-size:12px">NEXUS TravelTech · Bu sayfa güvenli bir ödeme bağlantısıdır; kimliğinizi isteyen e-postalara itibar etmeyin.</p>
</div></body></html>
