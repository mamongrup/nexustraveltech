<?php
/**
 * NEXUS rezervasyon akışı smoke testi.
 *
 * Çalıştırma (PostgreSQL kurulu ve migration'lar yüklü olmalı):
 *   php scripts/test-booking-flow.php
 *
 * Tüm senaryo tek işlem (transaction) içinde koşar ve sonunda geri alınır;
 * veritabanında test verisi bırakmaz.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/pricing.php';
require_once __DIR__ . '/../config/settlements.php';
require_once __DIR__ . '/../config/agency_bookings.php';
require_once __DIR__ . '/../config/loyalty.php';

$GLOBALS['failures'] = [];
$GLOBALS['checks'] = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    $GLOBALS['checks']++;
    if ($ok) {
        echo "  ✔ $name\n";
    } else {
        $GLOBALS['failures'][] = $name;
        echo "  ✘ $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    echo "NEXUS rezervasyon akışı testleri\n";
    echo "================================\n";

    // --- Test verisi -----------------------------------------------------
    $q = $pdo->prepare("INSERT INTO suppliers(company_name,status) VALUES('TEST Tedarikçi','pilot') RETURNING id");
    $q->execute();
    $supplierId = (int) $q->fetchColumn();
    $q = $pdo->prepare("INSERT INTO supplier_users(supplier_id,full_name,email,password_hash,role) VALUES(?,?,?,'TESTHASH','owner') RETURNING id");
    $q->execute([$supplierId, 'Test Kullanıcı', 'test-' . bin2hex(random_bytes(4)) . '@nexustraveltech.test']);
    $supplierUserId = (int) $q->fetchColumn();

    $q = $pdo->prepare("INSERT INTO agencies(company_name,status) VALUES('TEST Acente','active') RETURNING id");
    $q->execute();
    $agencyId = (int) $q->fetchColumn();

    $q = $pdo->prepare("INSERT INTO properties(supplier_id,property_type,name,city,country_code,status,product_details) VALUES(?,'hotel','TEST Otel','Fethiye','TR','active',?::jsonb) RETURNING id");
    $q->execute([$supplierId, json_encode(['commission_rate' => 15, 'collection_model' => 'agency_collects_deposit'], JSON_UNESCAPED_UNICODE)]);
    $propertyId = (int) $q->fetchColumn();

    $q = $pdo->prepare("INSERT INTO room_types(property_id,name,capacity_adults,total_units,room_details) VALUES(?,'TEST Deluxe',2,5,'{}') RETURNING id");
    $q->execute([$propertyId]);
    $roomTypeId = (int) $q->fetchColumn();

    $q = $pdo->prepare("INSERT INTO rate_plans(property_id,name,currency,board_type,status) VALUES(?,'TEST BB','EUR','BB','active') RETURNING id");
    $q->execute([$propertyId]);
    $ratePlanId = (int) $q->fetchColumn();

    $checkIn = date('Y-m-d', strtotime('+14 day'));
    $checkOut = date('Y-m-d', strtotime('+18 day'));
    $nights = 4;
    $ins = $pdo->prepare('INSERT INTO inventory_calendar(room_type_id,rate_plan_id,stay_date,allotment,sold,base_price,min_stay) VALUES(?,?,?,5,0,100,1)');
    for ($i = 0; $i < $nights; $i++) {
        $ins->execute([$roomTypeId, $ratePlanId, date('Y-m-d', strtotime($checkIn . " +$i day"))]);
    }

    // --- 1) Canlı müsaitlik sorgusu --------------------------------------
    echo "\n[1] Canlı müsaitlik sorgusu\n";
    $q = $pdo->prepare("SELECT r.id room_type_id,r.name room_name,p.id property_id,p.name property_name,MIN(i.base_price) price FROM room_types r JOIN properties p ON p.id=r.property_id JOIN suppliers s ON s.id=p.supplier_id JOIN inventory_calendar i ON i.room_type_id=r.id WHERE p.status='active' AND s.status IN ('active','pilot') AND i.stay_date>=? AND i.stay_date<? AND i.stop_sale=false AND i.allotment>i.sold GROUP BY r.id,r.name,p.id,p.name HAVING COUNT(*)=? ORDER BY price");
    $q->execute([$checkIn, $checkOut, $nights]);
    $available = $q->fetchAll();
    check('Sorgu sonucu en az 1 uygun oda döndürüyor', count($available) >= 1, 'sonuç: ' . count($available));

    // --- 2) Fiyat kuralları ----------------------------------------------
    echo "\n[2] Kural bazlı fiyat motoru\n";
    $q = $pdo->prepare("INSERT INTO rate_rules(supplier_id,property_id,rate_plan_id,name,rule_type,value,currency,stay_start,stay_end,status,stackable) VALUES(?,?,?,?,'percent',10,'EUR',?,?,'active',false)");
    $q->execute([$supplierId, $propertyId, $ratePlanId, 'TEST Erken rezervasyon', $checkIn, $checkOut]);
    $adjusted = apply_rate_rules($propertyId, $ratePlanId, 100.0, ['stay_date' => $checkIn, 'booking_date' => date('Y-m-d'), 'advance_days' => 14, 'market' => 'TR', 'nationality' => 'TR', 'channel' => 'agency', 'promo_code' => '']);
    check('Yüzde kuralı %10 indirim uygular', abs($adjusted['price'] - 90.0) < 0.01, 'fiyat: ' . $adjusted['price']);
    check('Uygulanan kural listelenir', count($adjusted['applied']) === 1, implode(',', $adjusted['applied']));

    // --- 3) Mutabakat hesabı ----------------------------------------------
    echo "\n[3] Mutabakat hesap motoru\n";
    $calc = settlement_calculation(100.0, 15.0);
    check('Komisyon %15 = 15,00', abs($calc['commission_amount'] - 15.0) < 0.01);
    check('Net = 85,00', abs($calc['net_amount'] - 85.0) < 0.01, 'net: ' . $calc['net_amount']);
    $rate = property_commission_rate($propertyId)['rate'];
    check('Tesis komisyon oranı ilandan okunuyor', abs($rate - 15.0) < 0.01, 'oran: ' . $rate);

    // --- 4) Rezervasyon talebi -------------------------------------------
    echo "\n[4] Acente rezervasyon talebi\n";
    $requestId = insert_agency_booking_request([
        'agency_id' => $agencyId, 'supplier_id' => $supplierId, 'property_id' => $propertyId,
        'room_type_id' => $roomTypeId, 'rate_plan_id' => $ratePlanId,
        'check_in' => $checkIn, 'check_out' => $checkOut, 'nights' => $nights, 'adults' => 2, 'children' => 0,
        'total_amount' => 360.0, 'currency' => 'EUR',
        'guest_first_name' => 'Test', 'guest_last_name' => 'Misafir',
        'guest_email' => 'misafir@nexustraveltech.test', 'guest_phone' => null,
    ]);
    check('Talep kaydedildi (pending)', $requestId > 0, 'id: ' . $requestId);

    // --- 5) Webhook aboneliği ----------------------------------------------
    echo "\n[5] Webhook aboneliği\n";
    $q = $pdo->prepare("INSERT INTO webhook_subscriptions(agency_id,url,secret,events,status) VALUES(?,'http://127.0.0.1:1/hook','test-secret',?::jsonb,'active') RETURNING id");
    $q->execute([$agencyId, json_encode(['booking.created', 'booking.request.rejected'])]);
    $webhookId = (int) $q->fetchColumn();
    check('Webhook aboneliği oluştu', $webhookId > 0);

    // --- 6) Onay akışı ------------------------------------------------------
    echo "\n[6] Talep onayı (rezervasyon kesinleşmesi)\n";
    $result = approve_agency_booking_request($requestId, $supplierId, $supplierUserId, 'TEST onay');
    check('Onay başarılı', !empty($result['ok']), $result['message'] ?? '');
    $bookingRef = $result['booking_reference'] ?? '';
    $q = $pdo->prepare("SELECT id,total_amount,status,source_code FROM supplier_bookings WHERE booking_reference=?");
    $q->execute([$bookingRef]);
    $booking = $q->fetch();
    check('Rezervasyon oluştu (confirmed)', $booking && $booking['status'] === 'confirmed', 'durum: ' . ($booking['status'] ?? 'yok'));
    check('Kaynak kodu agency_request', ($booking['source_code'] ?? '') === 'agency_request');
    $q = $pdo->prepare('SELECT COUNT(*) FROM booking_rooms WHERE booking_id=?');
    $q->execute([$booking['id']]);
    check('Oda kaydı oluştu', (int) $q->fetchColumn() === 1);
    $q = $pdo->prepare('SELECT COUNT(*) FROM booking_guests bg JOIN guest_profiles gp ON gp.id=bg.guest_id WHERE bg.booking_id=? AND gp.first_name=?');
    $q->execute([$booking['id'], 'Test']);
    check('Misafir profili oluştu', (int) $q->fetchColumn() === 1);
    $q = $pdo->prepare('SELECT sold FROM inventory_calendar WHERE room_type_id=? AND rate_plan_id=? AND stay_date=?');
    $q->execute([$roomTypeId, $ratePlanId, $checkIn]);
    $sold = $q->fetchColumn();
    check('Kontenjan düşüldü (sold=1)', (int) $sold === 1, 'sold: ' . var_export($sold, true));
    $q = $pdo->prepare('SELECT net_amount FROM supplier_settlements WHERE booking_id=?');
    $q->execute([$booking['id']]);
    check('Mutabakat kaydı oluştu (net 306,00)', abs((float) $q->fetchColumn() - 306.0) < 0.01);
    $q = $pdo->prepare("SELECT COUNT(*) FROM webhook_deliveries WHERE subscription_id=? AND event='booking.created' AND status='queued'");
    $q->execute([$webhookId]);
    check('Webhook kuyruğa eklendi (booking.created)', (int) $q->fetchColumn() === 1);

    // --- 7) Red akışı --------------------------------------------------------
    echo "\n[7] Talep reddi\n";
    $q = $pdo->prepare("INSERT INTO agency_booking_requests(agency_id,supplier_id,property_id,room_type_id,rate_plan_id,check_in,check_out,nights,adults,children,total_amount,currency,guest_first_name,guest_last_name) VALUES(?,?,?,?,?,?,?,?,2,0,360,'EUR','Test','Red') RETURNING id");
    $q->execute([$agencyId, $supplierId, $propertyId, $roomTypeId, $ratePlanId, $checkIn, $checkOut, $nights]);
    $rejectRequestId = (int) $q->fetchColumn();
    $result = reject_agency_booking_request($rejectRequestId, $supplierId, $supplierUserId, 'TEST red nedeni');
    check('Red başarılı', !empty($result['ok']));
    $q = $pdo->prepare("SELECT status FROM agency_booking_requests WHERE id=?");
    $q->execute([$rejectRequestId]);
    check('Talep rejected durumunda', $q->fetchColumn() === 'rejected');
    $q = $pdo->prepare("SELECT COUNT(*) FROM webhook_deliveries WHERE subscription_id=? AND event='booking.request.rejected'");
    $q->execute([$webhookId]);
    check('Webhook kuyruğa eklendi (booking.request.rejected)', (int) $q->fetchColumn() === 1);

    // --- 8) İptal politikası ----------------------------------------------------
    echo "\n[8] İptal politikası (iade hesabı)\n";
    $q = $pdo->prepare('UPDATE rate_plans SET free_cancel_before_days=7,cancel_fee_percent=20 WHERE id=?');
    $q->execute([$ratePlanId]);
    $q = $pdo->prepare('SELECT * FROM supplier_bookings WHERE id=?');
    $q->execute([$booking['id']]);
    $policy = booking_cancellation_policy($q->fetch(), $pdo);
    check('Ücretsiz iptal penceresi içinde iade = tam tutar', $policy !== null && $policy['free'] === true && abs((float) $policy['refundable'] - 360.0) < 0.01, $policy !== null ? 'iade: ' . $policy['refundable'] : 'politika yok');
    $q = $pdo->prepare('UPDATE supplier_bookings SET check_in=CURRENT_DATE+2,check_out=CURRENT_DATE+6 WHERE id=?');
    $q->execute([$booking['id']]);
    $q = $pdo->prepare('SELECT * FROM supplier_bookings WHERE id=?');
    $q->execute([$booking['id']]);
    $policy2 = booking_cancellation_policy($q->fetch(), $pdo);
    check('Pencere dışında %20 ücret düşer (iade 288)', $policy2 !== null && $policy2['free'] === false && abs((float) $policy2['refundable'] - 288.0) < 0.01, $policy2 !== null ? 'iade: ' . $policy2['refundable'] : 'politika yok');

    // --- 9) Depozito -------------------------------------------------------------
    echo "\n[9] Depozito takibi\n";
    $q = $pdo->prepare("INSERT INTO booking_folios(booking_id,currency,status) VALUES(?, 'EUR', 'open') RETURNING id");
    $q->execute([$booking['id']]);
    $folioId = (int) $q->fetchColumn();
    $pdo->prepare("UPDATE supplier_bookings SET deposit_amount=100,deposit_status='due' WHERE id=?")->execute([$booking['id']]);
    $q = $pdo->prepare('SELECT deposit_status FROM supplier_bookings WHERE id=?');
    $q->execute([$booking['id']]);
    check('Depozito tanımlı ve bekleniyor', $q->fetchColumn() === 'due');
    $pdo->prepare("UPDATE supplier_bookings SET deposit_status='paid',deposit_paid_at=now() WHERE id=?")->execute([$booking['id']]);
    $pdo->prepare("INSERT INTO folio_transactions(folio_id,transaction_type,department,description,amount) VALUES(?, 'payment', 'deposit', 'Depozito tahsilatı', ?)")->execute([$folioId, -100.0]);
    $q = $pdo->prepare("SELECT -SUM(amount) FILTER (WHERE transaction_type='payment') FROM folio_transactions WHERE folio_id=?");
    $q->execute([$folioId]);
    check('Depozito tahsilatı folyoya işlendi (100)', abs((float) $q->fetchColumn() - 100.0) < 0.01);

    // --- 10) Sadakat + check-out -------------------------------------------------
    echo "\n[10] Sadakat puanı ve check-out\n";
    $pdo->prepare("UPDATE supplier_bookings SET booking_status='checked_in',checked_in_at=now() WHERE id=?")->execute([$booking['id']]);
    $award = award_loyalty_points((int) $booking['id']);
    check('Check-out puanı kazandırıldı (4 gece = 40 puan)', $award['ok'] && abs((float) ($award['points'] ?? 0) - 40.0) < 0.01, $award['ok'] ? 'puan: ' . $award['points'] : ($award['message'] ?? ''));
    $q = $pdo->prepare("SELECT la.points_balance FROM guest_loyalty_accounts la JOIN booking_guests bg ON bg.guest_id=la.guest_id WHERE bg.booking_id=?");
    $q->execute([$booking['id']]);
    check('Sadakat hesabı 40 puan taşıyor', abs((float) $q->fetchColumn() - 40.0) < 0.01);
    $pdo->prepare("UPDATE supplier_bookings SET booking_status='checked_out',checked_out_at=now() WHERE id=?")->execute([$booking['id']]);
    $q = $pdo->prepare("SELECT booking_status FROM supplier_bookings WHERE id=?");
    $q->execute([$booking['id']]);
    check('Check-out tamamlandı', $q->fetchColumn() === 'checked_out');

    // --- Temizlik: tüm test verisi geri alınır -------------------------------
    $pdo->rollBack();

    echo "\n================================\n";
    $failed = count($GLOBALS['failures']);
    echo ($failed === 0 ? "TÜMÜ GEÇTİ" : "BAŞARISIZ: $failed") . " (" . $GLOBALS['checks'] . " kontrol)\n";
    exit($failed === 0 ? 0 : 1);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'HATA: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
    exit(1);
}
