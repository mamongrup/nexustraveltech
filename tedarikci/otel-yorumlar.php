<?php
declare(strict_types=1);
$active_module='reviews';
require_once __DIR__.'/layout.php';
require_once __DIR__.'/../config/mailer.php';
require_once __DIR__.'/../config/ai_settings.php';
if(empty($_SESSION['supplier_csrf']))$_SESSION['supplier_csrf']=bin2hex(random_bytes(32));
$u=$supplier_user;
$pdo=db();
$message='';$error='';
$translations=[];

try{
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['supplier_csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('Güvenlik doğrulaması geçersiz.');
  $action=$_POST['action']??'';
  if($action==='link'){
    $bookingId=(int)($_POST['booking_id']??0);
    $q=$pdo->prepare('SELECT b.property_id FROM supplier_bookings b WHERE b.id=? AND b.supplier_id=? AND b.check_out<? AND b.status NOT IN (\'cancelled\',\'rejected\')');
    $q->execute([$bookingId,$u['supplier_id'],date('Y-m-d')]);
    $propertyId=$q->fetchColumn();
    $q=$pdo->prepare('SELECT 1 FROM guest_reviews WHERE booking_id=?');
    $q->execute([$bookingId]);
    if(!$propertyId||$q->fetch())throw new RuntimeException('Bu rezervasyon için link oluşturulamaz.');
    $token=bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO guest_reviews(property_id,booking_id,token_hash,status) VALUES(?,?,?,'invited')")->execute([$propertyId,$bookingId,hash('sha256',$token)]);
    $url='https://nexustraveltech.com/misafir/degerlendirme?token='.$token;
    $q=$pdo->prepare('SELECT gp.email FROM booking_guests bg JOIN guest_profiles gp ON gp.id=bg.guest_id WHERE bg.booking_id=? AND bg.is_primary=true LIMIT 1');
    $q->execute([$bookingId]);
    $guestEmail=$q->fetchColumn();
    if($guestEmail){
      queue_email($guestEmail,'Konaklamanızı değerlendirin','<p>Merhaba,</p><p>Konaklamanızı değerlendirmeniz için <a href="'.$url.'">bu bağlantıyı</a> kullanabilirsiniz.</p><p>NEXUS TravelTech</p>','guest_review',$bookingId);
      $message='Değerlendirme linki oluşturuldu ve '.htmlspecialchars($guestEmail).' adresine gönderildi.';
    }else{
      $message='Değerlendirme linki oluşturuldu: '.$url;
    }
  }
  if(in_array($action,['publish','hide'],true)){
    $id=(int)($_POST['review_id']??0);
    $q=$pdo->prepare('SELECT r.id FROM guest_reviews r JOIN properties p ON p.id=r.property_id WHERE r.id=? AND p.supplier_id=?');
    $q->execute([$id,$u['supplier_id']]);
    if(!$q->fetch())throw new RuntimeException('Değerlendirme bulunamadı.');
    $pdo->prepare("UPDATE guest_reviews SET status=? WHERE id=?")->execute([$action==='publish'?'published':'hidden',$id]);
    $message=$action==='publish'?'Değerlendirme yayınlandı.':'Değerlendirme gizlendi.';
  }
  if($action==='respond'){
    $id=(int)($_POST['review_id']??0);
    $response=trim((string)($_POST['response']??''));
    if($response==='')throw new RuntimeException('Yanıt metni boş olamaz.');
    $q=$pdo->prepare('SELECT r.id FROM guest_reviews r JOIN properties p ON p.id=r.property_id WHERE r.id=? AND p.supplier_id=?');
    $q->execute([$id,$u['supplier_id']]);
    if(!$q->fetch())throw new RuntimeException('Değerlendirme bulunamadı.');
    $pdo->prepare('UPDATE guest_reviews SET response=?,responded_by=?,response_at=now() WHERE id=?')->execute([$response,$u['id'],$id]);
    $message='Yanıtınız kaydedildi.';
  }
  if($action==='translate'){
    $id=(int)($_POST['review_id']??0);
    $q=$pdo->prepare('SELECT r.title,r.body FROM guest_reviews r JOIN properties p ON p.id=r.property_id WHERE r.id=? AND p.supplier_id=?');
    $q->execute([$id,$u['supplier_id']]);
    $row=$q->fetch();
    if(!$row||trim((string)($row['body']??'')).trim((string)($row['title']??''))==='')throw new RuntimeException('Değerlendirme bulunamadı veya çevrilecek metin yok.');
    $source=trim((string)$row['title']).(trim((string)$row['title'])!==''&&trim((string)$row['body'])!==''?"\n":'').trim((string)$row['body']);
    $translations[$id]=ai_translate($source,'Türkçe');
    $message='Çeviri oluşturuldu.';
  }
}
}catch(Throwable $e){$error=$e->getMessage();}

$q=$pdo->prepare('SELECT id,name FROM properties WHERE supplier_id=? ORDER BY name');
$q->execute([$u['supplier_id']]);
$hotels=$q->fetchAll();

$q=$pdo->prepare("SELECT r.*,p.name property_name,b.booking_reference FROM guest_reviews r JOIN properties p ON p.id=r.property_id LEFT JOIN supplier_bookings b ON b.id=r.booking_id WHERE p.supplier_id=? ORDER BY CASE WHEN r.status IN ('invited','pending') THEN 0 ELSE 1 END,r.id DESC LIMIT 200");
$q->execute([$u['supplier_id']]);
$reviews=$q->fetchAll();

$q=$pdo->prepare("SELECT b.id,b.booking_reference,b.check_in,b.check_out,b.total_amount,b.currency,p.name property_name FROM supplier_bookings b JOIN properties p ON p.id=b.property_id WHERE b.supplier_id=? AND b.check_out<? AND b.status NOT IN ('cancelled','rejected') AND NOT EXISTS (SELECT 1 FROM guest_reviews g WHERE g.booking_id=b.id) ORDER BY b.check_out DESC LIMIT 50");
$q->execute([$u['supplier_id'],date('Y-m-d')]);
$eligible=$q->fetchAll();

supply_start('Misafir değerlendirmeleri',$active_module);?>
<section class="page-intro"><p>Konaklama sonrası misafirlerinize göndereceğiniz değerlendirme linkleri ve gelen yorumlar. Yayınlamadan önce moderasyondan geçirip yanıtlayabilirsiniz.</p></section>
<?php if($message):?><p class="save-success"><?=htmlspecialchars($message)?></p><?php endif;?>
<?php if($error):?><p class="login-error"><?=htmlspecialchars($error)?></p><?php endif;?>
<section class="next-module"><h2>Değerlendirme linki gönderilecek rezervasyonlar</h2>
<?php if(!$eligible):?><p class="muted">Çıkışı tamamlanmış ve değerlendirme linki oluşturulmamış rezervasyon yok.</p><?php endif;?>
<?php foreach($eligible as $b):?><article><div class="availability-row"><div><b><?=htmlspecialchars($b['property_name'])?> · <?=htmlspecialchars($b['booking_reference'])?></b><span><?=htmlspecialchars($b['check_in'])?> / <?=htmlspecialchars($b['check_out'])?> · <?=number_format((float)$b['total_amount'],2)?> <?=htmlspecialchars($b['currency'])?></span></div><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>"><input type="hidden" name="action" value="link"><input type="hidden" name="booking_id" value="<?=$b['id']?>"><button>Link oluştur</button></form></div></article><?php endforeach;?>
</section>
<section class="next-module"><h2>Değerlendirmeler</h2>
<?php if(!$reviews):?><p class="muted">Henüz değerlendirme yok.</p><?php endif;?>
<?php foreach($reviews as $r):?>
<article><div class="availability-row"><div><b><?=htmlspecialchars($r['property_name'])?> · <?=htmlspecialchars($r['guest_name']??$r['booking_reference']??'Misafir')?></b><span><?=htmlspecialchars($r['status'])?><?= $r['rating']?' · ★ '.$r['rating'].'/5':''?> · <?=htmlspecialchars((string)$r['created_at'])?></span></div>
<div class="form-row"><?php if($r['status']!=='published'&&$r['status']!=='invited'):?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>"><input type="hidden" name="action" value="publish"><input type="hidden" name="review_id" value="<?=$r['id']?>"><button>Yayınla</button></form><?php endif;?><?php if($r['status']!=='hidden'&&$r['status']!=='invited'):?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>"><input type="hidden" name="action" value="hide"><input type="hidden" name="review_id" value="<?=$r['id']?>"><button>Gizle</button></form><?php endif;?><?php if(trim((string)$r['body'])!==''||trim((string)$r['title'])!==''):?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>"><input type="hidden" name="action" value="translate"><input type="hidden" name="review_id" value="<?=$r['id']?>"><button>Türkçeye çevir</button></form><?php endif;?></div></div>
<?php if(isset($translations[$r['id']])):?><p style="background:#edf4ff;padding:8px"><b>Çeviri (Türkçe):</b> <?=nl2br(htmlspecialchars($translations[$r['id']]))?></p><?php endif;?>
<?php if($r['title']):?><p><b><?=htmlspecialchars($r['title'])?></b></p><?php endif;?>
<?php if($r['body']):?><p><?=nl2br(htmlspecialchars($r['body']))?></p><?php endif;?>
<?php if($r['response']):?><p class="muted"><b>Yanıtınız:</b> <?=htmlspecialchars($r['response'])?></p><?php endif;?>
<?php if($r['status']==='pending'||$r['status']==='published'):?><form method="post" class="supply-form" style="max-width:520px"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>"><input type="hidden" name="action" value="respond"><input type="hidden" name="review_id" value="<?=$r['id']?>"><label>Yanıtınız<textarea name="response" rows="2" placeholder="Misafirin yorumuna kurumsal yanıtınız"><?=htmlspecialchars((string)$r['response'])?></textarea></label><button>Yanıtı kaydet</button></form><?php endif;?>
</article>
<?php endforeach;?>
</section>
<?php supply_end(); ?>
