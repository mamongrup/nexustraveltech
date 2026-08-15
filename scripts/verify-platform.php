<?php
declare(strict_types=1);
require_once __DIR__.'/../config/database.php';

$requiredTables=['suppliers','properties','supplier_bookings','inventory_calendar','channel_connections','ical_connections','ical_events','physical_rooms','booking_folios','folio_transactions','payment_records','payment_allocations','hotel_invoices','night_audit_runs','hotel_staff','hotel_roles','loyalty_tiers','guest_loyalty_accounts','revenue_recommendations','guest_service_requests'];
try {
    $pdo=db();
    $query=$pdo->prepare("SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename=ANY(?::text[])");
    $query->execute(['{'.implode(',', $requiredTables).'}']);
    $found=array_flip($query->fetchAll(PDO::FETCH_COLUMN));
    $missing=array_values(array_diff($requiredTables,array_keys($found)));
    $config=db_config();
    $errors=[];
    if($missing)$errors[]='Eksik tablolar: '.implode(', ',$missing);
    if(strlen((string)($config['app_encryption_key']??''))<32)$errors[]='app_encryption_key eksik veya 32 karakterden kısa.';
    if(!extension_loaded('curl'))$errors[]='PHP cURL etkin değil; iCal aktarımı çalışmaz.';
    if(!extension_loaded('pdo_pgsql'))$errors[]='PDO PostgreSQL etkin değil.';
    if($errors){foreach($errors as $error)fwrite(STDERR,'HATA: '.$error.PHP_EOL);exit(1);}
    echo 'NEXUS platform doğrulaması başarılı. '.count($requiredTables).' tablo ve gerekli PHP uzantıları hazır.'.PHP_EOL;
} catch(Throwable $e) { fwrite(STDERR,'HATA: Veritabanı doğrulaması yapılamadı.'.PHP_EOL); exit(1); }
