<?php
declare(strict_types=1);
$active_module='agency_bookings';
require_once __DIR__.'/layout.php';
require_once __DIR__.'/../config/agency_bookings.php';
if(empty($_SESSION['supplier_csrf']))$_SESSION['supplier_csrf']=bin2hex(random_bytes(32));
$u=$supplier_user;
$pdo=db();
$message='';$error='';

try{
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['supplier_csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('Güvenlik doğrulaması geçersiz.');
  $id=(int)($_POST['request_id']??0);
  $decision=$_POST['decision']??'';
  $note=trim((string)($_POST['response_note']??''))?:null;
  if($id<1||!in_array($decision,['approved','rejected'],true))throw new RuntimeException('Geçersiz işlem.');
  $result=$decision==='approved'
    ? approve_agency_booking_request($id,(int)$u['supplier_id'],(int)$u['id'],$note)
    : reject_agency_booking_request($id,(int)$u['supplier_id'],(int)$u['id'],$note);
  $message=$result['message'];
}
}catch(Throwable $e){
  $error=$e->getMessage();
}

$q=$pdo->prepare("SELECT r.*,p.name property_name,rt.name room_name,a.company_name agency_name FROM agency_booking_requests r JOIN properties p ON p.id=r.property_id JOIN agencies a ON a.id=r.agency_id LEFT JOIN room_types rt ON rt.id=r.room_type_id WHERE r.supplier_id=? ORDER BY CASE WHEN r.status='pending' THEN 0 ELSE 1 END,r.id DESC");
$q->execute([$u['supplier_id']]);
$requests=$q->fetchAll();

supply_start('Acente rezervasyon talepleri',$active_module);?>
<section class="page-intro"><p>Acentelerin canlı müsaitlik ekranından gönderdiği rezervasyon talepleri. Onayladığınızda rezervasyon kesinleşir, misafir profili oluşturulur, mutabakat kaydı açılır ve kontenjanınızdan düşülür.</p></section>
<?php if($message):?><p class="save-success">✓ <?=htmlspecialchars($message)?></p><?php endif;?>
<?php if($error):?><p class="login-error"><?=htmlspecialchars($error)?></p><?php endif;?>
<?php if(!$requests):?><section class="next-module"><p>Henüz talep yok. Acente panelindeki canlı müsaitlik ekranı etkinleştiğinde talepler burada anlık görünür.</p></section><?php endif;?>
<?php foreach($requests as $r):?>
<section class="next-module"><article><h3><?=htmlspecialchars($r['property_name'])?> · <?=htmlspecialchars($r['room_name']?:'—')?> <span class="muted">(<?=htmlspecialchars($r['agency_name'])?>)</span></h3>
<p><b><?=htmlspecialchars($r['check_in'])?></b> → <b><?=htmlspecialchars($r['check_out'])?></b> · <?=(int)$r['nights']?> gece · <?=(int)$r['adults']?> yetişkin / <?=(int)$r['children']?> çocuk · <b><?=number_format((float)$r['total_amount'],2)?> <?=htmlspecialchars($r['currency'])?></b></p>
<p>Misafir: <?=htmlspecialchars($r['guest_first_name'].' '.$r['guest_last_name'])?> <?=htmlspecialchars($r['guest_email']??'')?> <?=htmlspecialchars($r['guest_phone']??'')?></p>
<?php if($r['agency_reference']):?><p class="muted">Acente referansı: <?=htmlspecialchars($r['agency_reference'])?></p><?php endif;?>
<?php if($r['note']):?><p class="muted">Not: <?=htmlspecialchars($r['note'])?></p><?php endif;?>
<?php if($r['status']==='pending'):?>
<div class="form-row">
<form method="post" class="supply-form" style="min-width:320px"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>"><input type="hidden" name="request_id" value="<?=$r['id']?>"><input name="response_note" placeholder="Acenteye not (ops.)"><button name="decision" value="approved">Onayla ve rezervasyonu kesinleştir</button></form>
<form method="post" class="supply-form" style="min-width:320px"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>"><input type="hidden" name="request_id" value="<?=$r['id']?>"><input name="response_note" placeholder="Red nedeni"><button name="decision" value="rejected">Reddet</button></form>
</div>
<?php else:?><p><strong><?=htmlspecialchars($r['status'])?></strong><?= $r['response_note']?' · '.htmlspecialchars($r['response_note']):''?> · <?=htmlspecialchars((string)$r['responded_at'])?></p><?php endif;?>
</article></section>
<?php endforeach;?>
<?php supply_end(); ?>
