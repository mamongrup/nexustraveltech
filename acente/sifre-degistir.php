<?php
declare(strict_types=1);
require_once __DIR__.'/../config/agency_auth.php';
$u=require_agency();
if(empty($_SESSION['agency_csrf']))$_SESSION['agency_csrf']=bin2hex(random_bytes(32));
$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['agency_csrf'],(string)($_POST['csrf']??''))){
    $error='Güvenlik doğrulaması geçersiz.';
  }else{
    $current=(string)($_POST['current_password']??'');
    $new=(string)($_POST['new_password']??'');
    $confirm=(string)($_POST['confirm_password']??'');
    $q=db()->prepare('SELECT password_hash FROM agency_users WHERE id=?');
    $q->execute([$u['id']]);
    $hash=$q->fetchColumn();
    if(!$hash||!password_verify($current,$hash))$error='Mevcut şifreniz hatalı.';
    elseif(strlen($new)<10)$error='Yeni şifre en az 10 karakter olmalıdır.';
    elseif($new!==$confirm)$error='Yeni şifreler eşleşmiyor.';
    else{
      db()->prepare('UPDATE agency_users SET password_hash=? WHERE id=?')->execute([password_hash($new,PASSWORD_DEFAULT),$u['id']]);
      $message='Şifreniz güncellendi. Bir sonraki girişte yeni şifrenizi kullanın.';
    }
  }
}
?>
<!doctype html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Şifre değiştir | NEXUS Acenta</title>
<style>body{font-family:Arial;background:#f7f7f2;margin:0}.w{width:min(480px,calc(100% - 32px));margin:35px auto}.c{background:#fff;border:1px solid #ddd;padding:20px;margin:15px 0}input,button{padding:10px;margin:5px 0;width:100%;box-sizing:border-box}button{background:#10211f;color:#fff;border:0;font-weight:bold}.ok{background:#e6f8c7;padding:9px}.er{background:#ffe2de;padding:9px}.muted{color:#64716d;font-size:13px}</style>
</head><body><main class="w"><a href="/nexustraveltech/acente/">← Panel</a><h1>Şifre değiştir</h1>
<?php if($message):?><p class="ok"><?=htmlspecialchars($message)?></p><?php endif;?>
<?php if($error):?><p class="er"><?=htmlspecialchars($error)?></p><?php endif;?>
<form method="post" class="c"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['agency_csrf'])?>">
  <label>Mevcut şifre<input type="password" name="current_password" autocomplete="current-password" required></label>
  <label>Yeni şifre (en az 10 karakter)<input type="password" name="new_password" autocomplete="new-password" minlength="10" required></label>
  <label>Yeni şifre tekrar<input type="password" name="confirm_password" autocomplete="new-password" minlength="10" required></label>
  <button>Şifreyi güncelle</button>
  <p class="muted">Şifrenizi kimseyle paylaşmayın; yöneticiler dahil hiçbir NEXUS çalışanı şifrenizi sormaz.</p>
</form>
</main></body></html>
