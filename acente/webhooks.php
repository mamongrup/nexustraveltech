<?php
declare(strict_types=1);
require_once __DIR__.'/../config/agency_auth.php';
$u=require_agency();
if(empty($_SESSION['agency_csrf']))$_SESSION['agency_csrf']=bin2hex(random_bytes(32));
$events=['booking.created'=>'Rezervasyon onaylandı','booking.request.rejected'=>'Rezervasyon talebi reddedildi'];
$message='';$error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['agency_csrf'],(string)($_POST['csrf']??''))){
    $error='Güvenlik doğrulaması geçersiz.';
  }else{
    $action=$_POST['action']??'';
    if($action==='add'){
      $url=trim((string)($_POST['url']??''));
      $secret=trim((string)($_POST['secret']??''))?:null;
      $selected=array_values(array_intersect((array)($_POST['events']??[]),array_keys($events)));
      if(!filter_var($url,FILTER_VALIDATE_URL)||strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https')$error='Geçerli bir https webhook adresi girin.';
      elseif(!$selected)$error='En az bir olay seçin.';
      else{
        db()->prepare('INSERT INTO webhook_subscriptions(agency_id,url,secret,events) VALUES(?,?,?,?::jsonb)')->execute([$u['agency_id'],$url,$secret,json_encode($selected,JSON_UNESCAPED_UNICODE)]);
        $message='Webhook aboneliği oluşturuldu. Teslimatlar NEXUS sunucusundan imzalı olarak gönderilir.';
      }
    }
    if($action==='toggle'){
      $id=(int)($_POST['id']??0);
      $q=db()->prepare('SELECT status FROM webhook_subscriptions WHERE id=? AND agency_id=?');
      $q->execute([$id,$u['agency_id']]);
      $status=$q->fetchColumn();
      if($status){db()->prepare("UPDATE webhook_subscriptions SET status=? WHERE id=?")->execute([$status==='active'?'paused':'active',$id]);$message='Abonelik durumu güncellendi.';}
    }
    if($action==='delete'){
      $id=(int)($_POST['id']??0);
      db()->prepare('DELETE FROM webhook_subscriptions WHERE id=? AND agency_id=?')->execute([$id,$u['agency_id']]);
      $message='Abonelik silindi.';
    }
  }
}

$q=db()->prepare('SELECT * FROM webhook_subscriptions WHERE agency_id=? ORDER BY id DESC');
$q->execute([$u['agency_id']]);
$subscriptions=$q->fetchAll();
?>
<!doctype html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Webhook bildirimleri | NEXUS Acenta</title>
<style>body{font-family:Arial;background:#f7f7f2;margin:0;color:#10211f}.w{width:min(900px,calc(100% - 32px));margin:35px auto}.c{background:#fff;border:1px solid #ddd;padding:18px;margin:15px 0}label{display:block;margin:10px 0 5px;font-weight:700;font-size:13px}input,button{padding:9px;font:inherit;border:1px solid #d8ded8;box-sizing:border-box}input[type=url]{width:100%}.ev{display:flex;gap:16px;flex-wrap:wrap}.ev label{font-weight:400;display:flex;gap:6px;align-items:center}.ok{background:#e6f8c7;padding:9px}.er{background:#ffe2de;padding:9px}.muted{color:#64716d;font-size:13px}button{background:#10211f;color:#fff;border:0;cursor:pointer;margin-top:10px}</style>
</head><body><main class="w"><a href="/nexustraveltech/acente/">← Panel</a><h1>Webhook bildirimleri</h1>
<p class="muted">Rezervasyon onayı veya talep reddi gibi olaylarda sisteminize anında POST gönderilir. İmza doğrulaması için <code>X-NEXUS-Signature</code> başlığını HMAC-SHA256(secret, body) ile doğrulayın.</p>
<?php if($message):?><p class="ok"><?=htmlspecialchars($message)?></p><?php endif;?>
<?php if($error):?><p class="er"><?=htmlspecialchars($error)?></p><?php endif;?>
<form method="post" class="c"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['agency_csrf'])?>"><input type="hidden" name="action" value="add">
<label>Webhook adresi (https)<input type="url" name="url" placeholder="https://sizin-sisteminiz.com/nexus-hook" required></label>
<label>İmza anahtarı (secret, opsiyonel)<input name="secret" placeholder="İmza doğrulaması için paylaşımlı anahtar"></label>
<label>Olaylar</label><div class="ev"><?php foreach($events as $code=>$label):?><label><input type="checkbox" name="events[]" value="<?=$code?>"> <?=htmlspecialchars($label)?></label><?php endforeach;?></div>
<button>Aboneliği ekle</button></form>
<?php foreach($subscriptions as $s):$subEvents=json_decode((string)$s['events'],true)?:[];?>
<section class="c"><b><?=htmlspecialchars($s['url'])?></b> · <?=htmlspecialchars($s['status'])?><?= $s['last_sent_at']?' · son gönderim: '.htmlspecialchars((string)$s['last_sent_at']):''?><p class="muted"><?=htmlspecialchars(implode(', ',array_map(fn($e)=>$events[$e]??$e,$subEvents)))?></p>
<form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['agency_csrf'])?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$s['id']?>"><button><?=$s['status']==='active'?'Duraklat':'Etkinleştir'?></button></form>
<form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['agency_csrf'])?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$s['id']?>"><button style="background:#8e2410">Sil</button></form>
</section>
<?php endforeach;?>
</main></body></html>
