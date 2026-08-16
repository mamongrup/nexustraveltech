<?php
declare(strict_types=1);
$active_module='bildirimler';
require_once __DIR__.'/layout.php';
require_once __DIR__.'/../config/notifications.php';
if(empty($_SESSION['supplier_csrf']))$_SESSION['supplier_csrf']=bin2hex(random_bytes(32));
$u=$supplier_user;
$pdo=db();
$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['supplier_csrf'],(string)($_POST['csrf']??''))){$error='Güvenlik doğrulaması geçersiz.';}
  else{mark_notifications_read('supplier',(int)$u['id']);$message='Tüm bildirimler okundu olarak işaretlendi.';}
}
$items=recent_notifications('supplier',(int)$u['id'],50);
supply_start('Bildirimler',$active_module);?>
<section class="page-intro"><p>Rezervasyon talepleri, ödemeler, misafir değerlendirmeleri ve doğrulama sonuçları burada toplanır.</p></section>
<?php if($message):?><p class="save-success">✓ <?=htmlspecialchars($message)?></p><?php endif;?>
<?php if($error):?><p class="login-error"><?=htmlspecialchars($error)?></p><?php endif;?>
<?php if(!$items):?><p class="muted">Henüz bildirim yok.</p><?php endif;?>
<?php foreach($items as $n):?>
<article><div class="availability-row"><div><b><?=htmlspecialchars($n['message'])?></b><?php if(!$n['is_read']):?><span class="muted"> · yeni</span><?php endif;?><span><?=htmlspecialchars($n['type'])?> · <?=htmlspecialchars((string)$n['created_at'])?></span></div><?php if($n['link']):?><a href="<?=htmlspecialchars($n['link'])?>">Görüntüle →</a><?php endif;?></div></article>
<?php endforeach;?>
<?php if($items):?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>"><button>Tümünü okundu işaretle</button></form><?php endif;?>
<?php supply_end(); ?>
