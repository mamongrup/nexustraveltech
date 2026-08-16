<?php
declare(strict_types=1);
require_once __DIR__.'/../config/database.php';

$token=trim((string)($_GET['token']??''));
$message='';$error='';
if($token===''){
  $error='Doğrulama bağlantısı eksik.';
}else{
  $q=db()->prepare('SELECT id,verified_at FROM agencies WHERE verify_token=? LIMIT 1');
  $q->execute([$token]);
  $agency=$q->fetch();
  if(!$agency){
    $error='Bu doğrulama bağlantısı geçersiz veya daha önce kullanılmış.';
  }elseif($agency['verified_at']){
    $message='E-postanız zaten doğrulanmış. Yönetici onayından sonra giriş yapabilirsiniz.';
  }else{
    db()->prepare('UPDATE agencies SET verified_at=now(),verify_token=NULL WHERE id=?')->execute([(int)$agency['id']]);
    $message='E-postanız doğrulandı. Hesabınız yönetici onayından sonra aktifleşir; onaylandığında bilgilendirilirsiniz.';
  }
}
?>
<!doctype html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>E-posta doğrulama | NEXUS</title>
<style>body{min-height:100vh;margin:0;display:grid;place-items:center;font-family:Arial;background:#10211f;color:#fff}.c{background:#fff;color:#10211f;width:min(440px,calc(100% - 30px));padding:30px;text-align:center}.ok{background:#e6f8c7;padding:12px}.e{background:#ffe2de;color:#8e2410;padding:12px}a{color:#0d7a4a;font-weight:700}</style>
</head><body><div class="c">
<h1 style="margin:0 0 12px">NEXUS</h1>
<?php if($message):?><p class="ok"><?=htmlspecialchars($message)?></p><?php endif;?>
<?php if($error):?><p class="e"><?=htmlspecialchars($error)?></p><?php endif;?>
<p><a href="/nexustraveltech/acente/login">Giriş sayfasına dön →</a></p>
</div></body></html>
