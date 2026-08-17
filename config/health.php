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

function health_check_run(bool $dryRun = false, bool $repair = false, bool $fix = false): array
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
        // tablo => [beklenen kolon(lar), tam migration zinciri]
        // Tespit TAM kolon setine göre yapılır — tek kolon değil; örn. channel_room_mappings'te
        // suggestion_score eksikse kısmi şema yakalanır ve (boşsa) yeniden kurulur.
        'channel_room_mappings' => [
            ['channel_connection_id', 'property_id', 'room_type_id', 'external_room_id', 'status', 'suggested_at', 'suggestion_count', 'rate_plan_id', 'suggestion_score'],
            ['045-channel-room-mappings-postgres.sql', '047-room-mapping-suggestions-postgres.sql', '049-room-plan-mapping-postgres.sql', '052-suggestion-score-postgres.sql'],
        ],
        'channel_property_mappings' => [
            ['channel_connection_id', 'property_id', 'external_property_id', 'status'],
            ['019-distribution-and-rate-management-postgres.sql'],
        ],
        'channel_rate_plan_mappings' => [
            ['channel_connection_id', 'property_id', 'external_rate_plan_id', 'status', 'rate_plan_id'],
            ['054-rate-plan-mappings-postgres.sql'],
        ],
        'fx_audit_daily' => [
            ['audit_date', 'missing_count', 'stale_count', 'details'],
            ['055-fx-audit-daily-postgres.sql'],
        ],
        'product_type_catalog' => [
            ['step_targets'],
            ['053-product-step-targets-postgres.sql'],
        ],
    ];
    $reapplyMigrations = []; // onarım sonrası zorla yeniden uygulanacak migration dosyaları

    $requiredTables = ['suppliers','supplier_users','properties','supplier_bookings','inventory_calendar','channel_connections','channel_property_mappings','ical_connections','ical_events','physical_rooms','booking_folios','folio_transactions','payment_records','payment_allocations','hotel_invoices','night_audit_runs','hotel_staff','hotel_roles','loyalty_tiers','guest_loyalty_accounts','revenue_recommendations','guest_service_requests','login_throttle','guest_reviews','agency_booking_requests','email_outbox','webhook_subscriptions','webhook_deliveries','error_logs','admin_audit_logs','payment_links','fx_rates','booking_groups','notifications','agencies','agency_users','email_templates','admin_2fa','scheduled_jobs','public_chat_messages','blocked_ips','panel_chat_messages','scheduled_job_runs','property_feature_catalog','channel_room_mappings','channel_rate_plan_mappings','feature_delete_backups','channel_sync_logs','ical_sync_logs','pending_trash_purges','fx_audit_daily','trash_upcoming_alerts'];

    $requiredColumns = [
        'supplier_bookings'=>['rate_plan_id','booking_status','checked_in_at','checked_out_at','early_arrival','late_departure','deposit_amount','deposit_status','no_show_at','cancelled_at','cancellation_reason'],
        'channel_connections'=>['webhook_loop_threshold'],
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
        'property_feature_catalog'=>['deleted_at','purge_at'],
        'feature_delete_backups'=>['feature_id','code','label','affected_properties'],
        'channel_room_mappings'=>['channel_connection_id','property_id','room_type_id','external_room_id','rate_plan_id','status','suggested_at','suggestion_count','suggestion_score'],
        'channel_property_mappings'=>['channel_connection_id','property_id','external_property_id','status'],'channel_rate_plan_mappings'=>['channel_connection_id','property_id','external_rate_plan_id','status','rate_plan_id'],
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

    // --- 2b) Kanal token doğrulama — eksik/geçersiz/tekrarlanan access_token HATA sayılır;
    //        --fix ile otomatik yenilenir (64 hex, benzersiz). Webhook ucu bu token ile korunur.
    $out .= "\n=== 2b) KANAL TOKEN DOĞRULAMA ===\n";
    try {
        $tokens = $pdo->query('SELECT id, channel_code, display_name, access_token FROM channel_connections ORDER BY id')->fetchAll();
        if (!$tokens) {
            $out .= "· Kanal bağlantısı yok — token denetimi atlandı.\n";
        } else {
            $missing = [];
            $invalid = [];
            $byToken = [];
            foreach ($tokens as $t) {
                $tok = trim((string) ($t['access_token'] ?? ''));
                if ($tok === '') { $missing[] = $t; continue; }
                if (!preg_match('/^[a-f0-9]{64}$/', $tok)) { $invalid[] = $t; continue; }
                $byToken[$tok][] = $t;
            }
            $dups = [];
            foreach ($byToken as $rows) {
                if (count($rows) > 1) $dups = array_merge($dups, array_slice($rows, 1));
            }
            $nProb = count($missing) + count($invalid) + count($dups);
            $probText = [];
            if ($missing) {
                $probText[] = count($missing) . ' kanalda token eksik (' . implode(', ', array_map(fn($m) => $m['display_name'] . ' #' . (int) $m['id'], $missing)) . ')';
                $out .= '✗ ' . end($probText) . "\n";
            }
            if ($invalid) {
                $probText[] = count($invalid) . ' kanalda token geçersiz biçim (' . implode(', ', array_map(fn($m) => $m['display_name'] . ' #' . (int) $m['id'], $invalid)) . ')';
                $out .= '✗ ' . end($probText) . "\n";
            }
            if ($dups) {
                $probText[] = count($dups) . ' kanalda tekrarlanan token (aynı token 2+ kanalda — güvenlik riski)';
                $out .= '✗ ' . end($probText) . "\n";
            }
            if ($nProb === 0) {
                $out .= "✓ Tüm kanal tokenları geçerli (" . count($tokens) . " bağlantı: 64 hex, benzersiz).\n";
            }
            if ($nProb > 0 && $fix) {
                if ($dryRun) {
                    $out .= '→ [dry-run] ' . $nProb . " token YENİLENECEK (64 hex rastgele — kanal tarafındaki webhook adresi güncellenmeli)\n";
                } else {
                    $upd = $pdo->prepare('UPDATE channel_connections SET access_token=? WHERE id=?');
                    $fixed = 0;
                    foreach (array_merge($missing, $invalid, $dups) as $t) {
                        $upd->execute([bin2hex(random_bytes(32)), (int) $t['id']]);
                        $fixed++;
                    }
                    $out .= '→ ' . $fixed . " token yenilendi (eksik/geçersiz/tekrarlanan) — kanal tarafındaki webhook adresi güncellenmeli.\n";
                    $nProb = 0;
                }
            }
            if ($nProb > 0) {
                $errors[] = 'kanal token sorunu: ' . implode('; ', $probText);
            }
        }
    } catch (Throwable $e) {
        $out .= "⚠ Token denetimi yapılamadı: " . $e->getMessage() . "\n";
    }

    // --- 2c) Tablo satır sayıları / tutarlılık denetimi — yarım kalan işler, yetim satırlar,
    //         çift yedekler. Bu sorunlar webhook/senkron akışını sessizce tıkayabilir. ---
    $out .= "\n=== 2c) TUTARLILIK / SATIR DENETİMİ ===\n";
    $consistencyErrors = [];
    $consistencyChecks = 0;

    // 2c-1) Kanal webhook kuyruğunda yarım kalan işler (queued/running, 2+ saat beklemiş).
    if (!in_array('channel_sync_logs', $missingTables, true)) {
        $consistencyChecks++;
        try {
            $stuck = (int) $pdo->query("SELECT COUNT(*) FROM channel_sync_logs WHERE status IN ('queued','running') AND created_at < now() - interval '2 hours'")->fetchColumn();
            if ($stuck > 0) {
                $out .= "⚠ channel_sync_logs: " . $stuck . " yarım kalan iş (queued/running > 2 saat) — işleyici kilitlenmiş olabilir, cron/process-channel-webhooks.php denetleyin\n";
                $consistencyErrors[] = 'channel_sync_logs: ' . $stuck . ' yarım kalan iş';
            } else {
                $out .= "✓ channel_sync_logs kuyruğu temiz (yarım kalan iş yok).\n";
            }
        } catch (Throwable $e) {
            $out .= "⚠ channel_sync_logs denetimi yapılamadı: " . $e->getMessage() . "\n";
        }
    }

    // 2c-2) Yetim kanal senkron günlüğü satırları (kanal bağlantısı silinmiş ama log kalmış —
    //        FK yoksa veya yabancı şemalı tabloda oluşmuşsa görülür).
    if (!in_array('channel_sync_logs', $missingTables, true) && !in_array('channel_connections', $missingTables, true)) {
        $consistencyChecks++;
        try {
            $orphan = (int) $pdo->query("SELECT COUNT(*) FROM channel_sync_logs l LEFT JOIN channel_connections c ON c.id=l.channel_connection_id WHERE c.id IS NULL")->fetchColumn();
            if ($orphan > 0) {
                $out .= "⚠ channel_sync_logs: " . $orphan . " yetim satır (kanal bağlantısı yok)\n";
                $consistencyErrors[] = 'channel_sync_logs: ' . $orphan . ' yetim satır';
            } else {
                $out .= "✓ Yetim kanal senkron günlüğü satırı yok.\n";
            }
        } catch (Throwable $e) {
            $out .= "⚠ Yetim kanal log denetimi yapılamadı: " . $e->getMessage() . "\n";
        }
    }

    // 2c-3) Aktif iCal bağlantısı 24+ saat hiç senkron kaydı üretmemiş (ölü bağlantı).
    if (!in_array('ical_connections', $missingTables, true) && !in_array('ical_sync_logs', $missingTables, true)) {
        $consistencyChecks++;
        try {
            $never = (int) $pdo->query("SELECT COUNT(*) FROM ical_connections c WHERE c.status='active' AND c.created_at < now() - interval '24 hours' AND NOT EXISTS (SELECT 1 FROM ical_sync_logs l WHERE l.ical_connection_id=c.id)")->fetchColumn();
            if ($never > 0) {
                $out .= "⚠ " . $never . " aktif iCal bağlantısı hiç senkron olmamış (>24 saat) — sync-ical-calendars.php denetleyin\n";
                $consistencyErrors[] = 'ical_connections: ' . $never . ' bağlantı hiç senkron olmamış';
            } else {
                $out .= "✓ Tüm aktif iCal bağlantıları en az bir kez senkron oldu.\n";
            }
        } catch (Throwable $e) {
            $out .= "⚠ iCal senkron denetimi yapılamadı: " . $e->getMessage() . "\n";
        }
    }

    // 2c-4) Çöp kutusu yedeklerinde çift kayıt (idempotans ihlali — aynı özellik 2+ kez silinmiş).
    if (!in_array('feature_delete_backups', $missingTables, true)) {
        $consistencyChecks++;
        try {
            $dup = $pdo->query("SELECT feature_id, COUNT(*) AS n FROM feature_delete_backups GROUP BY feature_id HAVING COUNT(*)>1")->fetchAll();
            if ($dup) {
                $ids = implode(', ', array_map(fn($r) => '#' . (int) $r['feature_id'] . '×' . (int) $r['n'], $dup));
                $out .= "⚠ feature_delete_backups: " . count($dup) . " özellikte çift yedek (" . $ids . ") — idempotans ihlali\n";
                $consistencyErrors[] = 'feature_delete_backups: ' . count($dup) . ' özellikte çift yedek';
            } else {
                $out .= "✓ Silme yedekleri benzersiz (özellik başına 1 kayıt).\n";
            }
        } catch (Throwable $e) {
            $out .= "⚠ feature_delete_backups denetimi yapılamadı: " . $e->getMessage() . "\n";
        }
    }

    // 2c-5) Yetim kalıcı silme onayları — yedeği olmayan (geri yüklenmiş ama temizlenmemiş) onaylar.
    if (!in_array('pending_trash_purges', $missingTables, true) && !in_array('feature_delete_backups', $missingTables, true)) {
        $consistencyChecks++;
        try {
            $orphan = (int) $pdo->query("SELECT COUNT(*) FROM pending_trash_purges p LEFT JOIN feature_delete_backups b ON b.feature_id=p.feature_id WHERE b.id IS NULL")->fetchColumn();
            if ($orphan > 0) {
                $out .= "⚠ pending_trash_purges: " . $orphan . " yetim onay (yedeği yok — restore sonrası temizlenmemiş)\n";
                $consistencyErrors[] = 'pending_trash_purges: ' . $orphan . ' yetim onay';
            } else {
                $out .= "✓ Kalıcı silme onayları tutarlı.\n";
            }
        } catch (Throwable $e) {
            $out .= "⚠ pending_trash_purges denetimi yapılamadı: " . $e->getMessage() . "\n";
        }
    }

    if ($consistencyErrors) {
        $errors = array_merge($errors, $consistencyErrors);
    } elseif ($consistencyChecks > 0) {
        $out .= "✓ Tüm satır sayısı/tutarlılık kontrolleri temiz.\n";
    }

    // --- 2d) Onarım — yabancı şemalı boş tabloları düşür (migration bölümü yeniden kurar).
    if ($repair) {
        $out .= "\n=== 2d) ONARIM MODU ===\n";
        $dropped = 0;
        $dryRunDropped = 0;
        $skippedNonEmpty = [];
        foreach ($repairMap as $table => $spec) {
            [$expected, $migs] = $spec;
            $expectedCols = is_array($expected) ? $expected : [$expected];
            $exists = (bool) $pdo->query("SELECT 1 FROM pg_tables WHERE schemaname='public' AND tablename='" . $table . "'")->fetchColumn();
            if (!$exists) {
                $out .= "· " . $table . " yok — atlanıyor (migration kurar)\n";
                continue;
            }
            $missingCols = [];
            foreach ($expectedCols as $col) {
                $hasCol = (bool) $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='" . $table . "' AND column_name='" . $col . "'")->fetchColumn();
                if (!$hasCol) {
                    $missingCols[] = $col;
                }
            }
            if ($missingCols === []) {
                $out .= "✓ " . $table . " beklenen şemada (" . count($expectedCols) . " kolon) — onarım gerekmiyor\n";
                continue;
            }
            $count = (int) $pdo->query("SELECT COUNT(*) FROM \"" . $table . "\"")->fetchColumn();
            if ($count > 0) {
                $skippedNonEmpty[] = $table . " (" . $count . " satır — eksik kolon: " . implode(', ', $missingCols) . ")";
                $out .= "⚠ " . $table . " yabancı şemada ama DOLU (" . $count . " satır) — DÜŞÜRÜLMEDİ, elle inceleyin (eksik: " . implode(', ', $missingCols) . ")\n";
                continue;
            }
            foreach ($migs as $mig) {
                $reapplyMigrations[$mig] = true; // dry-run'da da işaretlenir — migration bölümü ♻ gösterir
            }
            if ($dryRun) {
                $dryRunDropped++;
                $out .= "→ [dry-run] " . $table . " yabancı şemalı ve boş — DÜŞÜRÜLECEK; " . implode(', ', $migs) . " yeniden uygulanacak\n";
                continue;
            }
            try {
                $pdo->exec('DROP TABLE IF EXISTS \"' . $table . '\" CASCADE');
                $out .= "→ " . $table . " yabancı şemalı ve boş — düşürüldü; " . implode(', ', $migs) . " zinciriyle yeniden kurulacak\n";
                $dropped++;
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
                    if ($dryRun) {
                        $out .= '→ [dry-run] ' . count($orphans) . " yetim eşleştirme SILİNECEK (örnek: " . htmlspecialchars((string) $orphans[0]['external_room_id']) . ')' . "\n";
                    } else {
                        $del = $pdo->prepare('DELETE FROM channel_room_mappings WHERE id=?');
                        $removed = 0;
                        foreach ($orphans as $o) {
                            $del->execute([(int) $o['id']]);
                            $removed++;
                            $out .= '→ yetim eşleştirme #' . (int) $o['id'] . ' (' . htmlspecialchars((string) $o['external_room_id']) . ', room_type=' . (int) $o['room_type_id'] . ') silindi' . "\n";
                        }
                        $out .= "Özet: " . $removed . " yetim eşleştirme temizlendi.\n";
                    }
                } else {
                    $out .= "✓ Yetim/uyumsuz eşleştirme yok.\n";
                }
            } catch (Throwable $e) {
                $out .= "✗ Yetim taraması yapılamadı: " . $e->getMessage() . "\n";
                $errors[] = 'yetim eşleştirme temizliği başarısız: ' . $e->getMessage();
            }
        }
        $out .= $dryRun
            ? ($dryRunDropped === 0 && $skippedNonEmpty === []
                ? "✓ (dry-run) Düşürülecek tablo yok.\n"
                : "Özet (dry-run): " . $dryRunDropped . " tablo DÜŞÜRÜLECEK" . ($skippedNonEmpty ? '; elle müdahale: ' . implode('; ', $skippedNonEmpty) : '') . " — hiçbir değişiklik yapılmadı\n")
            : ($dropped === 0 && $skippedNonEmpty === []
                ? "✓ Onarım gerektiren boş tablo yok.\n"
                : "Özet: " . $dropped . " tablo düşürüldü" . ($skippedNonEmpty ? '; elle müdahale: ' . implode('; ', $skippedNonEmpty) : '') . "\n");
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
        $forceReapply = isset($reapplyMigrations[$base]);
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
    try {
        $failWebhook = (int) $pdo->query("SELECT COUNT(*) FROM channel_sync_logs WHERE direction='pull' AND status='failed' AND created_at >= now() - interval '24 hours'")->fetchColumn();
        $out .= ($failWebhook > 10 ? '⚠ ' : '✓ ') . "Başarısız webhook yükü (son 24 saat): " . $failWebhook . ($failWebhook > 10 ? ' — yüksek başarısızlık oranı, cron/process-channel-webhooks.php + retry-channel-webhooks.php denetleyin' : '') . "\n";
        if ($failWebhook > 10) $warnCount++;
    } catch (Throwable $e) {
        $out .= "⚠ channel_sync_logs okunamadı: " . $e->getMessage() . "\n";
        $warnCount++;
    }
    try {
        $failIcal = (int) $pdo->query("SELECT COUNT(*) FROM ical_sync_logs WHERE status='failed' AND created_at >= now() - interval '24 hours'")->fetchColumn();
        $out .= ($failIcal > 3 ? '⚠ ' : '✓ ') . "iCal senkron hata (son 24 saat): " . $failIcal . ($failIcal > 3 ? ' — tekrarlayan hatalar olabilir, cron/sync-ical-calendars.php + alert-ical-repeat.php denetleyin' : '') . "\n";
        if ($failIcal > 3) $warnCount++;
    } catch (Throwable $e) {
        $out .= "⚠ ical_sync_logs okunamadı: " . $e->getMessage() . "\n";
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
