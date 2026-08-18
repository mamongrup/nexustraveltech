<?php
declare(strict_types=1);
// Platform doğrulaması — tablo/kolon/migration durumu tek komutla:
//   /opt/plesk/php/8.5/bin/php scripts/verify-platform.php          → insan çıktısı
//   /opt/plesk/php/8.5/bin/php scripts/verify-platform.php --json   → makinece okunabilir JSON
// --json: checks (tables/columns/room_mappings/integration/repair_audit/migrations/env)
// + errors listesi; çıkış kodu sorun varsa 1 (health-check --json deseniyle uyumlu).
require_once __DIR__.'/../config/database.php';

$jsonMode = in_array('--json', $argv ?? [], true);

$requiredTables=['suppliers','supplier_users','properties','supplier_bookings','inventory_calendar','channel_connections','channel_property_mappings','ical_connections','ical_events','physical_rooms','booking_folios','folio_transactions','payment_records','payment_allocations','hotel_invoices','night_audit_runs','hotel_staff','hotel_roles','loyalty_tiers','guest_loyalty_accounts','revenue_recommendations','guest_service_requests','login_throttle','guest_reviews','agency_booking_requests','email_outbox','webhook_subscriptions','webhook_deliveries','error_logs','admin_audit_logs','payment_links','fx_rates','booking_groups','notifications','agencies','agency_users','email_templates','admin_2fa','scheduled_jobs','public_chat_messages','blocked_ips','panel_chat_messages','scheduled_job_runs','property_feature_catalog','channel_room_mappings','channel_rate_plan_mappings','feature_delete_backups','channel_sync_logs','ical_sync_logs','pending_trash_purges','fx_audit_daily','channel_mapping_blacklist'];

// Kritik kolonlar: migration eksikse burada yakalanır (tablo->kolon listesi).
$requiredColumns=[
    'suppliers'=>['settings'],
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
    'fx_audit_daily'=>['audit_date','missing_count','stale_count','details'],
    'error_logs'=>['level','status','message'],
    'admin_audit_logs'=>['action','admin_username','details'],
    'booking_groups'=>['group_code','status','option_expires_at'],
    'notifications'=>['user_type','user_id','is_read','message'],
    'email_templates'=>['code','subject','body_html','is_active'],
    'admin_2fa'=>['secret','enabled'],
    'scheduled_jobs'=>['code','command','schedule','enabled','last_status','last_fail_alert_at'],
    'property_feature_catalog'=>['deleted_at'],
    'feature_delete_backups'=>['feature_id','code','label','affected_properties'],
    'channel_room_mappings'=>['channel_connection_id','property_id','room_type_id','external_room_id','rate_plan_id','status','suggested_at','suggestion_count','suggestion_score','approved_by_type','approved_by_name','approved_by_user_id','approved_at'],'channel_property_mappings'=>['channel_connection_id','property_id','external_property_id','status'],'channel_rate_plan_mappings'=>['channel_connection_id','property_id','external_rate_plan_id','status','rate_plan_id'],
    'channel_sync_logs'=>['channel_connection_id','property_id','direction','scope','status','request_payload','response_payload','error_message','fx_audit'],
    'ical_sync_logs'=>['ical_connection_id','property_id','status','error_message','error_hash'],
    'pending_trash_purges'=>['feature_id','token','expires_at','approved_at'],
    'product_type_catalog'=>['step_targets'],
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
    $checks = [];
    $checks['tables'] = [
        'status' => $missing === [] ? 'ok' : 'error',
        'total' => count($requiredTables),
        'found' => count($found),
        'missing' => $missing,
    ];

    // Kolon bazlı kontrol — yalnızca mevcut tablolarda.
    $colStmt=$pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name=?");
    $colErrorsList = [];
    foreach($requiredColumns as $table=>$cols){
        if(in_array($table,$missing))continue;
        $colStmt->execute([$table]);
        $existing=array_flip($colStmt->fetchAll(PDO::FETCH_COLUMN));
        $missingCols=array_values(array_diff($cols,array_keys($existing)));
        if($missingCols){$colErrorsList[$table]=$missingCols;$errors[]="{$table} tablosunda eksik kolon(lar): ".implode(', ',$missingCols);}
    }
    $checks['columns'] = [
        'status' => $colErrorsList === [] ? 'ok' : 'error',
        'missing' => $colErrorsList,
    ];

    // Oda eşleştirme durumu — channel_room_mappings tablosu varsa tutarlılık denetimi.
    $checks['room_mappings'] = ['status' => 'ok', 'count' => 0, 'schema_ok' => true, 'missing_columns' => [], 'orphans' => 0];
    if(!in_array('channel_room_mappings',$missing,true)){
        $mappingCount=(int)$pdo->query('SELECT COUNT(*) FROM channel_room_mappings')->fetchColumn();
        $checks['room_mappings']['count'] = $mappingCount;
        // Şema uyumluluğu: yabancı/eski şemalı tablo (örn. channel_property_mapping_id + inventory_mode
        // ile elle/eski sürümde oluşturulmuş) sorguları patlatmasın — hedef durum raporlanır,
        // onarım scripts/health-check.php --repair ile yapılır (boşsa otomatik, doluysa elle).
        $rmCols=array_flip($pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name='channel_room_mappings'")->fetchAll(PDO::FETCH_COLUMN));
        $rmNeed=['channel_connection_id','property_id','external_room_id','room_type_id','status','rate_plan_id'];
        $rmMissing=array_values(array_filter($rmNeed,fn($c)=>!isset($rmCols[$c])));
        if($rmMissing){
            $errors[]='channel_room_mappings yabancı/eski şemada — eksik kolonlar: '.implode(', ',$rmMissing).' (scripts/health-check.php --repair ile yeniden kurun; tablo boşsa otomatik, doluysa elle veri taşıma gerekir).';
            $checks['room_mappings']['status'] = 'error';
            $checks['room_mappings']['schema_ok'] = false;
            $checks['room_mappings']['missing_columns'] = $rmMissing;
            echo 'Oda eşleştirme durumu: '.$mappingCount.' kayıt, ŞEMA UYUMSUZ ('.implode(', ',$rmMissing).") eksik — scripts/health-check.php --repair gerekli\n";
        } else {
            $orphanMappings=(int)$pdo->query("SELECT COUNT(*) FROM channel_room_mappings m LEFT JOIN room_types rt ON rt.id=m.room_type_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id WHERE rt.id IS NULL OR c.id IS NULL OR rt.property_id<>m.property_id OR (m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)))")->fetchColumn();
            $checks['room_mappings']['orphans'] = $orphanMappings;
            if($orphanMappings>0){$errors[]="channel_room_mappings: {$orphanMappings} yetim/uyumsuz eşleştirme (oda tipi veya kanal yok, ya da oda tipi başka ürüne ait).";$checks['room_mappings']['status']='error';}
            echo 'Oda eşleştirme durumu: '.$mappingCount.' kayıt, '.$orphanMappings." uyumsuz.\n";
            // Hedefi dolmuş öneriler: aynı kanal + dış kod için confirmed eşleşme varken hâlâ 'suggested'
            $staleRoom = (int) $pdo->query("SELECT COUNT(*) FROM channel_room_mappings s JOIN channel_room_mappings c ON c.channel_connection_id=s.channel_connection_id AND c.external_room_id=s.external_room_id AND c.status='confirmed' WHERE s.status='suggested'")->fetchColumn();
            $stalePlan = 0;
            try {
                $stalePlan = (int) $pdo->query("SELECT COUNT(*) FROM channel_rate_plan_mappings s JOIN channel_rate_plan_mappings c ON c.channel_connection_id=s.channel_connection_id AND c.external_rate_plan_id=s.external_rate_plan_id AND c.status='confirmed' WHERE s.status='suggested'")->fetchColumn();
            } catch (Throwable $e) {}
            $staleTotal = $staleRoom + $stalePlan;
            $checks['room_mappings']['stale_suggestions'] = $staleTotal;
            if ($staleTotal > 0) {
                $parts = [];
                if ($staleRoom > 0) $parts[] = 'oda: ' . $staleRoom;
                if ($stalePlan > 0) $parts[] = 'plan: ' . $stalePlan;
                echo '  ⚠ Hedefi dolmuş öneri: ' . $staleTotal . ' (' . implode(', ', $parts) . ') — health-check --repair ile confirmed yapılır' . PHP_EOL;
            }
        }
    } else {
        $checks['room_mappings']['status'] = 'error';
        $checks['room_mappings']['schema_ok'] = false;
        $checks['room_mappings']['missing_columns'] = ['tablo yok'];
    }
    // Yeni entegrasyon kolonları özeti — 047-055 migration'larının durumu tek satırda.
    // 047: channel_room_mappings.status/suggested_at/suggestion_count · 048: channel_sync_logs.fx_audit
    // 049: channel_room_mappings.rate_plan_id · 052: channel_room_mappings.suggestion_score
    // 053: product_type_catalog.step_targets · 054: channel_rate_plan_mappings (tablo/kolon)
    // 055: fx_audit_daily (tablo/kolon) · 061: channel_room_mappings.approved_by_* (onay izi)
    $newCols = [
        'channel_sync_logs.fx_audit' => '048',
        'channel_room_mappings.status' => '047',
        'channel_room_mappings.suggested_at' => '047',
        'channel_room_mappings.suggestion_count' => '047',
        'channel_room_mappings.rate_plan_id' => '049',
        'channel_room_mappings.suggestion_score' => '052',
        'product_type_catalog.step_targets' => '053',
        'channel_rate_plan_mappings.external_rate_plan_id' => '054',
        'fx_audit_daily.audit_date' => '055',
        'channel_room_mappings.approved_by_type' => '061',
        'channel_room_mappings.approved_at' => '061',
    ];
    $newSummary = [];
    $newColMissing = [];
    $colStmt2 = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name=?");
    foreach ($newCols as $colPath => $mig) {
        [$tbl, $col] = explode('.', $colPath, 2);
        if (in_array($tbl, $missing, true)) {
            $newSummary[] = $colPath . ' ✗ (tablo yok) ' . $mig;
            $newColMissing[] = $colPath . ' (migration ' . $mig . ' bekliyor) ';
            continue;
        }
        $colStmt2->execute([$tbl]);
        $colsNow = array_flip($colStmt2->fetchAll(PDO::FETCH_COLUMN));
        if (isset($colsNow[$col])) {
            $newSummary[] = $colPath . ' ✓';
        } else {
            $newSummary[] = $colPath . ' ✗ (migration ' . $mig . ' bekliyor) ';
            $newColMissing[] = $colPath . ' (migration ' . $mig . ' bekliyor) ';
        }
    }
    echo 'Yeni entegrasyon kolonları (047-055/061): ' . implode(' · ', $newSummary) . ' → ' . (count($newCols) - count($newColMissing)) . '/' . count($newCols) . ' hazır' . PHP_EOL;
    if ($newColMissing) {
        $errors[] = 'Yeni entegrasyon kolonları eksik: ' . implode(', ', $newColMissing) . '(scripts/health-check.php migration 047-055/061 uygular)';
    }
    $checks['integration'] = [
        'status' => $newColMissing === [] ? 'ok' : 'error',
        'ready' => count($newCols) - count($newColMissing),
        'total' => count($newCols),
        'missing' => array_values($newColMissing),
        'summary' => $newSummary,
    ];
    // Veri denetimi — channel_room_mappings / channel_rate_plan_mappings onarımı sonrası eski
    // kayıtların yeni şemaya nasıl taşındığını gösterir. Onarım yalnızca BOŞ tabloları düşürür
    // (health.repair_drop); dolu tablolara dokunmaz ve elle veri taşıma için raporlanır. Zincir:
    //   health.repair_drop           → tablo düşürüldü (o anda 0 kayıt; eski satır taşınacak veri yok)
    //   health.repair_verify         → migration zinciriyle yeniden kuruldu + kolon doğrulaması
    //   health.repair_orphan_cleanup → silinmiş hedefe işaret eden yetim satırlar temizlendi
    //   sonrası kayıtlar             → yeni şemaya webhook/panel akışıyla yazılan satırlar
    $auditTables = ['channel_room_mappings', 'channel_rate_plan_mappings'];
    $repairLogs = [];
    try {
        $repairQ = $pdo->prepare("SELECT action, details, admin_username, created_at FROM admin_audit_logs WHERE action IN ('health.repair_drop','health.repair_verify','health.repair_orphan_cleanup','health.repair_stale_confirm') AND created_at > now() - interval '90 days' ORDER BY id");
        $repairQ->execute();
        foreach ($repairQ->fetchAll() as $rl) {
            $d = json_decode((string) $rl['details'], true) ?: [];
            $tbl = (string) ($d['table'] ?? '');
            if ($tbl !== '' && !in_array($tbl, $auditTables, true)) continue;
            $repairLogs[] = [
                'action' => (string) $rl['action'],
                'table' => $tbl !== '' ? $tbl : '—',
                'migrations' => implode(', ', array_map('strval', (array) ($d['migrations'] ?? []))),
                'missing' => implode(', ', array_map('strval', (array) ($d['missing_columns'] ?? []))),
                'ok' => $d['ok'] ?? null,
                'total' => (int) ($d['total'] ?? 0),
                'at' => (string) $rl['created_at'],
            ];
        }
    } catch (Throwable $e) {
        $repairLogs = [];
    }
    echo 'Onarım veri denetimi (son 90 gün — admin_audit_logs):' . PHP_EOL;
    if ($repairLogs === []) {
        echo '  · Bu dönemde channel_room_mappings / channel_rate_plan_mappings onarım kaydı yok — eski şemadan taşınacak veri denetimi bulunamadı.' . PHP_EOL;
    } else {
        foreach ($repairLogs as $rl) {
            if ($rl['action'] === 'health.repair_drop') {
                $verdict = 'tablo BOŞTU → düşürüldü; eski satır yok (0 kayıt taşındı)' . ($rl['migrations'] !== '' ? ' · zincir: ' . $rl['migrations'] : ' · zincir belirtilmemiş');
            } elseif ($rl['action'] === 'health.repair_verify') {
                $verdict = $rl['ok'] ? 'yeniden kuruldu ✓ — beklenen kolonlar mevcut (sonraki satırlar yeni şemaya yazıldı)' : 'yeniden kurulamadı ✗ — eksik kolonlar kaldı: ' . ($rl['missing'] !== '' ? $rl['missing'] : '—');
            } elseif ($rl['action'] === 'health.repair_orphan_cleanup') {
                $verdict = $rl['total'] . ' yetim satır temizlendi (silinmiş oda tipi/plan/kanal bağlantıları)';
            } else {
                $verdict = 'hedefi dolmuş öneriler confirmed yapıldı: ' . ($rl['table'] !== '—' ? $rl['table'] : 'detay: ' . $rl['missing']);
            }
            echo '  · ' . $rl['at'] . ' — ' . str_replace('health.repair_', '', $rl['action']) . ' [' . $rl['table'] . '] → ' . $verdict . PHP_EOL;
        }
    }
    // Güncel veri durumu — onarım sonrası yeni şemaya yazılan satırlar (webhook/panel akışı).
    $auditCurrent = [];
    foreach ($auditTables as $at) {
        if (in_array($at, $missing, true)) continue;
        try {
            $count = (int) $pdo->query('SELECT COUNT(*) FROM "' . $at . '"')->fetchColumn();
            $conf = (int) $pdo->query('SELECT COUNT(*) FROM "' . $at . '" WHERE status=' . $pdo->quote('confirmed'))->fetchColumn();
            $sugg = (int) $pdo->query('SELECT COUNT(*) FROM "' . $at . '" WHERE status=' . $pdo->quote('suggested'))->fetchColumn();
            $latest = (string) ($pdo->query('SELECT MAX(created_at) FROM "' . $at . '"')->fetchColumn() ?: '—');
            $auditCurrent[$at] = ['count' => $count, 'confirmed' => $conf, 'suggested' => $sugg, 'latest' => $latest];
            echo '  · ' . $at . ' güncel: ' . $count . ' kayıt (' . $conf . ' confirmed · ' . $sugg . ' suggested) · son ekleme ' . $latest . PHP_EOL;
        } catch (Throwable $e) {
            $auditCurrent[$at] = ['error' => $e->getMessage()];
            echo '  · ' . $at . ' güncel durumu okunamadı: ' . $e->getMessage() . PHP_EOL;
        }
    }
    $checks['repair_audit'] = [
        'status' => $repairLogs === [] ? 'ok' : (count(array_filter($repairLogs, fn($rl) => $rl['action'] === 'health.repair_verify' && $rl['ok'] === false)) > 0 ? 'error' : 'info'),
        'logs' => $repairLogs,
        'current' => $auditCurrent,
    ];
    // Yetim eşleştirme özeti — üç tablonun toplam yetim sayısını tek satırda göster.
    $orphanSpecs = [
        'channel_room_mappings' => ['label' => 'oda', 'join' => 'LEFT JOIN room_types rt ON rt.id=m.room_type_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id', 'where' => 'm.room_type_id>0 AND (rt.id IS NULL OR c.id IS NULL OR rt.property_id<>m.property_id OR (m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)))'],
        'channel_rate_plan_mappings' => ['label' => 'plan', 'join' => 'LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id', 'where' => '(m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)) OR c.id IS NULL'],
        'channel_property_mappings' => ['label' => 'ürün', 'join' => 'LEFT JOIN properties p ON p.id=m.property_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id', 'where' => 'p.id IS NULL OR c.id IS NULL'],
        'ical_connections' => ['label' => 'iCal', 'join' => 'LEFT JOIN properties p ON p.id=m.property_id', 'where' => 'p.id IS NULL'],
    ];
    $orphanParts = [];
    $orphanTotal = 0;
    foreach ($orphanSpecs as $ot => $os) {
        if (in_array($ot, $missing, true)) continue;
        try {
            $oc = (int) $pdo->query('SELECT COUNT(*) FROM ' . $ot . ' m ' . $os['join'] . ' WHERE ' . $os['where'])->fetchColumn();
            $orphanParts[] = $os['label'] . ': ' . $oc;
            $orphanTotal += $oc;
        } catch (Throwable $e) {}
    }
    $orphanStatus = $orphanTotal === 0 ? 'ok' : 'warn';
    echo 'Yetim eşleştirme özeti: ' . ($orphanParts ? implode(' · ', $orphanParts) : '—') . ' → toplam: ' . $orphanTotal . ($orphanTotal > 0 ? ' (health-check --repair ile temizlenir)' : ' ✓') . PHP_EOL;
    $checks['orphans'] = [
        'status' => $orphanStatus,
        'total' => $orphanTotal,
        'parts' => $orphanParts,
    ];
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
    $appliedMigs=[];
    foreach($migrationFiles as $file){
        $base=basename($file);
        if(isset($appliedMap[$base])){$appliedMigs[]=['file'=>$base,'commit'=>($appliedMap[$base]!==''?substr($appliedMap[$base],0,7):null)];echo '  ✓ '.$base.($appliedMap[$base]!==''?' @ '.substr($appliedMap[$base],0,7):'').PHP_EOL;}
        else{$pendingMigs[]=$base;echo '  ⏳ '.$base.' (bekliyor — scripts/health-check.php uygular)'.PHP_EOL;}
    }
    if($pendingMigs)echo '  NOT: '.count($pendingMigs).' migration bekliyor: '.implode(', ',$pendingMigs).PHP_EOL;
    $checks['migrations'] = [
        'status' => $pendingMigs === [] ? 'ok' : 'error',
        'total' => count($migrationFiles),
        'legacy_skipped' => $legacyCount,
        'applied' => $appliedMigs,
        'pending' => $pendingMigs,
    ];
    if(strlen((string)($config['app_encryption_key']??''))<32)$errors[]='app_encryption_key eksik veya 32 karakterden kısa.';
    if(!extension_loaded('curl'))$errors[]='PHP cURL etkin değil; iCal aktarımı çalışmaz.';
    if(!extension_loaded('pdo_pgsql'))$errors[]='PDO PostgreSQL etkin değil.';
    $checks['env'] = [
        'status' => strlen((string)($config['app_encryption_key']??'')) >= 32 && extension_loaded('curl') && extension_loaded('pdo_pgsql') ? 'ok' : 'error',
        'app_encryption_key' => strlen((string)($config['app_encryption_key']??'')) >= 32,
        'curl' => extension_loaded('curl'),
        'pdo_pgsql' => extension_loaded('pdo_pgsql'),
    ];
    if($jsonMode){
        echo json_encode([
            'ok' => $errors === [],
            'ran_at' => gmdate('c'),
            'checks' => $checks,
            'errors' => $errors,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit($errors === [] ? 0 : 1);
    }
    if($errors){foreach($errors as $error)fwrite(STDERR,'HATA: '.$error.PHP_EOL);exit(1);}
    echo 'NEXUS platform doğrulaması başarılı. '.count($requiredTables).' tablo, kolon şemaları ve gerekli PHP uzantıları hazır.'.PHP_EOL;
} catch(Throwable $e) {
    if($jsonMode){echo json_encode(['ok'=>false,'ran_at'=>gmdate('c'),'checks'=>$checks??[],'errors'=>['Veritabanı doğrulaması yapılamadı: '.$e->getMessage()]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;exit(1);}
    fwrite(STDERR,'HATA: Veritabanı doğrulaması yapılamadı: '.$e->getMessage().PHP_EOL); exit(1); }
