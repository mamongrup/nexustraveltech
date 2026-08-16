<?php
declare(strict_types=1);
$active_module='hotel_daily';
require_once __DIR__.'/layout.php';
$u=$supplier_user;
$pdo=db();

$q=$pdo->prepare('SELECT id,name,city FROM properties WHERE supplier_id=? AND property_type=\'hotel\' ORDER BY name');
$q->execute([$u['supplier_id']]);
$hotels=$q->fetchAll();
$property=(int)($_GET['property']??($hotels[0]['id']??0));
$today=date('Y-m-d');
$tomorrow=date('Y-m-d',strtotime('+1 day'));
$weekEnd=date('Y-m-d',strtotime('+6 day'));

$arrivals=[];$departures=[];$inHouse=0;$tomorrowCount=0;$occupiedRooms=0;$totalUnits=0;$expectedRevenue=0.0;$forecast=[];$roomStatus=[];$openTasks=0;$paymentsToday=0.0;$chargesToday=0.0;$refundsToday=0.0;

if($property){
  $q=$pdo->prepare("SELECT b.booking_reference,b.check_in,b.check_out,b.total_amount,b.currency,b.booking_status,p.name property_name FROM supplier_bookings b JOIN properties p ON p.id=b.property_id WHERE b.property_id=? AND b.check_in=? AND b.status NOT IN ('cancelled','rejected') ORDER BY b.created_at DESC");
  $q->execute([$property,$today]);$arrivals=$q->fetchAll();
  $q=$pdo->prepare("SELECT b.booking_reference,b.check_in,b.check_out,b.total_amount,b.currency,b.booking_status,p.name property_name FROM supplier_bookings b JOIN properties p ON p.id=b.property_id WHERE b.property_id=? AND b.check_out=? AND b.status NOT IN ('cancelled','rejected') ORDER BY b.created_at DESC");
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
  $q=$pdo->prepare("SELECT COALESCE(SUM(ft.amount) FILTER (WHERE ft.transaction_type='payment'),0) payments,COALESCE(SUM(ft.amount) FILTER (WHERE ft.transaction_type IN ('room_charge','service_charge')),0) charges,COALESCE(SUM(ft.amount) FILTER (WHERE ft.transaction_type='refund'),0) refunds FROM folio_transactions ft JOIN booking_folios bf ON bf.id=ft.folio_id JOIN supplier_bookings b ON b.id=bf.booking_id WHERE b.property_id=? AND ft.transaction_at::date=?");
  $q->execute([$property,$today]);$row=$q->fetch();$paymentsToday=(float)($row['payments']??0);$chargesToday=(float)($row['charges']??0);$refundsToday=(float)($row['refunds']??0);
}
$occupancy=$totalUnits>0?round($occupiedRooms/$totalUnits*100):0;

supply_start('Günlük ön büro',$active_module);?>
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
<article class="panel"><div class="panel-title"><div><span>BUGÜN</span><h2>Gelen misafirler</h2></div></div><?php if(!$arrivals):?><p class="muted">Bugün gelen rezervasyon yok.</p><?php endif;?><?php foreach($arrivals as $b):?><div class="availability-row"><div><b><?=htmlspecialchars($b['booking_reference'])?></b><span><?=htmlspecialchars($b['property_name'])?></span></div><strong><?=number_format((float)$b['total_amount'],2)?> <?=htmlspecialchars($b['currency'])?></strong></div><?php endforeach;?></article>
<article class="panel"><div class="panel-title"><div><span>BUGÜN</span><h2>Çıkış yapacaklar</h2></div></div><?php if(!$departures):?><p class="muted">Bugün çıkış yok.</p><?php endif;?><?php foreach($departures as $b):?><div class="availability-row"><div><b><?=htmlspecialchars($b['booking_reference'])?></b><span><?=htmlspecialchars($b['property_name'])?></span></div><strong><?=htmlspecialchars($b['booking_status'])?></strong></div><?php endforeach;?></article>
<article class="panel"><div class="panel-title"><div><span>FORECAST</span><h2>Önümüzdeki 7 gün</h2></div></div><div class="availability-row"><div><b>Günlük gelen rezervasyon</b></div></div><?php foreach($forecast as $day=>$count):?><div class="availability-row"><div><b><?=htmlspecialchars($day)?></b></div><strong><?=$count?> misafir</strong></div><?php endforeach;?><?php if(!$forecast):?><p class="muted">Sonraki 7 gün için rezervasyon yok.</p><?php endif;?></article>
<article class="panel"><div class="panel-title"><div><span>KAT HİZMETLERİ</span><h2>Oda durumları</h2></div></div><?php foreach(['clean'=>'Temiz','dirty'=>'Kirli','inspected'=>'Kontrol edildi','out_of_order'=>'Arızalı','out_of_service'=>'Hizmet dışı','occupied'=>'Dolu'] as $code=>$label):?><div class="availability-row"><div><b><?=$label?></b></div><strong><?=(int)($roomStatus[$code]??0)?></strong></div><?php endforeach;?></article>
</section>
<?php supply_end(); ?>
