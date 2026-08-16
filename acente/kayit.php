<?php
declare(strict_types=1);
require_once __DIR__.'/../config/agency_auth.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../config/mailer.php';
agency_session();
if(empty($_SESSION['agency_csrf']))$_SESSION['agency_csrf']=bin2hex(random_bytes(32));

$done=false;$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['agency_csrf'],(string)($_POST['csrf']??''))){
    $error='Güvenlik doğrulaması geçersiz.';
  }elseif(trim((string)($_POST['website']??''))!==''){
    $error='Form gönderimi engellendi.';
  }else{
    $company=trim((string)($_POST['company_name']??''));
    $license=trim((string)($_POST['license_number']??''));
    $fullName=trim((string)($_POST['full_name']??''));
    $email=filter_var($_POST['email']??'',FILTER_VALIDATE_EMAIL);
    $password=(string)($_POST['password']??'');
    $password2=(string)($_POST['password2']??'');
    $country=mb_strtoupper(mb_substr(trim((string)($_POST['country_code']??'TR')),0,2));
    if($company===''||$fullName===''||!$email||strlen($password)<10){$error='Ünvan, yetkili, geçerli e-posta ve en az 10 karakter şifre zorunludur.';}
    elseif($password!==$password2){$error='Şifreler eşleşmiyor.';}
    else{
      try{
        $pdo=db();
        $check=$pdo->prepare('SELECT 1 FROM agency_users WHERE email=? LIMIT 1');
        $check->execute([$email]);
        if($check->fetch())throw new RuntimeException('Bu e-posta ile bir hesap zaten kayıtlı.');
        $pdo->beginTransaction();
        $token=bin2hex(random_bytes(32));
        $q=$pdo->prepare("INSERT INTO agencies(company_name,license_number,country_code,status,self_registered,verify_token) VALUES(?,?,?,'pending',true,?) RETURNING id");
        $q->execute([$company,$license!==''?$license:null,$country,$token]);
        $agencyId=(int)$q->fetchColumn();
        $pdo->prepare("INSERT INTO agency_users(agency_id,full_name,email,password_hash,role) VALUES(?,?,?,?,'owner')")
          ->execute([$agencyId,$fullName,$email,password_hash($password,PASSWORD_DEFAULT)]);
        $pdo->commit();
        $verifyUrl='https://nexustraveltech.com/acente/onay?token='.$token;
        queue_email($email,'NEXUS hesabınızı doğrulayın','<p>Merhaba '.htmlspecialchars($fullName).',</p><p>NEXUS TravelTech acente hesabınızı doğrulamak için <a href="'.$verifyUrl.'">bu bağlantıyı</a> kullanın.</p><p>Bağlantı çalışmıyorsa: '.$verifyUrl.'</p><p>NEXUS TravelTech</p>','agency_verify',$agencyId);
        $done=true;
      }catch(Throwable $e){
        if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
        $error=$e->getMessage();
      }
    }
  }
}
?>
<!doctype html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Acente kaydı | NEXUS</title>
<style>body{min-height:100vh;margin:0;display:grid;place-items:center;font-family:Arial;background:#10211f;color:#fff}.c{background:#fff;color:#10211f;width:min(480px,calc(100% - 30px));padding:30px;box-sizing:border-box}.c input{width:100%;box-sizing:border-box;padding:11px;margin:6px 0;border:1px solid #d8ded8}.c button{width:100%;padding:13px;border:0;background:#d7ff48;font-weight:800;cursor:pointer;margin-top:10px}.e{background:#ffe2de;color:#8e2410;padding:10px}.ok{background:#e6f8c7;color:#10211f;padding:10px}label{font-size:12px;font-weight:700}.muted{color:#64716d;font-size:13px}a{color:#0d7a4a}.hide{position:absolute;left:-9999px}</style>
</head><body><div class="c">
<h1 style="margin:0 0 4px">NEXUS Acenta kaydı</h1>
<p class="muted">Kayıt olduktan sonra e-posta doğrulaması ve yönetici onayıyla hesabınız aktifleşir.</p>
<?php if($done):?>
  <p class="ok"><b>✓ Kaydınız alındı.</b><br>E-posta adresinize doğrulama bağlantısı gönderdik. Doğruladıktan sonra yönetici onayı için kısa bir süre bekleyin.</p>
  <p class="muted"><a href="/nexustraveltech/acente/login">Giriş sayfasına dön →</a></p>
<?php else:?>
<?php if($error):?><p class="e"><?=htmlspecialchars($error)?></p><?php endif;?>
<form method="post">
  <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['agency_csrf'])?>">
  <label>Acente ünvanı</label><input name="company_name" required>
  <label>TÜRSAB / lisans no (opsiyonel)</label><input name="license_number">
  <div class="hide" aria-hidden="true"><label>Web sitesi (boş bırakın)</label><input name="website" tabindex="-1" autocomplete="off"></div>
  <label>Yetkili ad soyad</label><input name="full_name" required>
  <label>İş e-postası</label><input type="email" name="email" required>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
    <div><label>Şifre (en az 10 karakter)</label><input type="password" name="password" minlength="10" required></div>
    <div><label>Şifre tekrar</label><input type="password" name="password2" minlength="10" required></div>
  </div>
  <label>Ülke kodu</label><input name="country_code" value="TR" maxlength="2">
  <button type="submit">Kayıt ol</button>
  <p class="muted" style="margin-top:10px">Kayıtla birlikte <a href="/iletisim">KVKK aydınlatma metnini</a> kabul etmiş olursunuz. <a href="/nexustraveltech/acente/login">Zaten hesabınız var mı?</a></p>
</form>
<?php endif;?>
</div></body></html>
