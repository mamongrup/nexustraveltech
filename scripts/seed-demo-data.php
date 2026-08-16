#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Bu komut yalnızca komut satırından çalıştırılabilir.');
}

require_once __DIR__ . '/../config/database.php';

/**
 * NEXUS demo verisi üretici (CLI).
 *
 * Kullanım: php scripts/seed-demo-data.php
 * Tek transaction içinde koşar; her şey başarılı olursa commit eder.
 * Demo verisi zaten varsa (DEMO — Demir Otel) hiçbir şey değiştirmez.
 *
 * UYARI: Bu komut demo kullanıcıları oluşturur. Yalnızca geliştirme ve
 * satış demosu ortamlarında çalıştırın; canlı/üretim veritabanında çalıştırmayın.
 */

$say = static function (string $msg): void {
    echo '[seed] ' . $msg . PHP_EOL;
};

$pdo = db();
$pdo->beginTransaction();
try {
    // Idempotency: demo tesis zaten varsa hiçbir şey yapma.
    $existing = $pdo->query("SELECT id FROM properties WHERE name='DEMO — Demir Otel' LIMIT 1")->fetchColumn();
    if ($existing) {
        $pdo->rollBack();
        $say('Demo verisi zaten mevcut (DEMO — Demir Otel, id=' . (int) $existing . ').');
        $say('Temizlemek için: psql -c "DELETE FROM properties WHERE id=' . (int) $existing . ';"');
        exit(0);
    }

    // 1) Tedarikçi: pilot tedarikçiyi kullan; demo kullanıcısını yalnızca yoksa oluştur.
    $q = $pdo->prepare("SELECT supplier_id FROM supplier_users WHERE email=? LIMIT 1");
    $q->execute(['pilot@nexustraveltech.com']);
    $supplierId = $q->fetchColumn();
    if (!$supplierId) {
        $q = $pdo->prepare("INSERT INTO suppliers(company_name,status) VALUES(?,'pilot') RETURNING id");
        $q->execute(['DEMO Tedarik A.Ş.']);
        $supplierId = (int) $q->fetchColumn();
        $say('Tedarikçi oluşturuldu (id=' . $supplierId . ').');
    } else {
        $supplierId = (int) $supplierId;
        $say('Mevcut pilot tedarikçi kullanıldı (id=' . $supplierId . ').');
    }
    $q = $pdo->prepare('SELECT id FROM supplier_users WHERE supplier_id=? AND email=? LIMIT 1');
    $q->execute([$supplierId, 'demo@nexustraveltech.com']);
    $supplierUserId = $q->fetchColumn();
    if (!$supplierUserId) {
        $q = $pdo->prepare('INSERT INTO supplier_users(supplier_id,full_name,email,password_hash,role) VALUES(?,?,?,?,?) RETURNING id');
        $q->execute([$supplierId, 'Demo Kullanıcı', 'demo@nexustraveltech.com', password_hash('NexusDemo2026!', PASSWORD_DEFAULT), 'manager']);
        $supplierUserId = (int) $q->fetchColumn();
        $say('Demo tedarikçi kullanıcısı oluşturuldu (demo@nexustraveltech.com / NexusDemo2026!).');
    }

    // 2) Tesis + oda tipleri + fiyat planları + fiziksel odalar
    $q = $pdo->prepare("INSERT INTO properties(supplier_id,property_type,name,city,country_code,status,product_details) VALUES(?,?,?,?,?,'active',?::jsonb) RETURNING id");
    $q->execute([$supplierId, 'hotel', 'DEMO — Demir Otel', 'Antalya', 'TR', json_encode(['commission_rate' => 10, 'board' => 'half_board', 'stars' => 4], JSON_UNESCAPED_UNICODE)]);
    $propertyId = (int) $q->fetchColumn();

    $roomSpecs = [
        ['Standart Oda', 2, 4, 90.0],
        ['Deniz Manzaralı', 2, 3, 120.0],
        ['Aile Suiti', 4, 2, 170.0],
    ];
    $roomTypeIds = [];
    $roomInsert = $pdo->prepare('INSERT INTO room_types(property_id,name,capacity_adults,total_units,status) VALUES(?,?,?,?,\'active\') RETURNING id');
    foreach ($roomSpecs as [$name, $cap, $units, $price]) {
        $roomInsert->execute([$propertyId, $name, $cap, $units]);
        $roomTypeIds[] = ['id' => (int) $roomInsert->fetchColumn(), 'units' => $units, 'price' => $price];
    }
    $say(count($roomTypeIds) . ' oda tipi oluşturuldu.');

    $q = $pdo->prepare("INSERT INTO rate_plans(property_id,name,currency,board_type,status) VALUES(?,?,?,'half_board','active') RETURNING id");
    $q->execute([$propertyId, 'Yarım Pansiyon', 'EUR']);
    $rateHalf = (int) $q->fetchColumn();
    $q = $pdo->prepare("INSERT INTO rate_plans(property_id,name,currency,board_type,status) VALUES(?,?,?,'all_inclusive','active') RETURNING id");
    $q->execute([$propertyId, 'Her Şey Dahil', 'EUR']);
    $rateAll = (int) $q->fetchColumn();

    $roomInsert = $pdo->prepare('INSERT INTO physical_rooms(property_id,room_type_id,room_number,floor_label,status) VALUES(?,?,?,?,\'clean\') RETURNING id');
    $floor = ['Standart Oda' => [101, 102, 103, 104], 'Deniz Manzaralı' => [201, 202, 203], 'Aile Suiti' => [301, 302]];
    $roomIds = [];
    foreach ($roomTypeIds as $i => $rt) {
        $base = 101 + $i * 100;
        for ($u = 0; $u < $rt['units']; $u++) {
            $roomInsert->execute([$propertyId, $rt['id'], (string) ($base + $u), 'Kat ' . (int) floor(($base + $u) / 100)]);
            $roomIds[] = (int) $roomInsert->fetchColumn();
        }
    }
    $say(count($roomIds) . ' fiziksel oda oluşturuldu.');

    // 3) 90 günlük takvim: fiyat ve kontenjan
    $cal = $pdo->prepare('INSERT INTO inventory_calendar(room_type_id,rate_plan_id,stay_date,allotment,sold,base_price,min_stay) VALUES(?,?,?,4,0,?,1)');
    $date = new DateTimeImmutable('today');
    $end = $date->modify('+89 days');
    $days = 0;
    for ($d = $date; $d <= $end; $d = $d->modify('+1 day')) {
        foreach ($roomTypeIds as $rt) {
            $price = $rt['price'] + ($days >= 60 ? 15 : 0); // sezon ilerledikçe fiyat artışı
            $cal->execute([$rt['id'], $rateHalf, $d->format('Y-m-d'), $price]);
            $cal->execute([$rt['id'], $rateAll, $d->format('Y-m-d'), round($price * 1.25, 2)]);
        }
        $days++;
    }
    $say($days . ' günlük takvim oluşturuldu (2 fiyat planı).');

    // 4) Satış kuralı: 30+ gün önceden erken rezervasyon %10
    $pdo->prepare("INSERT INTO rate_rules(supplier_id,property_id,name,rule_type,value,min_advance_days,priority,stackable,status) VALUES(?,?,?,'percent',10,30,10,true,'active')")
        ->execute([$supplierId, $propertyId, 'Erken rezervasyon %10']);
    $say('Satış kuralı eklendi: Erken rezervasyon %10 (30+ gün).');

    // 5) Demo acente + kullanıcı + API anahtarı + müşteriler
    $q = $pdo->prepare("INSERT INTO agencies(company_name,license_number,country_code,status) VALUES(?,?,'TR','active') RETURNING id");
    $q->execute(['DEMO Turizm', 'DEMO-2026-001']);
    $agencyId = (int) $q->fetchColumn();
    $q = $pdo->prepare('INSERT INTO agency_users(agency_id,full_name,email,password_hash,role) VALUES(?,?,?,?,?) RETURNING id');
    $q->execute([$agencyId, 'Demo Acente', 'demo@nexustraveltech.com', password_hash('DemoAcente2026!', PASSWORD_DEFAULT), 'owner']);
    $q->fetchColumn();
    $apiRaw = 'nexdemo_' . bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO agency_api_keys(agency_id,key_prefix,key_hash,label,scopes,status) VALUES(?,?,?,?,?::jsonb,\'active\')')
        ->execute([$agencyId, 'NEXDEMO', hash('sha256', $apiRaw), 'Demo anahtarı', json_encode(['products'], JSON_UNESCAPED_UNICODE)]);
    $say('Demo acente oluşturuldu (demo@nexustraveltech.com / DemoAcente2026!).');
    $say('Demo API anahtarı: ' . $apiRaw);

    $customerInsert = $pdo->prepare('INSERT INTO agency_customers(agency_id,full_name,email,phone) VALUES(?,?,?,?)');
    $customerInsert->execute([$agencyId, 'Ali Yılmaz', 'ali@example.com', '+90 532 000 00 01']);
    $customerInsert->execute([$agencyId, 'Ayşe Demir', 'ayse@example.com', '+90 532 000 00 02']);

    // 6) Geçmiş rezervasyon (değerlendirme demosu için)
    $checkIn = date('Y-m-d', strtotime('-20 days'));
    $checkOut = date('Y-m-d', strtotime('-15 days'));
    $ref = 'NXS-DEMO-' . date('ymd');
    $q = $pdo->prepare("INSERT INTO supplier_bookings(supplier_id,property_id,booking_reference,status,booking_status,check_in,check_out,total_amount,currency,source_code,notes) VALUES(?,?,?,'confirmed','checked_out',?,?,360.0,'EUR','manual','Demo: değerlendirme akışı') RETURNING id");
    $q->execute([$supplierId, $propertyId, $ref, $checkIn, $checkOut]);
    $bookingId = (int) $q->fetchColumn();
    $q = $pdo->prepare('INSERT INTO guest_profiles(supplier_id,first_name,last_name,email,nationality) VALUES(?,?,?,?,?) RETURNING id');
    $q->execute([$supplierId, 'Mehmet', 'Kaya', 'mehmet@example.com', 'DE']);
    $guestId = (int) $q->fetchColumn();
    $pdo->prepare('INSERT INTO booking_guests(booking_id,guest_id,is_primary) VALUES(?,?,true)')->execute([$bookingId, $guestId]);
    $pdo->prepare('INSERT INTO booking_rooms(booking_id,room_type_id,adults,children,nightly_rate,currency) VALUES(?,?,2,0,120.0,\'EUR\')')->execute([$bookingId, $roomTypeIds[1]['id']]);
    $pdo->prepare('INSERT INTO booking_folios(booking_id,currency) VALUES(?,?)')->execute([$bookingId, 'EUR']);

    $reviewToken = 'demo-review-' . bin2hex(random_bytes(6));
    $pdo->prepare("INSERT INTO guest_reviews(property_id,booking_id,token_hash,status) VALUES(?,?,?,'invited')")
        ->execute([$propertyId, $bookingId, hash('sha256', $reviewToken)]);
    $say('Geçmiş rezervasyon oluşturuldu (değerlendirme linki: https://nexustraveltech.com/misafir/degerlendirme?token=' . $reviewToken . ')');

    // 7) Bekleyen acente rezervasyon talebi (onay akışı demosu)
    $pdo->prepare('INSERT INTO agency_booking_requests(agency_id,supplier_id,property_id,room_type_id,rate_plan_id,check_in,check_out,nights,adults,total_amount,currency,guest_first_name,guest_last_name,guest_email,agency_reference,note) VALUES(?,?,?,?,?,?,?,3,2,360.0,\'EUR\',\'Zeynep\',\'Arslan\',\'zeynep@example.com\',\'DEMO-REF-1\',\'Demo talebi — onay akışını denemek için\')')
        ->execute([$agencyId, $supplierId, $propertyId, $roomTypeIds[1]['id'], $rateAll, date('Y-m-d', strtotime('+30 days')), date('Y-m-d', strtotime('+33 days'))]);

    $pdo->commit();
    $say('');
    $say('Demo verisi hazır!');
    $say('  Tedarikçi paneli : /nexustraveltech/tedarikci  (demo@nexustraveltech.com / NexusDemo2026!)');
    $say('  Acente paneli     : /nexustraveltech/acente       (demo@nexustraveltech.com / DemoAcente2026!)');
    $say('  Bekleyen rezervasyon talebi tedarikçi panelinde: Acente rezervasyonları.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[seed] HATA: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
