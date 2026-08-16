<?php
declare(strict_types=1);
$active_module='pay_links';
require_once __DIR__.'/layout.php';
require_once __DIR__.'/../config/payments.php';
if(empty($_SESSION['supplier_csrf']))$_SESSION['supplier_csrf']=bin2hex(random_bytes(32));
$u=$supplier_user;
$pdo=db();
$message='';$error='';

try{
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['supplier_csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('Güvenlik doğrulaması geçersiz.');
  if(($_POST['action']??'')==='create'){
    $bookingId=(int)($_POST['booking_id']??0);
    $amount=(float)str_replace(',','.',(string)($_POST['amount']??0));
    $currency=mb_strtoupper(trim((string)($_POST['currency']??'EUR')));
    $testMode=isset($_POST['test_mode']);
    $days=(int)($_POST['expires_days']??30);
    $q=$pdo->prepare('SELECT b.id FROM supplier_bookings b WHERE b.id=? AND b.supplier_id=? AND b.status NOT IN (\'cancelled\',\'rejected\')');
    $q->execute([$bookingId,$u['supplier_id']]);
    if(!$q->fetch())throw new RuntimeException('Rezervasyon bulunamadı.');
    $created=create_payment_link((int)$u['supplier_id'],$bookingId,$amount,$currency,$testMode,$days);
    $message='Ödeme linki oluşturuldu: <a href="'.htmlspecialchars($created['url']).'">'.htmlspecialchars($created['url']).'</a>';
  }
}
}catch(Throwable $e){$error=$e->getMessage();}

$q=$pdo->prepare("SELECT b.id,b.booking_reference,b.check_in,b.check_out,b.total_amount,b.currency,p.name property_name FROM supplier_bookings b JOIN properties p ON p.id=b.property_id WHERE b.supplier_id=? AND b.status NOT IN ('cancelled','rejected') AND NOT EXISTS (SELECT 1 FROM payment_links pl WHERE pl.booking_id=b.id AND pl.status='pending') ORDER BY b.check_in DESC LIMIT 50");
$q->execute([$u['supplier_id']]);
$eligible=$q->fetchAll();

$q=$pdo->prepare('SELECT pl.*,b.booking_reference,p.name property_name FROM payment_links pl LEFT JOIN supplier_bookings b ON b.id=pl.booking_id LEFT JOIN properties p ON p.id=b.property_id WHERE pl.supplier_id=? ORDER BY pl.id DESC LIMIT 100');
$q->execute([$u['supplier_id']]);
$links=$q->fetchAll();

supply_start('Ödeme linkleri',$active_module);?>
<section class="page-intro"><p>Rezervasyon başına token'lı güvenli ödeme bağlantısı üretin; misafir QR ile veya bağlantıyı açarak öder. Ödeme folyoya otomatik işlenir. Şu an test modundadır — ödeme geçidi sözleşmesi sonrası canlı kart tahsilatına geçer.</p></section>
<?php if($message):?><p class="save-success">✓ <?=$message?></p><?php endif;?>
<?php if($error):?><p class="login-error"><?=htmlspecialchars($error)?></p><?php endif;?>
<section class="next-module"><h2>Yeni ödeme linki</h2>
<?php if(!$eligible):?><p class="muted">Bekleyen ödeme linki olmayan rezervasyon yok.</p><?php endif;?>
<form method="post" class="supply-form" style="max-width:520px"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>"><input type="hidden" name="action" value="create">
<label>Rezervasyon<select name="booking_id" required><?php foreach($eligible as $b):?><option value="<?=$b['id']?>"><?=htmlspecialchars($b['property_name'].' · '.$b['booking_reference'].' · '.number_format((float)$b['total_amount'],2).' '.$b['currency'])?></option><?php endforeach;?></select></label>
<div class="form-row"><label>Tutar<input type="number" name="amount" step="0.01" min="0.01" required></label><label>Para birimi<select name="currency"><option>EUR</option><option>TRY</option><option>USD</option></select></label><label>Geçerlilik (gün)<select name="expires_days"><option value="7">7 gün</option><option value="30" selected>30 gün</option><option value="90">90 gün</option></select></label></div>
<label><input type="checkbox" name="test_mode" checked> Test modu (simüle tahsilat)</label>
<button>Ödeme linki oluştur</button></form></section>
<section class="next-module"><h2>Linkler</h2>
<?php if(!$links):?><p class="muted">Henüz ödeme linki oluşturulmadı.</p><?php endif;?>
<?php foreach($links as $l):?>
<article><div class="availability-row"><div><b><?=htmlspecialchars($l['property_name']??'—')?> · <?=htmlspecialchars($l['booking_reference']??'#'.$l['booking_id'])?></b><span><?=htmlspecialchars($l['status'])?><?= $l['test_mode']?' · TEST':''?> · oluşturma <?=htmlspecialchars((string)$l['created_at'])?><?= $l['paid_at']?' · ödendi '.htmlspecialchars((string)$l['paid_at']):''?></span></div><strong><?=number_format((float)$l['amount'],2)?> <?=htmlspecialchars($l['currency'])?></strong></div>
<?php if($l['status']==='pending'):?>
<div class="form-row"><img style="width:96px;height:96px;border:1px solid #e5e5e5" src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&amp;data=<?=rawurlencode(payment_link_url((string)$l['token']))?>" alt="QR"><input readonly value="<?=htmlspecialchars(payment_link_url((string)$l['token']))?>"></div>
<?php endif;?>
<?php if($l['payment_record_id']):?><p class="muted">Tahsilat kaydı #<?=(int)$l['payment_record_id']?></p><?php endif;?>
</article><?php endforeach;?>
</section>
<?php supply_end(); ?>
