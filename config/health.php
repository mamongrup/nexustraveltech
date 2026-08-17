<?php
declare(strict_types=1);

// Sağlık kontrolü mantığı — scripts/health-check.php ve günlük zamanlayıcı görevi
// (cron/health-check-alert.php) tarafından paylaşılır.
//
// Bölümler: 1) tablolar   2) kritik kolonlar   3) migration durumu (schema_migrations
// takibi, yalnızca *-postgres.sql; legacy MySQL dosyaları atlanır)   4) ortam
// (app_encryption_key, PDO PostgreSQL, cURL).
//
// @return array{ok: bool, output: string, errors: list<string>}

require_once __DIR__ . '/database.php';

function health_check_run(bool $dryRun = false, bool $repair = false): array
{
    $pdo = db();
    $errors = [];
    $out = '';

    // --repair: bilinen yabancı/hatalı şemalı tabloları otomatik tespit edip, BOŞSA düşürür;
    // ardından migration bölümü tabloyu TAM migration zinciriyle yeniden kurar.
    // DOLU tablolara asla dokunulmaz (raporlanır, elle müdahale gerekir).
    // Tablo yanlış şemadaysa beklenen kolon yoktur -> boşsa düşürülür, migration'lar
    // (schema_migrations'ta kayıtlı olsalar bile) zorla yeniden uygulanır.
    $repairMap = [
        // tablo => [beklenen kolon, tam migration zinciri]
        'channel_room_mappings' => [
            'channel_connection_id',
            ['045-channel-room-mappings-postgres.sql', '047-room-mapping-suggestions-postgres.sql', '049-room-plan-mapping-postgres.sql', '052-suggestion-score-postgres.sql'],
        ],
        'channel_property_mappings' => [
            'external_property_id',
            ['019-distribution-and-rate-management-postgres.sql'],
        ],
    ];
    $reapplyMigrations = []; // onarım sonrası zorla yeniden uygulanacak migration dosyaları

    $requiredTables = ['suppliers','supplier_users','properties','supplier_bookings','inventory_calendar','channel_connections','channel_property_mappings','ical_connections','ical_events','physical_rooms','booking_folios','folio_transactions','payment_records','payment_allocations','hotel_invoices','night_audit_runs','hotel_staff','hotel_roles','loyalty_tiers','guest_loyalty_accounts','revenue_recommendations','guest_service_requests','login_throttle','guest_reviews','agency_booking_requests','email_outbox','webhook_subscriptions','webhook_deliveries','error_logs','admin_audit_logs','payment_links','fx_rates','booking_groups','notifications','agencies','agency_users','email_templates','admin_2fa','scheduled_jobs','public_chat_messages','blocked_ips','panel_chat_messages','scheduled_job_runs','property_feature_catalog','channel_room_mappings','feature_delete_backups','channel_sync_logs','ical_sync_logs','pending_trash_purges'];

    $requiredColumns = [
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
        'channel_property_mappings'=>['channel_connection_id','property_id','external_property_id','status'],
        'channel_sync_logs'=>['channel_connection_id','property_id','direction','scope','status','request_payload','response_payload','error_message','fx_audit'],
        'ical_sync_logs'=>['ical_connection_id','property_id','status','error_message','error_hash'],
        'pending_trash_purges'=>['feature_id','token','expires_at','approved_at'],
    ];

    // --- 1) Tablolar ---
    $q = $pdo->prepare("SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename=ANY(?::text[])");
    $q->execute(['{' . implode(',', $requiredTables) . '}']);
    $found = array_flip($q->fetchAll(PDO::FETCH_COLUMN));
    $missingTables = array_values(array_diff($requiredTables, array_keys($found)));

    $out .= "=== 1) TABLOLAR (" . count($requiredTables) . ") ===\n";
    foreach ($requiredTables as $t) {
        $out .= (isset($found[$t]) ? '✓' : '✗') . ' ' . $t . "\n";
    }
    if ($missingTables) {
        $errors[] = 'Eksik tablolar: ' . implode(', ', $missingTables);
    }

    // --- 2) Kritik kolonlar ---
    $colStmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name=?");
    $colErrors = [];
    foreach ($requiredColumns as $table => $cols) {
        if (in_array($table, $missingTables, true)) continue;
        $colStmt->execute([$table]);
        $existing = array_flip($colStmt->fetchAll(PDO::FETCH_COLUMN));
        $missingCols = array_values(array_diff($cols, array_keys($existing)));
        if ($missingCols) {
            $colErrors[] = "$table: " . implode(', ', $missingCols);
            $errors[] = "$table eksik kolon(lar): " . implode(', ', $missingCols);
        }
    }
    $out .= "\n=== 2) KRİTİK KOLONLAR ===\n";
    $out .= $colErrors === []
        ? "✓ Tüm kritik kolonlar mevcut.\n"
        : implode("\n", array_map(fn($e) => '✗ ' . $e, $colErrors)) . "\n";

    // --- 2a) Oda eşleştirme tutarlılığı — verify-platform ile aynı yetim/uyumsuz taraması. ---
    if (!in_array('channel_room_mappings', $missingTables, true)) {
        $out .= "\n=== 2a) ODA EŞLEŞTİRME DURUMU ===\n";
        try {
            $mappingCount = (int) $pdo->query('SELECT COUNT(*) FROM channel_room_mappings')->fetchColumn();
            $orphanMappings = (int) $pdo->query("SELECT COUNT(*) FROM channel_room_mappings m LEFT JOIN room_types rt ON rt.id=m.room_type_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id WHERE rt.id IS NULL OR c.id IS NULL OR rt.property_id<>m.property_id OR (m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id))")->fetchColumn();
            if ($orphanMappings > 0) {
                $out .= "⚠ " . $orphanMappings . " yetim/uyumsuz eşleştirme (oda tipi veya kanal yok, ya da oda tipi başka ürüne ait) — webhook yazımı bu satırlarda başarısız olabilir\n";
                $errors[] = 'channel_room_mappings: ' . $orphanMappings . ' yetim/uyumsuz eşleştirme';
            } else {
                $out .= "✓ Oda eşleştirme durumu: " . $mappingCount . " kayıt, 0 uyumsuz.\n";
            }
        } catch (Throwable $e) {
            $out .= "⚠ Oda eşleştirme taraması yapılamadı: " . $e->getMessage() . "\n";
        }
    }

    // --- 2b) Onarım — yabancı şemalı boş tabloları düşür (migration bölümü yeniden kurar).
    if ($repair) {
        $out .= "\n=== 2b) ONARIM MODU ===\n";
        $dropped = 0;
        $skippedNonEmpty = [];
        foreach ($repairMap as $table => [$expectedCol, $migs]) {
            $exists = (bool) $pdo->query("SELECT 1 FROM pg_tables WHERE schemaname='public' AND tablename='" . $table . "'")->fetchColumn();
            if (!$exists) {
                $out .= "· " . $table . " yok — atlanıyor (migration kurar)\n";
                continue;
            }
            $hasExpected = (bool) $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='" . $table . "' AND column_name='" . $expectedCol . "'")->fetchColumn();
            if ($hasExpected) {
                $out .= "✓ " . $table . " beklenen şemada — onarım gerekmiyor\n";
                continue;
            }
            $count = (int) $pdo->query("SELECT COUNT(*) FROM \"" . $table . "\"")->fetchColumn();
            if ($count > 0) {
                $skippedNonEmpty[] = $table . " (" . $count . " satır — elle müdahale gerekir)";
                $out .= "⚠ " . $table . " yabancı şemada ama DOLU (" . $count . " satır) — DÜŞÜRÜLMEDİ, elle inceleyin\n";
                continue;
            }
            try {
                $pdo->exec('DROP TABLE IF EXISTS \"' . $table . '\" CASCADE');
                $out .= "→ " . $table . " yabancı şemalı ve boş — düşürüldü; " . implode(', ', $migs) . " zinciriyle yeniden kurulacak\n";
                $dropped++;
                foreach ($migs as $mig) {
                    $reapplyMigrations[$mig] = true; // schema_migrations'ta kayıtlı olsa bile zorla yeniden uygula
                }
            } catch (Throwable $e) {
                $out .= "✗ " . $table . " düşürülemedi: " . $e->getMessage() . "\n";
                $errors[] = $table . ' onarılamadı: ' . $e->getMessage();
            }
        }
        // Yetim eşleştirmeleri temizle — yalnızca gerçekten başka ürüne/var olmayan kayda işaret
        // eden satırlar (room tipi/kanal/plan silinmiş veya başka ürüne ait).
        if (!in_array('channel_room_mappings', $missingTables, true)) {
            try {
                $orphanSql = "SELECT m.id, m.external_room_id, m.room_type_id, m.channel_connection_id, m.status FROM channel_room_mappings m LEFT JOIN room_types rt ON rt.id=m.room_type_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id WHERE m.room_type_id>0 AND (rt.id IS NULL OR c.id IS NULL OR rt.property_id<>m.property_id OR (m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)))";
                $orphans = $pdo->query($orphanSql)->fetchAll();
                if ($orphans) {
                    $del = $pdo->prepare('DELETE FROM channel_room_mappings WHERE id=?');
                    $removed = 0;
                    foreach ($orphans as $o) {
                        $del->execute([(int) $o['id']]);
                        $removed++;
                        $out .= '→ yetim eşleştirme #' . (int) $o['id'] . ' (' . htmlspecialchars((string) $o['external_room_id']) . ', room_type=' . (int) $o['room_type_id'] . ') silindi' . "\n";
                    }
                    $out .= "Özet: " . $removed . " yetim eşleştirme temizlendi.\n";
                } else {
                    $out .= "✓ Yetim/uyumsuz eşleştirme yok.\n";
                }
            } catch (Throwable $e) {
                $out .= "✗ Yetim taraması yapılamadı: " . $e->getMessage() . "\n";
                $errors[] = 'yetim eşleştirme temizliği başarısız: ' . $e->getMessage();
            }
        }
        $out .= $dropped === 0 && $skippedNonEmpty === []
            ? "✓ Onarım gerektiren boş tablo yok.\n"
            : "Özet: " . $dropped . " tablo düşürüldü" . ($skippedNonEmpty ? '; elle müdahale: ' . implode('; ', $skippedNonEmpty) : '') . "\n";
    }

    // --- 3) Migration durumu ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, file VARCHAR(190) NOT NULL UNIQUE, applied_at TIMESTAMPTZ NOT NULL DEFAULT now())");
    // commit_hash kolonu (git commit birlestirmesi) — eski kopyalarda eksikse ekle.
    $hasCommitCol = (bool) $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='schema_migrations' AND column_name='commit_hash'")->fetchColumn();
    if (!$hasCommitCol) {
        $pdo->exec('ALTER TABLE schema_migrations ADD COLUMN commit_hash CHAR(40)');
    }
    // Güncel commit hash'i — git HEAD dosyasından (alt süreç yok, güvenilir).
    $commitNow = '';
    $headFile = dirname(__DIR__) . '/.git/HEAD';
    if (is_readable($headFile)) {
        $head = trim((string) file_get_contents($headFile));
        if (str_starts_with($head, 'ref: ')) {
            $refPath = dirname(__DIR__) . '/.git/' . substr($head, 5);
            if (is_readable($refPath)) {
                $commitNow = trim((string) file_get_contents($refPath));
            }
        } elseif (preg_match('/^[a-f0-9]{40}$/', $head)) {
            $commitNow = $head;
        }
    }
    $appliedRows = $pdo->query('SELECT file, commit_hash FROM schema_migrations')->fetchAll();
    $appliedMap = [];
    foreach ($appliedRows as $ar) {
        $appliedMap[$ar['file']] = (string) ($ar['commit_hash'] ?? '');
    }

    $migrationFiles = glob(__DIR__ . '/../database/migrations/*-postgres.sql');
    sort($migrationFiles);
    $legacyFiles = glob(__DIR__ . '/../database/migrations/[0-9][0-9][0-9]-*.sql');
    $legacyCount = count(array_filter($legacyFiles, fn($f) => !str_contains($f, '-postgres')));

    $out .= "\n=== 3) MIGRATION DURUMU (" . count($migrationFiles) . " postgres + " . $legacyCount . " legacy atlandı) ===\n";
    $pendingCount = 0;
    $failedMigs = [];
    foreach ($migrationFiles as $file) {
        $base = basename($file);
        // Onarım modunda düşürülen tabloların migration'ları schema_migrations'ta kayıtlı olsa
        // bile zorla yeniden uygulanır (CREATE TABLE IF NOT EXISTS tabloyu yeniden kurar;
        // ALTER ... IF NOT EXISTS güvenlidir). Kayıt güncel commit hash'iyle güncellenir.
        $forceReapply = !$dryRun && isset($reapplyMigrations[$base]);
        if (!$forceReapply && isset($appliedMap[$base])) {
            $out .= '✓ ' . $base . (($appliedMap[$base] !== '') ? ' @ ' . substr($appliedMap[$base], 0, 7) : '') . "\n";
            continue;
        }
        if ($dryRun) {
            $out .= ($forceReapply ? '♻ ' : '⏳ ') . $base . ($forceReapply ? ' (onarım sonrası yeniden uygulanacak)' : ' (bekliyor)') . "\n";
            $pendingCount++;
            continue;
        }
        $sql = (string) file_get_contents($file);
        try {
            $pdo->beginTransaction();
            $pdo->exec($sql);
            $pdo->commit();
            if ($forceReapply) {
                $upd = $pdo->prepare('UPDATE schema_migrations SET applied_at=now(), commit_hash=? WHERE file=?');
                $upd->execute([$commitNow !== '' ? $commitNow : null, $base]);
                if ($upd->rowCount() === 0) {
                    $pdo->prepare('INSERT INTO schema_migrations(file, commit_hash) VALUES(?,?)')->execute([$base, $commitNow !== '' ? $commitNow : null]);
                }
                $out .= '♻ ' . $base . ' yeniden uygulandı' . ($commitNow !== '' ? ' @ ' . substr($commitNow, 0, 7) : '') . "\n";
            } else {
                $pdo->prepare('INSERT INTO schema_migrations(file, commit_hash) VALUES(?,?)')->execute([$base, $commitNow !== '' ? $commitNow : null]);
                $out .= '→ ' . $base . ' uygulandı' . ($commitNow !== '' ? ' @ ' . substr($commitNow, 0, 7) : ' (commit hash bulunamadı)') . "\n";
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $out .= '✗ ' . $base . ' — ' . $e->getMessage() . "\n";
            $failedMigs[] = $base;
            $errors[] = 'Migration başarısız: ' . $base . ' — ' . $e->getMessage();
        }
    }
    if ($dryRun && $pendingCount > 0) {
        $out .= "($pendingCount migration bekliyor — --dry-run uygulamadı)\n";
    }
    if ($failedMigs) {
        $out .= "Başarısız migration'lar: " . implode(', ', $failedMigs) . "\n";
    }

    // --- 4) Operasyonel uyarılar (son 24 saat) ---
    $out .= "\n=== 4) OPERASYONEL UYARILAR (son 24 saat) ===\n";
    $warnCount = 0;
    try {
        $errCount = (int) $pdo->query("SELECT COUNT(*) FROM error_logs WHERE level IN ('error','critical') AND status='new' AND created_at >= now() - interval '24 hours'")->fetchColumn();
        $out .= ($errCount > 20 ? '⚠ ' : '✓ ') . "Hata logu (son 24 saat, error/critical, yeni): " . $errCount . ($errCount > 20 ? ' — yüksek, inceleyin (admin/hata-izleme)' : '') . "\n";
        if ($errCount > 20) $warnCount++;
    } catch (Throwable $e) {
        $out .= "⚠ error_logs okunamadı: " . $e->getMessage() . "\n";
        $warnCount++;
    }
    try {
        $emailQ = (int) $pdo->query("SELECT COUNT(*) FROM email_outbox WHERE status='queued' AND created_at >= now() - interval '24 hours'")->fetchColumn();
        $out .= ($emailQ > 50 ? '⚠ ' : '✓ ') . "Bekleyen e-posta (son 24 saat, kuyrukta): " . $emailQ . ($emailQ > 50 ? ' — kuyruk birikiyor (cron/process-emails.php)' : '') . "\n";
        if ($emailQ > 50) $warnCount++;
    } catch (Throwable $e) {
        $out .= "⚠ email_outbox okunamadı: " . $e->getMessage() . "\n";
        $warnCount++;
    }
    $out .= $warnCount === 0 ? "✓ Operasyonel uyarı yok.\n" : "⚠ " . $warnCount . " operasyonel uyarı (kritik değil — sağlık durumunu bozmaz).\n";

    // --- 5) Ortam ---
    $out .= "\n=== 5) ORTAM ===\n";
    $config = db_config();
    if (strlen((string) ($config['app_encryption_key'] ?? '')) < 32) {
        $out .= "✗ app_encryption_key eksik veya 32 karakterden kısa\n";
        $errors[] = 'app_encryption_key';
    } else {
        $out .= "✓ app_encryption_key\n";
    }
    foreach (['pdo_pgsql' => 'PDO PostgreSQL', 'curl' => 'cURL'] as $ext => $label) {
        $ok = extension_loaded($ext);
        $out .= ($ok ? '✓' : '✗') . ' ' . $label . "\n";
        if (!$ok) $errors[] = $label . ' etkin değil';
    }

    $out .= "\n";
    if ($errors) {
        $out .= 'SONUÇ: ' . count($errors) . ' sorun — ' . implode('; ', array_slice($errors, 0, 10)) . "\n";
    } else {
        $out .= 'SONUÇ: Tüm kontroller başarılı — ' . count($requiredTables) . " tablo, kritik kolonlar ve " . count($migrationFiles) . " migration hazır.\n";
    }

    return ['ok' => $errors === [], 'output' => $out, 'errors' => $errors];
}
