<?php
declare(strict_types=1);
$active_module='hotel_finance';
require_once __DIR__.'/layout.php';
if(empty($_SESSION['supplier_csrf']))$_SESSION['supplier_csrf']=bin2hex(random_bytes(32));
$u=$supplier_user;
$pdo=db();
$message='';$error='';

$folioId=(int)($_GET['id']??0);
$bookingParam=(int)($_GET['booking']??0);
$print=isset($_GET['print']);

if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['supplier_csrf'],(string)($_POST['csrf']??''))){$error='Güvenlik doğrulaması geçersiz.';}
  else try{
    $action=$_POST['action']??'';
    if($action==='create_folio'){
      $bid=(int)($_POST['booking_id']??0);
      $q=$pdo->prepare('SELECT id FROM supplier_bookings WHERE id=? AND supplier_id=?');
      $q->execute([$bid,$u['supplier_id']]);
      if(!$q->fetch())throw new RuntimeException('Rezervasyon bulunamadı.');
      $q=$pdo->prepare("INSERT INTO booking_folios(booking_id,currency,status) VALUES(?,?,'open') RETURNING id");
      $q->execute([$bid,mb_strtoupper(mb_substr((string)($_POST['currency']??'EUR'),0,3))]);
      $folioId=(int)$q->fetchColumn();
      $message='Folyo oluşturuldu.';
    }
    elseif($folioId>0&&in_array($action,['charge','payment','refund'],true)){
      $amount=round((float)str_replace(',','.',(string)($_POST['amount']??0)),2);
      if($amount<=0)throw new RuntimeException('Tutar sıfırdan büyük olmalı.');
      $desc=trim((string)($_POST['description']??''));
      if($desc==='')throw new RuntimeException('Açıklama zorunludur.');
      $department=trim((string)($_POST['department']??''))?:null;
      if($action==='charge'){
        $type=in_array($_POST['transaction_type']??'',['room_charge','service_charge','adjustment'],true)?$_POST['transaction_type']:'service_charge';
        $amount=$amount;
      }elseif($action==='payment'){$type='payment';$amount=-$amount;}
      else{$type='refund';}
      $pdo->prepare('INSERT INTO folio_transactions(folio_id,transaction_type,department,description,amount) VALUES(?,?,?,?,?)')
        ->execute([$folioId,$type,$department,$desc,$amount]);
      $message='Hareket kaydedildi.';
    }
  }catch(Throwable $e){$error=$e->getMessage();}
}

$folio=null;$booking=null;$guestName='';
if($folioId>0){
  $q=$pdo->prepare("SELECT bf.*,b.booking_reference,b.check_in,b.check_out,b.total_amount booking_total,b.currency booking_currency,p.name property_name,p.city FROM booking_folios bf JOIN supplier_bookings b ON b.id=bf.booking_id JOIN properties p ON p.id=b.property_id WHERE bf.id=? AND b.supplier_id=?");
  $q->execute([$folioId,$u['supplier_id']]);
  $folio=$q->fetch()?:null;
  if($folio)$booking=$folio;
}
elseif($bookingParam>0){
  $q=$pdo->prepare("SELECT bf.id FROM booking_folios bf JOIN supplier_bookings b ON b.id=bf.booking_id WHERE b.id=? AND b.supplier_id=? ORDER BY bf.id LIMIT 1");
  $q->execute([$bookingParam,$u['supplier_id']]);
  $fid=$q->fetchColumn();
  if($fid){header('Location: folio.php?id='.(int)$fid);exit;}
  $q=$pdo->prepare("SELECT b.id booking_id,b.booking_reference,b.check_in,b.check_out,b.total_amount booking_total,b.currency booking_currency,p.name property_name,p.city FROM supplier_bookings b JOIN properties p ON p.id=b.property_id WHERE b.id=? AND b.supplier_id=?");
  $q->execute([$bookingParam,$u['supplier_id']]);
  $booking=$q->fetch()?:null;
}

$transactions=[];$charges=0.0;$payments=0.0;$refunds=0.0;
if($folio){
  $q=$pdo->prepare('SELECT * FROM folio_transactions WHERE folio_id=? ORDER BY id DESC');
  $q->execute([$folioId]);
  $transactions=$q->fetchAll();
  foreach($transactions as $t){
    if(in_array($t['transaction_type'],['room_charge','service_charge','adjustment','tax'],true))$charges+=(float)$t['amount'];
    elseif($t['transaction_type']==='payment')$payments+=(float)$t['amount'];
    elseif($t['transaction_type']==='refund')$refunds+=(float)$t['amount'];
  }
  $gq=$pdo->prepare("SELECT gp.first_name,gp.last_name FROM booking_guests bg JOIN guest_profiles gp ON gp.id=bg.guest_id WHERE bg.booking_id=? AND bg.is_primary=true LIMIT 1");
  $gq->execute([(int)$folio['booking_id']]);
  $g=$gq->fetch();
  if($g)$guestName=trim((string)$g['first_name'].' '.(string)$g['last_name']);
}
$balance=$charges+$refunds+$payments;
$collected=-$payments;
$currency=$folio?$folio['currency']:($booking?$booking['booking_currency']:'EUR');

if(isset($_GET['pdf'])&&$folio){
  require_once __DIR__.'/../config/pdf.php';
  $rows='';
  foreach($transactions as $t){
    $rows.='<tr><td style="border:1px solid #999;padding:6px">'.htmlspecialchars((string)$t['transaction_at']).'</td><td style="border:1px solid #999;padding:6px">'.htmlspecialchars($t['transaction_type']).'</td><td style="border:1px solid #999;padding:6px">'.htmlspecialchars($t['description']).'</td><td style="border:1px solid #999;padding:6px;text-align:right">'.number_format((float)$t['amount'],2).'</td></tr>';
  }
  $html='<h1 style="text-align:center">KONAKLAMA FATURA TASLAĞI</h1>'
    .'<p>'.$booking['property_name'].' · '.htmlspecialchars((string)$booking['city']).'<br>Referans: '.htmlspecialchars((string)$folio['booking_reference']).'<br>Misafir: '.htmlspecialchars($guestName!==''?$guestName:'—').'<br>Giriş / Çıkış: '.htmlspecialchars((string)$folio['check_in']).' / '.htmlspecialchars((string)$folio['check_out']).'</p>'
    .'<table style="width:100%;border-collapse:collapse"><tr><th style="border:1px solid #999;padding:6px;text-align:left">Zaman</th><th style="border:1px solid #999;padding:6px;text-align:left">Tür</th><th style="border:1px solid #999;padding:6px;text-align:left">Açıklama</th><th style="border:1px solid #999;padding:6px;text-align:right">Tutar</th></tr>'.$rows
    .'<tr><td colspan="3" style="border:1px solid #999;padding:6px;text-align:right"><b>Bakiye</b></td><td style="border:1px solid #999;padding:6px;text-align:right"><b>'.number_format($balance,2).' '.htmlspecialchars($currency).'</b></td></tr></table>'
    .'<p style="margin-top:30px;color:#666;font-size:12px">Bu belge fatura taslağıdır; e-Fatura entegrasyonunda GİB onaylı belge ile değiştirilir. NEXUS TravelTech</p>';
  pdf_download($html,'fatura-'.$folio['booking_reference']);
}

supply_start('Folyo ' . ($folio ? ('#' . ($folio['folio_number']?:$folio['booking_reference'])) : ''), $active_module);?>
<style>@media print{.side,.supply-top,.supply-form,button,.no-print{display:none!important}.supply-main{padding:0!important;margin:0!important}table{width:100%;border-collapse:collapse}td,th{border:1px solid #ccc;padding:8px;text-align:left}}</style>
<?php if($message):?><p class="save-success">✓ <?=htmlspecialchars($message)?></p><?php endif;?>
<?php if($error):?><p class="login-error"><?=htmlspecialchars($error)?></p><?php endif;?>

<?php if(!$booking):?><section class="next-module"><p class="muted">Rezervasyon veya folyo bulunamadı.</p></section><?php supply_end(); return; endif;?>

<?php if(!$folio):?>
<section class="next-module"><h2>Folyo yok — <?=htmlspecialchars($booking['booking_reference'])?></h2>
<p><?=htmlspecialchars($booking['property_name'])?> · <?=htmlspecialchars($booking['check_in'])?> / <?=htmlspecialchars($booking['check_out'])?> · <?=number_format((float)$booking['booking_total'],2)?> <?=htmlspecialchars($booking['booking_currency'])?></p>
<form method="post" class="supply-form" style="max-width:360px"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>"><input type="hidden" name="action" value="create_folio"><input type="hidden" name="booking_id" value="<?=(int)$booking['booking_id']?>"><label>Para birimi<select name="currency"><option>EUR</option><option>TRY</option><option>USD</option></select></label><button>Folyo oluştur</button></form></section>
<?php supply_end(); return; endif;?>

<section class="next-module"><h2><?=htmlspecialchars($booking['property_name'])?> — Folyo #<?=htmlspecialchars($folio['folio_number']?:$folio['booking_reference'])?></h2>
<div class="availability-row"><div><b>Referans: <?=htmlspecialchars($folio['booking_reference'])?></b><span><?=htmlspecialchars($guestName!==''?$guestName:'Misafir')?> · <?=htmlspecialchars($folio['check_in'])?> / <?=htmlspecialchars($folio['check_out'])?> · <?=htmlspecialchars($folio['status'])?></span></div>
<strong>Bakiye: <?=number_format($balance,2)?> <?=htmlspecialchars($currency)?></strong></div>
<div class="form-row"><a href="folio.php?id=<?=$folioId?>&amp;print=1" class="no-print" target="_blank">Fatura taslağını yazdır</a> · <a href="folio.php?id=<?=$folioId?>&amp;pdf=1" class="no-print">PDF indir</a></div>
</section>

<section class="next-module"><h2>Hareketler</h2>
<?php if(!$transactions):?><p class="muted">Henüz hareket yok.</p><?php endif;?>
<table><tr><th>Zaman</th><th>Tür</th><th>Açıklama</th><th>Tutar</th></tr>
<?php foreach($transactions as $t):$neg=$t['transaction_type']==='payment'||(float)$t['amount']<0;?>
<tr><td><?=htmlspecialchars((string)$t['transaction_at'])?></td><td><?=htmlspecialchars($t['transaction_type'])?><?= $t['department']?' · '.htmlspecialchars($t['department']):''?></td><td><?=htmlspecialchars($t['description'])?></td><td style="<?=$neg?'color:#8e2410':''?>"><?=number_format((float)$t['amount'],2)?></td></tr>
<?php endforeach;?>
<tr><td colspan="3" style="text-align:right;font-weight:700">Tahakkuk</td><td><b><?=number_format($charges,2)?></b></td></tr>
<tr><td colspan="3" style="text-align:right;font-weight:700">Tahsilat</td><td><b><?=number_format($collected,2)?></b></td></tr>
<tr><td colspan="3" style="text-align:right;font-weight:700">İade</td><td><b><?=number_format($refunds,2)?></b></td></tr>
<tr><td colspan="3" style="text-align:right;font-weight:700">Bakiye</td><td><b><?=number_format($balance,2)?> <?=htmlspecialchars($currency)?></b></td></tr>
</table>
</section>

<section class="next-module no-print" style="background:#f8faf9;border:1px solid #cbe0d4;padding:18px;border-radius:10px">
  <h2>⚡ Dokunmatik Hızlı POS (Tablet / Mobil Satış)</h2>
  <p style="font-size:13px;color:#2c5e43;margin-bottom:12px">Garson veya resepsiyon için tek dokunuşla folyoya standart harcama ekleyin:</p>
  <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(130px, 1fr));gap:10px;margin-bottom:16px">
    <form method="post" style="margin:0">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>">
      <input type="hidden" name="action" value="charge">
      <input type="hidden" name="transaction_type" value="service_charge">
      <input type="hidden" name="department" value="Restoran">
      <input type="hidden" name="description" value="Serpme Kahvaltı">
      <input type="hidden" name="amount" value="15.00">
      <button type="submit" style="width:100%;padding:12px 8px;background:#fff;border:1.5px solid #a2d6b9;border-radius:8px;cursor:pointer;text-align:center;color:#10211f">
        <div style="font-size:20px">🍳</div>
        <div style="font-weight:700;font-size:12px;margin-top:2px">Kahvaltı</div>
        <div style="font-size:11px;color:#13593b;font-weight:bold">15.00 <?=htmlspecialchars($currency)?></div>
      </button>
    </form>
    <form method="post" style="margin:0">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>">
      <input type="hidden" name="action" value="charge">
      <input type="hidden" name="transaction_type" value="service_charge">
      <input type="hidden" name="department" value="Bar">
      <input type="hidden" name="description" value="Kahve & İçecek">
      <input type="hidden" name="amount" value="5.00">
      <button type="submit" style="width:100%;padding:12px 8px;background:#fff;border:1.5px solid #a2d6b9;border-radius:8px;cursor:pointer;text-align:center;color:#10211f">
        <div style="font-size:20px">☕</div>
        <div style="font-weight:700;font-size:12px;margin-top:2px">Kahve/İçecek</div>
        <div style="font-size:11px;color:#13593b;font-weight:bold">5.00 <?=htmlspecialchars($currency)?></div>
      </button>
    </form>
    <form method="post" style="margin:0">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>">
      <input type="hidden" name="action" value="charge">
      <input type="hidden" name="transaction_type" value="service_charge">
      <input type="hidden" name="department" value="Minibar">
      <input type="hidden" name="description" value="Minibar Tüketimi">
      <input type="hidden" name="amount" value="20.00">
      <button type="submit" style="width:100%;padding:12px 8px;background:#fff;border:1.5px solid #a2d6b9;border-radius:8px;cursor:pointer;text-align:center;color:#10211f">
        <div style="font-size:20px">🍫</div>
        <div style="font-weight:700;font-size:12px;margin-top:2px">Minibar</div>
        <div style="font-size:11px;color:#13593b;font-weight:bold">20.00 <?=htmlspecialchars($currency)?></div>
      </button>
    </form>
    <form method="post" style="margin:0">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>">
      <input type="hidden" name="action" value="charge">
      <input type="hidden" name="transaction_type" value="service_charge">
      <input type="hidden" name="department" value="Transfer">
      <input type="hidden" name="description" value="Havalimanı VIP Transfer">
      <input type="hidden" name="amount" value="50.00">
      <button type="submit" style="width:100%;padding:12px 8px;background:#fff;border:1.5px solid #a2d6b9;border-radius:8px;cursor:pointer;text-align:center;color:#10211f">
        <div style="font-size:20px">🚘</div>
        <div style="font-weight:700;font-size:12px;margin-top:2px">Transfer</div>
        <div style="font-size:11px;color:#13593b;font-weight:bold">50.00 <?=htmlspecialchars($currency)?></div>
      </button>
    </form>
    <form method="post" style="margin:0">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>">
      <input type="hidden" name="action" value="charge">
      <input type="hidden" name="transaction_type" value="service_charge">
      <input type="hidden" name="department" value="Çamaşırhane">
      <input type="hidden" name="description" value="Yıkama & Ütü Servisi">
      <input type="hidden" name="amount" value="12.00">
      <button type="submit" style="width:100%;padding:12px 8px;background:#fff;border:1.5px solid #a2d6b9;border-radius:8px;cursor:pointer;text-align:center;color:#10211f">
        <div style="font-size:20px">👔</div>
        <div style="font-weight:700;font-size:12px;margin-top:2px">Çamaşırhane</div>
        <div style="font-size:11px;color:#13593b;font-weight:bold">12.00 <?=htmlspecialchars($currency)?></div>
      </button>
    </form>
  </div>

  <?php if($balance > 0): ?>
    <div style="background:#fff;padding:14px;border-radius:8px;border:1px solid #d8ded8;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <div>
        <strong style="color:#10211f">💳 Kalan Bakiye Tahsilatı: <?=number_format($balance,2)?> <?=htmlspecialchars($currency)?></strong>
        <div style="font-size:12px;color:#666">Misafire SMS/WhatsApp ile 3D Secure kredi kartı ödeme linki gönderin.</div>
      </div>
      <form method="post" style="margin:0" action="rezervasyonlar.php">
        <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>">
        <input type="hidden" name="action" value="paylink">
        <input type="hidden" name="booking_id" value="<?=(int)$folio['booking_id']?>">
        <input type="hidden" name="amount" value="<?=number_format($balance,2,'.','')?>">
        <button style="background:#13593b;color:#fff;border:none;padding:8px 16px;border-radius:6px;font-weight:bold;cursor:pointer">📲 Ödeme Linki Üret</button>
      </form>
    </div>
  <?php endif; ?>
</section>

<section class="next-module no-print"><h2>Manuel hareket ekle</h2>
<form method="post" class="supply-form" style="max-width:560px"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>">
<div class="form-row"><label>Tür<select name="transaction_type"><option value="room_charge">Oda ücreti</option><option value="service_charge">Servis ücreti</option><option value="adjustment">Düzeltme</option></select></label><label>Tutar<input type="number" name="amount" step="0.01" min="0.01" required></label><label>Bölüm<input name="department" placeholder="Örn. Restoran"></label></div>
<label>Açıklama<input name="description" required placeholder="Hareket açıklaması"></label>
<div class="form-row"><button name="action" value="charge">Tahakkuk ekle</button><button name="action" value="payment" style="background:#0d7a4a">Tahsilat kaydet</button><button name="action" value="refund" style="background:#a86026">İade kaydet</button></div>
</form>
</section>
<?php supply_end(); ?>
