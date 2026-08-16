<?php
declare(strict_types=1);
require_once __DIR__.'/../config/agency_auth.php';
require_once __DIR__.'/../config/pricing.php';
require_once __DIR__.'/../config/agency_bookings.php';
$u=require_agency();
if(empty($_SESSION['agency_csrf']))$_SESSION['agency_csrf']=bin2hex(random_bytes(32));
$message='';$error='';
$checkIn=trim((string)($_GET['check_in']??$_POST['check_in']??''));
$checkOut=trim((string)($_GET['check_out']??$_POST['check_out']??''));
$adults=(int)($_GET['adults']??$_POST['adults']??2);
$city=trim((string)($_GET['city']??''));
$property=(int)($_GET['property']??0);
$promo=trim((string)($_GET['promo_code']??$_POST['promo_code']??''));
$maxPrice=(float)($_GET['max_price']??$_POST['max_price']??0);
$results=[];

$cities=db()->query("SELECT DISTINCT p.city FROM properties p JOIN suppliers s ON s.id=p.supplier_id WHERE p.status='active' AND s.status IN ('active','pilot') AND p.city IS NOT NULL ORDER BY p.city")->fetchAll();
$properties=db()->query("SELECT p.id,p.name,p.city FROM properties p JOIN suppliers s ON s.id=p.supplier_id WHERE p.status='active' AND s.status IN ('active','pilot') ORDER BY p.name")->fetchAll();

function agency_pricing_context(string $stayDate, string $promoCode): array
{
    return [
        'stay_date' => $stayDate,
        'booking_date' => date('Y-m-d'),
        'advance_days' => max(0, (int) ((strtotime($stayDate) - time()) / 86400)),
        'market' => 'TR',
        'nationality' => 'TR',
        'channel' => 'agency',
        'promo_code' => $promoCode,
    ];
}

// Rezervasyon talebi oluştur
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['form']??'')==='request'){
  if(!hash_equals($_SESSION['agency_csrf'],(string)($_POST['csrf']??''))){
    $error='Güvenlik doğrulaması geçersiz.';
  }else{
    $checkIn=trim((string)($_POST['check_in']??''));
    $checkOut=trim((string)($_POST['check_out']??''));
    $promo=trim((string)($_POST['promo_code']??''));
    $roomType=(int)($_POST['room_type_id']??0);
    $propertyId=(int)($_POST['property_id']??0);
    $adults=max(1,min(20,(int)($_POST['adults']??2)));
    $children=max(0,(int)($_POST['children']??0));
    $first=trim((string)($_POST['guest_first_name']??''));
    $last=trim((string)($_POST['guest_last_name']??''));
    $email=filter_var($_POST['guest_email']??'',FILTER_VALIDATE_EMAIL)?:null;
    $phone=trim((string)($_POST['guest_phone']??''))?:null;
    $note=trim((string)($_POST['note']??''))?:null;
    $agencyRef=trim((string)($_POST['agency_reference']??''))?:null;

    if($checkIn===''||$checkOut===''||$checkIn>=$checkOut)$error='Geçerli giriş ve çıkış tarihi seçin.';
    elseif($first===''||$last==='')$error='Misafir ad ve soyadı zorunludur.';
    else{
      $nights=(int)((strtotime($checkOut)-strtotime($checkIn))/86400);
      $q=db()->prepare('SELECT p.id,p.supplier_id FROM properties p JOIN suppliers s ON s.id=p.supplier_id WHERE p.id=? AND p.status=\'active\' AND s.status IN (\'active\',\'pilot\')');
      $q->execute([$propertyId]);
      $prop=$q->fetch();
      $q=db()->prepare('SELECT 1 FROM room_types WHERE id=? AND property_id=?');
      $q->execute([$roomType,$propertyId]);
      if(!$prop||!$q->fetch())$error='Seçilen ürün artık satışa açık değil.';
      else{
        $q=db()->prepare("SELECT i.rate_plan_id, MIN(i.base_price) price FROM inventory_calendar i JOIN rate_plans rp ON rp.id=i.rate_plan_id WHERE i.room_type_id=? AND i.stay_date>=? AND i.stay_date<? AND i.stop_sale=false AND i.allotment>i.sold GROUP BY i.rate_plan_id ORDER BY MIN(i.base_price) LIMIT 1");
        $q->execute([$roomType,$checkIn,$checkOut]);
        $pricing=$q->fetch();
        if(!$pricing)$error='Seçilen tarihlerde müsaitlik bulunamadı.';
        else{
          $adjusted=apply_rate_rules((int)$propertyId,(int)$pricing['rate_plan_id'],(float)$pricing['price'],agency_pricing_context($checkIn,$promo));
          $total=round($adjusted['price']*$nights,2);
          insert_agency_booking_request([
            'agency_id'=>$u['agency_id'],'supplier_id'=>$prop['supplier_id'],'property_id'=>$propertyId,
            'room_type_id'=>$roomType,'rate_plan_id'=>(int)$pricing['rate_plan_id'],
            'check_in'=>$checkIn,'check_out'=>$checkOut,'nights'=>$nights,'adults'=>$adults,'children'=>$children,
            'total_amount'=>$total,'currency'=>'EUR',
            'guest_first_name'=>$first,'guest_last_name'=>$last,'guest_email'=>$email,'guest_phone'=>$phone,
            'agency_reference'=>$agencyRef,'note'=>$note,
          ]);
          $message='Rezervasyon talebiniz tedarikçiye iletildi. Onay durumu aşağıdaki listeden takip edilebilir.';
        }
      }
    }
  }
}

// Canlı müsaitlik sorgusu
$nights=0;
if($checkIn!==''&&$checkOut!==''&&$checkIn<$checkOut){
  $nights=(int)((strtotime($checkOut)-strtotime($checkIn))/86400);
  $where="p.status='active' AND s.status IN ('active','pilot') AND i.stay_date>=? AND i.stay_date<? AND i.stop_sale=false AND i.allotment>i.sold";
  $params=[$checkIn,$checkOut];
  if($city!==''){$where.=' AND lower(p.city)=lower(?)';$params[]=$city;}
  if($property>0){$where.=' AND p.id=?';$params[]=$property;}
  $sql="SELECT r.id room_type_id,r.name room_name,r.capacity_adults,r.total_units,p.id property_id,p.name property_name,p.city,MIN(i.base_price) price FROM room_types r JOIN properties p ON p.id=r.property_id JOIN suppliers s ON s.id=p.supplier_id JOIN inventory_calendar i ON i.room_type_id=r.id WHERE $where GROUP BY r.id,r.name,r.capacity_adults,r.total_units,p.id,p.name,p.city HAVING COUNT(*)=?";
  $params[]=$nights;
  if($maxPrice>0){$sql.=' AND MIN(i.base_price)<=?';$params[]=$maxPrice;}
  $sql.=' ORDER BY price';
  $q=db()->prepare($sql);
  $q->execute($params);
  $results=$q->fetchAll();
}

$q=db()->prepare('SELECT r.*,p.name property_name,rt.name room_name FROM agency_booking_requests r JOIN properties p ON p.id=r.property_id LEFT JOIN room_types rt ON rt.id=r.room_type_id WHERE r.agency_id=? ORDER BY r.id DESC LIMIT 50');
$q->execute([$u['agency_id']]);
$requests=$q->fetchAll();
?>
<!doctype html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Canlı müsaitlik | NEXUS Acenta</title>
<style>body{font-family:Arial;background:#f7f7f2;margin:0;color:#10211f}.w{width:min(1000px,calc(100% - 32px));margin:35px auto}.c{background:#fff;border:1px solid #ddd;padding:18px;margin:15px 0}.r{display:grid;grid-template-columns:repeat(6,1fr);gap:8px;align-items:end}input,select,textarea,button{padding:9px;font:inherit;border:1px solid #d8ded8;box-sizing:border-box;width:100%}button{background:#10211f;color:#fff;font-weight:bold;border:0;cursor:pointer}.ok{background:#e6f8c7;padding:9px}.er{background:#ffe2de;padding:9px}.muted{color:#64716d}.result{border-top:1px solid #e5e5e5;padding:14px 0;display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;align-items:start}.result h3{margin:0 0 4px;font-size:16px}.result b.price{color:#0d7a4a;font-size:18px}.result form{display:grid;gap:6px}.result form input,.result form textarea{width:100%}.result form .row2{display:grid;grid-template-columns:1fr 1fr;gap:6px}.rule-note{font-size:11px;color:#a86026}@media(max-width:720px){.r,.result{grid-template-columns:1fr}}</style>
</head><body><main class="w"><a href="/nexustraveltech/acente/">← Panel</a><h1>Canlı müsaitlik & rezervasyon talebi</h1>
<p class="muted">Tedarikçilerin güncel fiyat, kontenjan ve satış kurallarına göre sorgulayın; talebiniz onaylanınca rezervasyon kesinleşir.</p>
<?php if($message):?><p class="ok"><?=htmlspecialchars($message)?></p><?php endif;?>
<?php if($error):?><p class="er"><?=htmlspecialchars($error)?></p><?php endif;?>
<form method="get" class="c"><div class="r">
<label>Giriş<input type="date" name="check_in" value="<?=htmlspecialchars($checkIn)?>" required></label>
<label>Çıkış<input type="date" name="check_out" value="<?=htmlspecialchars($checkOut)?>" required></label>
<label>Yetişkin<input type="number" name="adults" min="1" max="20" value="<?=$adults?>"></label>
<label>Şehir<select name="city"><option value="">Tümü</option><?php foreach($cities as $c):?><option value="<?=htmlspecialchars($c['city'])?>" <?=$city===$c['city']?'selected':''?>><?=htmlspecialchars($c['city'])?></option><?php endforeach;?></select></label>
<label>Ürün<select name="property"><option value="0">Tümü</option><?php foreach($properties as $p):?><option value="<?=$p['id']?>" <?=$property===$p['id']?'selected':''?>><?=htmlspecialchars($p['name'].' · '.$p['city'])?></option><?php endforeach;?></select></label>
<label>Promosyon kodu<input name="promo_code" value="<?=htmlspecialchars($promo)?>" placeholder="ops."></label>
<label>Bütçe (EUR/gece)<input type="number" name="max_price" min="0" step="0.01" value="<?=$maxPrice>0?$maxPrice:''?>" placeholder="ops."></label>
<button>Müsaitlik ara</button></div></form>
<?php if($results):?><section class="c"><h2><?=count($results)?> uygun seçenek</h2>
<?php foreach($results as $room):
$rpQ=db()->prepare("SELECT i.rate_plan_id, MIN(i.base_price) price FROM inventory_calendar i JOIN rate_plans rp ON rp.id=i.rate_plan_id WHERE i.room_type_id=? AND i.stay_date>=? AND i.stay_date<? AND i.stop_sale=false AND i.allotment>i.sold GROUP BY i.rate_plan_id ORDER BY MIN(i.base_price) LIMIT 1");
$rpQ->execute([$room['room_type_id'],$checkIn,$checkOut]);
$rpRow=$rpQ->fetch();
$base=$rpRow?(float)$rpRow['price']:(float)$room['price'];
$ratePlanId=$rpRow?(int)$rpRow['rate_plan_id']:null;
$adjusted=apply_rate_rules((int)$room['property_id'],$ratePlanId,$base,agency_pricing_context($checkIn,$promo));
?>
<div class="result"><div><h3><?=htmlspecialchars($room['property_name'])?> · <?=htmlspecialchars($room['room_name'])?></h3><p class="muted"><?=htmlspecialchars($room['city'])?> · <?=(int)$room['capacity_adults']?> yetişkin</p></div>
<div><b class="price"><?=number_format($adjusted['price'],2)?> EUR</b><span class="muted"> / gece</span><p class="muted"><?=$nights?> gece · toplam <?=number_format($adjusted['price']*$nights,2)?> EUR</p><?php if($adjusted['applied']):?><p class="rule-note">Uygulanan kural: <?=htmlspecialchars(implode(', ',$adjusted['applied']))?></p><?php endif;?></div>
<div><form method="post" class="c" style="margin:0"><input type="hidden" name="form" value="request"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['agency_csrf'])?>"><input type="hidden" name="property_id" value="<?=(int)$room['property_id']?>"><input type="hidden" name="room_type_id" value="<?=(int)$room['room_type_id']?>"><input type="hidden" name="check_in" value="<?=htmlspecialchars($checkIn)?>"><input type="hidden" name="check_out" value="<?=htmlspecialchars($checkOut)?>"><input type="hidden" name="adults" value="<?=$adults?>"><input type="hidden" name="promo_code" value="<?=htmlspecialchars($promo)?>">
<div class="row2"><input name="guest_first_name" placeholder="Misafir ad" required><input name="guest_last_name" placeholder="Misafir soyad" required></div>
<div class="row2"><input name="guest_email" type="email" placeholder="Misafir e-posta"><input name="guest_phone" placeholder="Misafir telefon"></div>
<div class="row2"><input name="agency_reference" placeholder="Kendi referansınız (ops.)"><input name="children" type="number" min="0" value="0" placeholder="Çocuk"></div>
<textarea name="note" rows="2" placeholder="Tedarikçiye not (ops.)"></textarea>
<button>Rezervasyon talebi gönder</button></form></div></div>
<?php endforeach;?></section>
<?php elseif($checkIn!==''):?><p class="c muted">Seçilen kriterlerde müsaitlik bulunamadı.</p><?php endif;?>
<section class="c"><h2>Taleplerim</h2><?php if(!$requests):?><p class="muted">Henüz rezervasyon talebiniz yok.</p><?php endif;?>
<?php foreach($requests as $r):?><p><b><?=htmlspecialchars($r['property_name'])?></b> · <?=htmlspecialchars($r['room_name']?:'—')?> · <?=htmlspecialchars($r['check_in'])?> / <?=htmlspecialchars($r['check_out'])?> · <?=number_format((float)$r['total_amount'],2)?> <?=htmlspecialchars($r['currency'])?> · <strong><?=htmlspecialchars($r['status'])?></strong><?= $r['response_note']?' · <span class="muted">'.htmlspecialchars($r['response_note']).'</span>':''?></p><?php endforeach;?>
</section></main><?php require_once __DIR__.'/../config/ai_widget.php'; ai_widget('/nexustraveltech/acente/ai-chat','agency_csrf'); ?></body></html>
