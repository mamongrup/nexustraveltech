<?php
declare(strict_types=1);
$active_module='calendar';
require_once __DIR__.'/layout.php';
$u=$supplier_user;
$pdo=db();

$hotels=$pdo->prepare("SELECT id,name,city FROM properties WHERE supplier_id=? AND property_type='hotel' ORDER BY name");
$hotels->execute([$u['supplier_id']]);
$hotels=$hotels->fetchAll();
$property=(int)($_GET['property']??($hotels[0]['id']??0));
$start=date('Y-m-d');
$end=date('Y-m-d',strtotime('+29 days'));
$day=(string)($_GET['day']??'');

$rooms=[];$bookings=[];$dayDetails=[];
if($property){
  $q=$pdo->prepare("SELECT id,name FROM room_types WHERE property_id=? AND status='active' ORDER BY name");
  $q->execute([$property]);
  $rooms=$q->fetchAll();
  $q=$pdo->prepare("SELECT b.id,b.booking_reference,b.check_in,b.check_out,b.total_amount,b.currency,b.status,b.booking_status,gp.first_name,gp.last_name,br.room_type_id FROM supplier_bookings b JOIN booking_rooms br ON br.booking_id=b.id LEFT JOIN booking_guests bg ON bg.booking_id=b.id AND bg.is_primary=true LEFT JOIN guest_profiles gp ON gp.id=bg.guest_id WHERE b.property_id=? AND b.status NOT IN ('cancelled','rejected') AND b.booking_status NOT IN ('cancelled','no_show','checked_out') AND b.check_in<? AND b.check_out>? ORDER BY b.check_in,b.id");
  $q->execute([$property,$end,$start]);
  $bookings=$q->fetchAll();
  if($day!==''&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$day)){
    foreach($bookings as $b){if($b['check_in']<=$day&&$b['check_out']>$day)$dayDetails[]=$b;}
  }
}
$cellW=26;
$days=30;
$weekdays=['Pzt','Sal','Çar','Per','Cum','Cmt','Paz'];
supply_start('Rezervasyon takvimi',$active_module);?>
<section class="page-intro"><p>Oda tipi bazında önümüzdeki 30 günün doluluk haritası. Bloklar rezervasyonlardır; gün sütununa tıklayınca o günün rezervasyonları aşağıda listelenir.</p></section>
<?php if(!$hotels):?><section class="next-module"><p>Henüz otel ürününüz yok. Önce <a href="/nexustraveltech/tedarikci/tesisler">tesisler</a> sayfasından bir otel oluşturun.</p></section><?php supply_end(); return; endif;?>
<form method="get" class="supply-form" style="max-width:420px"><label>Otel<select name="property" onchange="this.form.submit()"><?php foreach($hotels as $h):?><option value="<?=$h['id']?>" <?=$h['id']===$property?'selected':''?>><?=htmlspecialchars($h['name'].' · '.$h['city'])?></option><?php endforeach;?></select></label><label>Gün detayı<input type="date" name="day" value="<?=htmlspecialchars($day)?>"></label><button>Görüntüle</button></form>
<?php if(!$rooms):?><section class="next-module"><p>Bu otel için oda tipi tanımlı değil.</p></section><?php supply_end(); return; endif;?>
<section class="next-module"><h2>Doluluk — <?=htmlspecialchars($start)?> / <?=htmlspecialchars($end)?></h2>
<div style="overflow-x:auto;padding-bottom:8px"><div style="min-width:<?=($cellW*$days+150)?>px">
<div class="availability-row" style="display:flex;align-items:center"><b style="width:150px;flex:none">Tarih</b><?php for($i=0;$i<$days;$i++):$d=date('Y-m-d',strtotime("+$i day"));?><div style="width:<?=$cellW?>px;flex:none;text-align:center;font-size:11px;line-height:1.3"><a href="rezervasyon-takvimi?property=<?=$property?>&amp;day=<?=$d?>" style="color:#10211f;text-decoration:none"><?=htmlspecialchars($weekdays[date('N',strtotime($d))-1])?><br><b><?=date('j',strtotime($d))?></b></a></div><?php endfor;?></div>
<?php foreach($rooms as $rt):?>
<div style="display:flex;align-items:center;border-top:1px solid #eef1ec;padding:3px 0"><b style="width:150px;flex:none;font-size:13px"><?=htmlspecialchars($rt['name'])?></b><div style="position:relative;width:<?=($cellW*$days)?>px;height:26px;flex:none">
<?php foreach($bookings as $b):if((int)$b['room_type_id']!==(int)$rt['id'])continue;
$bIn=$b['check_in'];$bOut=$b['check_out'];
if($bOut<=$start||$bIn>=$end)continue;
$from=$bIn<$start?$start:$bIn;$to=$bOut>$end?$end:$bOut;
$left=(int)((strtotime($from)-strtotime($start))/86400)*$cellW;
$w=max($cellW,(int)((strtotime($to)-strtotime($from))/86400)*$cellW);
$color=$b['booking_status']==='checked_in'?'#2e6da4':($b['status']==='confirmed'?'#0d7a4a':($b['status']==='reserved'?'#c99a2e':'#64716d'));
?><a href="rezervasyon-takvimi?property=<?=$property?>&amp;day=<?=htmlspecialchars($bIn)?>" title="<?=htmlspecialchars($b['booking_reference'].' · '.trim(($b['first_name']??'').' '.($b['last_name']??'')).' · '.$bIn.'/'.$bOut.' · '.number_format((float)$b['total_amount'],2).' '.$b['currency'].' · '.$b['status'])?>" style="position:absolute;left:<?=$left?>px;width:<?=$w?>px;height:22px;top:2px;background:<?=$color?>;border-radius:4px;color:#fff;font-size:10px;line-height:22px;padding:0 4px;box-sizing:border-box;overflow:hidden;white-space:nowrap;text-decoration:none"><?=htmlspecialchars($b['booking_reference'])?></a>
<?php endforeach;?></div></div>
<?php endforeach;?>
<div style="display:flex;align-items:center;border-top:2px solid #10211f;padding:3px 0"><b style="width:150px;flex:none;font-size:13px">Dolu oda</b><?php for($i=0;$i<$days;$i++):$d=date('Y-m-d',strtotime("+$i day"));$c=0;foreach($bookings as $b){if($b['check_in']<=$d&&$b['check_out']>$d)$c++;}?><div style="width:<?=$cellW?>px;flex:none;text-align:center;font-size:11px;font-weight:700;color:<?=$c>0?'#8e2410':'#64716d'?>"><?=$c?></div><?php endfor;?></div>
</div></div>
<p class="muted">Renkler: <span style="background:#0d7a4a;color:#fff;padding:1px 6px">onaylı</span> <span style="background:#c99a2e;color:#fff;padding:1px 6px">beklemede</span> <span style="background:#2e6da4;color:#fff;padding:1px 6px">check-in yapıldı</span></p>
</section>
<section class="next-module"><h2><?=htmlspecialchars($day!==''?$day:'Seçili gün')?> — rezervasyonlar</h2>
<?php if($day===''):?><p class="muted">Gün detayı için takvimde bir tarihe tıklayın.</p><?php elseif(!$dayDetails):?><p class="muted">Bu gün için rezervasyon yok.</p><?php endif;?>
<?php foreach($dayDetails as $b):?><article><div class="availability-row"><div><b><?=htmlspecialchars($b['booking_reference'])?> · <?=htmlspecialchars(trim(($b['first_name']??'').' '.($b['last_name']??'')))?></b><span><?=htmlspecialchars($b['check_in'])?> / <?=htmlspecialchars($b['check_out'])?> · <?=htmlspecialchars($b['status'])?><?= $b['booking_status']?' / '.htmlspecialchars($b['booking_status']):''?></span></div><div><strong><?=number_format((float)$b['total_amount'],2)?> <?=htmlspecialchars($b['currency'])?></strong> <a href="folio.php?booking=<?=(int)$b['id']?>">Folyo →</a></div></div></article><?php endforeach;?>
</section>
<?php supply_end(); ?>
