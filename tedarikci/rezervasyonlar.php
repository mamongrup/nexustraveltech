<?php
declare(strict_types=1);
$active_module='bookings';
require_once __DIR__.'/layout.php';
require_once __DIR__.'/../config/agency_bookings.php';
require_once __DIR__.'/../config/payments.php';
$u=$supplier_user;
$pdo=db();
$source=trim((string)($_GET['source']??''));
$message='';$error='';

try{
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['supplier_csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('Güvenlik doğrulaması geçersiz.');
  if(($_POST['action']??'')==='cancel'){
    $id=(int)($_POST['booking_id']??0);
    $reason=trim((string)($_POST['cancel_reason']??''));
    if($id<1||$reason==='')throw new RuntimeException('İptal nedeni zorunludur.');
    $result=cancel_booking($id,(int)$u['supplier_id'],(int)$u['id'],$reason);
    $message=$result['message'];
  }
  if(($_POST['action']??'')==='paylink'){
    $id=(int)($_POST['booking_id']??0);
    $amount=(float)str_replace(',','.',(string)($_POST['amount']??0));
    if($id<1||$amount<=0)throw new RuntimeException('Geçersiz ödeme tutarı.');
    $created=create_payment_link((int)$u['supplier_id'],$id,$amount,'EUR',true,30);
    $message='Ödeme linki oluşturuldu: '.$created['url'];
  }
}
}catch(Throwable $e){$error=$e->getMessage();}

$sources=$pdo->prepare('SELECT DISTINCT source_code FROM supplier_bookings WHERE supplier_id=? ORDER BY source_code');
$sources->execute([$u['supplier_id']]);
$sourceList=$sources->fetchAll();

$q=$pdo->prepare("SELECT source_code,COUNT(*) total,COALESCE(SUM(total_amount) FILTER (WHERE status NOT IN ('cancelled','rejected')),0) revenue,COUNT(*) FILTER (WHERE status NOT IN ('cancelled','rejected')) active FROM supplier_bookings WHERE supplier_id=? GROUP BY source_code ORDER BY revenue DESC");
$q->execute([$u['supplier_id']]);
$summary=$q->fetchAll();

$sql='SELECT b.*,p.name property_name FROM supplier_bookings b LEFT JOIN properties p ON p.id=b.property_id WHERE b.supplier_id=?';
$params=[$u['supplier_id']];
if($source!==''){$sql.=' AND b.source_code=?';$params[]=$source;}
$sql.=' ORDER BY b.created_at DESC LIMIT 200';
$q=$pdo->prepare($sql);
$q->execute($params);
$rows=$q->fetchAll();

supply_start('Rezervasyonlar',$active_module);?>
<section class="page-intro"><p>Acente, API, kanal ve doğrudan satış kaynaklarından gelen rezervasyonları kaynak bazında filtreleyin; iptallerde kontenjan ve mutabakat otomatik geri iade edilir.</p></section>
<?php if($message):?><p class="save-success">✓ <?=htmlspecialchars($message)?></p><?php endif;?>
<?php if($error):?><p class="login-error"><?=htmlspecialchars($error)?></p><?php endif;?>
<section class="next-module"><h2>Kaynak bazlı özet</h2>
<div class="availability-row"><div><b>Kaynak</b></div><div><b>Rezervasyon</b></div><div><b>Gelir</b></div></div>
<?php if(!$summary):?><p class="muted">Henüz rezervasyon yok.</p><?php endif;?>
<?php foreach($summary as $s):?><div class="availability-row"><div><b><?=htmlspecialchars($s['source_code']?:'—')?></b><span><?=(int)$s['active']?> aktif</span></div><div><?=(int)$s['total']?></div><strong><?=number_format((float)$s['revenue'],2)?> EUR</strong></div><?php endforeach;?>
</section>
<section class="next-module"><h2>Rezervasyonlar</h2>
<form method="get" class="supply-form" style="max-width:360px"><label>Kaynak<select name="source"><option value="">Tümü</option><?php foreach($sourceList as $s):?><option value="<?=htmlspecialchars($s['source_code'])?>" <?=$source===$s['source_code']?'selected':''?>><?=htmlspecialchars($s['source_code']?:'—')?></option><?php endforeach;?></select></label><button>Filtrele</button></form>
<?php if(!$rows):?><p class="muted">Bu kaynakta rezervasyon yok.</p><?php endif;?>
<?php foreach($rows as $b):$cancellable=!in_array($b['status'],['cancelled','rejected'],true)&&!in_array($b['booking_status'],['cancelled','no_show','checked_out'],true);?>
<article><div class="availability-row"><div><b><?=htmlspecialchars($b['booking_reference'])?></b> · <?=htmlspecialchars($b['property_name']?:'Ürün')?><span><?=htmlspecialchars($b['check_in']?:'—')?> / <?=htmlspecialchars($b['check_out']?:'—')?> · <?=number_format((float)$b['total_amount'],2)?> <?=htmlspecialchars($b['currency'])?> · kaynak: <?=htmlspecialchars($b['source_code']?:'—')?></span></div>
<strong><?=htmlspecialchars($b['status'])?><?= $b['booking_status']?' / '.htmlspecialchars($b['booking_status']):''?></strong></div>
<?php if($cancellable):?><form method="post" class="supply-form" style="max-width:480px"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="booking_id" value="<?=$b['id']?>"><div class="form-row"><input name="cancel_reason" placeholder="İptal nedeni (misafir, acente, kanal…)" required><button style="background:#8e2410">İptal et</button></div></form><?php endif;?>
<?php if($b['status']==='confirmed'||$b['booking_status']==='reserved'):?><form method="post" class="supply-form" style="max-width:480px"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>"><input type="hidden" name="action" value="paylink"><input type="hidden" name="booking_id" value="<?=$b['id']?>"><div class="form-row"><input name="amount" type="number" step="0.01" min="0.01" value="<?=htmlspecialchars((string)$b['total_amount'])?>" required><button style="background:#0d7a4a">Ödeme linki oluştur</button></div></form><?php endif;?>
<?php if($b['cancellation_reason']):?><p class="muted">İptal nedeni: <?=htmlspecialchars($b['cancellation_reason'])?></p><?php endif;?>
</article>
<?php endforeach;?>
</section>
<?php supply_end(); ?>
