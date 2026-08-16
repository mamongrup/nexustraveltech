<?php
declare(strict_types=1);
$active_module='hotel_daily';
require_once __DIR__.'/layout.php';
$u=$supplier_user;
$pdo=db();
$message='';$error='';
if(empty($_SESSION['supplier_csrf']))$_SESSION['supplier_csrf']=bin2hex(random_bytes(32));

if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['supplier_csrf'],(string)($_POST['csrf']??''))){$error='Güvenlik doğrulaması geçersiz.';}
  else try{
    if(($_POST['action']??'')==='assign_checkin'){
      $bid=(int)($_POST['booking_id']??0);
      $roomId=(int)($_POST['physical_room_id']??0);
      $propId=(int)($_POST['property_id']??0);
      $q=$pdo->prepare("SELECT b.*,br.id booking_room_id,br.room_type_id FROM supplier_bookings b LEFT JOIN booking_rooms br ON br.booking_id=b.id WHERE b.id=? AND b.property_id=? AND b.status NOT IN ('cancelled','rejected') AND b.booking_status NOT IN ('cancelled','no_show','checked_out') LIMIT 1");
      $q->execute([$bid,$propId]);
      $b=$q->fetch();
      if(!$b)throw new RuntimeException('Rezervasyon bulunamadı.');
      $conflict=$pdo->prepare("SELECT 1 FROM booking_rooms br JOIN supplier_bookings sb ON sb.id=br.booking_id WHERE br.physical_room_id=? AND br.id IS DISTINCT FROM ? AND sb.booking_status NOT IN ('cancelled','no_show','checked_out') AND sb.check_in<? AND sb.check_out>? LIMIT 1");
      $conflict->execute([$roomId,$b['booking_room_id']?:0,$b['check_out'],$b['check_in']]);
      if($conflict->fetch())throw new RuntimeException('Seçilen oda bu tarihlerde dolu.');
      $pdo->beginTransaction();
      if($b['booking_room_id']){
        $pdo->prepare('UPDATE booking_rooms SET physical_room_id=? WHERE id=?')->execute([$roomId,$b['booking_room_id']]);
      }else{
        $nights=max(1,(int)((strtotime($b['check_out'])-strtotime($b['check_in']))/86400));
        $nightly=round((float)$b['total_amount']/$nights,2);
        $pdo->prepare("INSERT INTO booking_rooms(booking_id,room_type_id,physical_room_id,adults,children,nightly_rate,currency) VALUES(?,?,?,2,0,?,?)")->execute([$bid,$b['room_type_id']?:null,$roomId,$nightly,$b['currency']]);
      }
      $pdo->prepare("UPDATE supplier_bookings SET booking_status='checked_in',status=CASE WHEN status IN ('reserved','confirmed') THEN 'confirmed' ELSE status END,checked_in_at=now() WHERE id=?")->execute([$bid]);
      $pdo->prepare("UPDATE booking_guests SET checkin_status='checked_in' WHERE booking_id=? AND checkin_status<>'checked_in'")->execute([$bid]);
      $pdo->prepare("UPDATE physical_rooms SET status='occupied' WHERE id=?")->execute([$roomId]);
      $pdo->commit();
      $message='Oda atandı ve check-in yapıldı.';
    }
    if(($_POST['action']??'')==='checkout'){
      $bid=(int)($_POST['booking_id']??0);
      $propId=(int)($_POST['property_id']??0);
      $q=$pdo->prepare("SELECT * FROM supplier_bookings WHERE id=? AND property_id=? AND status NOT IN ('cancelled','rejected') AND booking_status IN ('checked_in','reserved','confirmed') FOR UPDATE");
      $q->execute([$bid,$propId]);
      $b=$q->fetch();
      if(!$b)throw new RuntimeException('Rezervasyon bulunamadı veya çıkışa uygun değil.');
      $fq=$pdo->prepare("SELECT id FROM booking_folios WHERE booking_id=? AND status<>'closed' ORDER BY id LIMIT 1");
      $fq->execute([$bid]);
      $folioId=$fq->fetchColumn();
      if($folioId){
        $bq=$pdo->prepare("SELECT COALESCE(SUM(amount) FILTER (WHERE transaction_type IN ('room_charge','service_charge','adjustment','tax')),0)+COALESCE(SUM(amount) FILTER (WHERE transaction_type='refund'),0)+COALESCE(SUM(amount) FILTER (WHERE transaction_type='payment'),0) FROM folio_transactions WHERE folio_id=?");
        $bq->execute([$folioId]);
        $balance=(float)$bq->fetchColumn();
        if($balance>0.01)throw new RuntimeException('Folyoda '.number_format($balance,2).' '.$b['currency'].' tahsil edilmemiş bakiye var — önce tahsilatı tamamlayın.');
      }
      $pdo->beginTransaction();
      $pdo->prepare("UPDATE supplier_bookings SET booking_status='checked_out',checked_out_at=now() WHERE id=?")->execute([$bid]);
      $pdo->prepare("UPDATE booking_rooms SET status='checked_out' WHERE booking_id=? AND status IN ('reserved','checked_in')")->execute([$bid]);
      $rooms=$pdo->prepare('SELECT id FROM booking_rooms WHERE booking_id=? AND physical_room_id IS NOT NULL');
      $rooms->execute([$bid]);
      foreach($rooms->fetchAll() as $r){
        $pdo->prepare("UPDATE physical_rooms SET status='dirty' WHERE id=? AND status='occupied'")->execute([$r['id']]);
        $pdo->prepare("INSERT INTO housekeeping_tasks(property_id,physical_room_id,task_type,status,notes) VALUES(?,?,'cleaning','open',?)")->execute([$propId,$r['id'],'Check-out sonrası temizlik — '.$b['booking_reference']]);
      }
      $pdo->commit();
      require_once __DIR__.'/../config/loyalty.php';
      $award=award_loyalty_points($bid);
      try{
        $aq=$pdo->prepare('SELECT agency_id FROM agency_booking_requests WHERE booking_id=? LIMIT 1');
        $aq->execute([$bid]);
        $agencyId=$aq->fetchColumn();
        if($agencyId){require_once __DIR__.'/../config/notifications.php';notify_user('agency',(int)$agencyId,'booking.checked_out','Check-out tamamlandı: '.$b['booking_reference'],'/nexustraveltech/acente/');}
      }catch(Throwable $e){}
      $message='Check-out tamamlandı; odalar kat hizmetlerine düştü'.($award['ok']?' ve '.$award['points'].' sadakat puanı kazandırıldı.':'.');
    }
    if(($_POST['action']??'')==='no_show'){
      $bid=(int)($_POST['booking_id']??0);
      $propId=(int)($_POST['property_id']??0);
      $q=$pdo->prepare("SELECT * FROM supplier_bookings WHERE id=? AND property_id=? AND status NOT IN ('cancelled','rejected') AND booking_status IN ('reserved','confirmed') AND check_in<? FOR UPDATE");
      $q->execute([$bid,$propId,date('Y-m-d')]);
      $b=$q->fetch();
      if(!$b)throw new RuntimeException('No-show yalnızca bugünden önce girişi olan ve check-in yapılmamış rezervasyonlara işlenebilir.');
      $pdo->beginTransaction();
      $pdo->prepare("UPDATE supplier_bookings SET booking_status='no_show',no_show_at=now() WHERE id=?")->execute([$bid]);
      $pdo->prepare("UPDATE booking_rooms SET status='no_show' WHERE booking_id=? AND status='reserved'")->execute([$bid]);
      if($b['check_in']&&$b['check_out']){
        if($b['rate_plan_id']){
          $pdo->prepare('UPDATE inventory_calendar i SET sold=GREATEST(0,i.sold-1) WHERE i.room_type_id IN (SELECT room_type_id FROM booking_rooms WHERE booking_id=?) AND i.rate_plan_id=? AND i.stay_date>=? AND i.stay_date<? AND i.sold>0')->execute([$bid,$b['rate_plan_id'],$b['check_in'],$b['check_out']]);
        }else{
          $pdo->prepare('UPDATE inventory_calendar i SET sold=GREATEST(0,i.sold-1) WHERE i.room_type_id IN (SELECT room_type_id FROM booking_rooms WHERE booking_id=?) AND i.stay_date>=? AND i.stay_date<? AND i.sold>0')->execute([$bid,$b['check_in'],$b['check_out']]);
        }
      }
      $pdo->commit();
      $message='No-show işlendi; kontenjan serbest bırakıldı.';
    }
    if(($_POST['action']??'')==='toggle_flag'){
      $bid=(int)($_POST['booking_id']??0);
      $flag=$_POST['flag']??'';
      if(!in_array($flag,['early_arrival','late_departure'],true))throw new RuntimeException('Geçersiz işaret.');
      $q=$pdo->prepare('SELECT id FROM supplier_bookings WHERE id=? AND property_id=?');
      $q->execute([$bid,(int)($_POST['property_id']??0)]);
      if(!$q->fetch())throw new RuntimeException('Rezervasyon bulunamadı.');
      $pdo->prepare("UPDATE supplier_bookings SET $flag=NOT $flag WHERE id=?")->execute([$bid]);
      $message='Giriş/çıkış işareti güncellendi.';
    }
  }catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
}

$q=$pdo->prepare('SELECT id,name,city FROM properties WHERE supplier_id=? AND property_type=\'hotel\' ORDER BY name');
$q->execute([$u['supplier_id']]);
$hotels=$q->fetchAll();
$property=(int)($_GET['property']??($hotels[0]['id']??0));
$today=date('Y-m-d');
$tomorrow=date('Y-m-d',strtotime('+1 day'));
$weekEnd=date('Y-m-d',strtotime('+6 day'));

$arrivals=[];$departures=[];$inHouse=0;$tomorrowCount=0;$occupiedRooms=0;$totalUnits=0;$expectedRevenue=0.0;$forecast=[];$roomStatus=[];$openTasks=0;$paymentsToday=0.0;$chargesToday=0.0;$refundsToday=0.0;

if($property){
  $q=$pdo->prepare("SELECT b.booking_reference,b.check_in,b.check_out,b.total_amount,b.currency,b.booking_status,b.id booking_id,b.early_arrival,b.deposit_amount,b.deposit_status,p.name property_name,gp.first_name,gp.last_name,(SELECT br.id FROM booking_rooms br WHERE br.booking_id=b.id ORDER BY br.id LIMIT 1) booking_room_id,(SELECT br.physical_room_id FROM booking_rooms br WHERE br.booking_id=b.id ORDER BY br.id LIMIT 1) physical_room_id,(SELECT br.room_type_id FROM booking_rooms br WHERE br.booking_id=b.id ORDER BY br.id LIMIT 1) room_type_id,(SELECT bf.id FROM booking_folios bf WHERE bf.booking_id=b.id ORDER BY bf.id LIMIT 1) folio_id FROM supplier_bookings b JOIN properties p ON p.id=b.property_id LEFT JOIN booking_guests bg ON bg.booking_id=b.id AND bg.is_primary=true LEFT JOIN guest_profiles gp ON gp.id=bg.guest_id WHERE b.property_id=? AND b.check_in=? AND b.status NOT IN ('cancelled','rejected') ORDER BY b.created_at DESC");
  $q->execute([$property,$today]);$arrivals=$q->fetchAll();
  $q=$pdo->prepare("SELECT b.id booking_id,b.booking_reference,b.check_in,b.check_out,b.total_amount,b.currency,b.booking_status,b.early_arrival,b.late_departure,b.deposit_amount,b.deposit_status,p.name property_name,gp.first_name,gp.last_name,(SELECT bf.id FROM booking_folios bf WHERE bf.booking_id=b.id ORDER BY bf.id LIMIT 1) folio_id FROM supplier_bookings b JOIN properties p ON p.id=b.property_id LEFT JOIN booking_guests bg ON bg.booking_id=b.id AND bg.is_primary=true LEFT JOIN guest_profiles gp ON gp.id=bg.guest_id WHERE b.property_id=? AND b.check_out=? AND b.status NOT IN ('cancelled','rejected') ORDER BY b.created_at DESC");
  $q->execute([$property,$today]);$departures=$q->fetchAll();
  $q=$pdo->prepare("SELECT COUNT(*) FROM supplier_bookings WHERE property_id=? AND booking_status='checked_in'");
  $q->execute([$property]);$inHouse=(int)$q->fetchColumn();
  $q=$pdo->prepare("SELECT COUNT(*) FROM supplier_bookings WHERE property_id=? AND check_in=? AND status NOT IN ('cancelled','rejected')");
  $q->execute([$property,$tomorrow]);$tomorrowCount=(int)$q->fetchColumn();
  $q=$pdo->prepare("SELECT COUNT(DISTINCT br.id) FROM booking_rooms br JOIN supplier_bookings b ON b.id=br.booking_id WHERE b.property_id=? AND b.check_in<=? AND b.check_out>? AND b.status NOT IN ('cancelled','rejected') AND br.status NOT IN ('cancelled','no_show')");
  $q->execute([$property,$today,$today]);$occupiedRooms=(int)$q->fetchColumn();
  $q=$pdo->prepare('SELECT COALESCE(SUM(total_units),0) FROM room_types WHERE property_id=?');
  $q->execute([$property]);$totalUnits=(int)$q->fetchColumn();
  $q=$pdo->prepare("SELECT COALESCE(SUM(b.total_amount),0) FROM supplier_bookings b WHERE b.property_id=? AND b.status NOT IN ('cancelled','rejected') AND b.check_in<=? AND b.check_out>?");
  $q->execute([$property,$weekEnd,$today]);$expectedRevenue=(float)$q->fetchColumn();
  $q=$pdo->prepare("SELECT check_in,COUNT(*) c FROM supplier_bookings WHERE property_id=? AND check_in BETWEEN ? AND ? AND status NOT IN ('cancelled','rejected') GROUP BY check_in ORDER BY check_in");
  $q->execute([$property,$today,$weekEnd]);
  foreach($q->fetchAll() as $row)$forecast[$row['check_in']]=(int)$row['c'];
  $q=$pdo->prepare('SELECT status,COUNT(*) c FROM physical_rooms WHERE property_id=? GROUP BY status');
  $q->execute([$property]);
  foreach($q->fetchAll() as $row)$roomStatus[$row['status']]=(int)$row['c'];
  $q=$pdo->prepare("SELECT (SELECT COUNT(*) FROM housekeeping_tasks WHERE property_id=? AND status IN ('open','assigned','in_progress'))+(SELECT COUNT(*) FROM maintenance_tickets WHERE property_id=? AND status IN ('open','assigned','in_progress'))+(SELECT COUNT(*) FROM guest_service_requests WHERE booking_id IN (SELECT id FROM supplier_bookings WHERE property_id=?))");
  $q->execute([$property,$property,$property]);$openTasks=(int)$q->fetchColumn();
  $q=$pdo->prepare("SELECT COALESCE(-SUM(ft.amount) FILTER (WHERE ft.transaction_type='payment'),0) payments,COALESCE(SUM(ft.amount) FILTER (WHERE ft.transaction_type IN ('room_charge','service_charge')),0) charges,COALESCE(SUM(ft.amount) FILTER (WHERE ft.transaction_type='refund'),0) refunds FROM folio_transactions ft JOIN booking_folios bf ON bf.id=ft.folio_id JOIN supplier_bookings b ON b.id=bf.booking_id WHERE b.property_id=? AND ft.transaction_at::date=?");
  $q->execute([$property,$today]);$row=$q->fetch();$paymentsToday=(float)($row['payments']??0);$chargesToday=(float)($row['charges']??0);$refundsToday=(float)($row['refunds']??0);
}
$occupancy=$totalUnits>0?round($occupiedRooms/$totalUnits*100):0;

supply_start('Günlük ön büro',$active_module);?>
<?php if($message):?><p class="save-success">✓ <?=htmlspecialchars($message)?></p><?php endif;?>
<?php if($error):?><p class="login-error"><?=htmlspecialchars($error)?></p><?php endif;?>
<style>@media print{.side,.supply-top,.supply-form,button{display:none!important}.supply-main{padding:0!important;margin:0!important}.stats,.panel{break-inside:avoid}}</style>
<section class="page-intro"><p>Bugünün gelen/çıkan misafiri, doluluk, beklenen gelir ve açık görevler tek ekranda — ön büro vardiya başı için günlük görünüm.</p><button onclick="window.print()" style="width:max-content">Yazdır / vardiya raporu</button></section>
<?php if(!$hotels):?><section class="next-module"><p>Henüz otel ürününüz yok. Önce <a href="/nexustraveltech/tedarikci/tesisler">tesisler</a> sayfasından bir otel oluşturun.</p></section><?php supply_end(); return; endif;?>
<form method="get" class="supply-form" style="max-width:360px"><label>Otel<select name="property"><?php foreach($hotels as $h):?><option value="<?=$h['id']?>" <?=$h['id']===$property?'selected':''?>><?=htmlspecialchars($h['name'].' · '.$h['city'])?></option><?php endforeach;?></select></label><button>Görüntüle</button></form>
<section class="stats">
<article><span>BUGÜN GELEN</span><b><?=count($arrivals)?></b><small>Arrivals</small></article>
<article><span>BUGÜN ÇIKAN</span><b><?=count($departures)?></b><small>Departures</small></article>
<article><span>KONAKLAYAN</span><b><?=$inHouse?></b><small>In-house</small></article>
<article><span>DOLULUK</span><b>%<?=$occupancy?></b><small><?=$occupiedRooms?> / <?=$totalUnits?> oda</small></article>
<article class="accent"><span>YARIN GELEN</span><b><?=$tomorrowCount?></b><small>Forecast</small></article>
<article><span>7 GÜNLÜK GELİR</span><b><?=number_format($expectedRevenue,2)?> €</b><small>Beklenen</small></article>
<article><span>AÇIK GÖREV</span><b><?=$openTasks?></b><small>Servis & bakım</small></article>
</section>
<section class="stats">
<article><span>BUGÜN TAHSİLAT</span><b><?=number_format($paymentsToday,2)?> €</b><small>Ödeme</small></article>
<article><span>TAHAKKUK</span><b><?=number_format($chargesToday,2)?> €</b><small>Oda + servis</small></article>
<article><span>İADE</span><b><?=number_format($refundsToday,2)?> €</b><small>Refund</small></article>
<article class="accent"><span>NET TAHSILAT</span><b><?=number_format($paymentsToday-$refundsToday,2)?> €</b><small>Bugün</small></article>
</section>
<section class="dashboard-grid">
<article class="panel"><div class="panel-title"><div><span>BUGÜN</span><h2>Gelen misafirler</h2></div></div><?php if(!$arrivals):?><p class="muted">Bugün gelen rezervasyon yok.</p><?php endif;?><?php foreach($arrivals as $b):$checked=$b['booking_status']==='checked_in';$freeRooms=[];if(!$checked&&$b['booking_id']){$rq=$pdo->prepare("SELECT pr.id,pr.room_number FROM physical_rooms pr WHERE pr.property_id=? AND pr.status IN ('clean','dirty','inspected') AND (pr.room_type_id=? OR ? IS NULL) AND NOT EXISTS (SELECT 1 FROM booking_rooms br JOIN supplier_bookings sb ON sb.id=br.booking_id WHERE br.physical_room_id=pr.id AND sb.booking_status NOT IN ('cancelled','no_show','checked_out') AND sb.check_in<? AND sb.check_out>?) ORDER BY pr.room_number");$rq->execute([$property,$b['room_type_id'],$b['room_type_id'],$b['check_out'],$b['check_in']]);$freeRooms=$rq->fetchAll();}?><article style="border-bottom:1px solid #eef1ec;padding:8px 0"><div class="availability-row"><div><b><?=htmlspecialchars($b['booking_reference'])?> · <?=htmlspecialchars(trim(($b['first_name']??'').' '.($b['last_name']??'')))?></b><span><?=htmlspecialchars($b['property_name'])?><?= $checked?' · ✓ check-in yapıldı':''?><?= $b['physical_room_id']?' · oda #'.(int)$b['physical_room_id']:''?></span></div><strong><?=number_format((float)$b['total_amount'],2)?> <?=htmlspecialchars($b['currency'])?></strong></div>
<?php if(!$checked):?>
<form method="post" class="supply-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>"><input type="hidden" name="action" value="assign_checkin"><input type="hidden" name="property_id" value="<?=$property?>"><input type="hidden" name="booking_id" value="<?=$b['booking_id']?>"><div class="form-row"><?php if(!$freeRooms):?><span class="muted">Uygun oda yok (dolu veya temiz değil).</span><?php else:?><select name="physical_room_id" required><option value="">— Oda seç —</option><?php foreach($freeRooms as $r):?><option value="<?=$r['id']?>"><?=htmlspecialchars($r['room_number'])?></option><?php endforeach;?></select><button style="background:#0d7a4a">Odayı ata &amp; check-in</button><?php endif;?></div></form>
<?php endif;?>
<?php if(!$checked&&$b['deposit_status']==='due'):?><p class="login-error" style="margin:4px 0">Depozito bekleniyor: <?=number_format((float)$b['deposit_amount'],2)?> <?=htmlspecialchars($b['currency'])?></p><?php elseif($b['deposit_status']==='paid'):?><p class="muted" style="margin:4px 0">Depozito alındı ✓</p><?php endif;?>
<?php if(!$checked):?><form method="post" class="supply-form" style="max-width:480px"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>"><input type="hidden" name="action" value="toggle_flag"><input type="hidden" name="property_id" value="<?=$property?>"><input type="hidden" name="booking_id" value="<?=$b['booking_id']?>"><input type="hidden" name="flag" value="early_arrival"><button style="background:#a86026;margin:0"><?=$b['early_arrival']?'Erken giriş ✓ (kaldır)':'Erken giriş iste'?></button></form><?php endif;?>
<?php if(!$checked&&$b['check_in']<date('Y-m-d')):?><form method="post" class="supply-form" style="max-width:480px" onsubmit="return confirm('Misafir gelmedi olarak işaretlensin mi?')"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>"><input type="hidden" name="action" value="no_show"><input type="hidden" name="property_id" value="<?=$property?>"><input type="hidden" name="booking_id" value="<?=$b['booking_id']?>"><button style="background:#8e2410;margin:0">No-show işaretle</button></form><?php endif;?>
<div class="form-row"><a href="folio.php?booking=<?=(int)$b['booking_id']?>">Folyo →</a></div></article><?php endforeach;?></article>
<article class="panel"><div class="panel-title"><div><span>BUGÜN</span><h2>Çıkış yapacaklar</h2></div></div><?php if(!$departures):?><p class="muted">Bugün çıkış yok.</p><?php endif;?><?php foreach($departures as $b):$out=in_array($b['booking_status'],['checked_out','no_show','cancelled'],true);?><article style="border-bottom:1px solid #eef1ec;padding:8px 0"><div class="availability-row"><div><b><?=htmlspecialchars($b['booking_reference'])?> · <?=htmlspecialchars(trim(($b['first_name']??'').' '.($b['last_name']??'')))?></b><span><?=htmlspecialchars($b['property_name'])?> · <?=htmlspecialchars($b['booking_status'])?><?= $b['late_departure']?' · geç çıkış':''?></span></div><strong><?=number_format((float)$b['total_amount'],2)?> <?=htmlspecialchars($b['currency'])?></strong></div>
<?php if($b['deposit_status']==='due'):?><p class="login-error" style="margin:4px 0">Depozito bekleniyor: <?=number_format((float)$b['deposit_amount'],2)?> <?=htmlspecialchars($b['currency'])?></p><?php endif;?>
<?php if(!$out):?><form method="post" class="supply-form" style="max-width:480px"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>"><input type="hidden" name="action" value="toggle_flag"><input type="hidden" name="property_id" value="<?=$property?>"><input type="hidden" name="booking_id" value="<?=$b['booking_id']?>"><input type="hidden" name="flag" value="late_departure"><button style="background:#a86026;margin:0"><?=$b['late_departure']?'Geç çıkış ✓ (kaldır)':'Geç çıkış iste'?></button></form>
<form method="post" class="supply-form" style="max-width:480px" onsubmit="return confirm('Check-out onaylansın mı? Folyo bakiyesi sıfır olmalı.')"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>"><input type="hidden" name="action" value="checkout"><input type="hidden" name="property_id" value="<?=$property?>"><input type="hidden" name="booking_id" value="<?=$b['booking_id']?>"><button style="background:#0d7a4a;margin:0">Check-out yap</button></form><?php endif;?>
<div class="form-row"><a href="folio.php?<?=$b['folio_id']?'id='.(int)$b['folio_id']:'booking='.(int)$b['booking_id']?>">Folyo →</a></div></article><?php endforeach;?></article>
<article class="panel"><div class="panel-title"><div><span>FORECAST</span><h2>Önümüzdeki 7 gün</h2></div></div><div class="availability-row"><div><b>Günlük gelen rezervasyon</b></div></div><?php foreach($forecast as $day=>$count):?><div class="availability-row"><div><b><?=htmlspecialchars($day)?></b></div><strong><?=$count?> misafir</strong></div><?php endforeach;?><?php if(!$forecast):?><p class="muted">Sonraki 7 gün için rezervasyon yok.</p><?php endif;?></article>
<article class="panel"><div class="panel-title"><div><span>KAT HİZMETLERİ</span><h2>Oda durumları</h2></div></div><?php foreach(['clean'=>'Temiz','dirty'=>'Kirli','inspected'=>'Kontrol edildi','out_of_order'=>'Arızalı','out_of_service'=>'Hizmet dışı','occupied'=>'Dolu'] as $code=>$label):?><div class="availability-row"><div><b><?=$label?></b></div><strong><?=(int)($roomStatus[$code]??0)?></strong></div><?php endforeach;?></article>
</section>
<?php supply_end(); ?>
