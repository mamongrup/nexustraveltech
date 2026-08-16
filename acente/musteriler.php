<?php
declare(strict_types=1);
require_once __DIR__.'/../config/agency_auth.php';
$u=require_agency();
if(empty($_SESSION['agency_csrf']))$_SESSION['agency_csrf']=bin2hex(random_bytes(32));
$e='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['agency_csrf'],(string)($_POST['csrf']??''))){$e='Güvenlik doğrulaması geçersiz.';}
  else{
    $name=trim((string)($_POST['full_name']??''));
    if($name==='')$e='Müşteri adı zorunludur.';
    else db()->prepare('INSERT INTO agency_customers(agency_id,full_name,email,phone,notes) VALUES(?,?,?,?,?)')
      ->execute([$u['agency_id'],$name,trim((string)$_POST['email'])?:null,trim((string)$_POST['phone'])?:null,trim((string)$_POST['notes'])?:null]);
  }
}
$q=db()->prepare('SELECT * FROM agency_customers WHERE agency_id=? ORDER BY id DESC');
$q->execute([$u['agency_id']]);
$rows=$q->fetchAll();
?>
<!doctype html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Müşteri CRM</title>
<style>body{font-family:Arial;background:#f7f7f2;margin:0}.w{width:min(900px,calc(100% - 32px));margin:35px auto}.c{background:#fff;border:1px solid #ddd;padding:18px;margin:15px 0}input,textarea,button{padding:9px;margin:4px;width:calc(100% - 8px);box-sizing:border-box}.er{background:#ffe2de;padding:9px}</style>
</head><body><main class="w"><a href="/nexustraveltech/acente/">← Panel</a><h1>Müşteri CRM</h1>
<?php if($e):?><p class="er"><?=htmlspecialchars($e)?></p><?php endif;?>
<form method="post" class="c"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['agency_csrf'])?>"><input name="full_name" placeholder="Ad soyad / ünvan" required><input name="email" type="email" placeholder="E-posta"><input name="phone" placeholder="Telefon"><textarea name="notes" placeholder="Notlar"></textarea><button>Müşteri ekle</button></form>
<?php foreach($rows as $r):?><article class="c"><b><?=htmlspecialchars($r['full_name'])?></b><p><?=htmlspecialchars($r['email']?:'')?> <?=htmlspecialchars($r['phone']?:'')?></p><small><?=htmlspecialchars($r['notes']?:'')?></small></article><?php endforeach;?>
</main></body></html>
