<?php
declare(strict_types=1);
require_once __DIR__.'/../config/database.php';

$requiredTables=['suppliers','supplier_users','properties','supplier_bookings','inventory_calendar','channel_connections','ical_connections','ical_events','physical_rooms','booking_folios','folio_transactions','payment_records','payment_allocations','hotel_invoices','night_audit_runs','hotel_staff','hotel_roles','loyalty_tiers','guest_loyalty_accounts','revenue_recommendations','guest_service_requests','login_throttle','guest_reviews','agency_booking_requests','email_outbox','webhook_subscriptions','webhook_deliveries','error_logs','admin_audit_logs','payment_links','fx_rates','booking_groups','notifications','agencies','agency_users','email_templates','admin_2fa','scheduled_jobs','public_chat_messages','blocked_ips','panel_chat_messages','scheduled_job_runs','property_feature_catalog','channel_room_mappings','feature_delete_backups','channel_sync_logs','ical_sync_logs','pending_trash_purges'];

// Kritik kolonlar: migration eksikse burada yakalanır (tablo->kolon listesi).
$requiredColumns=[
    'supplier_bookings'=>['rate_plan_id','booking_status','checked_in_at','checked_out_at','early_arrival','late_departure','deposit_amount','deposit_status','no_show_at','cancelled_at','cancellation_reason'],
    'agency_booking_requests'=>['booking_id'],
    'guest_document_records'=>['reported_at'],
    'rate_plans'=>['free_cancel_before_days','cancel_fee_percent'],
    'supplier_users'=>['totp_secret'],
    'agency_users'=>['totp_secret'],
    'agencies'=>['verify_token','verified_at','self_registered','credit_limit','payment_score'],
    'email_outbox'=>['to_address','subject','body_html','status','error_message','sent_at','attachment_name','attachment_base64'],
    'panel_chat_messages'=>['role','supplier_id','agency_id','user_message','ai_reply'],
    'scheduled_job_runs'=>['job_id','status','output','duration_ms','triggered_by'],
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
    'scheduled_jobs'=>['code','command','schedule','enabled','last_status','last_fail_alert_at'],
    'property_feature_catalog'=>['deleted_at'],
    'feature_delete_backups'=>['feature_id','code','label','affected_properties'],
    'channel_room_mappings'=>['channel_connection_id','property_id','room_type_id','external_room_id','rate_plan_id','status','suggested_at','suggestion_count','suggestion_score'],
    'channel_sync_logs'=>['channel_connection_id','property_id','direction','scope','status','request_payload','response_payload','error_message','fx_audit'],
    'ical_sync_logs'=>['ical_connection_id','property_id','status','error_message','error_hash'],
    'pending_trash_purges'=>['feature_id','token','expires_at','approved_at'],
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

    // Oda eşleştirme durumu — channel_room_mappings tablosu varsa tutarlılık denetimi.
    if(!in_array('channel_room_mappings',$missing,true)){
        $mappingCount=(int)$pdo->query('SELECT COUNT(*) FROM channel_room_mappings')->fetchColumn();
        $orphanMappings=(int)$pdo->query("SELECT COUNT(*) FROM channel_room_mappings m LEFT JOIN room_types rt ON rt.id=m.room_type_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id WHERE rt.id IS NULL OR c.id IS NULL OR rt.property_id<>m.property_id OR (m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id))")->fetchColumn();
        if($orphanMappings>0)$errors[]="channel_room_mappings: {$orphanMappings} yetim/uyumsuz eşleştirme (oda tipi veya kanal yok, ya da oda tipi başka ürüne ait).";
        echo 'Oda eşleştirme durumu: '.$mappingCount.' kayıt, '.$orphanMappings." uyumsuz.\n";
    }
    // Migration durumu — schema_migrations takibi (health-check ile aynı; burada uygulanmaz, yalnızca raporlanır).
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, file VARCHAR(190) NOT NULL UNIQUE, applied_at TIMESTAMPTZ NOT NULL DEFAULT now())");
    $hasCommitCol=(bool)$pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='schema_migrations' AND column_name='commit_hash'")->fetchColumn();
    if(!$hasCommitCol)$pdo->exec('ALTER TABLE schema_migrations ADD COLUMN commit_hash CHAR(40)');
    $appliedRows=$pdo->query('SELECT file, commit_hash FROM schema_migrations')->fetchAll();
    $appliedMap=[];
    foreach($appliedRows as $ar)$appliedMap[$ar['file']]=(string)($ar['commit_hash']??'');
    $migrationFiles=glob(__DIR__.'/../database/migrations/*-postgres.sql');
    sort($migrationFiles);
    $legacyFiles=glob(__DIR__.'/../database/migrations/[0-9][0-9][0-9]-*.sql');
    $legacyCount=count(array_filter($legacyFiles,fn($f)=>!str_contains($f,'-postgres')));
    echo 'Migration durumu ('.count($migrationFiles).' postgres + '.$legacyCount." legacy atlandı):".PHP_EOL;
    $pendingMigs=[];
    foreach($migrationFiles as $file){
        $base=basename($file);
        if(isset($appliedMap[$base])){echo '  ✓ '.$base.($appliedMap[$base]!==''?' @ '.substr($appliedMap[$base],0,7):'').PHP_EOL;}
        else{$pendingMigs[]=$base;echo '  ⏳ '.$base.' (bekliyor — scripts/health-check.php uygular)'.PHP_EOL;}
    }
    if($pendingMigs)echo '  NOT: '.count($pendingMigs).' migration bekliyor: '.implode(', ',$pendingMigs).PHP_EOL;
    if(strlen((string)($config['app_encryption_key']??''))<32)$errors[]='app_encryption_key eksik veya 32 karakterden kısa.';
    if(!extension_loaded('curl'))$errors[]='PHP cURL etkin değil; iCal aktarımı çalışmaz.';
    if(!extension_loaded('pdo_pgsql'))$errors[]='PDO PostgreSQL etkin değil.';
    if($errors){foreach($errors as $error)fwrite(STDERR,'HATA: '.$error.PHP_EOL);exit(1);}
    echo 'NEXUS platform doğrulaması başarılı. '.count($requiredTables).' tablo, kolon şemaları ve gerekli PHP uzantıları hazır.'.PHP_EOL;
} catch(Throwable $e) { fwrite(STDERR,'HATA: Veritabanı doğrulaması yapılamadı.'.PHP_EOL); exit(1); }
