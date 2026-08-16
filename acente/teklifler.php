<?php
declare(strict_types=1);
require_once __DIR__.'/../config/agency_auth.php';
$u=require_agency();
if(empty($_SESSION['agency_csrf']))$_SESSION['agency_csrf']=bin2hex(random_bytes(32));
$e='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['agency_csrf'],(string)($_POST['csrf']??''))){$e='Güvenlik doğrulaması geçersiz.';}
  else{
    $number=trim((string)($_POST['quote_number']??''));
    $total=(float)str_replace(',','.',$_POST['total_amount']??0);
    if($number===''||$total<0)$e='Teklif no ve tutarı kontrol edin.';
    else db()->prepare('INSERT INTO agency_quotes(agency_id,quote_number,valid_until,total_amount,currency,status) VALUES(?,?,?,?,?,\'draft\')')
      ->execute([$u['agency_id'],$number,$_POST['valid_until']?:null,$total,$_POST['currency']??'EUR']);
  }
}
$q=db()->prepare('SELECT * FROM agency_quotes WHERE agency_id=? ORDER BY id DESC');
$q->execute([$u['agency_id']]);
$rows=$q->fetchAll();
?>
<!doctype html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Teklifler</title>
<style>body{font-family:Arial;background:#f7f7f2;margin:0}.w{width:min(900px,calc(100% - 32px));margin:35px auto}.c{background:#fff;border:1px solid #ddd;padding:18px;margin:15px 0}input,select,button{padding:9px;margin:4px}.er{background:#ffe2de;padding:9px}</style>
</head><body><main class="w"><a href="/nexustraveltech/acente/">← Panel</a><h1>Teklif yönetimi</h1>
<?php if($e):?><p class="er"><?=htmlspecialchars($e)?></p><?php endif;?>
<form method="post" class="c"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['agency_csrf'])?>"><input name="quote_number" placeholder="Teklif no" required><input name="valid_until" type="date"><input name="total_amount" type="number" step="0.01" placeholder="Toplam"><select name="currency"><option>EUR</option><option>TRY</option><option>USD</option><option>GBP</option></select><button>Taslak teklif oluştur</button></form>
<?php foreach($rows as $r):?><article class="c"><b><?=htmlspecialchars($r['quote_number'])?></b> · <?=number_format((float)$r['total_amount'],2)?> <?=htmlspecialchars($r['currency'])?> · <?=htmlspecialchars($r['status'])?></article><?php endforeach;?>
</main></body></html>
