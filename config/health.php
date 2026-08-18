<?php
declare(strict_types=1);

// Sağlık kontrolü mantığı — scripts/health-check.php ve günlük zamanlayıcı görevi
// (cron/health-check-alert.php) tarafından paylaşılır.
//
// Bölümler: 1) tablolar   2) kritik kolonlar   3) migration durumu (schema_migrations
// takibi, yalnızca *-postgres.sql; legacy MySQL dosyaları atlanır)   4) ortam
// (app_encryption_key, PDO PostgreSQL, cURL).
//
// @return array{ok: bool, output: string, errors: list<string>, checks: array<string, mixed>, ran_at: string}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/platform_settings.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/mailer.php';

/**
 * Yetim eşleştirme temizliği — üç tabloda silinmiş oda tipi/plan/kanala işaret eden
 * satırları tarar ve (dryRun değilse) siler. --repair modu, onay sayfası
 * (admin/approve-orphan-cleanup.php) ve günlük görev aynı fonksiyonu kullanır.
 *
 * @return array{removed:int, codes:array<string,list<string>>, out:string, errors:list<string>}
 */
function health_orphan_cleanup(PDO $pdo, bool $dryRun = false): array
{
    $out = '';
    $errors = [];
    $removed = 0;
    $codes = [];
    $specs = [
        'channel_room_mappings' => [
            'label' => 'oda eşleştirmesi',
            'join' => 'LEFT JOIN room_types rt ON rt.id=m.room_type_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id',
            'where' => 'm.room_type_id>0 AND (rt.id IS NULL OR c.id IS NULL OR rt.property_id<>m.property_id OR (m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)))',
            'cols' => 'm.id, m.external_room_id AS code, m.status, c.display_name AS channel_name, m.channel_connection_id AS conn_id',
        ],
        'channel_rate_plan_mappings' => [
            'label' => 'fiyat planı eşleştirmesi',
            'join' => 'LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id',
            'where' => '(m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)) OR c.id IS NULL',
            'cols' => 'm.id, m.external_rate_plan_id AS code, m.status, c.display_name AS channel_name, m.channel_connection_id AS conn_id',
        ],
        'channel_property_mappings' => [
            'label' => 'ürün eşleştirmesi',
            'join' => 'LEFT JOIN properties p ON p.id=m.property_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id',
            'where' => 'p.id IS NULL OR c.id IS NULL',
            'cols' => 'm.id, m.external_property_id AS code, m.status, c.display_name AS channel_name, m.channel_connection_id AS conn_id',
        ],
        'ical_connections' => [
            'label' => 'iCal bağlantısı',
            'join' => 'LEFT JOIN properties p ON p.id=m.property_id',
            'where' => 'p.id IS NULL',
            'cols' => 'm.id, m.label AS code, m.status, m.direction, m.supplier_id',
        ],
        'ical_events' => [
            'label' => 'iCal olayı',
            'join' => 'LEFT JOIN ical_connections c ON c.id=m.ical_connection_id',
            'where' => 'c.id IS NULL',
            'cols' => "m.id, m.external_uid AS code, '' AS status, m.property_id",
        ],
        'ical_sync_logs' => [
            'label' => 'iCal senkron kaydı',
            'join' => 'LEFT JOIN ical_connections c ON c.id=m.ical_connection_id',
            'where' => 'c.id IS NULL',
            'cols' => 'm.id, m.status AS code, m.status, m.property_id',
        ],
    ];
    foreach ($specs as $table => $spec) {
        $tbl = (bool) $pdo->query("SELECT 1 FROM pg_tables WHERE schemaname='public' AND tablename='" . $table . "'")->fetchColumn();
        if (!$tbl) continue;
        try {
            $orphanSql = 'SELECT ' . $spec['cols'] . ' FROM ' . $table . ' m ' . $spec['join'] . ' WHERE ' . $spec['where'];
            $orphans = $pdo->query($orphanSql)->fetchAll();
            if ($orphans) {
                // Bağlam bilgisi üret: kanal adı, tedarikçi adı gibi ek alanlar.
                $contextFn = function (array $o) use ($pdo, $table): string {
                    $parts = [];
                    // Kanal adı (mapping tabloları)
                    if (!empty($o['channel_name'])) $parts[] = 'kanal: ' . (string) $o['channel_name'];
                    elseif (!empty($o['conn_id'])) $parts[] = 'kanal #' . (int) $o['conn_id'] . ' (silindi)';
                    // iCal: tedarikçi adı
                    if ($table === 'ical_connections' && !empty($o['supplier_id'])) {
                        try {
                            $sName = $pdo->prepare('SELECT full_name FROM supplier_users WHERE supplier_id=? LIMIT 1');
                            $sName->execute([(int) $o['supplier_id']]);
                            $sRow = $sName->fetch();
                            if ($sRow) $parts[] = 'tedarikçi: ' . (string) $sRow['full_name'];
                        } catch (Throwable $e) {}
                        if (!empty($o['direction'])) $parts[] = $o['direction'];
                    }
                    // iCal events/sync_logs: property_id
                    if (in_array($table, ['ical_events', 'ical_sync_logs'], true) && !empty($o['property_id'])) {
                        try {
                            $pName = $pdo->prepare('SELECT name FROM properties WHERE id=?');
                            $pName->execute([(int) $o['property_id']]);
                            $pRow = $pName->fetch();
                            if ($pRow) $parts[] = 'ilan: ' . (string) $pRow['name'];
                        } catch (Throwable $e) {}
                    }
                    return $parts !== [] ? ' · ' . implode(' · ', $parts) : '';
                };
                if ($dryRun) {
                    $examples = array_slice($orphans, 0, 3);
                    $exList = [];
                    foreach ($examples as $ex) {
                        $exList[] = htmlspecialchars((string) $ex['code']) . $contextFn($ex);
                    }
                    $out .= '→ [dry-run] ' . count($orphans) . ' yetim ' . $spec['label'] . ' SİLİNECEK' . ($exList ? ' (örnek: ' . implode(', ', $exList) . ')' : '') . "\n";
                } else {
                    $del = $pdo->prepare('DELETE FROM ' . $table . ' WHERE id=?');
                    $removedCodes = [];
                    foreach ($orphans as $o) {
                        $del->execute([(int) $o['id']]);
                        $removed++;
                        $removedCodes[] = (string) $o['code'];
                        $ctx = $contextFn($o);
                        $out .= '→ yetim ' . $spec['label'] . ' #' . (int) $o['id'] . ' (' . htmlspecialchars((string) $o['code']) . ', status=' . htmlspecialchars((string) ($o['status'] ?? '')) . ')' . $ctx . ' silindi' . "\n";
                    }
                    $out .= 'Özet: ' . $removed . ' yetim ' . $spec['label'] . ' temizlendi.' . "\n";
                    $codes[$table] = $removedCodes;
                }
            } else {
                $out .= '✓ Yetim ' . $spec['label'] . ' yok.' . "\n";
            }
        } catch (Throwable $e) {
            $out .= '✗ Yetim ' . $spec['label'] . ' taraması yapılamadı: ' . $e->getMessage() . "\n";
            $errors[] = 'yetim ' . $spec['label'] . ' temizliği başarısız: ' . $e->getMessage();
        }
    }
    return ['removed' => $removed, 'codes' => $codes, 'out' => $out, 'errors' => $errors];
}

/**
 * Migration zincirinden tablo → kolon eşlemesini çözer.
 * CREATE TABLE bloklarının parantezlerini dengeler, üst düzey virgüllerle böler ve
 * kolon adlarını (kısıt/anahtar satırlarını atlayarak) çıkarır; ALTER TABLE
 * ADD [COLUMN] [IF NOT EXISTS] ... satırlarını da toplar. Tablo ve kolon adları
 * küçük harfe indirilir. --repair'in adaylarını requiredColumns'a elle eklemek
 * zorunda kalmadan migration zincirinin TAMAMINDAN türetmesini sağlar.
 */
function health_parse_migration_columns(string $sql): array
{
    $cols = [];
    $re = '/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+["`]?([A-Za-z_][A-Za-z0-9_]*)["`]?\s*\(/i';
    if (preg_match_all($re, $sql, $m, PREG_OFFSET_CAPTURE)) {
        $len = strlen($sql);
        foreach ($m[0] as $i => $whole) {
            $tbl = strtolower($m[1][$i][0]);
            $open = $whole[1] + strlen($whole[0]) - 1;
            if ($open >= $len) continue;
            $depth = 0;
            $end = $len;
            $inStr = null;
            for ($p = $open; $p < $len; $p++) {
                $ch = $sql[$p];
                if ($inStr !== null) {
                    if ($ch === $inStr && ($p === 0 || $sql[$p - 1] !== '\\')) $inStr = null;
                    continue;
                }
                if ($ch === "'" || $ch === '"' || $ch === '`') { $inStr = $ch; continue; }
                if ($ch === '(') { $depth++; }
                elseif ($ch === ')') {
                    $depth--;
                    if ($depth === 0) { $end = $p; break; }
                }
            }
            $block = substr($sql, $open + 1, $end - $open - 1);
            foreach (health_split_sql_top($block) as $seg) {
                $seg = trim($seg);
                if ($seg === '') continue;
                if (preg_match('/^(PRIMARY|UNIQUE|CONSTRAINT|FOREIGN|CHECK|EXCLUDE|INDEX|KEY|FULLTEXT|SPATIAL|PERIOD|LIKE|PARTITION)\b/i', $seg)) continue;
                if (preg_match('/^["`]?([A-Za-z_][A-Za-z0-9_]*)["`]?\s/i', $seg, $cm)) {
                    $cols[$tbl][strtolower($cm[1])] = true;
                }
            }
        }
    }
    if (preg_match_all('/ALTER\s+TABLE(?:\s+IF\s+EXISTS)?\s+["`]?([A-Za-z_][A-Za-z0-9_]*)["`]?\s+ADD(?:\s+COLUMN)?(?:\s+IF\s+NOT\s+EXISTS)?\s+["`]?([A-Za-z_][A-Za-z0-9_]*)["`]?\s/i', $sql, $am)) {
        foreach ($am[1] as $i => $tbl) {
            $cols[strtolower($tbl)][strtolower($am[2][$i])] = true;
        }
    }
    return $cols;
}

/**
 * SQL bloğunu tırnak ve parantez farkında olarak üst düzey virgüllerden böler.
 */
function health_split_sql_top(string $block): array
{
    $parts = [];
    $depth = 0;
    $inStr = null;
    $cur = '';
    $len = strlen($block);
    for ($p = 0; $p < $len; $p++) {
        $ch = $block[$p];
        if ($inStr !== null) {
            $cur .= $ch;
            if ($ch === $inStr && ($p === 0 || $block[$p - 1] !== '\\')) $inStr = null;
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') { $inStr = $ch; $cur .= $ch; continue; }
        if ($ch === '(') { $depth++; $cur .= $ch; continue; }
        if ($ch === ')') { $depth--; $cur .= $ch; continue; }
        if ($ch === ',' && $depth === 0) { $parts[] = $cur; $cur = ''; continue; }
        $cur .= $ch;
    }
    if (trim($cur) !== '') $parts[] = $cur;
    return $parts;
}

/**
 * Bir tablonun CANLI şemasını PostgreSQL kataloglarından yeniden kurar:
 * kolonlar (format_type ile tam tip, DEFAULT, identity, NOT NULL) + tüm kısıtlar
 * (pg_get_constraintdef) + kısıta bağlı olmayan indeksler (pg_indexes).
 * --repair --backup-schema ile düşürülecek BOŞ tabloların düşürmeden ÖNCE
 * database/backups/schema-backup-*.sql dosyasına yazılması için kullanılır.
 */
function health_schema_dump_table(PDO $pdo, string $table): string
{
    $t = '"' . str_replace('"', '""', $table) . '"';
    $sql = '-- Tablo: ' . $table . "\n";
    $rows = $pdo->query("SELECT a.attname, format_type(a.atttypid, a.atttypmod) AS ftype,
            a.attnotnull, pg_get_expr(d.adbin, d.adrelid) AS defexpr, a.attidentity
        FROM pg_attribute a
        LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
        WHERE a.attrelid = '" . $table . "'::regclass AND a.attnum > 0 AND NOT a.attisdropped
        ORDER BY a.attnum")->fetchAll();
    $parts = [];
    foreach ($rows as $c) {
        $line = '    "' . str_replace('"', '""', (string) $c['attname']) . '" ' . $c['ftype'];
        if (in_array((string) $c['attidentity'], ['a', 'd'], true)) {
            $line .= ' GENERATED ' . ((string) $c['attidentity'] === 'a' ? 'ALWAYS' : 'BY DEFAULT') . ' AS IDENTITY';
        } elseif ($c['defexpr'] !== null && (string) $c['defexpr'] !== '') {
            $line .= ' DEFAULT ' . $c['defexpr'];
        }
        if ($c['attnotnull']) $line .= ' NOT NULL';
        $parts[] = $line;
    }
    $cons = $pdo->query("SELECT conname, pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conrelid = '" . $table . "'::regclass ORDER BY conname")->fetchAll();
    foreach ($cons as $c) {
        $parts[] = '    CONSTRAINT "' . str_replace('"', '""', (string) $c['conname']) . '" ' . $c['def'];
    }
    $idxs = $pdo->query("SELECT i.indexname, i.indexdef FROM pg_indexes i
        WHERE i.schemaname = 'public' AND i.tablename = '" . $table . "'
          AND NOT EXISTS (SELECT 1 FROM pg_constraint c WHERE c.conindid = i.indexname::regclass AND c.conrelid = i.tablename::regclass)
        ORDER BY i.indexname")->fetchAll();
    $indexSql = '';
    foreach ($idxs as $i) {
        $indexSql .= $i['indexdef'] . ";\n";
    }
    $sql .= 'CREATE TABLE ' . $t . " (\n" . implode(",\n", $parts) . "\n);\n";
    if ($indexSql !== '') $sql .= "\n" . $indexSql;
    return $sql;
}

/**
 * 'must be owner' hatalarını kendi başına çözer: tüm public tablo/dizi/görünüm
 * sahipliğini current_user'a devretmeyi sırasıyla dener —
 *  (A) mevcut bağlantı süper kullanıcıysa doğrudan,
 *  (B) secrets.php'de db_admin_user/db_admin_pass varsa o hesapla,
 *  (C) süreç root olarak çalışıyorsa `sudo -n -u postgres psql` ile (shell).
 * Migration uygulaması 'must be owner' verdiğinde ve --repair sahiplik bölümünde
 * kullanılır — tablo sahibi farklıysa devri otomatik yapar, manuel komut gerekmez.
 *
 * @return array{done:int, via:string, error:string}
 */
function health_auto_fix_ownership(PDO $pdo): array
{
    $curUser = (string) $pdo->query('SELECT current_user')->fetchColumn();
    $qUser = '"' . str_replace('"', '""', $curUser) . '"';
    // [sql kalıbı, ad kolonu, sahip kolonu, kaynak tablo, ALTER türü]
    $specs = [
        ['ALTER TABLE %s OWNER TO %s', 'tablename', 'tableowner', 'pg_tables', 'TABLE'],
        ['ALTER SEQUENCE %s OWNER TO %s', 'sequencename', 'sequenceowner', 'pg_sequences', 'SEQUENCE'],
        ['ALTER VIEW %s OWNER TO %s', 'viewname', 'viewowner', 'pg_views', 'VIEW'],
    ];
    $lists = [];
    $mismatch = 0;
    foreach ($specs as $sp) {
        $rows = $pdo->query("SELECT " . $sp[1] . " FROM " . $sp[3] . " WHERE schemaname='public' AND " . $sp[2] . " <> current_user")->fetchAll(PDO::FETCH_COLUMN);
        $lists[] = $rows;
        $mismatch += count($rows);
    }
    if ($mismatch === 0) {
        return ['done' => 0, 'via' => 'uyumsuz nesne yok', 'error' => ''];
    }
    $done = 0;
    $fail = 0;
    $via = '';
    $errNotes = [];
    // (A) mevcut bağlantı süper kullanıcı mı?
    try {
        $isSuper = (string) $pdo->query("SELECT current_setting('is_superuser')")->fetchColumn();
        if ($isSuper === 'on') {
            $via = $curUser . ' (süper kullanıcı)';
            foreach ($specs as $i => $sp) {
                foreach ($lists[$i] as $name) {
                    $qn = '"' . str_replace('"', '""', (string) $name) . '"';
                    try {
                        $pdo->exec(sprintf($sp[0], $qn, $qUser));
                        $done++;
                    } catch (Throwable $e) {
                        $fail++;
                        $errNotes[] = $qn . ': ' . $e->getMessage();
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $errNotes[] = 'süper kullanıcı kontrolü: ' . $e->getMessage();
    }
    // (B) secrets db_admin_user
    if ($done < $mismatch && $via === '') {
        try {
            $cfg = db_config();
            $au = trim((string) ($cfg['db_admin_user'] ?? ''));
            if ($au !== '') {
                $dsn = 'pgsql:host=' . $cfg['db_host'] . ';port=' . $cfg['db_port'] . ';dbname=' . $cfg['db_name'];
                $admin = new PDO($dsn, $au, (string) ($cfg['db_admin_pass'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $via = $au . ' (secrets db_admin_user)';
                foreach ($specs as $i => $sp) {
                    foreach ($lists[$i] as $name) {
                        $qn = '"' . str_replace('"', '""', (string) $name) . '"';
                        try {
                            $admin->exec(sprintf($sp[0], $qn, $qUser));
                            $done++;
                        } catch (Throwable $e) {
                            $fail++;
                            $errNotes[] = $qn . ': ' . $e->getMessage();
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            $errNotes[] = 'db_admin_user bağlantısı: ' . $e->getMessage();
        }
    }
    // (C) root + sudo -u postgres — Plesk/root ortamında otomatik devir.
    if ($done < $mismatch && function_exists('shell_exec') && stripos((string) ini_get('disable_functions'), 'shell_exec') === false
        && function_exists('posix_getuid') && posix_getuid() === 0) {
        $sql = '';
        foreach ($specs as $i => $sp) {
            foreach ($lists[$i] as $name) {
                $sql .= 'ALTER ' . $sp[4] . ' "' . str_replace('"', '""', (string) $name) . '" OWNER TO ' . $qUser . ";\n";
            }
        }
        $cmd = 'sudo -n -u postgres psql -d ' . escapeshellarg((string) (db_config()['db_name'] ?? 'nexus_traveltech'))
            . ' -v ON_ERROR_STOP=1 -q -c ' . escapeshellarg($sql) . ' 2>&1';
        $outTxt = trim((string) shell_exec($cmd));
        if ($outTxt === '' || stripos($outTxt, 'ERROR') === false) {
            $done = $mismatch;
            $via = 'sudo -u postgres (root)';
        } else {
            $errNotes[] = 'sudo devri: ' . $outTxt;
        }
    }
    if ($via === '') {
        $via = 'yol yok (süper değil, db_admin_user yok, root değil)';
    }
    return ['done' => $done, 'via' => $via, 'error' => ($fail > 0 || $done === 0) ? implode('; ', array_slice($errNotes, 0, 3)) : ''];
}

function health_check_run(bool $dryRun = false, bool $repair = false, bool $fix = false, bool $yes = false, bool $orphans = false, bool $backupSchema = false): array
{
    $pdo = db();
    $errors = [];
    $out = '';
    $checks = []; // --json: her bölümün makinece okunabilir sonucu burada toplanır
    $appliedMigs = [];
    $pendingMigs = [];

    // --repair: yabancı/hatalı şemalı tabloları otomatik tespit edip, BOŞSA düşürür;
    // ardından migration bölümü tabloyu TAM migration zinciriyle yeniden kurar.
    // DOLU tablolara asla dokunulmaz (raporlanır, elle müdahale gerekir).
    // Tablo yanlış şemadaysa beklenen kolon yoktur -> boşsa düşürülür, migration'lar
    // (schema_migrations'ta kayıtlı olsalar bile) zorla yeniden uygulanır.
    // Adaylar statik liste DEĞİL — requiredColumns + migration zincirinde CREATE TABLE
    // ile kurulan HER tablo otomatik taranır (health_parse_migration_columns).
    $repairMap = [];
    $reapplyMigrations = []; // onarım sonrası zorla yeniden uygulanacak migration dosyaları

    $requiredTables = ['suppliers','supplier_users','properties','supplier_bookings','inventory_calendar','channel_connections','channel_property_mappings','ical_connections','ical_events','physical_rooms','booking_folios','folio_transactions','payment_records','payment_allocations','hotel_invoices','night_audit_runs','hotel_staff','hotel_roles','loyalty_tiers','guest_loyalty_accounts','revenue_recommendations','guest_service_requests','login_throttle','guest_reviews','agency_booking_requests','email_outbox','webhook_subscriptions','webhook_deliveries','error_logs','admin_audit_logs','payment_links','fx_rates','booking_groups','notifications','agencies','agency_users','email_templates','admin_2fa','scheduled_jobs','public_chat_messages','blocked_ips','panel_chat_messages','scheduled_job_runs','property_feature_catalog','channel_room_mappings','channel_rate_plan_mappings','feature_delete_backups','channel_sync_logs','ical_sync_logs','pending_trash_purges','fx_audit_daily','trash_upcoming_alerts','channel_mapping_blacklist'];

    $requiredColumns = [
        'suppliers'=>['settings'],
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
        'channel_room_mappings'=>['channel_connection_id','property_id','room_type_id','external_room_id','rate_plan_id','status','suggested_at','suggestion_count','suggestion_score','approved_by_type','approved_by_name','approved_by_user_id','approved_at'],
        'channel_property_mappings'=>['channel_connection_id','property_id','external_property_id','status'],'channel_rate_plan_mappings'=>['channel_connection_id','property_id','external_rate_plan_id','status','rate_plan_id'],
        'channel_sync_logs'=>['channel_connection_id','property_id','direction','scope','status','request_payload','response_payload','error_message','fx_audit'],
        'ical_sync_logs'=>['ical_connection_id','property_id','status','error_message','error_hash'],
        'pending_trash_purges'=>['feature_id','token','expires_at','approved_at'],
        'product_type_catalog'=>['step_targets'],
    ];

    // Yabancı şema adayları — statik liste YOK. requiredColumns + migration zincirinde
    // CREATE TABLE ile kurulan HER tablo otomatik taranır; beklenen kolon seti zincirin
    // CREATE/ALTER içeriğinden çözümlenir (health_parse_migration_columns). Yeni bir
    // tablo migration'a eklendiğinde requiredColumns'a elle eklemek GEREKMEZ.
    // Migration zinciri, tabloyu değiştiren TÜM *-postgres.sql dosyalarından türetilir:
    // CREATE TABLE, ALTER TABLE ve CREATE INDEX ... ON (sıralı). Böylece çok dosyalı
    // kurulumlar da tam yakalanır — örn. channel_room_mappings 045'te CREATE +
    // 047/049/052'de ALTER ile kurulur; salt CREATE araması zinciri kısaltır.
    // Güvenli onarım (İKİSİ de):
    //   1) Zincir tabloyu KURAN bir CREATE TABLE içermeli — suppliers/rate_plans gibi temel
    //      şemada (postgresql-schema.sql) oluşan tablolar migration ile yeniden kurulamaz;
    //      bunlar düşürülmez, yalnızca raporlanır.
    //   2) Zincirin toplam içeriği beklenen TÜM kolonları içermeli — ek kolonlar zincir dışı
    //      bir migration'da kalmışsa düşürme şemayı tam kuramaz, rapor-only kalır.
    $migFiles = glob(__DIR__ . '/../database/migrations/*-postgres.sql');
    $migText = [];
    $chainCols = [];
    foreach ($migFiles as $f) {
        $c = (string) @file_get_contents($f);
        if ($c === '') continue;
        $migText[basename($f)] = $c;
        foreach (health_parse_migration_columns($c) as $t => $cset) {
            foreach ($cset as $col => $v) $chainCols[$t][$col] = true;
        }
    }
    $candidateTables = array_values(array_unique(array_merge(array_keys($requiredColumns), array_keys($chainCols))));
    foreach ($candidateTables as $tbl) {
        $cols = array_values(array_unique(array_merge($requiredColumns[$tbl] ?? [], array_keys($chainCols[$tbl] ?? []))));
        if ($cols === []) continue;
        $nameRe = preg_quote($tbl, '/');
        $foundMigs = [];
        foreach ($migText as $fname => $c) {
            if (preg_match('/(?:CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|ALTER\s+TABLE(?:\s+IF\s+EXISTS)?|CREATE(?:\s+UNIQUE)?\s+INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?\S+\s+ON)\s+[`"]?' . $nameRe . '[\s"(]/i', $c)) {
                $foundMigs[] = $fname;
            }
        }
        sort($foundMigs);
        $safe = false;
        $hasCreate = false;
        if ($foundMigs !== []) {
            $allText = '';
            foreach ($foundMigs as $fm) {
                $allText .= $migText[$fm];
                if (preg_match('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+[`"]?' . $nameRe . '[\s"(]/i', $migText[$fm])) $hasCreate = true;
            }
            $safe = $hasCreate;
            if ($safe) {
                foreach ($cols as $col) {
                    if (!preg_match('/(?:^|[\s,(])' . preg_quote($col, '/') . '[\s,)]/i', $allText)) {
                        $safe = false;
                        break;
                    }
                }
            }
        }
        $repairMap[$tbl] = [$cols, $safe ? $foundMigs : []];
    }

    // --- 1) Tablolar ---
    $q = $pdo->prepare("SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename=ANY(?::text[])");
    $q->execute(['{' . implode(',', $requiredTables) . '}']);
    $found = array_flip($q->fetchAll(PDO::FETCH_COLUMN));
    $missingTables = array_values(array_diff($requiredTables, array_keys($found)));
    $checks['tables'] = [
        'status' => $missingTables === [] ? 'ok' : 'error',
        'total' => count($requiredTables),
        'found' => count($found),
        'missing' => $missingTables,
    ];

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
    $checks['columns'] = [
        'status' => $colErrors === [] ? 'ok' : 'error',
        'missing' => $colErrors,
    ];
    $out .= "\n=== 2) KRİTİK KOLONLAR ===\n";
    $out .= $colErrors === []
        ? "✓ Tüm kritik kolonlar mevcut.\n"
        : implode("\n", array_map(fn($e) => '✗ ' . $e, $colErrors)) . "\n";

    // --- 2a0) Kısmi şema tespiti (normal mod) ---
    // $repairMap yalnızca --repair modunda kullanılıyordu; bu blok AYNI taramayı normal modda
    // da çalıştırır: migration zincirinden çözümlenen TÜM kolonlar denetlenir (requiredColumns'a
    // elle eklenmemiş tablolar ve zincirdeki ek kolonlar dahil — örn. channel_property_mappings,
    // rate_rules, channel_room_mappings.created_at/approved_by_*). Eksik kolon uyarı olarak
    // gösterilir ve checks.partial_schema JSON'una yazılır; böylece '--repair gerekli mi' sorusu
    // kuru çalıştırmada bile cevaplanır. 2) bölümünün çift hata üretmemesi için yalnızca
    // requiredColumns'ta OLMAYAN (migration zincirinden gelen) kolonlar taranır.
    $partialSchema = [];
    $partialColStmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name=?");
    foreach ($repairMap as $tbl => $spec) {
        if (in_array($tbl, $missingTables, true)) continue;
        [$expected, $migs] = $spec;
        if ($expected === []) continue;
        $known = $requiredColumns[$tbl] ?? [];
        $extra = array_values(array_diff($expected, $known));
        if ($extra === []) continue; // migration zinciri ek kolonu yok — 2) bölümü yeterli
        $partialColStmt->execute([$tbl]);
        $existing = array_flip($partialColStmt->fetchAll(PDO::FETCH_COLUMN));
        $missingExtra = array_values(array_diff($extra, array_keys($existing)));
        if ($missingExtra === []) continue;
        $partialSchema[$tbl] = [
            'missing_columns' => $missingExtra,
            'migrations' => $migs,
            'repairable' => $migs !== [],
        ];
        $out .= '⚠ ' . $tbl . ': kısmi şema — eksik kolonlar: ' . implode(', ', $missingExtra)
            . ($migs !== [] ? ' (scripts/health-check.php --repair gerekli)' : ' (migration zinciri yok — elle müdahale)') . "\n";
        $errors[] = $tbl . ': kısmi şema — eksik kolonlar: ' . implode(', ', $missingExtra);
    }
    if ($partialSchema !== []) {
        $out .= '→ ' . count($partialSchema) . ' tabloda kısmi şema — ' . (count(array_filter($partialSchema, fn($p) => $p['repairable'])) > 0 ? 'boşsa scripts/health-check.php --repair düşürüp yeniden kurar' : 'migration zinciri yok, elle müdahale gerekir') . "\n";
    }
    $checks['partial_schema'] = [
        'status' => $partialSchema === [] ? 'ok' : 'error',
        'tables' => $partialSchema,
    ];


    // --- 2a) Oda eşleştirme tutarlılığı — verify-platform ile aynı yetim/uyumsuz taraması. ---
    if (!in_array('channel_room_mappings', $missingTables, true)) {
        $out .= "\n=== 2a) ODA EŞLEŞTİRME DURUMU ===\n";
        try {
            $mappingCount = (int) $pdo->query('SELECT COUNT(*) FROM channel_room_mappings')->fetchColumn();
            $orphanRows = $pdo->query("SELECT m.id, m.external_room_id AS code, m.status,
                CASE
                    WHEN rt.id IS NULL THEN 'oda tipi yok'
                    WHEN c.id IS NULL THEN 'kanal yok'
                    WHEN rt.property_id<>m.property_id THEN 'oda tipi başka ürüne ait'
                    WHEN m.rate_plan_id IS NOT NULL AND rp.id IS NULL THEN 'fiyat planı yok'
                    WHEN m.rate_plan_id IS NOT NULL AND rp.property_id<>m.property_id THEN 'fiyat planı başka ürüne ait'
                    ELSE 'bilinmiyor'
                END AS issue
                FROM channel_room_mappings m
                LEFT JOIN room_types rt ON rt.id=m.room_type_id
                LEFT JOIN channel_connections c ON c.id=m.channel_connection_id
                LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id
                WHERE rt.id IS NULL OR c.id IS NULL OR rt.property_id<>m.property_id OR (m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id))
                ORDER BY m.id")->fetchAll();
            $orphanMappings = count($orphanRows);
            if ($orphanMappings > 0) {
                $out .= "⚠ " . $orphanMappings . " yetim/uyumsuz eşleştirme (oda tipi veya kanal yok, ya da oda tipi başka ürüne ait) — webhook yazımı bu satırlarda başarısız olabilir\n";
                if ($orphans) {
                    $out .= "— Ayrıntı (ID · dış kod · durum · sorun):\n";
                    foreach ($orphanRows as $or) {
                        $out .= '  #' . (int) $or['id'] . ' · ' . htmlspecialchars((string) $or['code']) . ' · ' . htmlspecialchars((string) ($or['status'] ?? '')) . ' · ' . htmlspecialchars((string) $or['issue']) . "\n";
                    }
                }
                $errors[] = 'channel_room_mappings: ' . $orphanMappings . ' yetim/uyumsuz eşleştirme';
            } else {
                $out .= "✓ Oda eşleştirme durumu: " . $mappingCount . " kayıt, 0 uyumsuz.\n";
            }
            $checks['room_mappings'] = [
                'status' => $orphanMappings === 0 ? 'ok' : 'error',
                'count' => $mappingCount,
                'orphans' => $orphanMappings,
            ];
            // Hedefi dolmuş öneriler: aynı kanal + aynı dış kod için confirmed eşleşme varken
            // hâlâ 'suggested' kalan satırlar — gereksiz bekleme; --repair otomatik confirmed yapar.
            try {
                $staleSugRows = $pdo->query("SELECT s.id, s.external_room_id AS code, s.status FROM channel_room_mappings s JOIN channel_room_mappings c ON c.channel_connection_id=s.channel_connection_id AND c.external_room_id=s.external_room_id AND c.status='confirmed' WHERE s.status='suggested' ORDER BY s.id")->fetchAll();
                $staleSug = count($staleSugRows);
                if ($staleSug > 0) {
                    $out .= "⚠ " . $staleSug . " hedefi dolmuş öneri (aynı dış kod için confirmed eşleşme var ama hâlâ suggested) — --repair otomatik confirmed yapar\n";
                    if ($orphans) {
                        $out .= "— Ayrıntı (ID · dış kod):\n";
                        foreach ($staleSugRows as $sr) {
                            $out .= '  #' . (int) $sr['id'] . ' · ' . htmlspecialchars((string) $sr['code']) . "\n";
                        }
                    }
                }
            } catch (Throwable $e) {}
        } catch (Throwable $e) {
            $out .= "⚠ Oda eşleştirme taraması yapılamadı: " . $e->getMessage() . "\n";
            $checks['room_mappings'] = ['status' => 'error', 'error' => $e->getMessage()];
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
            $checks['tokens'] = ['status' => $nProb === 0 ? 'ok' : 'error', 'problems' => $nProb];
        }
    } catch (Throwable $e) {
        $out .= "⚠ Token denetimi yapılamadı: " . $e->getMessage() . "\n";
        $checks['tokens'] = ['status' => 'error', 'error' => $e->getMessage()];
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

    // 2c-6) Yetim iCal bağlantıları — silinmiş ürüne işaret eden ical_connections satırları
    //        (FK yoksa veya yabancı şemalı tabloda oluşmuşsa görülür) ve silinmiş bağlantıya
    //        işaret eden ical_sync_logs satırları.
    if (!in_array('ical_connections', $missingTables, true) && !in_array('properties', $missingTables, true)) {
        $consistencyChecks++;
        try {
            $orphanRows = $pdo->query("SELECT c.id, c.label, c.status, c.direction, c.supplier_id,
                su.full_name AS supplier_name, c.created_at,
                (SELECT MAX(l.created_at) FROM ical_sync_logs l WHERE l.ical_connection_id=c.id) AS last_sync_at
                FROM ical_connections c
                LEFT JOIN properties p ON p.id=c.property_id
                LEFT JOIN supplier_users su ON su.supplier_id=c.supplier_id
                WHERE p.id IS NULL ORDER BY c.id")->fetchAll();
            $orphan = count($orphanRows);
            if ($orphan > 0) {
                $out .= "⚠ ical_connections: " . $orphan . " yetim bağlantı (silinmiş ürün)" . "\n";
                $consistencyErrors[] = 'ical_connections: ' . $orphan . ' yetim bağlantı (silinmiş ürün)';
                if ($orphans) {
                    $out .= "— Ayrıntı (ID · etiket · durum · yön · tedarikçi · son senkron):\n";
                    foreach ($orphanRows as $or) {
                        $out .= '  #' . (int) $or['id'] . ' · ' . htmlspecialchars((string) $or['label'])
                            . ' · ' . htmlspecialchars((string) $or['status'])
                            . ' · ' . htmlspecialchars((string) $or['direction'])
                            . ' · ' . htmlspecialchars((string) ($or['supplier_name'] ?? 'tedarikçi #' . (int) $or['supplier_id']))
                            . ' · ' . ($or['last_sync_at'] ? htmlspecialchars((string) $or['last_sync_at']) : 'hiç yok') . "\n";
                    }
                }
            } else {
                $out .= "✓ Yetim iCal bağlantısı yok (tümü geçerli ürüne bağlı)." . "\n";
            }
        } catch (Throwable $e) {
            $out .= "⚠ iCal yetim denetimi yapılamadı: " . $e->getMessage() . "\n";
        }
    }
    if (!in_array('ical_sync_logs', $missingTables, true) && !in_array('ical_connections', $missingTables, true)) {
        $consistencyChecks++;
        try {
            $orphanRows = $pdo->query("SELECT l.id, l.status, l.error_message, l.created_at, l.ical_connection_id
                FROM ical_sync_logs l
                LEFT JOIN ical_connections c ON c.id=l.ical_connection_id
                WHERE c.id IS NULL ORDER BY l.id")->fetchAll();
            $orphan = count($orphanRows);
            if ($orphan > 0) {
                $out .= "⚠ ical_sync_logs: " . $orphan . " yetim satır (iCal bağlantısı yok)" . "\n";
                $consistencyErrors[] = 'ical_sync_logs: ' . $orphan . ' yetim satır';
                if ($orphans) {
                    $out .= "— Ayrıntı (ID · durum · hata · tarih · bağlantı #):\n";
                    foreach ($orphanRows as $or) {
                        $out .= '  #' . (int) $or['id'] . ' · ' . htmlspecialchars((string) $or['status'])
                            . ' · ' . htmlspecialchars(mb_substr((string) ($or['error_message'] ?? ''), 0, 60))
                            . ' · ' . htmlspecialchars((string) $or['created_at'])
                            . ' · #' . (int) $or['ical_connection_id'] . "\n";
                    }
                }
            } else {
                $out .= "✓ Yetim iCal senkron günlüğü satırı yok." . "\n";
            }
        } catch (Throwable $e) {
            $out .= "⚠ iCal log yetim denetimi yapılamadı: " . $e->getMessage() . "\n";
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
    $checks['consistency'] = [
        'status' => $consistencyErrors === [] ? 'ok' : 'error',
        'errors' => $consistencyErrors,
        'checks' => $consistencyChecks,
    ];

    // --- 2e) Zamanlayıcı kilidi (advisory lock) sağlık kontrolü — tick.php bayat kilit kontrolü.
    //        pre_tick_lock_check() her 10 dk'da bayat kilitleri kırar; burada claimed/kilit durumu
    //        apertureHealth-check çıktısına yansıtılır. Bağlantı koparsa kilit TP'de kalmış olabilir.
    $out .= "\n=== 2e) ZAMANLAYICI KİLİDİ ===\n";
    $lockOk = false;
    $lockAge = 0;
    $lockPid = 0;
    $lockAppName = '';
    $lockWarn = '';
    try {
        require_once __DIR__ . '/tick_lock.php';
        $lockKey = SCHEDULER_LOCK_KEY;
        // Kilidi tutan PID'i bul.
        $holder = $pdo->query("
            SELECT l.pid, a.state, a.state_change, a.usename, a.client_addr, a.application_name
            FROM pg_locks l
            JOIN pg_stat_activity a ON a.pid = l.pid
            WHERE l.locktype = 'advisory'
              AND l.classid = 0
              AND l.objid = " . $lockKey . "
              AND l.granted = true
            ORDER BY l.pid
            LIMIT 1
        ")->fetch();

        if (!$holder) {
            // Kilit tutulmuyor — tick çalışıyor veya hiç çalışmadı.
            // Gereken: son successful tick'i kontrol et.
            $lastTick = (string) platform_setting('scheduler_last_tick_at', '');
            if ($lastTick === '') {
                $out .= "✓ Kilit serbest (henüz hiç tick çalışmadı).\n";
            } else {
                $tickAge = (int) (time() - strtotime($lastTick));
                if ($tickAge > 1800) { // 30 dk
                    $out .= "✗ Kilit serbest ama son tick " . round($tickAge / 60) . " dk önce — tick kilitlenmemiş/çalışmamış olabilir\n";
                    $errors[] = 'scheduler lock serbest ama son tick ' . round($tickAge / 60) . ' dk önce';
                    $checks['scheduler_lock'] = ['status' => 'error', 'last_tick_age' => $tickAge];
                } else {
                    $out .= "✓ Kilit serbest, son tick " . round($tickAge / 60) . " dk önce.\n";
                    $checks['scheduler_lock'] = ['status' => 'ok', 'last_tick_age' => $tickAge];
                }
            }
            $lockOk = true;
        } else {
            $lockPid = (int) $holder['pid'];
            $lockAppName = (string) ($holder['application_name'] ?? '?');
            $stateChangeTs = strtotime((string) ($holder['state_change'] ?? ''));
            $lockAge = $stateChangeTs > 0 ? time() - $stateChangeTs : 0;
            $staleThreshold = 600; // 10 dk

            if ($lockAge >= $staleThreshold) {
                $out .= sprintf("✗ Kilit bayat: PID %d, %d sn önce tutulmuş (%s) — bayat eşik: %d sn\n", $lockPid, $lockAge, $lockAppName, $staleThreshold);
                $errors[] = sprintf('scheduler lock bayat: PID %d, %d sn (%s)', $lockPid, $lockAge, $lockAppName);
                $lockWarn = 'bayat';
            } else {
                $out .= sprintf("✓ Kilit aktif: PID %d, %d sn önce tutulmuş (%s) — bayat değil\n", $lockPid, $lockAge, $lockAppName);
                $lockOk = true;
            }
            $checks['scheduler_lock'] = [
                'status' => $lockOk ? 'ok' : 'error',
                'pid' => $lockPid,
                'age_seconds' => $lockAge,
                'application_name' => $lockAppName,
                'warning' => $lockWarn ?: null,
            ];
        }
    } catch (Throwable $e) {
        $out .= "⚠ Zamanlayıcı kilidi kontrolü yapılamadı: " . $e->getMessage() . "\n";
        $checks['scheduler_lock'] = ['status' => 'error', 'error' => $e->getMessage()];
    }

    // --- 2d) Onarım — yabancı şemalı boş tabloları düşür (migration bölümü yeniden kurar).
    if ($repair) {
        $out .= "\n=== 2d) ONARIM MODU ===\n";
        $dropped = 0;
        $dryRunDropped = 0;
        $migrated = 0; // DOLU eski şemalı tabloda düşürme yerine veri taşıma (ör. channel_property_mapping_id → channel_connection_id/property_id)
        $dryRunMigrated = 0;
        $skippedNonEmpty = [];
        $rebuiltTables = []; // gerçekten düşürülen tablolar — onarım sonrası doğrulama (3b) bunları kontrol eder
        // Eski şemadan yeni şemaya GÜVENLİ veri taşıma haritaları — DOLU tabloda düşürme
        // yerine satırları korur. legacy_col → kaynak tablonun id'si; map: yeni kolon → kaynak kolon.
        // Yalnızca kanıtlanmış eşlemeler buraya eklenir (yeni bir eski şema bulunursa elle eklenir).
        $legacyMigrateMap = [
            'channel_room_mappings' => [
                'legacy_col' => 'channel_property_mapping_id',
                'source' => 'channel_property_mappings',
                'map' => ['channel_connection_id' => 'channel_connection_id', 'property_id' => 'property_id'],
            ],
        ];
        $backupFile = null; // --backup-schema: düşürülecek tabloların canlı şeması bu dosyaya yazılır
        $backedUp = [];
        $repairChanges = []; // --json: tablo bazlı karar kaydı (ok/skipped_nonempty/dropped/...)
        foreach ($repairMap as $table => $spec) {
            [$expected, $migs] = $spec;
            $expectedCols = is_array($expected) ? $expected : [$expected];
            $exists = (bool) $pdo->query("SELECT 1 FROM pg_tables WHERE schemaname='public' AND tablename='" . $table . "'")->fetchColumn();
            if (!$exists) {
                $out .= "· " . $table . " yok — atlanıyor (migration kurar)\n";
                $repairChanges[$table] = ['status' => 'missing', 'note' => 'tablo yok — migration kuracak'];
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
                $repairChanges[$table] = ['status' => 'ok', 'columns' => count($expectedCols), 'note' => 'beklenen şemada — onarım gerekmiyor'];
                continue;
            }
            $count = (int) $pdo->query("SELECT COUNT(*) FROM \"" . $table . "\"")->fetchColumn();
            if ($count > 0) {
                // --- Güvenli otomatik geçiş: DOLU eski şemalı tabloda düşürme yerine veri taşı ---
                // Eski channel_room_mappings channel_property_mapping_id (FK -> channel_property_mappings.id)
                // kullanır; yeni şema channel_connection_id + property_id ister. Tablo doluysa ve eski
                // kolon + kaynak tablo mevcutsa satırlar taşınır (migration zinciri yine de uygulanır).
                $legacySpec = $legacyMigrateMap[$table] ?? null;
                $canMigrate = false;
                if ($legacySpec !== null) {
                    $legacyColOk = (bool) $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='" . $table . "' AND column_name='" . $legacySpec['legacy_col'] . "'")->fetchColumn();
                    $srcOk = (bool) $pdo->query("SELECT 1 FROM pg_tables WHERE schemaname='public' AND tablename='" . $legacySpec['source'] . "'")->fetchColumn();
                    $targetMissing = array_values(array_filter(array_keys($legacySpec['map']), fn($tc) => in_array($tc, $missingCols, true)));
                    $canMigrate = $legacyColOk && $srcOk && $targetMissing !== [];
                }
                if ($canMigrate) {
                    $mappable = (int) $pdo->query("SELECT COUNT(*) FROM \"" . $table . "\" m JOIN \"" . $legacySpec['source'] . "\" s ON s.id = m.\"" . $legacySpec['legacy_col'] . "\"")->fetchColumn();
                    if ($dryRun) {
                        $dryRunMigrated++;
                        $out .= "→ [dry-run] " . $table . " DOLU (" . $count . " satır) — otomatik geçiş MÜMKÜN: " . $mappable . "/" . $count . " satır " . $legacySpec['legacy_col'] . " → " . implode(', ', array_keys($legacySpec['map'])) . " taşınacak (" . $legacySpec['source'] . " üzerinden); düşürme GEREKMEZ\n";
                        $repairChanges[$table] = ['status' => 'migrate_candidate', 'rows' => $count, 'mappable' => $mappable, 'missing_columns' => $missingCols, 'from' => $legacySpec['legacy_col'], 'to' => array_keys($legacySpec['map']), 'note' => 'dry-run — otomatik veri taşıma önerisi'];
                        continue;
                    }
                    // Gerçek mod: transaction içinde kolonları ekle, veriyi taşı, eksik kalanları raporla.
                    try {
                        $pdo->beginTransaction();
                        foreach (array_keys($legacySpec['map']) as $newCol) {
                            $pdo->exec('ALTER TABLE "' . $table . '" ADD COLUMN IF NOT EXISTS "' . $newCol . '" BIGINT');
                        }
                        $setParts = [];
                        foreach ($legacySpec['map'] as $newCol => $srcCol) {
                            $setParts[] = '"' . $newCol . '" = s."' . $srcCol . '"';
                        }
                        $pdo->exec('UPDATE "' . $table . '" m SET ' . implode(', ', $setParts) . ' FROM "' . $legacySpec['source'] . '" s WHERE s.id = m."' . $legacySpec['legacy_col'] . '" AND m."' . array_key_first($legacySpec['map']) . '" IS NULL');
                        $stillNull = (int) $pdo->query('SELECT COUNT(*) FROM "' . $table . '" WHERE "' . array_key_first($legacySpec['map']) . '" IS NULL')->fetchColumn();
                        $pdo->commit();
                        if ($stillNull === 0) {
                            $migrated++;
                            $out .= "✓ " . $table . " DOLU (" . $count . " satır) — otomatik geçiş tamamlandı: " . $mappable . " satır " . $legacySpec['legacy_col'] . " → " . implode(', ', array_keys($legacySpec['map'])) . " taşındı; migration zinciri kalan kolonları ekler\n";
                            $repairChanges[$table] = ['status' => 'migrated', 'rows' => $count, 'transferred' => $mappable, 'missing_columns' => $missingCols, 'note' => 'DOLU tablo düşürülmedi; eski kolondan veri taşındı'];
                            try { audit_log('health.repair_migrate', 'schema', null, ['table' => $table, 'rows' => $count, 'transferred' => $mappable, 'from' => $legacySpec['legacy_col'], 'to' => array_keys($legacySpec['map']), 'note' => 'eski şemalı DOLU tabloda düşürme yerine otomatik veri taşıma'], 'health-check'); } catch (Throwable $ae) {}
                        } else {
                            $skippedNonEmpty[] = $table . " (" . $count . " satır — " . $stillNull . " satır " . $legacySpec['legacy_col'] . " eşleşmedi, elle inceleyin)";
                            $out .= "⚠ " . $table . " DOLU (" . $count . " satır) — otomatik geçiş KISMİ: " . ($count - $stillNull) . " taşındı, " . $stillNull . " satır " . $legacySpec['legacy_col'] . " kaynakta yok; kalan elle inceleyin\n";
                            $repairChanges[$table] = ['status' => 'migrate_partial', 'rows' => $count, 'unmapped' => $stillNull, 'missing_columns' => $missingCols, 'note' => 'bazı satırlar kaynak tabloda eşleşmedi — elle müdahale gerekir'];
                        }
                        continue;
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $re) {} }
                        $out .= "✗ " . $table . " otomatik geçiş BAŞARISIZ: " . $e->getMessage() . " — elle inceleyin (düşürülmedi)\n";
                        $errors[] = $table . ' otomatik geçiş başarısız: ' . $e->getMessage();
                        $repairChanges[$table] = ['status' => 'migrate_failed', 'rows' => $count, 'error' => $e->getMessage(), 'note' => 'otomatik veri taşıma başarısız — elle müdahale gerekir'];
                        continue;
                    }
                }
                $skippedNonEmpty[] = $table . " (" . $count . " satır — eksik kolon: " . implode(', ', $missingCols) . ")";
                $out .= "⚠ " . $table . " yabancı şemada ama DOLU (" . $count . " satır) — DÜŞÜRÜLMEDİ, elle inceleyin (eksik: " . implode(', ', $missingCols) . ")\n";
                $repairChanges[$table] = ['status' => 'skipped_nonempty', 'rows' => $count, 'missing_columns' => $missingCols, 'note' => 'DOLU tablo — düşürülmedi, elle veri taşıma gerekir'];
                continue;
            }
            if ($migs === []) {
                // Zincir güvenli değil (ek kolonlar başka migration'larda veya dosya bulunamadı):
                // düşürmek şemayı tam kuramaz — yalnızca raporlanır, elle müdahale önerilir.
                $out .= "⚠ " . $table . " yabancı şemada ama migration zinciri güvenli değil (ek kolonlar başka migration'larda) — DÜŞÜRÜLMEDİ, elle inceleyin (eksik: " . implode(', ', $missingCols) . ")\n";
                $repairChanges[$table] = ['status' => 'unsafe_chain', 'missing_columns' => $missingCols, 'note' => 'migration zinciri güvenli değil — düşürülmedi, elle inceleyin'];
                continue;
            }
            foreach ($migs as $mig) {
                $reapplyMigrations[$mig] = true; // dry-run'da da işaretlenir — migration bölümü ♻ gösterir
            }
            if ($dryRun) {
                $dryRunDropped++;
                $out .= "→ [dry-run] " . $table . " yabancı şemalı ve boş — DÜŞÜRÜLECEK; " . implode(', ', $migs) . " yeniden uygulanacak" . ($backupSchema ? ' (--backup-schema: düşürmeden önce canlı şema yedeklenecek)' : '') . "\n";
                $repairChanges[$table] = ['status' => 'drop_candidate', 'missing_columns' => $missingCols, 'migrations' => $migs, 'backup_schema' => $backupSchema, 'note' => 'dry-run — düşürülecek (gerçek modda bu karar uygulanır)'];
                continue;
            }
            // Gerçek modda onay — otomasyon (cron) --yes ister; etkileşimli terminal yoksa ve
            // --yes verilmediyse tablo düşürülmez (yalnızca raporlanır).
            $interactive = function_exists('posix_isatty') && @posix_isatty(STDIN);
            if (!$yes && !$interactive) {
                $out .= "→ " . $table . " yabancı şemalı ve boş — DÜŞÜRÜLMEDİ (etkileşimli terminal yok; onay için --yes ekleyin)\n";
                $repairChanges[$table] = ['status' => 'skipped_no_confirm', 'missing_columns' => $missingCols, 'migrations' => $migs, 'note' => 'etkileşimli terminal yok ve --yes verilmedi — düşürülmedi'];
                continue;
            }
            if (!$yes) {
                echo "❓ " . $table . " düşürülecek (" . implode(', ', $migs) . " yeniden uygulanacak). Onaylıyor musunuz? [e/H] ";
                $ans = strtolower(trim((string) fgets(STDIN)));
                if (!in_array($ans, ['e', 'evet', 'y', 'yes'], true)) {
                    $out .= "→ " . $table . " düşürme onaylanmadı — atlandı\n";
                    $repairChanges[$table] = ['status' => 'declined', 'missing_columns' => $missingCols, 'migrations' => $migs, 'note' => 'etkileşimli onay reddedildi'];
                    continue;
                }
            }
            // --backup-schema: düşürmeden ÖNCE canlı şemayı yedek dosyasına yaz.
            // Yedek, düşürme başarısız olsa bile kalır (güvenli yön). Yalnızca gerçek
            // düşürme anında yazılır; --dry-run hiçbir dosya oluşturmaz.
            if ($backupSchema) {
                if ($backupFile === null) {
                    $backupDir = dirname(__DIR__) . '/database/backups';
                    if (!is_dir($backupDir)) @mkdir($backupDir, 0775, true);
                    $backupFile = $backupDir . '/schema-backup-' . gmdate('Ymd-His') . '.sql';
                    @file_put_contents($backupFile, "-- NEXUS health-check --repair şema yedeği\n"
                        . '-- Zaman: ' . gmdate('c') . "\n"
                        . "-- Mod: --repair --backup-schema\n\n", FILE_APPEND);
                }
                $dump = '-- Düşürülecek: ' . $table . ' (eksik kolon: ' . implode(', ', $missingCols) . ')' . "\n"
                    . '-- Zincir: ' . implode(', ', $migs) . "\n";
                try {
                    $dump .= health_schema_dump_table($pdo, $table) . "\n";
                } catch (Throwable $e) {
                    $dump .= '-- (canlı şema çözümlenemedi: ' . $e->getMessage() . ')' . "\n";
                }
                @file_put_contents($backupFile, $dump, FILE_APPEND);
                $out .= "→ " . $table . " şeması yedeklendi → " . $backupFile . "\n";
                $backedUp[] = $table;
            }
            try {
                $pdo->exec('DROP TABLE IF EXISTS "' . $table . '" CASCADE');
                $out .= "→ " . $table . " yabancı şemalı ve boş — düşürüldü; " . implode(', ', $migs) . " zinciriyle yeniden kurulacak\n";
                $dropped++;
                $rebuiltTables[$table] = ['cols' => $expectedCols, 'migs' => $migs]; // 3b doğrulaması + denetimi için kaydet
                $repairChanges[$table] = ['status' => 'dropped', 'missing_columns' => $missingCols, 'migrations' => $migs, 'backed_up' => in_array($table, $backedUp, true), 'note' => 'tablo düşürüldü; ' . implode(', ', $migs) . ' zinciriyle yeniden kurulacak'];
                // Onarım denetimi: ne zaman, hangi tablo, hangi migration zinciri yeniden kurulacak.
                audit_log('health.repair_drop', 'schema', null, ['table' => $table, 'migrations' => $migs, 'missing_columns' => $missingCols, 'backup' => $backupFile !== null ? basename((string) $backupFile) : null, 'note' => 'yabancı şema düşürüldü; migration zinciri tabloyu yeniden kuracak'], 'health-check');
            } catch (Throwable $e) {
                $out .= "✗ " . $table . " düşürülemedi: " . $e->getMessage() . "\n";
                $errors[] = $table . ' onarılamadı: ' . $e->getMessage();
                $repairChanges[$table] = ['status' => 'drop_failed', 'missing_columns' => $missingCols, 'error' => $e->getMessage(), 'note' => 'düşürme başarısız — elle müdahale gerekir'];
            }
        }
        // Yetim eşleştirmeleri temizle — paylaşılan health_orphan_cleanup() (aynı mantık
        // onay sayfası admin/approve-orphan-cleanup.php ve günlük görevde de kullanılır).
        // --orphans ile dry-run'da satır satır ayrıntı gösterilir.
        $orphanRes = health_orphan_cleanup($pdo, $dryRun);
        $out .= $orphanRes['out'];
        foreach ($orphanRes['errors'] as $oe) {
            $errors[] = $oe;
        }
        if ($orphanRes['removed'] > 0 && !$dryRun) {
            try {
                audit_log('health.repair_orphan_cleanup', 'schema', null, [
                    'removed' => implode(';', array_map(fn($t) => $t . ':' . count($orphanRes['codes'][$t] ?? []), array_keys($orphanRes['codes']))),
                    'codes' => $orphanRes['codes'],
                    'total' => $orphanRes['removed'],
                    'ran_at' => gmdate('c'),
                    'note' => 'silinmiş oda tipi/plan/kanala işaret eden yetim eşleştirmeler temizlendi',
                ], 'health-check');
            } catch (Throwable $e) {}
        }
        // Hedefi dolmuş önerileri otomatik confirmed yap — aynı kanal + aynı dış kod için
        // confirmed eşleşme varken hâlâ 'suggested' kalan satırlar (oda + fiyat planı).
        // Onay akışı gereksizdir: kod zaten o kanalda confirmed bir satıra bağlı.
        $staleConfirmNote = '';
        $staleSpecs = [
            'channel_room_mappings' => ['label' => 'oda önerisi', 'code' => 'external_room_id'],
            'channel_rate_plan_mappings' => ['label' => 'fiyat planı önerisi', 'code' => 'external_rate_plan_id'],
        ];
        foreach ($staleSpecs as $staleTable => $sspec) {
            if (in_array($staleTable, $missingTables, true)) continue;
            try {
                $staleSql = "SELECT s.id, s.{" . $sspec['code'] . "} AS code, s.status FROM " . $staleTable . " s JOIN " . $staleTable . " c ON c.channel_connection_id=s.channel_connection_id AND c." . $sspec['code'] . "=s." . $sspec['code'] . " AND c.status='confirmed' WHERE s.status='suggested' ORDER BY s.id";
                $staleRows = $pdo->query($staleSql)->fetchAll();
                if ($staleRows) {
                    if ($dryRun) {
                        $out .= '→ [dry-run] ' . count($staleRows) . ' hedefi dolmuş ' . $sspec['label'] . ' CONFIRMED yapılacak (örnek: ' . htmlspecialchars((string) $staleRows[0]['code']) . ')' . "\n";
                    } else {
                        $ids = array_map(fn($r) => (int) $r['id'], $staleRows);
                        $ph = implode(',', array_fill(0, count($ids), '?'));
                        $upd = $pdo->prepare("UPDATE " . $staleTable . " SET status='confirmed', suggested_at=NULL, approved_by_type='auto', approved_by_name='health-check --repair', approved_by_user_id=NULL, approved_at=now() WHERE id IN (" . $ph . ")");
                        $upd->execute($ids);
                        $out .= '→ ' . count($staleRows) . ' hedefi dolmuş ' . $sspec['label'] . ' confirmed yapıldı' . "\n";
                        $staleConfirmNote .= $staleTable . ':' . count($staleRows) . ';';
                    }
                } else {
                    $out .= '✓ Hedefi dolmuş ' . $sspec['label'] . ' yok.' . "\n";
                }
            } catch (Throwable $e) {
                $out .= '✗ Hedefi dolmuş ' . $sspec['label'] . ' taraması yapılamadı: ' . $e->getMessage() . "\n";
                $errors[] = 'hedefi dolmuş öneri düzeltmesi başarısız: ' . $e->getMessage();
            }
        }
        if ($staleConfirmNote !== '' && !$dryRun) {
            try {
                audit_log('health.repair_stale_confirm', 'schema', null, ['confirmed' => rtrim($staleConfirmNote, ';'), 'ran_at' => gmdate('c'), 'note' => 'hedefi dolmuş onay bekleyen öneriler otomatik confirmed yapıldı'], 'health-check');
            } catch (Throwable $e) {}
        }
        if ($backupSchema && $backupFile !== null) {
            $out .= "✓ Şema yedeği: " . $backupFile . " (" . count($backedUp) . " tablo: " . implode(', ', $backedUp) . ")\n";
        } elseif ($backupSchema) {
            $out .= "· --backup-schema etkin ama düşürülecek tablo yok — yedek dosyası oluşturulmadı.\n";
        }
        $out .= $dryRun
            ? ($dryRunDropped === 0 && $dryRunMigrated === 0 && $skippedNonEmpty === []
                ? "✓ (dry-run) Düşürülecek/taşınacak tablo yok.\n"
                : "Özet (dry-run): " . $dryRunDropped . " tablo DÜŞÜRÜLECEK" . ($dryRunMigrated > 0 ? ' · ' . $dryRunMigrated . ' DOLU tabloda veri TAŞINACAK' : '') . ($skippedNonEmpty ? '; elle müdahale: ' . implode('; ', $skippedNonEmpty) : '') . " — hiçbir değişiklik yapılmadı\n")
            : ($dropped === 0 && $migrated === 0 && $skippedNonEmpty === []
                ? "✓ Onarım gerektiren boş tablo yok.\n"
                : "Özet: " . $dropped . " tablo düşürüldü" . ($migrated > 0 ? ' · ' . $migrated . ' DOLU tabloda veri taşındı' : '') . ($skippedNonEmpty ? '; elle müdahale: ' . implode('; ', $skippedNonEmpty) : '') . "\n");

        // --- 2d-1) Sahiplik devri — app kullanıcısı tablo sahibi değilse ÖNCE devret ---
        // Migration'lar app kullanıcısıyla uygulanır; tablolar postgres sahibindeyse
        // 'must be owner' hatası verir. Bu adım sahipliği devreder; yapılamazsa
        // migration bölümü atlanır (uygulanmaya çalışılmaz) ve tek satır komut gösterilir.
        $ownershipBlocked = false;
        $ownershipTransferred = 0;
        $curUser = '';
        try {
            $curUser = (string) $pdo->query('SELECT current_user')->fetchColumn();
            $ownTables = $pdo->query("SELECT tablename, tableowner FROM pg_tables WHERE schemaname='public' AND tableowner <> current_user ORDER BY tablename")->fetchAll();
            $ownSeqs = $pdo->query("SELECT sequencename, sequenceowner FROM pg_sequences WHERE schemaname='public' AND sequenceowner <> current_user ORDER BY sequencename")->fetchAll();
            $ownViews = $pdo->query("SELECT viewname, viewowner FROM pg_views WHERE schemaname='public' AND viewowner <> current_user ORDER BY viewname")->fetchAll();
            $mismatch = count($ownTables) + count($ownSeqs) + count($ownViews);
            if ($mismatch === 0) {
                $out .= "\n=== 2d-1) SAHİPLİK DEVRİ ===\n✓ Tüm public nesnelerin sahibi " . $curUser . ".\n";
            } else {
                $out .= "\n=== 2d-1) SAHİPLİK DEVRİ ===\n⚠ " . $mismatch . " nesnenin sahibi " . $curUser . " değil (" . count($ownTables) . " tablo, " . count($ownSeqs) . " dizi, " . count($ownViews) . " görünüm)\n";
                if ($dryRun) {
                    $out .= "→ [dry-run] Sahiplik devri YAPILMAZ — devredilecekler:\n";
                    foreach ($ownTables as $o) $out .= '  tablo: ' . $o['tablename'] . ' (' . $o['tableowner'] . ")\n";
                    foreach ($ownSeqs as $o) $out .= '  dizi: ' . $o['sequencename'] . ' (' . $o['sequenceowner'] . ")\n";
                    foreach ($ownViews as $o) $out .= '  görünüm: ' . $o['viewname'] . ' (' . $o['viewowner'] . ")\n";
                } else {
                    // Devir girişimi: (A) mevcut bağlantı süper kullanıcıysa doğrudan;
                    // (B) secrets'ta db_admin_user/db_admin_pass varsa o hesapla; ikisi de
                    // yoksa engelle (migration'lar uygulanmaz, tek satır komut gösterilir).
                    $transferPdo = null;
                    $transferAs = '';
                    $isSuper = (string) $pdo->query("SELECT current_setting('is_superuser')")->fetchColumn();
                    if ($isSuper === 'on') {
                        $transferPdo = $pdo;
                        $transferAs = $curUser . ' (süper kullanıcı)';
                    } else {
                        $cfg = db_config();
                        $au = trim((string) ($cfg['db_admin_user'] ?? ''));
                        if ($au !== '') {
                            try {
                                $dsn = 'pgsql:host=' . $cfg['db_host'] . ';port=' . $cfg['db_port'] . ';dbname=' . $cfg['db_name'];
                                $transferPdo = new PDO($dsn, $au, (string) ($cfg['db_admin_pass'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                                $transferAs = $au . ' (secrets db_admin_user)';
                            } catch (Throwable $e) {
                                $out .= "⚠ db_admin_user bağlantısı başarısız: " . $e->getMessage() . "\n";
                                $transferPdo = null;
                            }
                        }
                    }
                    if ($transferPdo === null) {
                        // (C) Root + sudo -u postgres — Plesk/root ortamında otomatik devir.
                        // Süreç root olarak çalışıyorsa sahiplik doğrudan devredilir;
                        // manuel tek satır komuta gerek kalmaz. Denemesi güvenli: başarısızsa
                        // aşağıda engel + tek satır komut gösterilir (eski davranış).
                        $cfgDb = db_config();
                        try {
                            $sudoFix = health_auto_fix_ownership($pdo);
                            if ((int) $sudoFix['done'] > 0) {
                                $ownershipTransferred = (int) $sudoFix['done'];
                                $out .= "→ " . $ownershipTransferred . " nesnenin sahipliği otomatik devredildi (" . $sudoFix['via'] . ")\n";
                                audit_log('health.repair_ownership', 'schema', null, ['target_user' => $curUser, 'transferred' => $ownershipTransferred, 'failed' => 0, 'via' => $sudoFix['via'], 'note' => 'must be owner sorunu root/sudo ile otomatik çözüldü'], 'health-check');
                            } else {
                                $ownershipBlocked = true;
                                $out .= "✗ Sahiplik devredilemedi — mevcut kullanıcı süper değil, secrets.php'de db_admin_user tanımlı değil ve otomatik devir (" . $sudoFix['via'] . ") sonuç vermedi.\n";
                                $out .= "  → Migration'lar uygulanmayacak. postgres olarak önce tek satır (kullanıcıyı secrets'tan okur):\n";
                                $out .= "    cd " . dirname(__DIR__) . " && OWNER=\"$(/opt/plesk/php/8.5/bin/php -r '\$c=require \"config/secrets.php\"; echo \$c[\"db_user\"] ?? \"app\";')\" && sudo -u postgres psql -d " . $cfgDb['db_name'] . " -v ON_ERROR_STOP=1 -v owner=\"\$OWNER\" -c \"SELECT format('ALTER TABLE %I OWNER TO %I', tablename, :'owner') FROM pg_tables WHERE schemaname='public' \\gexec\"\n";
                                $errors[] = 'Sahiplik devri gerekli ama yapılamadı (süper değil + db_admin_user yok + otomatik devir sonuç vermedi) — migration uygulaması atlandı.';
                            }
                        } catch (Throwable $eOwn) {
                            $ownershipBlocked = true;
                            $out .= "✗ Sahiplik devredilemedi: " . $eOwn->getMessage() . "\n";
                            $errors[] = 'Sahiplik devri gerekli ama yapılamadı: ' . $eOwn->getMessage();
                        }
                    } else {
                        $qUser = '"' . str_replace('"', '""', $curUser) . '"';
                        $done = 0;
                        $failN = 0;
                        $specs = [
                            ['ALTER TABLE %s OWNER TO %s', 'tablename', $ownTables],
                            ['ALTER SEQUENCE %s OWNER TO %s', 'sequencename', $ownSeqs],
                            ['ALTER VIEW %s OWNER TO %s', 'viewname', $ownViews],
                        ];
                        foreach ($specs as $sp) {
                            [$tpl, $col, $rowsList] = $sp;
                            foreach ($rowsList as $r) {
                                $qn = '"' . str_replace('"', '""', (string) $r[$col]) . '"';
                                try {
                                    $transferPdo->exec(sprintf($tpl, $qn, $qUser));
                                    $done++;
                                } catch (Throwable $e) {
                                    $failN++;
                                    $out .= "⚠ devredilemedi: " . $qn . ' — ' . $e->getMessage() . "\n";
                                }
                            }
                        }
                        $ownershipTransferred = $done;
                        $out .= "→ " . $done . " nesnenin sahipliği " . $curUser . "'a devredildi (" . $transferAs . ")" . ($failN > 0 ? ' · ' . $failN . ' başarısız' : '') . "\n";
                        if ($done > 0) {
                            audit_log('health.repair_ownership', 'schema', null, ['target_user' => $curUser, 'transferred' => $done, 'failed' => $failN, 'via' => $transferAs, 'note' => 'app kullanıcısı tablo sahibi olmadığı için sahiplik önce devredildi'], 'health-check');
                        }
                        if ($failN > 0) {
                            $errors[] = 'Sahiplik devrinde ' . $failN . ' nesne başarısız.';
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            $out .= "⚠ Sahiplik denetimi yapılamadı: " . $e->getMessage() . "\n";
        }

        // --json: onarım değişiklik özeti — hangi tablo düşürüldü/atlandı, tekrar uygulanacak
        // migration zinciri, yedek durumu ve ek işlemler (yetim/öneri/sahiplik). İnsan çıktısıyla
        // birebir aynı kararları makinece okunabilir yapıda taşır.
        $checks['repair'] = [
            'status' => ($dryRun ? $dryRunDropped : $dropped) > 0 || ($dryRun ? $dryRunMigrated : $migrated) > 0 || $skippedNonEmpty !== [] ? ($dryRun ? 'dry_run_pending' : 'done') : 'clean',
            'dry_run' => (bool) $dryRun,
            'summary' => [
                'dropped' => $dropped,
                'dry_run_dropped' => $dryRunDropped,
                'migrated' => $migrated,
                'dry_run_migrated' => $dryRunMigrated,
                'skipped_nonempty' => count($skippedNonEmpty),
                'orphans_removed' => (int) ($orphanRes['removed'] ?? 0),
                'stale_confirmations' => $staleConfirmNote !== '' ? count(array_filter(explode(';', rtrim($staleConfirmNote, ';')))) : 0,
                'ownership_transferred' => (int) ($ownershipTransferred ?? 0),
                'backed_up' => $backedUp,
                'backup_file' => $backupFile !== null ? basename((string) $backupFile) : null,
            ],
            'tables' => $repairChanges,
        ];
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
    if ($ownershipBlocked) {
        $out .= "⚠ Sahiplik devri engellendiği için migration uygulaması ATLANDI — önce sahipliği devredin (yukarıdaki tek satır komut).\n";
    }

    // --- 3a) Sahiplik ÖN kontrolü — migration'lar uygulanmadan ÖNCE ---
    // Tablo sahibi app kullanıcısı değilse migration 'must be owner' verir. Bu blok
    // durumu önceden raporlar: (a) app kullanıcısına ait olmayan public nesneler
    // (tablo/dizi/görünüm), (b) sahibi artık var olmayan bir role işaret eden
    // gerçek SAHİPSİZ tablolar. --repair modunda 2d-1 sahipliği zaten devretmiş
    // olur; normal modda da uyarı görünür — başarısız migration'ın nedeni belli olur.
    $ownMismatch = 0;
    $ownList = [];
    $ownOwnerless = [];
    try {
        $curUser = (string) $pdo->query('SELECT current_user')->fetchColumn();
        $ownCheck = [
            ['Tablo', 'tablename', 'tableowner', 'pg_tables'],
            ['Dizi', 'sequencename', 'sequenceowner', 'pg_sequences'],
            ['Görünüm', 'viewname', 'viewowner', 'pg_views'],
        ];
        foreach ($ownCheck as [$oLabel, $oNameCol, $oOwnCol, $oSrc]) {
            $rows = $pdo->query("SELECT " . $oNameCol . ", " . $oOwnCol . " FROM " . $oSrc . " WHERE schemaname='public' AND " . $oOwnCol . " <> current_user ORDER BY " . $oNameCol)->fetchAll();
            foreach ($rows as $r) {
                $ownMismatch++;
                $ownList[] = $oLabel . ': ' . $r[$oNameCol] . ' (' . $r[$oOwnCol] . ')';
            }
        }
        // (b) Gerçek sahipsiz: sahibi var olmayan role işaret eden tablolar.
        $ownerlessRows = $pdo->query("SELECT t.tablename, t.tableowner FROM pg_tables t WHERE t.schemaname='public' AND NOT EXISTS (SELECT 1 FROM pg_roles r WHERE r.rolname = t.tableowner) ORDER BY t.tablename")->fetchAll();
        foreach ($ownerlessRows as $r) {
            $ownOwnerless[] = $r['tablename'] . ' (sahip rolü yok: ' . $r['tableowner'] . ')';
        }
        $out .= "\n=== 3a) SAHİPLİK ÖN KONTROLÜ ===\n";
        if ($ownMismatch === 0 && $ownOwnerless === []) {
            $out .= "✓ Tüm public nesnelerin sahibi " . $curUser . " — migration öncesi sahiplik sorunu yok.\n";
        } else {
            if ($ownMismatch > 0) {
                $out .= "⚠ " . $ownMismatch . " nesne app kullanıcısına (" . $curUser . ") ait değil — migration'lar 'must be owner' verebilir:\n";
                foreach (array_slice($ownList, 0, 12) as $ol) {
                    $out .= "  · " . $ol . "\n";
                }
                if (count($ownList) > 12) {
                    $out .= "  … +" . (count($ownList) - 12) . " daha\n";
                }
            }
            if ($ownOwnerless !== []) {
                $out .= "⚠ " . count($ownOwnerless) . " SAHİPSİZ tablo (sahip rolü silinmiş):\n";
                foreach (array_slice($ownOwnerless, 0, 12) as $oo) {
                    $out .= "  · " . $oo . "\n";
                }
                if (count($ownOwnerless) > 12) {
                    $out .= "  … +" . (count($ownOwnerless) - 12) . " daha\n";
                }
            }
            $out .= "  → Çözüm: scripts/fix-server.sh (adım 4) veya health-check --repair sahipliği otomatik devreder.\n";
            $errDetail = array_merge(array_slice($ownList, 0, 5), array_slice($ownOwnerless, 0, 5));
            $errors[] = 'Sahiplik ön kontrolü: ' . ($ownMismatch + count($ownOwnerless)) . ' nesne sorunlu (' . implode('; ', $errDetail) . ($ownMismatch + count($ownOwnerless) > 5 ? ' …' : '') . ')';
        }
        $checks['ownership_precheck'] = [
            'status' => ($ownMismatch === 0 && $ownOwnerless === []) ? 'ok' : 'error',
            'mismatch' => $ownMismatch,
            'ownerless' => $ownOwnerless,
        ];
    } catch (Throwable $e) {
        $out .= "⚠ Sahiplik ön kontrolü yapılamadı: " . $e->getMessage() . "\n";
        $checks['ownership_precheck'] = ['status' => 'error', 'error' => $e->getMessage()];
    }
    $pendingCount = 0;
    $failedMigs = [];
    // 'must be owner' otomatik devri — migration bir kez başarısız olursa sahiplik
    // devredilip yeniden denenir; bu bayrak tüm migration döngüsünde yalnızca bir kez
    // devir girişimi yapılmasını garantiler (sonsuz döngü yok).
    $ownershipRetried = false;
    // 'Kayıtlı ama etkisiz' tespiti — schema_migrations'ta ✓ görünen bir dosyanın kurması
    // beklenen tablo/kolonlar gerçekte yoksa uyarı üretilir. Tipik neden: CREATE TABLE
    // IF NOT EXISTS, tablo eski/yabancı şemada zaten var olduğu için sessizce atlanır
    // (örn. 045 channel_room_mappings) ya da tablo sonradan silinmiş ama kayıt duruyor.
    $ineffectiveMigs = [];
    $ineffColStmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name=?");
    foreach ($migrationFiles as $file) {
        $base = basename($file);
        // Onarım modunda düşürülen tabloların migration'ları schema_migrations'ta kayıtlı olsa
        // bile zorla yeniden uygulanır (CREATE TABLE IF NOT EXISTS tabloyu yeniden kurar;
        // ALTER ... IF NOT EXISTS güvenlidir). Kayıt güncel commit hash'iyle güncellenir.
        $forceReapply = isset($reapplyMigrations[$base]);
        if (!$forceReapply && isset($appliedMap[$base])) {
            $out .= '✓ ' . $base . (($appliedMap[$base] !== '') ? ' @ ' . substr($appliedMap[$base], 0, 7) : '') . "\n";
            // Dosyanın CREATE/ALTER ettiği her tabloda beklenen kolonlar gerçekte var mı?
            if (isset($migText[$base])) {
                foreach (health_parse_migration_columns($migText[$base]) as $t => $cset) {
                    if (in_array($t, $missingTables, true)) {
                        $ineffectiveMigs[] = $base . ' → ' . $t . ': tablo yok (kayıt duruyor) — --repair kurar';
                        continue;
                    }
                    $ineffColStmt->execute([$t]);
                    $existing = array_flip($ineffColStmt->fetchAll(PDO::FETCH_COLUMN));
                    $miss = array_values(array_diff(array_keys($cset), array_keys($existing)));
                    if ($miss !== []) {
                        $ineffectiveMigs[] = $base . ' → ' . $t . ': eksik kolon(lar) ' . implode(', ', $miss) . ' — CREATE atlanmış (eski şema), --repair düzeltir';
                    }
                }
            }
            continue;
        }
        if ($ownershipBlocked) {
            $out .= '⏳ ' . $base . ' (sahiplik devri bekleniyor — atlandı)' . "\n";
            $pendingMigs[] = $base;
            continue;
        }
        if ($dryRun) {
            $out .= ($forceReapply ? '♻ ' : '⏳ ') . $base . ($forceReapply ? ' (onarım sonrası yeniden uygulanacak)' : ' (bekliyor)') . "\n";
            $pendingCount++;
            $pendingMigs[] = $base;
            continue;
        }
        $sql = (string) file_get_contents($file);
        // Sahiplikten bağımsız çalışma: migration'lardaki @APP_DB_USER@ yer tutucusu
        // secrets.php'deki db_user ile değiştirilir (GRANT + ALTER ... OWNER satırları).
        $sql = str_replace('@APP_DB_USER@', (string) (db_config()['db_user'] ?? 'nexus_app'), $sql);
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
                $appliedMigs[] = $base;
                audit_log('health.repair_rebuild', 'schema', null, ['migration' => $base, 'commit' => $commitNow !== '' ? substr($commitNow, 0, 7) : null, 'reapplied' => true, 'note' => 'onarım sonrası tablo yeniden kuruldu'], 'health-check');
            } else {
                $pdo->prepare('INSERT INTO schema_migrations(file, commit_hash) VALUES(?,?)')->execute([$base, $commitNow !== '' ? $commitNow : null]);
                $out .= '→ ' . $base . ' uygulandı' . ($commitNow !== '' ? ' @ ' . substr($commitNow, 0, 7) : ' (commit hash bulunamadı)') . "\n";
                $appliedMigs[] = $base;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // 'must be owner': tablo sahibi farklıysa sahipliği otomatik devredip
            // migration'ı bir kez yeniden dener. Root yetkisi varsa sudo -u postgres
            // yoluyla; süper kullanıcı/db_admin_user da otomatik denenir. Devir başarılı
            // olursa aynı SQL yeniden uygulanır, başarısızsa normal hata akışı.
            $msg = (string) $e->getMessage();
            if (!$ownershipRetried && stripos($msg, 'must be owner') !== false) {
                $ownershipRetried = true;
                try {
                    $fix = health_auto_fix_ownership($pdo);
                    if ((int) $fix['done'] > 0) {
                        $out .= "→ 'must be owner' — " . $fix['done'] . " nesnenin sahipliği otomatik devredildi (" . $fix['via'] . "); " . $base . " yeniden deneniyor\n";
                        audit_log('health.auto_ownership', 'schema', null, ['migration' => $base, 'transferred' => (int) $fix['done'], 'via' => $fix['via'], 'note' => 'must be owner hatası otomatik sahiplik devriyle çözüldü'], 'health-check');
                        try {
                            $pdo->beginTransaction();
                            $pdo->exec($sql);
                            $pdo->commit();
                            $pdo->prepare('INSERT INTO schema_migrations(file, commit_hash) VALUES(?,?)')->execute([$base, $commitNow !== '' ? $commitNow : null]);
                            $out .= '→ ' . $base . ' uygulandı (sahiplik devri sonrası)' . ($commitNow !== '' ? ' @ ' . substr($commitNow, 0, 7) : '') . "\n";
                            $appliedMigs[] = $base;
                            continue;
                        } catch (Throwable $e2) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            $out .= '✗ ' . $base . ' — ' . $e2->getMessage() . "\n";
                            $failedMigs[] = $base;
                            $errors[] = 'Migration başarısız: ' . $base . ' — ' . $e2->getMessage();
                            continue;
                        }
                    }
                    $out .= "⚠ 'must be owner' — otomatik devir yapılamadı (" . $fix['via'] . ").\n";
                } catch (Throwable $eFix) {
                    $out .= "⚠ 'must be owner' — otomatik devir girişimi başarısız: " . $eFix->getMessage() . "\n";
                }
            }
            $out .= '✗ ' . $base . ' — ' . $msg . "\n";
            $failedMigs[] = $base;
            $errors[] = 'Migration başarısız: ' . $base . ' — ' . $msg;
        }
    }
    if ($dryRun && $pendingCount > 0) {
        $out .= "($pendingCount migration bekliyor — --dry-run uygulamadı)\n";
    }
    if ($failedMigs) {
        $out .= "Başarısız migration'lar: " . implode(', ', $failedMigs) . "\n";
    }
    if ($ineffectiveMigs !== []) {
        $out .= "⚠ " . count($ineffectiveMigs) . " kayıtlı ama ETKİSİZ migration (dosya schema_migrations'ta ✓ ama hedef tablo/kolon gerçekte yok):\n";
        foreach (array_slice($ineffectiveMigs, 0, 8) as $im) {
            $out .= "  · " . $im . "\n";
        }
        if (count($ineffectiveMigs) > 8) {
            $out .= "  … +" . (count($ineffectiveMigs) - 8) . " daha\n";
        }
        $out .= "  → Çözüm: scripts/health-check.php --repair (boşsa düşürüp yeniden kurar; DOLUysa otomatik veri taşıma dener)\n";
    }
    $checks['migrations'] = [
        'status' => $failedMigs === [] && $pendingMigs === [] ? 'ok' : ($failedMigs !== [] ? 'error' : 'pending'),
        'commit' => $commitNow !== '' ? substr($commitNow, 0, 7) : '',
        'applied' => $appliedMigs,
        'pending' => $pendingMigs,
        'failed' => $failedMigs,
        'ineffective' => $ineffectiveMigs,
    ];
    if ($ineffectiveMigs !== []) {
        // Etkisiz migration ayrı bir hata satırı değildir (tablo/kolon eksikliği zaten 1-2
        // bölümlerinde HATA sayılır); kök nedeni açıklayan bilgilendirme uyarısıdır.
        $out .= "· Not: etkisiz migration'lar ayrı HATA sayılmaz — eksik tablo/kolon zaten yukarıda raporlandı.\n";
    }

    // --- 3b) Onarım sonrası doğrulama — düşürülüp yeniden kurulan tabloların beklenen
    // kolonları tekrar kontrol edilir. Migration bölümü az önce bittiği için tablolar
    // kurulmuş durumdadır; eksik kalan kolon varsa HATA sayılır (onarım başarısız demektir).
    // Dry-run'da hiçbir tablo düşürülmediği için bu bölüm atlanır. --repair --yes ile
    // koşan günlük görev de bu doğrulamayı görür — sorun varsa e-posta uyarısına düşer.
    $postFail = []; // 3b çalışmadıysa (düşürülen tablo yok) özet e-postası için boş kalır
    if ($repair && !$dryRun && $rebuiltTables !== []) {
        $out .= "\n=== 3b) ONARIM SONRASI DOĞRULAMA (" . count($rebuiltTables) . " tablo) ===\n";
        $postColStmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name=?");
        $postFail = [];
        foreach ($rebuiltTables as $table => $info) {
            $cols = $info['cols'] ?? [];
            $migs = $info['migs'] ?? [];
            $postColStmt->execute([$table]);
            $existing = array_flip($postColStmt->fetchAll(PDO::FETCH_COLUMN));
            $missingAfter = array_values(array_diff($cols, array_keys($existing)));
            if ($missingAfter === []) {
                $out .= "✓ " . $table . " yeniden kuruldu — beklenen " . count($cols) . " kolon mevcut\n";
            } else {
                $out .= "✗ " . $table . " yeniden kurulamadı — hâlâ eksik: " . implode(', ', $missingAfter) . "\n";
                $postFail[] = $table . ' (' . implode(', ', $missingAfter) . ')';
            }
            // Onarım denetimi: hangi tablo, ne zaman (created_at), hangi migration zinciriyle
            // yeniden kuruldu ve doğrulama sonucu (tamam/eksik kaldı). Başarılı ve başarısız
            // her iki durum da kaydedilir — günlük --repair --yes görevi e-postada görür.
            try {
                audit_log('health.repair_verify', 'schema', null, [
                    'table' => $table,
                    'migrations' => $migs,
                    'expected_columns' => $cols,
                    'missing_after' => $missingAfter,
                    'ok' => $missingAfter === [],
                    'note' => $missingAfter === [] ? 'tablo beklenen kolonlarla yeniden kuruldu' : 'tablo yeniden kurulamadı — eksik kolonlar kaldı',
                ], 'health-check');
            } catch (Throwable $e) {}
        }
        if ($postFail) {
            $out .= "Özet: " . count($rebuiltTables) . " tablodan " . count($postFail) . " yeniden kurulamadı — " . implode('; ', $postFail) . "\n";
            $errors[] = 'Onarım sonrası doğrulama: ' . count($postFail) . ' tablo hâlâ eksik kolonlarla (' . implode('; ', $postFail) . ')';
        } else {
            $out .= "✓ Onarım sonrası doğrulama başarılı — düşürülen tabloların tümü beklenen kolonlarla yeniden kuruldu.\n";
        }
        // --json: onarım sonrası doğrulama sonucu — her yeniden kurulan tablonun beklenen
        // kolonları karşılayıp karşılamadığı makinece okunabilir (checks.repair.verify).
        $checks['repair']['verify'] = [
            'status' => $postFail === [] ? 'ok' : 'error',
            'rebuilt' => count($rebuiltTables),
            'failed' => $postFail,
            'tables' => array_keys($rebuiltTables),
        ];
    }

    // --- Onarım sonrası özet e-postası ---
    // --repair tam modda (dry-run değil) ve en az bir işlem yapıldıysa admin_alert_email'e
    // özet gider. Migration uygulaması ve 3b doğrulaması BİTTİKTEN sonra gönderilir; böylece
    // düşürülen tabloların gerçekten yeniden kurulup kurulmadığı (✓/✗) e-postaya yansır.
    // İçerik: düşürülen + yeniden kurulan tablolar, DOLU (elle müdahale) tablolar,
    // yetim temizliği, otomatik öneri onayları, yedek, sahiplik devri.
    if (!$dryRun && ($dropped > 0 || $migrated > 0 || $skippedNonEmpty !== [] || (int) ($orphanRes['removed'] ?? 0) > 0 || $staleConfirmNote !== '' || $ownershipTransferred > 0)) {
        try {
            $adminEmail = trim((string) platform_setting('admin_alert_email', ''));
            if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                $rows = '';
                foreach ($repairChanges as $mcTable => $mcInfo) {
                    if (($mcInfo['status'] ?? '') === 'migrated') {
                        $rows .= '<tr><td style="padding:7px 12px;border:1px solid #e1e5de">' . htmlspecialchars((string) $mcTable) . '</td><td style="padding:7px 12px;border:1px solid #e1e5de"><b style="color:#2e7d32">DOLU — otomatik veri taşındı ✓</b></td><td style="padding:7px 12px;border:1px solid #e1e5de;color:#64716d">' . (int) ($mcInfo['transferred'] ?? 0) . ' satır (' . htmlspecialchars(implode(', ', (array) ($mcInfo['to'] ?? []))) . ')</td></tr>';
                    }
                }
                if ($dropped > 0) {
                    foreach ($rebuiltTables as $rt => $rspec) {
                        // 3b doğrulaması: tablo beklenen kolonlarla kuruldu mu? ($postFail listesinde mi?)
                        $rebuiltOk = true;
                        foreach ($postFail as $pf) {
                            if (str_starts_with($pf, $rt . ' (')) { $rebuiltOk = false; break; }
                        }
                        $rows .= '<tr><td style="padding:7px 12px;border:1px solid #e1e5de">' . htmlspecialchars((string) $rt) . '</td><td style="padding:7px 12px;border:1px solid #e1e5de">' . ($rebuiltOk ? '<b style="color:#2e7d32">Düşürüldü + yeniden kuruldu ✓</b>' : '<b style="color:#b0301a">Düşürüldü — yeniden KURULAMADI ✗</b>') . '</td><td style="padding:7px 12px;border:1px solid #e1e5de;color:#64716d">' . htmlspecialchars(implode(', ', $rspec['migs'])) . '</td></tr>';
                    }
                }
                foreach ($skippedNonEmpty as $sn) {
                    $rows .= '<tr><td style="padding:7px 12px;border:1px solid #e1e5de">' . htmlspecialchars((string) $sn) . '</td><td style="padding:7px 12px;border:1px solid #e1e5de;color:#8e2410">DOLU — elle müdahale</td><td style="padding:7px 12px;border:1px solid #e1e5de;color:#64716d">—</td></tr>';
                }
                $orphanN = (int) ($orphanRes['removed'] ?? 0);
                if ($orphanN > 0) {
                    $rows .= '<tr><td style="padding:7px 12px;border:1px solid #e1e5de">Yetim eşleştirme</td><td style="padding:7px 12px;border:1px solid #e1e5de">' . $orphanN . ' satır temizlendi</td><td style="padding:7px 12px;border:1px solid #e1e5de;color:#64716d">oda / fiyat planı / ürün</td></tr>';
                }
                if ($staleConfirmNote !== '') {
                    foreach (explode(';', rtrim($staleConfirmNote, ';')) as $scBit) {
                        if ($scBit === '') continue;
                        [$scT, $scN] = array_pad(explode(':', $scBit), 2, '0');
                        $rows .= '<tr><td style="padding:7px 12px;border:1px solid #e1e5de">' . htmlspecialchars((string) $scT) . '</td><td style="padding:7px 12px;border:1px solid #e1e5de">' . (int) $scN . ' hedefi dolmuş öneri confirmed</td><td style="padding:7px 12px;border:1px solid #e1e5de;color:#64716d">otomatik</td></tr>';
                    }
                }
                if ($backupFile !== null) {
                    $rows .= '<tr><td style="padding:7px 12px;border:1px solid #e1e5de">Şema yedeği</td><td style="padding:7px 12px;border:1px solid #e1e5de">' . count($backedUp) . ' tablo</td><td style="padding:7px 12px;border:1px solid #e1e5de;color:#64716d">' . htmlspecialchars(basename((string) $backupFile)) . '</td></tr>';
                }
                if ($ownershipTransferred > 0) {
                    $rows .= '<tr><td style="padding:7px 12px;border:1px solid #e1e5de">Sahiplik devri</td><td style="padding:7px 12px;border:1px solid #e1e5de">' . $ownershipTransferred . ' nesne</td><td style="padding:7px 12px;border:1px solid #e1e5de;color:#64716d">tablo/dizi/görünüm → ' . htmlspecialchars((string) $curUser) . '</td></tr>';
                }                    $summary = $dropped . ' tablo düşürülüp yeniden kuruldu'
                        . ($migrated > 0 ? ' · ' . $migrated . ' DOLU tabloda veri taşındı' : '')
                        . ($postFail ? ' · ' . count($postFail) . ' KURULAMADI' : '')
                        . ($skippedNonEmpty !== [] ? ' · ' . count($skippedNonEmpty) . ' DOLU tablo elle müdahale bekliyor' : '')
                        . ($orphanN > 0 ? ' · ' . $orphanN . ' yetim temizlendi' : '')
                        . ($staleConfirmNote !== '' ? ' · öneri onayları otomatik tamamlandı' : '')
                        . ($ownershipTransferred > 0 ? ' · ' . $ownershipTransferred . ' nesnenin sahipliği devredildi' : '');
                queue_email($adminEmail,
                    'NEXUS: sağlık onarımı tamamlandı — ' . $summary,
                    '<div style="font-family:Arial,sans-serif;max-width:640px;margin:0 auto">'
                    . '<h2 style="color:#1c2b3a;margin-bottom:6px">✅ Sağlık onarımı tamamlandı</h2>'
                    . '<p style="color:#4b5a68;margin-top:0">' . gmdate('d.m.Y H:i') . ' UTC · <code>health-check --repair</code> tam modda çalıştı, migration uygulaması ve doğrulama bitti.</p>'
                    . '<table style="border-collapse:collapse;width:100%;font-size:13px">'
                    . '<tr><th style="padding:7px 12px;border:1px solid #c9d3cc;background:#eef2ee;text-align:left">Nesne</th><th style="padding:7px 12px;border:1px solid #c9d3cc;background:#eef2ee;text-align:left">İşlem</th><th style="padding:7px 12px;border:1px solid #c9d3cc;background:#eef2ee;text-align:left">Detay</th></tr>'
                    . $rows
                    . '</table>'
                    . '<p style="color:#64716d;font-size:12px;margin-top:14px">Tam konsol çıktısı için sunucuda <code>scripts/health-check.php --repair</code> çalıştırın. Denetim kayıtları: <code>health.repair_drop</code> / <code>health.repair_verify</code> / <code>health.repair_orphan_cleanup</code> / <code>health.repair_stale_confirm</code>.</p>'
                    . '</div>',
                    'health_check', null, null, null);
                $out .= "→ Onarım özeti kuyruğa alındı → " . $adminEmail . "\n";
            }
        } catch (Throwable $e) {
            $out .= "⚠ Onarım özeti e-postası kuyruğa alınamadı: " . $e->getMessage() . "\n";
        }
    }

    // --- 4) Operasyonel uyarılar (son 24 saat) ---
    // Eşikler kontrol merkezinden yönetilir (health_warn_* platform ayarları, kod değişmeden).
    $out .= "\n=== 4) OPERASYONEL UYARILAR (son 24 saat) ===\n";
    $warnCount = 0;
    $warnLogThreshold = max(1, (int) platform_setting('health_warn_error_logs', 20));
    $warnEmailThreshold = max(1, (int) platform_setting('health_warn_email_queue', 50));
    $warnWebhookThreshold = max(1, (int) platform_setting('health_warn_webhook_fail', 10));
    $warnIcalThreshold = max(1, (int) platform_setting('health_warn_ical_fail', 3));
    try {
        $errCount = (int) $pdo->query("SELECT COUNT(*) FROM error_logs WHERE level IN ('error','critical') AND status='new' AND created_at >= now() - interval '24 hours'")->fetchColumn();
        $out .= ($errCount > $warnLogThreshold ? '⚠ ' : '✓ ') . "Hata logu (son 24 saat, error/critical, yeni): " . $errCount . " (eşik " . $warnLogThreshold . ")" . ($errCount > $warnLogThreshold ? ' — yüksek, inceleyin (admin/hata-izleme)' : '') . "\n";
        if ($errCount > $warnLogThreshold) $warnCount++;
    } catch (Throwable $e) {
        $out .= "⚠ error_logs okunamadı: " . $e->getMessage() . "\n";
        $warnCount++;
    }
    try {
        $emailQ = (int) $pdo->query("SELECT COUNT(*) FROM email_outbox WHERE status='queued' AND created_at >= now() - interval '24 hours'")->fetchColumn();
        $out .= ($emailQ > $warnEmailThreshold ? '⚠ ' : '✓ ') . "Bekleyen e-posta (son 24 saat, kuyrukta): " . $emailQ . " (eşik " . $warnEmailThreshold . ")" . ($emailQ > $warnEmailThreshold ? ' — kuyruk birikiyor (cron/process-emails.php)' : '') . "\n";
        if ($emailQ > $warnEmailThreshold) $warnCount++;
    } catch (Throwable $e) {
        $out .= "⚠ email_outbox okunamadı: " . $e->getMessage() . "\n";
        $warnCount++;
    }
    try {
        $failWebhook = (int) $pdo->query("SELECT COUNT(*) FROM channel_sync_logs WHERE direction='pull' AND status='failed' AND created_at >= now() - interval '24 hours'")->fetchColumn();
        $out .= ($failWebhook > $warnWebhookThreshold ? '⚠ ' : '✓ ') . "Başarısız webhook yükü (son 24 saat): " . $failWebhook . " (eşik " . $warnWebhookThreshold . ")" . ($failWebhook > $warnWebhookThreshold ? ' — yüksek başarısızlık oranı, cron/process-channel-webhooks.php + retry-channel-webhooks.php denetleyin' : '') . "\n";
        if ($failWebhook > $warnWebhookThreshold) $warnCount++;
    } catch (Throwable $e) {
        $out .= "⚠ channel_sync_logs okunamadı: " . $e->getMessage() . "\n";
        $warnCount++;
    }
    try {
        $failIcal = (int) $pdo->query("SELECT COUNT(*) FROM ical_sync_logs WHERE status='failed' AND created_at >= now() - interval '24 hours'")->fetchColumn();
        $out .= ($failIcal > $warnIcalThreshold ? '⚠ ' : '✓ ') . "iCal senkron hata (son 24 saat): " . $failIcal . " (eşik " . $warnIcalThreshold . ")" . ($failIcal > $warnIcalThreshold ? ' — tekrarlayan hatalar olabilir, cron/sync-ical-calendars.php + alert-ical-repeat.php denetleyin' : '') . "\n";
        if ($failIcal > $warnIcalThreshold) $warnCount++;
    } catch (Throwable $e) {
        $out .= "⚠ ical_sync_logs okunamadı: " . $e->getMessage() . "\n";
        $warnCount++;
    }
    $checks['operational'] = [
        'status' => $warnCount === 0 ? 'ok' : 'warn',
        'warnings' => $warnCount,
        'error_logs' => (int) ($errCount ?? 0),
        'email_queue' => (int) ($emailQ ?? 0),
        'webhook_fail' => (int) ($failWebhook ?? 0),
        'ical_fail' => (int) ($failIcal ?? 0),
        'thresholds' => [
            'error_logs' => $warnLogThreshold,
            'email_queue' => $warnEmailThreshold,
            'webhook_fail' => $warnWebhookThreshold,
            'ical_fail' => $warnIcalThreshold,
        ],
    ];
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
    $checks['env'] = [
        'status' => strlen((string) ($config['app_encryption_key'] ?? '')) >= 32 && extension_loaded('pdo_pgsql') && extension_loaded('curl') ? 'ok' : 'error',
        'app_encryption_key' => strlen((string) ($config['app_encryption_key'] ?? '')) >= 32,
        'pdo_pgsql' => extension_loaded('pdo_pgsql'),
        'curl' => extension_loaded('curl'),
    ];

    // --- 5b) ZAMANLAYICI CRON DURUMU ---
    $out .= "\n=== 5b) CRON DURUMU ===\n";
    $cronOk = true;
    // 1) tick.php heartbeat
    $crontab = (string) shell_exec("crontab -l 2>/dev/null || true");
    $hasTick = (bool) preg_match("/tick\.php/", $crontab);
    $out .= ($hasTick ? "✓" : "✗") . " tick.php nabzi (crontab)\n";
    if (!$hasTick) { $errors[] = "tick.php nabzi crontab-da yok"; $cronOk = false; }
    // 2) Eski URL tabanli satirlar
    $oldLines = [];
    foreach (explode("\n", $crontab) as $cl) {
        $cl = trim($cl);
        if ($cl === "" || $cl[0] === "#") continue;
        if (preg_match("/Request a URL|wget.*cron|curl.*http|http.*tick/i", $cl)) $oldLines[] = $cl;
    }
    if ($oldLines) {
        $out .= "⚠ " . count($oldLines) . " eski URL tabanli cron satiri:\n";
        foreach (array_slice($oldLines, 0, 5) as $ol) $out .= "   " . htmlspecialchars($ol) . "\n";
        $cronOk = false;
    } else {
        $out .= "✓ Eski URL tabanli cron satiri yok\n";
    }
    // 3) scheduled_jobs sayisi
    try {
        $jobCount = (int) db()->query("SELECT COUNT(*) FROM scheduled_jobs")->fetchColumn();
        $out .= ($jobCount >= 20 ? "✓" : "⚠") . " scheduled_jobs: " . $jobCount . " gorev\n";
        if ($jobCount < 20) { $out .= "   ⚠ " . (20 - $jobCount) . " gorev eksik\n"; }
    } catch (Throwable $e) { $out .= "✗ scheduled_jobs tablosu okunamadi\n"; $cronOk = false; }
    // 4) Son tick calismasi
    try {
        $recentTicks = (int) db()->query("SELECT COUNT(*) FROM scheduled_job_runs WHERE started_at >= now()-interval '5 minutes'")->fetchColumn();
        $out .= ($recentTicks > 0 ? "✓" : "⚠") . " Son 5 dkda " . $recentTicks . " gorev baslatildi\n";
        if ($recentTicks === 0) $out .= "   ⚠ tick.php son 5 dkda calismamis olabilir\n";
    } catch (Throwable $e) { $out .= "   (scheduled_job_runs yok)\n"; }
    $checks["cron"] = ["status" => $cronOk ? "ok" : "warning", "has_tick" => $hasTick, "old_lines" => count($oldLines), "job_count" => $jobCount ?? 0];

    $out .= "\n";
    if ($errors) {
        $out .= 'SONUÇ: ' . count($errors) . ' sorun — ' . implode('; ', array_slice($errors, 0, 10)) . "\n";
    } else {
        $out .= 'SONUÇ: Tüm kontroller başarılı — ' . count($requiredTables) . " tablo, kritik kolonlar ve " . count($migrationFiles) . " migration hazır.\n";
    }

    return ['ok' => $errors === [], 'output' => $out, 'errors' => $errors, 'checks' => $checks, 'ran_at' => gmdate('c')];
}
