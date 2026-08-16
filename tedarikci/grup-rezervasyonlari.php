<?php
declare(strict_types=1);
$active_module='groups';
require_once __DIR__.'/layout.php';
if(empty($_SESSION['supplier_csrf']))$_SESSION['supplier_csrf']=bin2hex(random_bytes(32));
$u=$supplier_user;
$pdo=db();
$message='';$error='';

try{
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['supplier_csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('Güvenlik doğrulaması geçersiz.');
  $action=$_POST['action']??'';
  if($action==='create'){
    $propertyId=(int)($_POST['property_id']??0);
    $code=mb_strtoupper(trim((string)($_POST['group_code']??'')));
    $name=trim((string)($_POST['group_name']??''));
    $agencyId=(int)($_POST['agency_id']??0)?:null;
    $status=in_array($_POST['status']??'option',['option','confirmed','cancelled','lost'],true)?$_POST['status']:'option';
    $expires=trim((string)($_POST['option_expires_at']??''));
    $notes=trim((string)($_POST['notes']??''));
    if(!$propertyId||$code===''||$name===''||!preg_match('/^[A-Z0-9\-]{2,30}$/',$code))throw new RuntimeException('Ürün, grup kodu (harf/rakam/-) ve ad zorunludur.');
    $q=$pdo->prepare('SELECT id FROM properties WHERE id=? AND supplier_id=?');
    $q->execute([$propertyId,$u['supplier_id']]);
    if(!$q->fetch())throw new RuntimeException('Ürün bulunamadı.');
    $pdo->prepare('INSERT INTO booking_groups(supplier_id,property_id,group_code,group_name,agency_id,status,option_expires_at,notes) VALUES(?,?,?,?,?,?,?,?)')
      ->execute([$u['supplier_id'],$propertyId,$code,$name,$agencyId,$status,$expires!==''?$expires:null,$notes!==''?$notes:null]);
    $message='Grup oluşturuldu: '.$code;
  }
  if($action==='assign'){
    $bookingId=(int)($_POST['booking_id']??0);
    $groupId=(int)($_POST['group_id']??0);
    $q=$pdo->prepare('UPDATE supplier_bookings SET group_id=? WHERE id=? AND supplier_id=? AND status NOT IN (\'cancelled\',\'rejected\')');
    $q->execute([$groupId?:null,$bookingId,$u['supplier_id']]);
    $message=$q->rowCount()>0?'Rezervasyon gruba atandı.':'Rezervasyon atanamadı.';
  }
  if($action==='unassign'){
    $bookingId=(int)($_POST['booking_id']??0);
    $pdo->prepare('UPDATE supplier_bookings SET group_id=NULL WHERE id=? AND supplier_id=?')->execute([$bookingId,$u['supplier_id']]);
    $message='Rezervasyon gruptan çıkarıldı.';
  }
}
}catch(Throwable $e){$error=$e->getMessage();}

$properties=$pdo->prepare('SELECT id,name FROM properties WHERE supplier_id=? AND status IN (\'draft\',\'active\') ORDER BY name');
$properties->execute([$u['supplier_id']]);
$propertyList=$properties->fetchAll();
$agencies=$pdo->query("SELECT id,company_name FROM agencies WHERE status='active' ORDER BY company_name")->fetchAll();

$q=$pdo->prepare("SELECT g.*,p.name property_name,(SELECT COUNT(*) FROM supplier_bookings b WHERE b.group_id=g.id) booking_count,(SELECT COALESCE(SUM(b.total_amount),0) FROM supplier_bookings b WHERE b.group_id=g.id AND b.status NOT IN ('cancelled','rejected')) group_revenue FROM booking_groups g JOIN properties p ON p.id=g.property_id WHERE g.supplier_id=? ORDER BY g.id DESC");
$q->execute([$u['supplier_id']]);
$groups=$q->fetchAll();

$q=$pdo->prepare("SELECT b.id,b.booking_reference,b.check_in,b.check_out,b.total_amount,b.currency,p.name property_name FROM supplier_bookings b JOIN properties p ON p.id=b.property_id WHERE b.supplier_id=? AND b.group_id IS NULL AND b.status NOT IN ('cancelled','rejected') ORDER BY b.check_in DESC LIMIT 80");
$q->execute([$u['supplier_id']]);
$unassigned=$q->fetchAll();

$q=$pdo->prepare("SELECT b.id,b.booking_reference,g.group_code,p.name property_name FROM supplier_bookings b JOIN booking_groups g ON g.id=b.group_id JOIN properties p ON p.id=b.property_id WHERE b.supplier_id=? AND b.group_id IS NOT NULL ORDER BY b.id DESC LIMIT 80");
$q->execute([$u['supplier_id']]);
$assigned=$q->fetchAll();

supply_start('Grup rezervasyonları',$active_module);?>
<section class="page-intro"><p>Tur operatörü ve kurumsal grupları tek grup kodu altında toplayın; opsiyon süresi ve durumuyla takip edin. Rezervasyonları gruba atayarak gelir ve oda dağılımını grup bazında görün.</p></section>
<?php if($message):?><p class="save-success">✓ <?=htmlspecialchars($message)?></p><?php endif;?>
<?php if($error):?><p class="login-error"><?=htmlspecialchars($error)?></p><?php endif;?>
<section class="next-module"><h2>Yeni grup</h2>
<form method="post" class="supply-form" style="max-width:560px"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>"><input type="hidden" name="action" value="create">
<div class="form-row"><label>Ürün<select name="property_id" required><?php foreach($propertyList as $p):?><option value="<?=$p['id']?>"><?=htmlspecialchars($p['name'])?></option><?php endforeach;?></select></label><label>Grup kodu<input name="group_code" placeholder="TUI-2026-15" required></label></div>
<div class="form-row"><label>Grup adı<input name="group_name" placeholder="TUI Almanya Turu — Haziran" required></label><label>Acente (opsiyonel)<select name="agency_id"><option value="0">— Seçilmedi —</option><?php foreach($agencies as $a):?><option value="<?=$a['id']?>"><?=htmlspecialchars($a['company_name'])?></option><?php endforeach;?></select></label></div>
<div class="form-row"><label>Durum<select name="status"><option value="option">Opsiyon</option><option value="confirmed">Kesinleşti</option><option value="lost">Kaybedildi</option><option value="cancelled">İptal</option></select></label><label>Opsiyon sonu<input type="date" name="option_expires_at"></label></div>
<label>Not<textarea name="notes" rows="2" placeholder="Tur bilgileri, depozito şartı…"></textarea></label>
<button>Grubu oluştur</button></form></section>
<section class="next-module"><h2>Gruplar</h2>
<?php if(!$groups):?><p class="muted">Henüz grup yok.</p><?php endif;?>
<?php foreach($groups as $g):?>
<article><div class="availability-row"><div><b><?=htmlspecialchars($g['group_code'])?> · <?=htmlspecialchars($g['group_name'])?></b><span><?=htmlspecialchars($g['property_name'])?> · <?=htmlspecialchars($g['status'])?><?= $g['option_expires_at']?' · opsiyon sonu '.htmlspecialchars((string)$g['option_expires_at']):''?></span></div><strong><?=(int)$g['booking_count']?> rezervasyon · <?=number_format((float)$g['group_revenue'],2)?> EUR</strong></div>
<?php if($g['notes']):?><p class="muted"><?=nl2br(htmlspecialchars($g['notes']))?></p><?php endif;?>
<form method="post" class="supply-form" style="max-width:480px"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>"><input type="hidden" name="action" value="assign"><input type="hidden" name="group_id" value="<?=$g['id']?>"><label>Rezervasyon ata<select name="booking_id" required><option value="">— Seç —</option><?php foreach($unassigned as $b):?><option value="<?=$b['id']?>"><?=htmlspecialchars($b['property_name'].' · '.$b['booking_reference'].' · '.$b['check_in'])?></option><?php endforeach;?></select></label><button>Gruba ata</button></form>
</article><?php endforeach;?>
</section>
<section class="next-module"><h2>Grup üyeleri</h2>
<?php if(!$assigned):?><p class="muted">Gruba atanmış rezervasyon yok.</p><?php endif;?>
<?php foreach($assigned as $b):?>
<div class="availability-row"><div><b><?=htmlspecialchars($b['property_name'])?> · <?=htmlspecialchars($b['booking_reference'])?></b><span><?=htmlspecialchars($b['group_code'])?></span></div>
<form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>"><input type="hidden" name="action" value="unassign"><input type="hidden" name="booking_id" value="<?=$b['id']?>"><button>Gruptan çıkar</button></form></div>
<?php endforeach;?>
</section>
<?php supply_end(); ?>
