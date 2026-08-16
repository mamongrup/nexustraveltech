<?php
declare(strict_types=1);
$active_module='';
require_once __DIR__.'/layout.php';
if(empty($_SESSION['supplier_csrf']))$_SESSION['supplier_csrf']=bin2hex(random_bytes(32));
$u=$supplier_user;
$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['supplier_csrf'],(string)($_POST['csrf']??''))){
    $error='Güvenlik doğrulaması geçersiz.';
  }else{
    $current=(string)($_POST['current_password']??'');
    $new=(string)($_POST['new_password']??'');
    $confirm=(string)($_POST['confirm_password']??'');
    $q=db()->prepare('SELECT password_hash FROM supplier_users WHERE id=?');
    $q->execute([$u['id']]);
    $hash=$q->fetchColumn();
    if(!$hash||!password_verify($current,$hash))$error='Mevcut şifreniz hatalı.';
    elseif(strlen($new)<10)$error='Yeni şifre en az 10 karakter olmalıdır.';
    elseif($new!==$confirm)$error='Yeni şifreler eşleşmiyor.';
    else{
      db()->prepare('UPDATE supplier_users SET password_hash=? WHERE id=?')->execute([password_hash($new,PASSWORD_DEFAULT),$u['id']]);
      $message='Şifreniz güncellendi. Bir sonraki girişte yeni şifrenizi kullanın.';
    }
  }
}
supply_start('Şifre değiştir','');
?>
<section class="page-intro"><p>Hesap şifrenizi güncelleyin. Şifrenizi kimseyle paylaşmayın; NEXUS çalışanları şifrenizi asla sormaz.</p></section>
<?php if($message):?><p class="save-success">✓ <?=htmlspecialchars($message)?></p><?php endif;?>
<?php if($error):?><p class="login-error"><?=htmlspecialchars($error)?></p><?php endif;?>
<section class="next-module" style="max-width:520px"><form method="post" class="supply-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>">
<label>Mevcut şifre<input type="password" name="current_password" autocomplete="current-password" required></label>
<label>Yeni şifre (en az 10 karakter)<input type="password" name="new_password" autocomplete="new-password" minlength="10" required></label>
<label>Yeni şifre tekrar<input type="password" name="confirm_password" autocomplete="new-password" minlength="10" required></label>
<button>Şifreyi güncelle →</button>
</form></section>
<?php supply_end(); ?>
