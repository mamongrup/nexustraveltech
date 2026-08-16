<?php
declare(strict_types=1);
require_once __DIR__.'/../config/database.php';

$requiredTables=['suppliers','supplier_users','properties','supplier_bookings','inventory_calendar','channel_connections','ical_connections','ical_events','physical_rooms','booking_folios','folio_transactions','payment_records','payment_allocations','hotel_invoices','night_audit_runs','hotel_staff','hotel_roles','loyalty_tiers','guest_loyalty_accounts','revenue_recommendations','guest_service_requests','login_throttle','guest_reviews','agency_booking_requests','email_outbox','webhook_subscriptions','webhook_deliveries','error_logs','admin_audit_logs','payment_links','fx_rates','booking_groups','notifications','agencies','agency_users','email_templates','admin_2fa'];

// Kritik kolonlar: migration eksikse burada yakalanır (tablo->kolon listesi).
$requiredColumns=[
    'supplier_bookings'=>['rate_plan_id','booking_status','checked_in_at','checked_out_at','early_arrival','late_departure','deposit_amount','deposit_status','no_show_at','cancelled_at','cancellation_reason'],
    'agency_booking_requests'=>['booking_id','consent_at'],
    'guest_document_records'=>['reported_at'],
    'rate_plans'=>['free_cancel_before_days','cancel_fee_percent'],
    'supplier_users'=>['totp_secret'],
    'agency_users'=>['totp_secret'],
    'agencies'=>['verify_token','verified_at','self_registered','credit_limit','payment_score'],
    'email_outbox'=>['to_address','subject','body_html','status','error_message','sent_at'],
    'webhook_subscriptions'=>['url','secret','events','status','created_at'],
    'webhook_deliveries'=>['event','payload','status','attempts','http_status','error_message','sent_at'],
    'login_throttle'=>['bucket','attempts','window_start','locked_until'],
    'guest_reviews'=>['token_hash','rating','status','response','submitted_at'],
    'agency_quotes'=>['quote_number','valid_until','total_amount','status'],
    'payment_links'=>['token','status','test_mode','amount','currency'],
    'fx_rates'=>['base_currency','quote_currency','rate','rate_date'],
    'error_logs'=>['level','status','message'],
    'admin_audit_logs'=>['action','admin_username','details'],
    'booking_groups'=>['group_code','status','option_expires_at'],
    'notifications'=>['user_type','user_id','is_read','message'],
    'email_templates'=>['code','subject','body_html','is_active'],
    'admin_2fa'=>['secret','enabled'],
];

try {
    $pdo=db();
    $query=$pdo->prepare("SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename=ANY(?::text[])");
    $query->execute(['{'.implode(',', $requiredTables).'}']);
    $found=array_flip($query->fetchAll(PDO::FETCH_COLUMN));
    $missing=array_values(array_diff($requiredTables,array_keys($found)));
    $config=db_config();
    $errors=[];
    if($missing)$errors[]='Eksik tablolar: '.implode(', ',$missing);

    // Kolon bazlı kontrol — yalnızca mevcut tablolarda.
    $colStmt=$pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name=?");
    foreach($requiredColumns as $table=>$cols){
        if(in_array($table,$missing))continue;
        $colStmt->execute([$table]);
        $existing=array_flip($colStmt->fetchAll(PDO::FETCH_COLUMN));
        $missingCols=array_values(array_diff($cols,array_keys($existing)));
        if($missingCols)$errors[]="{$table} tablosunda eksik kolon(lar): ".implode(', ',$missingCols);
    }

    if(strlen((string)($config['app_encryption_key']??''))<32)$errors[]='app_encryption_key eksik veya 32 karakterden kısa.';
    if(!extension_loaded('curl'))$errors[]='PHP cURL etkin değil; iCal aktarımı çalışmaz.';
    if(!extension_loaded('pdo_pgsql'))$errors[]='PDO PostgreSQL etkin değil.';
    if($errors){foreach($errors as $error)fwrite(STDERR,'HATA: '.$error.PHP_EOL);exit(1);}
    echo 'NEXUS platform doğrulaması başarılı. '.count($requiredTables).' tablo, kolon şemaları ve gerekli PHP uzantıları hazır.'.PHP_EOL;
} catch(Throwable $e) { fwrite(STDERR,'HATA: Veritabanı doğrulaması yapılamadı.'.PHP_EOL); exit(1); }
