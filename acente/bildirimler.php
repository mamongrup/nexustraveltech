<?php
declare(strict_types=1);
require_once __DIR__.'/../config/agency_auth.php';
require_once __DIR__.'/../config/notifications.php';
$u=require_agency();
if(empty($_SESSION['agency_csrf']))$_SESSION['agency_csrf']=bin2hex(random_bytes(32));
$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['agency_csrf'],(string)($_POST['csrf']??''))){$error='Güvenlik doğrulaması geçersiz.';}
  else{mark_notifications_read('agency',(int)$u['id']);$message='Tüm bildirimler okundu olarak işaretlendi.';}
}
$items=recent_notifications('agency',(int)$u['id'],50);
?>
<!doctype html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bildirimler | NEXUS Acenta</title>
<style>body{margin:0;font-family:Arial;background:#f7f7f2;color:#10211f}.w{width:min(900px,calc(100% - 32px));margin:35px auto}.c{background:#fff;border:1px solid #ddd;padding:16px;margin:12px 0;display:flex;justify-content:space-between;gap:12px;align-items:center}.muted{color:#64716d;font-size:13px}.ok{background:#e6f8c7;padding:9px}.er{background:#ffe2de;padding:9px}button{background:#10211f;color:#fff;border:0;padding:10px 14px;font-weight:700;cursor:pointer}</style>
</head><body><main class="w"><a href="/nexustraveltech/acente/">← Panel</a><h1>Bildirimler</h1>
<?php if($message):?><p class="ok"><?=htmlspecialchars($message)?></p><?php endif;?>
<?php if($error):?><p class="er"><?=htmlspecialchars($error)?></p><?php endif;?>
<?php if(!$items):?><p class="muted">Henüz bildirim yok.</p><?php endif;?>
<?php foreach($items as $n):?>
<div class="c"><div><b><?=htmlspecialchars($n['message'])?></b><?php if(!$n['is_read']):?> <span class="muted">· yeni</span><?php endif;?><p class="muted"><?=htmlspecialchars($n['type'])?> · <?=htmlspecialchars((string)$n['created_at'])?></p></div><?php if($n['link']):?><a href="<?=htmlspecialchars($n['link'])?>">Görüntüle →</a><?php endif;?></div>
<?php endforeach;?>
<?php if($items):?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['agency_csrf'])?>"><button>Tümünü okundu işaretle</button></form><?php endif;?>
</main><?php require_once __DIR__.'/../config/ai_widget.php'; ai_widget('/nexustraveltech/acente/ai-chat','agency_csrf'); ?></body></html>
