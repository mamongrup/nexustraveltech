<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
$property = (int) ($_GET['property'] ?? $_POST['property'] ?? 0);
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$q = db()->prepare('SELECT p.*,w.confirmation_mode FROM properties p JOIN property_booking_widgets w ON w.property_id=p.id WHERE p.id=? AND w.public_token=? AND w.is_enabled=true');
$q->execute([$property, $token]); $hotel = $q->fetch();
if (!$hotel) { http_response_code(404); exit('Rezervasyon bağlantısı bulunamadı.'); }
$message = ''; $rooms = [];
$checkIn = (string) ($_GET['check_in'] ?? $_POST['check_in'] ?? ''); $checkOut = (string) ($_GET['check_out'] ?? $_POST['check_out'] ?? '');
if ($checkIn !== '' && $checkOut !== '' && $checkIn < $checkOut) {
 $q = db()->prepare("SELECT r.id,r.name,MIN(i.base_price) price FROM room_types r JOIN inventory_calendar i ON i.room_type_id=r.id WHERE r.property_id=? AND i.stay_date>=? AND i.stay_date<? AND i.stop_sale=false AND i.allotment>i.sold GROUP BY r.id,r.name HAVING COUNT(*)=(?::date-?::date) ORDER BY price");
 $q->execute([$property,$checkIn,$checkOut,$checkOut,$checkIn]); $rooms=$q->fetchAll();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['room_type_id'])) {
 $room=(int)$_POST['room_type_id'];$first=trim((string)$_POST['first_name']);$last=trim((string)$_POST['last_name']);
 if($checkIn===''||$checkOut===''||$checkIn>=$checkOut||$first===''||$last==='')$message='Bilgileri kontrol edin.';
 else { $pdo=db();$pdo->beginTransaction();try {
  $a=$pdo->prepare('SELECT COUNT(*) FROM inventory_calendar WHERE room_type_id=? AND stay_date>=? AND stay_date<? AND stop_sale=false AND allotment>sold FOR UPDATE');$a->execute([$room,$checkIn,$checkOut]);
  if((int)$a->fetchColumn()!==((strtotime($checkOut)-strtotime($checkIn))/86400))throw new RuntimeException('Seçilen oda artık müsait değil.');
  $g=$pdo->prepare('INSERT INTO guest_profiles(supplier_id,first_name,last_name,email,phone) VALUES(?,?,?,?,?) RETURNING id');$g->execute([$hotel['supplier_id'],$first,$last,filter_var($_POST['email']??'',FILTER_VALIDATE_EMAIL)?:null,trim((string)$_POST['phone'])?:null]);$guest=(int)$g->fetchColumn();
  $ref='NXD-'.date('ymdHis').'-'.random_int(100,999);$b=$pdo->prepare("INSERT INTO supplier_bookings(supplier_id,property_id,booking_reference,status,booking_status,check_in,check_out,total_amount,currency,source_code) VALUES(?,?,?,'pending','reserved',?,?,0,'EUR','direct') RETURNING id");$b->execute([$hotel['supplier_id'],$property,$ref,$checkIn,$checkOut]);$booking=(int)$b->fetchColumn();
  $pdo->prepare('INSERT INTO booking_guests(booking_id,guest_id,is_primary) VALUES(?,?,true)')->execute([$booking,$guest]);$pdo->prepare("INSERT INTO booking_rooms(booking_id,room_type_id,adults,children,currency) VALUES(?,?,?,?, 'EUR')")->execute([$booking,$room,max(1,(int)$_POST['adults']),max(0,(int)$_POST['children'])]);$pdo->prepare('UPDATE inventory_calendar SET sold=sold+1 WHERE room_type_id=? AND stay_date>=? AND stay_date<?')->execute([$room,$checkIn,$checkOut]);$pdo->commit();$message='Rezervasyon talebiniz alındı. Referansınız: '.$ref;
 } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$message='Rezervasyon oluşturulamadı.';}}
}
?><!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=htmlspecialchars($hotel['name'])?> | Rezervasyon</title><style>body{font-family:Arial;background:#f6f7f4}.w{max-width:850px;margin:30px auto;background:#fff;padding:24px}input,button{padding:10px;margin:4px}button{background:#14241f;color:#fff;border:0}</style></head><body><main class="w"><h1><?=htmlspecialchars($hotel['name'])?></h1><?php if($message):?><p><?=htmlspecialchars($message)?></p><?php endif;?><form method="get"><input type="hidden" name="property" value="<?=$property?>"><input type="hidden" name="token" value="<?=htmlspecialchars($token)?>"><input type="date" name="check_in" value="<?=htmlspecialchars($checkIn)?>" required><input type="date" name="check_out" value="<?=htmlspecialchars($checkOut)?>" required><button>Müsaitlik ara</button></form><?php foreach($rooms as $r):?><form method="post"><input type="hidden" name="property" value="<?=$property?>"><input type="hidden" name="token" value="<?=htmlspecialchars($token)?>"><input type="hidden" name="check_in" value="<?=htmlspecialchars($checkIn)?>"><input type="hidden" name="check_out" value="<?=htmlspecialchars($checkOut)?>"><input type="hidden" name="room_type_id" value="<?=$r['id']?>"><h3><?=htmlspecialchars($r['name'])?> · <?=number_format((float)$r['price'],2)?> EUR/gece</h3><input name="first_name" placeholder="Ad" required><input name="last_name" placeholder="Soyad" required><input name="email" type="email" placeholder="E-posta"><input name="phone" placeholder="Telefon"><input name="adults" type="number" value="2" min="1"><input name="children" type="number" value="0" min="0"><button>Rezervasyon talebi oluştur</button></form><?php endforeach;?></main></body></html>
