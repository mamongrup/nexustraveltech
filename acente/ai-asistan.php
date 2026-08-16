<?php
declare(strict_types=1);
require_once __DIR__.'/../config/agency_auth.php';
require_once __DIR__.'/../config/ai_settings.php';
require_once __DIR__.'/../config/pricing.php';
$u=require_agency();
if(empty($_SESSION['agency_csrf']))$_SESSION['agency_csrf']=bin2hex(random_bytes(32));
$message='';$error='';
$parsed=null;$results=[];
$guestName='';$preferences=[];

function ai_parse_request(string $query): array
{
    $settings=deepseek_settings();
    if($settings['api_key']==='')throw new RuntimeException('AI asistanı için yönetici panelinden DeepSeek API anahtarı ekleyin.');
    $year=date('Y');
    $body=['model'=>$settings['model'],'messages'=>[
        ['role'=>'system','content'=>"Sen bir seyahat rezervasyonu sorgu çözümleyicisisin. Türkçe isteği JSON'a dönüştür. YALNIZCA şu yapıda geçerli JSON döndür, başka hiçbir metin yazma: {\"check_in\":\"YYYY-MM-DD\",\"check_out\":\"YYYY-MM-DD\",\"adults\":2,\"children\":0,\"city\":\"\",\"property_name\":\"\",\"max_price\":0,\"guest_name\":\"\",\"preferences\":[\"ör. deniz manzaralı\"]}. Kurallar: gün verildiyse $year yılını kullan; ay yoksa ilgili tarihi boş bırak; fiyat varsayılan EUR; misafir adı ve tercihler varsa doldur; emin değilsen boş değer kullan."],
        ['role'=>'user','content'=>$query],
    ],'temperature'=>0,'stream'=>false];
    $ch=curl_init('https://api.deepseek.com/chat/completions');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$settings['api_key']],CURLOPT_POSTFIELDS=>json_encode($body,JSON_UNESCAPED_UNICODE),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>45]);
    $raw=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if($status<200||$status>=300)throw new RuntimeException('AI sorgusu çözümlenemedi (HTTP '.$status.').');
    $data=json_decode((string)$raw,true);
    $content=trim((string)($data['choices'][0]['message']['content']??''));
    if(str_starts_with($content,'```')){$content=preg_replace('/^```(?:json)?\s*/i','',$content);$content=preg_replace('/\s*```$/','',$content);}
    $result=json_decode($content,true);
    if(!is_array($result))throw new RuntimeException('AI sorgusu anlaşılamadı. Lütfen daha açık yazın.');
    return $result;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['agency_csrf'],(string)($_POST['csrf']??''))){
    $error='Güvenlik doğrulaması geçersiz.';
  }elseif(($_POST['action']??'')==='save_quote'){
    $total=(float)str_replace(',','.',(string)($_POST['quote_total']??0));
    $customerId=(int)($_POST['customer_id']??0);
    $guestName=trim((string)($_POST['guest_name']??''));
    if($total<=0)$error='Teklif tutarı geçersiz.';
    else{
      $pdo=db();
      if($guestName!==''&&$customerId===0){
        $q=$pdo->prepare('INSERT INTO agency_customers(agency_id,full_name) VALUES(?,?) RETURNING id');
        $q->execute([$u['agency_id'],$guestName]);
        $customerId=(int)$q->fetchColumn();
      }
      $quoteNumber='TKF-'.date('ymdHis').'-'.random_int(100,999);
      $q=$pdo->prepare("INSERT INTO agency_quotes(agency_id,customer_id,quote_number,valid_until,total_amount,currency,status) VALUES(?,?,?,now()+interval '7 days',?,?,'draft')");
      $q->execute([$u['agency_id'],$customerId?:null,$quoteNumber,$total,'EUR']);
      $message='Teklif '.$quoteNumber.' oluşturuldu ve taslak olarak kaydedildi. <a href="/nexustraveltech/acente/teklifler">Tekliflere git →</a>';
    }
  }else{
    $query=trim((string)($_POST['query']??''));
    if($query==='')$error='Sorgunuzu yazın.';
    else{
      try{$parsed=ai_parse_request($query);}catch(Throwable $e){$error=$e->getMessage();}
    }
  }
}

if(is_array($parsed)){
  $checkIn=preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($parsed['check_in']??''))?(string)$parsed['check_in']:'';
  $checkOut=preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($parsed['check_out']??''))?(string)$parsed['check_out']:'';
  $adults=max(1,min(20,(int)($parsed['adults']??2)));
  $city=trim((string)($parsed['city']??''));
  $maxPrice=max(0,(float)($parsed['max_price']??0));
  $propertyName=trim((string)($parsed['property_name']??''));
  $guestName=trim((string)($parsed['guest_name']??''));
  $preferences=array_values(array_filter(array_map('trim',(array)($parsed['preferences']??[]))));

  if($checkIn!==''&&$checkOut!==''&&$checkIn<$checkOut){
    $nights=(int)((strtotime($checkOut)-strtotime($checkIn))/86400);
    $where="p.status='active' AND s.status IN ('active','pilot') AND i.stay_date>=? AND i.stay_date<? AND i.stop_sale=false AND i.allotment>i.sold";
    $params=[$checkIn,$checkOut];
    if($city!==''){$where.=' AND lower(p.city)=lower(?)';$params[]=$city;}
    if($propertyName!==''){$where.=' AND lower(p.name) LIKE lower(?)';$params[]='%'.$propertyName.'%';}
    $sql="SELECT r.id room_type_id,r.name room_name,r.capacity_adults,p.id property_id,p.name property_name,p.city,MIN(i.base_price) price FROM room_types r JOIN properties p ON p.id=r.property_id JOIN suppliers s ON s.id=p.supplier_id JOIN inventory_calendar i ON i.room_type_id=r.id WHERE $where GROUP BY r.id,r.name,r.capacity_adults,p.id,p.name,p.city HAVING COUNT(*)=?";
    $params[]=$nights;
    if($maxPrice>0){$sql.=' AND MIN(i.base_price)<=?';$params[]=$maxPrice;}
    $sql.=' ORDER BY price LIMIT 15';
    $q=db()->prepare($sql);
    $q->execute($params);
    $results=$q->fetchAll();
  }
}

$q=db()->prepare('SELECT id,full_name FROM agency_customers WHERE agency_id=? ORDER BY full_name');
$q->execute([$u['agency_id']]);
$customers=$q->fetchAll();
?>
<!doctype html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AI asistan | NEXUS Acenta</title>
<style>body{font-family:Arial;background:#f7f7f2;margin:0;color:#10211f}.w{width:min(1000px,calc(100% - 32px));margin:35px auto}.c{background:#fff;border:1px solid #ddd;padding:18px;margin:15px 0}.muted{color:#64716d}textarea,input,select,button{padding:10px;font:inherit;border:1px solid #d8ded8;box-sizing:border-box}textarea{width:100%;min-height:90px}button{background:#10211f;color:#fff;border:0;font-weight:bold;cursor:pointer;margin-top:10px}.ok{background:#e6f8c7;padding:9px}.er{background:#ffe2de;padding:9px}.result{border-top:1px solid #e5e5e5;padding:14px 0;display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;align-items:start}.result h3{margin:0 0 4px;font-size:16px}.result b.price{color:#0d7a4a;font-size:18px}.result form{display:grid;gap:6px}.result form .row2{display:grid;grid-template-columns:1fr 1fr;gap:6px}.chips{display:flex;gap:8px;flex-wrap:wrap}.chip{background:#edf1eb;padding:6px 10px;border-radius:20px;font-size:12px}.quote-btn{background:#0d7a4a}@media(max-width:720px){.result{grid-template-columns:1fr}}</style>
</head><body><main class="w"><a href="/nexustraveltech/acente/">← Panel</a><h1>AI rezervasyon asistanı</h1>
<p class="muted">Doğal dilde yazın; NEXUS AI sorgunuzu çözümleyip canlı envanterde arar. Örn: <i>"Antalya'da 5 yıldızlı, deniz manzaralı, 4 kişilik, 15 Ağustos'ta 3 gece, bütçe 600€, misafir: Ali Yılmaz"</i></p>
<?php if($message):?><p class="ok"><?=$message?></p><?php endif;?>
<?php if($error):?><p class="er"><?=htmlspecialchars($error)?></p><?php endif;?>
<form method="post" class="c"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['agency_csrf'])?>"><textarea name="query" placeholder="Rezervasyon isteğinizi yazın…" required></textarea><button>AI ile ara</button></form>
<?php if(is_array($parsed)):?>
<section class="c"><h2 style="margin-top:0">Çözümlenen sorgu</h2>
<div class="chips"><span class="chip">Giriş: <?=htmlspecialchars($checkIn?:'—')?></span><span class="chip">Çıkış: <?=htmlspecialchars($checkOut?:'—')?></span><span class="chip">Yetişkin: <?=$adults?></span><?php if($city):?><span class="chip">Şehir: <?=htmlspecialchars($city)?></span><?php endif;?><?php if($propertyName):?><span class="chip">Tesis: <?=htmlspecialchars($propertyName)?></span><?php endif;?><?php if($maxPrice>0):?><span class="chip">Bütçe: <?=number_format($maxPrice,2)?> EUR/gece</span><?php endif;?><?php if($guestName):?><span class="chip">Misafir: <?=htmlspecialchars($guestName)?></span><?php endif;?><?php foreach($preferences as $p):?><span class="chip">Tercih: <?=htmlspecialchars($p)?></span><?php endforeach;?></div>
<p class="muted">Tarihleri düzeltmek için <a href="/nexustraveltech/acente/musaitlik?check_in=<?=urlencode($checkIn)?>&check_out=<?=urlencode($checkOut)?>&adults=<?=$adults?>&city=<?=urlencode($city)?>&max_price=<?=$maxPrice?>">canlı müsaitlik ekranında açın</a>.</p>
</section>
<?php if($results):?>
<section class="c"><h2 style="margin-top:0"><?=count($results)?> uygun seçenek</h2>
<?php foreach($results as $room):
$context=['stay_date'=>$checkIn,'booking_date'=>date('Y-m-d'),'advance_days'=>max(0,(int)((strtotime($checkIn)-time())/86400)),'market'=>'TR','nationality'=>'TR','channel'=>'agency','promo_code'=>''];
$adjusted=apply_rate_rules((int)$room['property_id'],null,(float)$room['price'],$context);
$quoteTotal=$adjusted['price']*$nights;
?>
<div class="result"><div><h3><?=htmlspecialchars($room['property_name'])?> · <?=htmlspecialchars($room['room_name'])?></h3><p class="muted"><?=htmlspecialchars($room['city'])?> · <?=(int)$room['capacity_adults']?> yetişkin</p></div>
<div><b class="price"><?=number_format($adjusted['price'],2)?> EUR</b><span class="muted"> / gece · toplam <?=number_format($quoteTotal,2)?> EUR</span></div>
<div><form method="post" action="/nexustraveltech/acente/musaitlik" class="c" style="margin:0"><input type="hidden" name="form" value="request"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['agency_csrf'])?>"><input type="hidden" name="property_id" value="<?=(int)$room['property_id']?>"><input type="hidden" name="room_type_id" value="<?=(int)$room['room_type_id']?>"><input type="hidden" name="check_in" value="<?=htmlspecialchars($checkIn)?>"><input type="hidden" name="check_out" value="<?=htmlspecialchars($checkOut)?>"><input type="hidden" name="adults" value="<?=$adults?>"><input type="hidden" name="promo_code" value="">
<div class="row2"><input name="guest_first_name" placeholder="Misafir ad" value="<?=htmlspecialchars(explode(' ',$guestName)[0]??'')?>" required><input name="guest_last_name" placeholder="Misafir soyad" value="<?=htmlspecialchars(implode(' ',array_slice(explode(' ',$guestName),1)))?>" required></div>
<div class="row2"><input name="guest_email" type="email" placeholder="Misafir e-posta"><input name="guest_phone" placeholder="Misafir telefon"></div>
<button>Rezervasyon talebi gönder</button></form>
<form method="post" class="c" style="margin:0;border-top:1px dashed #ddd"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['agency_csrf'])?>"><input type="hidden" name="action" value="save_quote"><input type="hidden" name="quote_total" value="<?=htmlspecialchars((string)$quoteTotal)?>"><input type="hidden" name="guest_name" value="<?=htmlspecialchars($guestName)?>">
<label style="font-size:12px">Müşteri (opsiyonel)<select name="customer_id"><option value="0">— Yeni / misafir adıyla —</option><?php foreach($customers as $c):?><option value="<?=$c['id']?>"><?=htmlspecialchars($c['full_name'])?></option><?php endforeach;?></select></label>
<button class="quote-btn"><?=number_format($quoteTotal,2)?> EUR teklif olarak kaydet</button></form></div></div>
<?php endforeach;?>
</section>
<?php elseif($checkIn!==''&&$checkOut!==''):?><p class="c muted">Çözümlenen kriterlerde müsaitlik bulunamadı.</p><?php endif;?>
<?php endif;?>
</main></body></html>
