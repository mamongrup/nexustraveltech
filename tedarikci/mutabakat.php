<?php
declare(strict_types=1);
$active_module='settlements';
require_once __DIR__.'/layout.php';
require_once __DIR__.'/../config/settlements.php';
$u=$supplier_user;
$pdo=db();

$q=$pdo->prepare('SELECT * FROM supplier_settlements WHERE supplier_id=? ORDER BY created_at DESC LIMIT 200');
$q->execute([$u['supplier_id']]);
$rows=$q->fetchAll();
$totals=['pending'=>0,'paid'=>0,'failed'=>0,'refunded'=>0];
foreach($rows as $r)if(isset($totals[$r['status']]))$totals[$r['status']]+=(float)$r['net_amount'];

$q=$pdo->prepare("SELECT b.id,b.booking_reference,b.check_in,b.check_out,b.total_amount,b.currency,p.name property_name FROM supplier_bookings b JOIN properties p ON p.id=b.property_id WHERE b.supplier_id=? AND b.status NOT IN ('cancelled','rejected') ORDER BY b.created_at DESC LIMIT 50");
$q->execute([$u['supplier_id']]);
$bookings=$q->fetchAll();

supply_start('Tahsilat & mutabakat',$active_module);?>
<section class="page-intro"><p>Acente, API ve ödeme sağlayıcılarından oluşan net tahsilatlarınız. Net tutar = brüt − komisyon formülüyle, ilanınızdaki komisyon oranından otomatik hesaplanır.</p></section>
<section class="stat-grid"><article><span>BEKLEYEN</span><b><?=number_format($totals['pending'],2)?> EUR</b></article><article><span>ÖDENEN</span><b><?=number_format($totals['paid'],2)?> EUR</b></article><article><span>İADE</span><b><?=number_format($totals['refunded'],2)?> EUR</b></article></section>
<section class="next-module"><h2>Rezervasyon bazlı hesap</h2>
<div class="availability-row"><div><b>Rezervasyon</b></div><div><b>Brüt</b></div><div><b>Komisyon</b></div><div><b>Net</b></div></div>
<?php if(!$bookings):?><p class="muted">Henüz rezervasyon yok.</p><?php endif;?>
<?php foreach($bookings as $b):
$rate=property_commission_rate((int)$b['property_id'])['rate'];
$calc=settlement_calculation((float)$b['total_amount'],$rate);
?><div class="availability-row"><div><b><?=htmlspecialchars($b['property_name'])?> · <?=htmlspecialchars($b['booking_reference'])?></b><span><?=htmlspecialchars($b['check_in'])?> / <?=htmlspecialchars($b['check_out'])?></span></div><div><?=number_format($calc['gross'],2)?> <?=htmlspecialchars($b['currency'])?></div><div>%<?=$rate?> · <?=number_format($calc['commission_amount'],2)?></div><strong><?=number_format($calc['net_amount'],2)?> <?=htmlspecialchars($b['currency'])?></strong></div>
<?php endforeach;?>
</section>
<section class="next-module"><h2>Mutabakat hareketleri</h2>
<?php if(!$rows):?><p class="muted">Onaylanan her acente rezervasyonu için mutabakat kaydı otomatik oluşturulur.</p><?php endif;?>
<?php foreach($rows as $r):?><p><b><?=htmlspecialchars($r['transaction_type'])?></b> · brüt <?=number_format((float)$r['gross_amount'],2)?> · komisyon <?=number_format((float)$r['commission_amount'],2)?> · net <?=number_format((float)$r['net_amount'],2)?> <?=htmlspecialchars($r['currency'])?> · <?=htmlspecialchars($r['status'])?></p><?php endforeach;?>
</section>
<?php supply_end(); ?>
