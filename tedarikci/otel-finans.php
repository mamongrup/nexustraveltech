<?php
declare(strict_types=1);
$active_module='hotel_finance'; require_once __DIR__.'/layout.php';
if(empty($_SESSION['supplier_csrf'])) $_SESSION['supplier_csrf']=bin2hex(random_bytes(32));
$u=$supplier_user; $pdo=db(); $message=''; $error='';
try {
 if($_SERVER['REQUEST_METHOD']==='POST') {
  if(!hash_equals($_SESSION['supplier_csrf'],(string)($_POST['csrf']??''))) throw new RuntimeException('Güvenlik doğrulaması geçersiz.');
  $action=(string)($_POST['action']??'');
  if($action==='payment') {
   $folio=(int)$_POST['folio_id']; $amount=(float)$_POST['amount']; if($amount<=0) throw new RuntimeException('Ödeme tutarı sıfırdan büyük olmalıdır.');
   $q=$pdo->prepare('SELECT f.id,f.booking_id,f.currency FROM booking_folios f JOIN supplier_bookings b ON b.id=f.booking_id WHERE f.id=? AND b.supplier_id=?'); $q->execute([$folio,$u['supplier_id']]); $f=$q->fetch(); if(!$f) throw new RuntimeException('Folio bulunamadı.');
   $pdo->beginTransaction(); $reference='PAY-'.date('YmdHis').'-'.random_int(100,999);
   $stmt=$pdo->prepare("INSERT INTO payment_records(supplier_id,booking_id,payment_reference,payment_method,amount,currency,status,provider_transaction_id) VALUES(?,?,?,?,?,?, 'captured',?) RETURNING id");
   $stmt->execute([$u['supplier_id'],$f['booking_id'],$reference,$_POST['payment_method']??'cash',$amount,$f['currency'],trim((string)$_POST['provider_reference'])?:null]); $paymentId=(int)$stmt->fetchColumn();
   $pdo->prepare('INSERT INTO payment_allocations(payment_id,folio_id,amount) VALUES(?,?,?)')->execute([$paymentId,$folio,$amount]);
   $pdo->prepare("INSERT INTO folio_transactions(folio_id,transaction_type,department,description,amount,created_by) VALUES(?,'payment','Front Office',?, ?,?)")->execute([$folio,'Tahsilat '.$reference,-$amount,$u['id']]);
   $pdo->commit(); $message='Tahsilat kaydedildi: '.$reference;
  }
  if($action==='invoice') {
   $folio=(int)$_POST['folio_id']; $q=$pdo->prepare('SELECT f.id,f.currency FROM booking_folios f JOIN supplier_bookings b ON b.id=f.booking_id WHERE f.id=? AND b.supplier_id=?'); $q->execute([$folio,$u['supplier_id']]); $f=$q->fetch(); if(!$f) throw new RuntimeException('Folio bulunamadı.');
   $subtotal=(float)$_POST['subtotal']; $tax=(float)$_POST['tax_amount']; $number=trim((string)$_POST['invoice_number']) ?: 'INV-'.date('YmdHis');
   $pdo->prepare("INSERT INTO hotel_invoices(supplier_id,folio_id,invoice_number,invoice_type,recipient_name,recipient_tax_number,subtotal,tax_amount,total_amount,currency,status,issued_at) VALUES(?,?,?,?,?,?,?,?,?,?, 'issued',now())")->execute([$u['supplier_id'],$folio,$number,$_POST['invoice_type']??'invoice',trim((string)$_POST['recipient_name']),trim((string)$_POST['recipient_tax_number'])?:null,$subtotal,$tax,$subtotal+$tax,$f['currency']]);
   $message='Fatura kaydedildi: '.$number;
  }
  if($action==='night_audit') {
   $property=(int)$_POST['property_id']; $date=(string)$_POST['business_date'];
   $q=$pdo->prepare("SELECT COUNT(*) FROM supplier_bookings WHERE property_id=? AND booking_status='in_house' AND check_in<=? AND check_out>? "); $q->execute([$property,$date,$date]); $inHouse=(int)$q->fetchColumn();
   $q=$pdo->prepare("SELECT COUNT(*) FROM booking_folios f JOIN supplier_bookings b ON b.id=f.booking_id WHERE b.property_id=? AND f.status='open' GROUP BY b.property_id HAVING COALESCE(SUM((SELECT SUM(amount) FROM folio_transactions t WHERE t.folio_id=f.id)),0)<>0"); $q->execute([$property]); $unbalanced=(int)($q->fetchColumn()?:0);
   $errors=[]; if($unbalanced>0)$errors[]=$unbalanced.' açık folio bakiyesi var.';
   $pdo->prepare("INSERT INTO night_audit_runs(property_id,business_date,status,validation_errors,performed_by,closed_at) VALUES(?,?,?,?::jsonb,?,CASE WHEN ?='closed' THEN now() ELSE NULL END) ON CONFLICT(property_id,business_date) DO UPDATE SET status=EXCLUDED.status,validation_errors=EXCLUDED.validation_errors,performed_by=EXCLUDED.performed_by,closed_at=EXCLUDED.closed_at")->execute([$property,$date,$errors?'validated':'closed',json_encode($errors,JSON_UNESCAPED_UNICODE),$u['id'],$errors?'validated':'closed']);
   if(!$errors) $pdo->prepare('INSERT INTO hotel_daily_closures(property_id,business_date,closed_by,notes) VALUES(?,?,?,?) ON CONFLICT(property_id,business_date) DO NOTHING')->execute([$property,$date,$u['id'],'Night audit ile otomatik kapatıldı']);
   $message=$errors ? 'Gün sonu doğrulandı, kapatma için bakiyeleri kontrol edin.' : 'Gün sonu kapatıldı. Konaklayan misafir: '.$inHouse;
  }
 }
} catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); $error=$e->getMessage(); }
$q=$pdo->prepare("SELECT f.id,f.currency,f.status,b.booking_reference,p.name property_name,COALESCE((SELECT SUM(amount) FROM folio_transactions t WHERE t.folio_id=f.id),0) balance FROM booking_folios f JOIN supplier_bookings b ON b.id=f.booking_id JOIN properties p ON p.id=b.property_id WHERE b.supplier_id=? ORDER BY f.id DESC LIMIT 100"); $q->execute([$u['supplier_id']]); $folios=$q->fetchAll();
$q=$pdo->prepare("SELECT p.id,p.name FROM properties p WHERE p.supplier_id=? AND p.property_type='hotel' ORDER BY p.name");$q->execute([$u['supplier_id']]);$hotels=$q->fetchAll();
$q=$pdo->prepare("SELECT pr.*,b.booking_reference FROM payment_records pr LEFT JOIN supplier_bookings b ON b.id=pr.booking_id WHERE pr.supplier_id=? ORDER BY pr.id DESC LIMIT 30");$q->execute([$u['supplier_id']]);$payments=$q->fetchAll();
$q=$pdo->prepare("SELECT i.*,b.booking_reference FROM hotel_invoices i JOIN booking_folios f ON f.id=i.folio_id JOIN supplier_bookings b ON b.id=f.booking_id WHERE i.supplier_id=? ORDER BY i.id DESC LIMIT 30");$q->execute([$u['supplier_id']]);$invoices=$q->fetchAll();
supply_start('Otel finans, fatura ve gün sonu',$active_module); ?>
<section class="page-intro"><p>Folio tahsilatı, fatura takibi ve gün sonu kontrolünü hareket bazında yönetin. Kart/pos entegrasyonu eklenene kadar kaydedilen tahsilatlar manuel finans kaydı niteliğindedir.</p></section>
<?php if($message):?><p class="save-success">✓ <?=htmlspecialchars($message)?></p><?php endif;?><?php if($error):?><p class="login-error"><?=htmlspecialchars($error)?></p><?php endif;?>
<section class="next-module"><h2>Tahsilat kaydı</h2><form method="post" class="supply-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>"><input type="hidden" name="action" value="payment"><div class="form-row"><label>Folio<select name="folio_id"><?php foreach($folios as $f):?><option value="<?=$f['id']?>"><?=htmlspecialchars($f['booking_reference'].' · '.$f['property_name'].' · bakiye '.number_format((float)$f['balance'],2).' '.$f['currency'])?></option><?php endforeach;?></select></label><select name="payment_method"><option value="cash">Nakit</option><option value="card">Kart / POS</option><option value="bank_transfer">Havale / EFT</option><option value="agency_credit">Acente cari</option></select><input name="amount" type="number" min="0.01" step="0.01" required placeholder="Tahsilat tutarı"><input name="provider_reference" placeholder="POS / banka referansı"></div><button>Tahsilatı kaydet</button></form></section>
<section class="next-module"><h2>Fatura</h2><form method="post" class="supply-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>"><input type="hidden" name="action" value="invoice"><div class="form-row"><label>Folio<select name="folio_id"><?php foreach($folios as $f):?><option value="<?=$f['id']?>"><?=htmlspecialchars($f['booking_reference'].' · '.$f['currency'])?></option><?php endforeach;?></select></label><input name="invoice_number" placeholder="Fatura numarası"><input name="recipient_name" required placeholder="Alıcı unvanı / adı"><input name="recipient_tax_number" placeholder="VKN / TCKN"><input type="number" step="0.01" name="subtotal" required placeholder="Ara toplam"><input type="number" step="0.01" name="tax_amount" value="0" placeholder="KDV"><select name="invoice_type"><option value="invoice">Fatura</option><option value="proforma">Proforma</option></select></div><button>Faturayı oluştur</button></form></section>
<section class="next-module"><h2>Night audit / gün sonu</h2><form method="post" class="supply-form"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>"><input type="hidden" name="action" value="night_audit"><div class="form-row"><select name="property_id"><?php foreach($hotels as $h):?><option value="<?=$h['id']?>"><?=htmlspecialchars($h['name'])?></option><?php endforeach;?></select><input type="date" name="business_date" value="<?=date('Y-m-d')?>"></div><button>Kontrol et ve gün sonunu kapat</button></form></section>
<section class="next-module"><h2>Son hareketler</h2><?php foreach($payments as $p):?><p><b><?=htmlspecialchars($p['payment_reference'])?></b> · <?=htmlspecialchars($p['booking_reference']??'') ?> · <?=number_format((float)$p['amount'],2)?> <?=htmlspecialchars($p['currency'])?> · <?=htmlspecialchars($p['payment_method'])?></p><?php endforeach;?><?php foreach($invoices as $i):?><p><b><?=htmlspecialchars($i['invoice_number'])?></b> · <?=htmlspecialchars($i['booking_reference'])?> · <?=number_format((float)$i['total_amount'],2)?> <?=htmlspecialchars($i['currency'])?> · <?=htmlspecialchars($i['status'])?></p><?php endforeach;?></section>
<?php supply_end();
