<?php
declare(strict_types=1);
$active_module='compliance';
require_once __DIR__.'/layout.php';
require_once __DIR__.'/../config/ai_settings.php';
if(empty($_SESSION['supplier_csrf']))$_SESSION['supplier_csrf']=bin2hex(random_bytes(32));
$u=$supplier_user;
$pdo=db();
$message='';$error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['supplier_csrf'],(string)($_POST['csrf']??''))){$error='Güvenlik doğrulaması geçersiz.';}
  else{
    $id=(int)($_POST['record_id']??0);
    $q=$pdo->prepare('UPDATE guest_document_records SET reported_at=now() WHERE id=? AND guest_id IN (SELECT id FROM guest_profiles WHERE supplier_id=?)');
    $q->execute([$id,$u['supplier_id']]);
    $message=$q->rowCount()?'Kayıt bildirildi olarak işaretlendi.':'Kayıt bulunamadı.';
  }
}

$from=(string)($_GET['from']??date('Y-m-d',strtotime('-30 day')));
$to=(string)($_GET['to']??date('Y-m-d'));
$property=(int)($_GET['property']??0);
if($from>$to){$t=$from;$from=$to;$to=$t;}

$where='p.supplier_id=? AND gdr.created_at::date BETWEEN ? AND ?';
$params=[$u['supplier_id'],$from,$to];
if($property>0){$where.=' AND p.id=?';$params[]=$property;}
$sql="SELECT gdr.id,gdr.document_type,gdr.document_number_masked,gdr.verification_status,gdr.consent_at,gdr.reported_at,gdr.created_at,gp.id guest_id,gp.first_name,gp.last_name,gp.nationality,gp.identity_type,gp.identity_number,b.booking_reference,p.name property_name FROM guest_document_records gdr JOIN guest_profiles gp ON gp.id=gdr.guest_id JOIN supplier_bookings b ON b.id=gdr.booking_id JOIN properties p ON p.id=b.property_id WHERE $where ORDER BY gdr.created_at DESC";
$q=$pdo->prepare($sql);
$q->execute($params);
$rows=$q->fetchAll();

$q=$pdo->prepare('SELECT id,name FROM properties WHERE supplier_id=? ORDER BY name');
$q->execute([$u['supplier_id']]);
$hotels=$q->fetchAll();

// Kimlik bilgisini çöz (online check-in sırasında app_encryption_key ile şifrelenmişti).
function identity_value(?string $encrypted, ?string $masked): string {
  if($encrypted==='')return (string)($masked??'—');
  try{return decrypt_ai_secret($encrypted);}catch(Throwable $e){return (string)($masked??'—');}
}

if(($_GET['export']??'')==='kbs_xml'){
  header('Content-Type: application/xml; charset=utf-8');
  header('Content-Disposition: attachment; filename="kbs-bildirim-'.$from.'-'.$to.'.xml"');
  $xml = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><KBSBildirimListesi/>');
  $xml->addAttribute('Tarih', date('Y-m-d H:i:s'));
  $xml->addAttribute('KayitSayisi', (string)count($rows));
  foreach($rows as $r){
    $item = $xml->addChild('Konaklayan');
    $item->addChild('TesisAdi', htmlspecialchars($r['property_name']));
    $item->addChild('RezervasyonNo', htmlspecialchars($r['booking_reference']));
    $item->addChild('Adi', htmlspecialchars($r['first_name']));
    $item->addChild('Soyadi', htmlspecialchars($r['last_name']));
    $item->addChild('Uyruk', htmlspecialchars($r['nationality'] ?? 'TR'));
    $item->addChild('BelgeTuru', htmlspecialchars((string)($r['identity_type']??$r['document_type'])));
    $item->addChild('KimlikNo', htmlspecialchars(identity_value((string)$r['identity_number'], (string)$r['document_number_masked'])));
    $item->addChild('GirisTarihi', htmlspecialchars($r['created_at']));
  }
  echo $xml->asXML();
  exit;
}

if(($_GET['export']??'')==='csv'){
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="kimlik-bildirimi-'.$from.'-'.$to.'.csv"');
  $out=fopen('php://output','w');
  fwrite($out,"\xEF\xBB\xBF"); // BOM
  fputcsv($out,['Tarih','Tesis','Rezervasyon','Misafir','Uyruk','Belge Türü','Kimlik No','Durum','Bildirim']);
  foreach($rows as $r){
    fputcsv($out,[$r['created_at'],$r['property_name'],$r['booking_reference'],$r['first_name'].' '.$r['last_name'],$r['nationality']??'',(string)($r['identity_type']??$r['document_type']),identity_value((string)$r['identity_number'],(string)$r['document_number_masked']),$r['verification_status'],$r['reported_at']?'Bildirildi':'Bildirilmedi']);
  }
  fclose($out);
  exit;
}

supply_start('Kimlik bildirimi (KBS)',$active_module);?>
<section class="page-intro"><p>Online check-in sırasında toplanan misafir kimlik kayıtları. Emniyet ve Jandarma KBS entegrasyonu için kayıtları görüntüleyin, KBS XML veya CSV formatında tek tıkla dışa aktarın.</p></section>
<div style="display:flex;gap:10px;margin-bottom:16px">
  <a class="btn-primary" href="?export=kbs_xml&from=<?=htmlspecialchars($from)?>&to=<?=htmlspecialchars($to)?>" style="background:#13593b;color:#fff;padding:8px 16px;border-radius:6px;text-decoration:none;font-weight:bold">📄 Emniyet/Jandarma KBS XML İndir</a>
  <a class="btn-primary" href="?export=csv&from=<?=htmlspecialchars($from)?>&to=<?=htmlspecialchars($to)?>" style="background:#10211f;color:#fff;padding:8px 16px;border-radius:6px;text-decoration:none;font-weight:bold">📊 Excel (CSV) İndir</a>
</div>
<?php if($message):?><p class="save-success">✓ <?=htmlspecialchars($message)?></p><?php endif;?>
<?php if($error):?><p class="login-error"><?=htmlspecialchars($error)?></p><?php endif;?>
<form method="get" class="supply-form" style="max-width:760px"><div class="form-row">
<label>Başlangıç<input type="date" name="from" value="<?=htmlspecialchars($from)?>"></label>
<label>Bitiş<input type="date" name="to" value="<?=htmlspecialchars($to)?>"></label>
<label>Tesis<select name="property"><option value="0">Tümü</option><?php foreach($hotels as $h):?><option value="<?=$h['id']?>" <?=$property===$h['id']?'selected':''?>><?=htmlspecialchars($h['name'])?></option><?php endforeach;?></select></label>
<button>Filtrele</button></div></form>
<section class="next-module"><div class="form-row"><h2><?=count($rows)?> kayıt</h2><a href="?from=<?=urlencode($from)?>&to=<?=urlencode($to)?>&property=<?=$property?>&export=csv"><button>CSV indir</button></a></div>
<?php if(!$rows):?><p class="muted">Seçilen aralıkta kayıt yok. Online check-in tamamlandıkça kimlik kayıtları burada birikir.</p><?php endif;?>
<?php foreach($rows as $r):?>
<article><div class="availability-row"><div><b><?=htmlspecialchars($r['property_name'])?> · <?=htmlspecialchars($r['booking_reference'])?></b><span><?=htmlspecialchars((string)$r['created_at'])?> · <?=htmlspecialchars($r['verification_status'])?></span></div>
<?php if(!$r['reported_at']):?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>"><input type="hidden" name="record_id" value="<?=$r['id']?>"><button>Bildirildi olarak işaretle</button></form><?php else:?><strong>Bildirildi</strong><?php endif;?></div>
<p><b><?=htmlspecialchars($r['first_name'].' '.$r['last_name'])?></b> · <?=htmlspecialchars((string)($r['nationality']??'—'))?> · <?=htmlspecialchars((string)($r['identity_type']??$r['document_type']))?> · <b><?=htmlspecialchars(identity_value((string)$r['identity_number'],(string)$r['document_number_masked']))?></b><?= $r['reported_at']?' · Bildirim: '.htmlspecialchars((string)$r['reported_at']):''?></p>
</article>
<?php endforeach;?>
</section>
<?php supply_end(); ?>
