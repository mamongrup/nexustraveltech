<?php

declare(strict_types=1);

/**
 * Performans denetimi (CLI):
 *  - Büyüyen tabloları yaklaşık satır sayısıyla listeler
 *  - Sık kullanılan sorgu desenleri için eksik indeksleri raporlar
 *  - Kritik yabancı anahtar sütunlarında indeks kontrolü yapar
 *
 * Kullanım: php scripts/audit-performance.php
 */

require_once __DIR__ . '/../config/database.php';

$pdo = db();
$issues = 0;

echo "=== NEXUS performans denetimi ===\n\n";

echo "[1] Büyük tablolar (yaklaşık satır)\n";
$tables = $pdo->query(
    "SELECT relname AS table_name, reltuples::bigint AS approx_rows
     FROM pg_class
     WHERE relkind='r' AND relnamespace=(SELECT oid FROM pg_namespace WHERE nspname='public')
       AND reltuples > 1000
     ORDER BY reltuples DESC LIMIT 25"
)->fetchAll();
foreach ($tables as $t) {
    printf("  %-32s %12d satır\n", $t['table_name'], (int) $t['approx_rows']);
}
if (!$tables) echo "  (1000+ satır tablo yok — küçük veri seti)\n";

echo "\n[2] Önerilen indeksler\n";
$recommended = [
    'supplier_bookings' => ['supplier_id, booking_status', 'property_id, check_in', 'property_id, booking_status'],
    'inventory_calendar' => ['stay_date, rate_plan_id', 'room_type_id, stay_date'],
    'folio_transactions' => ['folio_id, transaction_at'],
    'webhook_deliveries' => ['status, created_at'],
    'notifications' => ['user_type, user_id, is_read, created_at DESC'],
    'email_outbox' => ['status, created_at'],
    'agency_booking_requests' => ['status, created_at DESC', 'supplier_id, status, created_at DESC', 'agency_id, status'],
    'guest_reviews' => ['property_id, status, created_at DESC'],
    'supplier_settlements' => ['supplier_id, status, due_date'],
    'loyalty_ledger' => ['account_id, created_at'],
];
$existing = [];
$idx = $pdo->query(
    "SELECT tablename, indexdef FROM pg_indexes WHERE schemaname='public'"
)->fetchAll();
foreach ($idx as $i) {
    $existing[$i['tablename']][] = $i['indexdef'];
}
foreach ($recommended as $table => $cols) {
    foreach ($cols as $colspec) {
        $col = explode(',', $colspec)[0];
        $found = false;
        foreach ($existing[$table] ?? [] as $def) {
            if (stripos($def, '(' . $col . ',') !== false || stripos($def, '(' . $col . ')') !== false) {
                $found = true;
                break;
            }
        }
        if ($found) {
            echo "  ✓ $table($colspec)\n";
        } else {
            $issues++;
            $idxName = 'idx_' . $table . '_' . str_replace([' ', ',', '-'], '_', $col);
            echo "  ✗ EKSİK  $table($colspec)\n";
            echo "    CREATE INDEX IF NOT EXISTS $idxName ON $table($colspec);\n";
        }
    }
}

echo "\n[3] İndekssiz yabancı anahtar sütunları (büyük tablolarda)\n";
$fks = $pdo->query(
    "SELECT tc.table_name, kcu.column_name
     FROM information_schema.table_constraints tc
     JOIN information_schema.key_column_usage kcu ON tc.constraint_name=kcu.constraint_name
     JOIN pg_class c ON c.relname=tc.table_name
     WHERE tc.constraint_type='FOREIGN KEY' AND c.reltuples > 5000"
)->fetchAll();
foreach ($fks as $f) {
    $isIdx = $pdo->prepare(
        "SELECT 1 FROM pg_indexes
         WHERE schemaname='public' AND tablename=? AND (indexdef ILIKE ? OR indexdef ILIKE ?)
         LIMIT 1"
    );
    $isIdx->execute([$f['table_name'], '%(' . $f['column_name'] . ',%', '%(' . $f['column_name'] . ')%']);
    if (!$isIdx->fetch()) {
        $issues++;
        echo "  ✗ {$f['table_name']}({$f['column_name']}) indekssiz\n";
    }
}
if (!$fks) echo "  (5000+ satır tabloda yabancı anahtar yok)\n";

echo "\nSonuç: " . ($issues === 0 ? "TEMİZ — ek indeks gerekmiyor." : "$issues eksik indeks önerisi.") . "\n";
exit($issues === 0 ? 0 : 1);
