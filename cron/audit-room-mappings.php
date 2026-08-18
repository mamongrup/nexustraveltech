<?php
declare(strict_types=1);

// Günlük eşleştirme tutarlılık denetimi — 3 tablo:
//   channel_room_mappings, channel_rate_plan_mappings, channel_property_mappings
//
// Her tabloda silinmiş/uyumsuz satırları tarar; sorun varsa admin_alert_email'e
// tablolu özet e-postası gider; temizse yalnızca konsol çıktısı.
//
// Zamanlayıcı: nexus-room-mapping-audit (varsayılan: her gün 05:30).

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/platform_settings.php';

$pdo = db();
$adminEmail = trim((string) platform_setting('admin_alert_email', ''));

// 3 tablonun tarama tanımı.
$tableSpecs = [
    'channel_room_mappings' => [
        'label'       => 'Oda eşleştirmesi',
        'codeCol'     => 'external_room_id',
        'join'        => 'LEFT JOIN room_types rt ON rt.id=m.room_type_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id LEFT JOIN properties p ON p.id=m.property_id',
        'where'       => "m.room_type_id>0 AND (rt.id IS NULL OR c.id IS NULL OR rt.property_id<>m.property_id OR (m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)))",
        'selectExtra' => ', rt.name AS room_name, rt.property_id AS room_property, c.display_name AS channel_name, p.name AS property_name',
        'reasonFn'    => 'room',
    ],
    'channel_rate_plan_mappings' => [
        'label'       => 'Fiyat planı eşleştirmesi',
        'codeCol'     => 'external_rate_plan_id',
        'join'        => 'LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id LEFT JOIN properties p ON p.id=m.property_id',
        'where'       => '(m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)) OR c.id IS NULL',
        'selectExtra' => ', rp.name AS plan_name, rp.property_id AS plan_property, c.display_name AS channel_name, p.name AS property_name',
        'reasonFn'    => 'plan',
    ],
    'channel_property_mappings' => [
        'label'       => 'Ürün eşleştirmesi',
        'codeCol'     => 'external_property_id',
        'join'        => 'LEFT JOIN properties p ON p.id=m.property_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id',
        'where'       => 'p.id IS NULL OR c.id IS NULL',
        'selectExtra' => ', c.display_name AS channel_name, p.name AS property_name',
        'reasonFn'    => 'property',
    ],
];

function orphanReason(array $o, string $fn): string {
    if ($fn === 'room') {
        if ($o['room_name'] === null) return 'oda tipi silinmiş';
        if ($o['channel_name'] === null) return 'kanal silinmiş';
        if ((int) ($o['room_property'] ?? 0) !== (int) $o['property_id']) return 'oda tipi başka ürüne ait';
        return 'fiyat planı silinmiş/başka ürüne ait';
    }
    if ($fn === 'plan') {
        if ($o['plan_name'] === null && $o['rate_plan_id'] !== null) return 'fiyat planı silinmiş';
        if ((int) ($o['plan_property'] ?? 0) !== (int) $o['property_id']) return 'fiyat planı başka ürüne ait';
        if ($o['channel_name'] === null) return 'kanal silinmiş';
        return 'uyumsuz';
    }
    if ($o['property_name'] === null) return 'ürün silinmiş';
    if ($o['channel_name'] === null) return 'kanal silinmiş';
    return 'uyumsuz';
}

$allOrphans = [];
$allCounts = [];

foreach ($tableSpecs as $tbl => $spec) {
    $exists = (bool) $pdo->query("SELECT 1 FROM pg_tables WHERE schemaname='public' AND tablename='" . $tbl . "'")->fetchColumn();
    if (!$exists) { $allCounts[$tbl] = 0; continue; }
    try {
        $cnt = (int) $pdo->query("SELECT COUNT(*) FROM $tbl")->fetchColumn();
        $allCounts[$tbl] = $cnt;
        $orphanSql = "SELECT m.id, m.{$spec['codeCol']} AS code, m.status, m.channel_connection_id, m.property_id"
            . $spec['selectExtra']
            . " FROM $tbl m " . $spec['join'] . " WHERE " . $spec['where'] . " ORDER BY m.id";
        $rows = $pdo->query($orphanSql)->fetchAll();
        if ($rows) {
            foreach ($rows as $r) $r['_table'] = $tbl;
            $allOrphans[$tbl] = $rows;
        }
    } catch (Throwable $e) {
        echo "✗ $tbl taraması başarısız: " . $e->getMessage() . "\n";
    }
}

$totalOrphans = array_sum(array_map('count', $allOrphans));
if ($totalOrphans === 0) {
    $parts = [];
    foreach ($allCounts as $t => $c) $parts[] = $c . ' ' . str_replace('channel_', '', $t);
    echo "Eşleştirme denetimi temiz: " . implode(', ', $parts) . ", 0 yetim.\n";
    exit(0);
}

// Konsol çıktısı
foreach ($allOrphans as $tbl => $rows) {
    $spec = $tableSpecs[$tbl];
    echo count($rows) . ' yetim ' . $spec['label'] . " (" . $allCounts[$tbl] . " toplam):\n";
    foreach ($rows as $o) {
        echo '  #' . (int) $o['id'] . ' ' . htmlspecialchars((string) $o['code']) . ' — ' . orphanReason($o, $spec['reasonFn']) . "\n";
    }
}

// Admin e-postası
if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    $summaryParts = [];
    foreach ($allOrphans as $tbl => $rows) {
        $summaryParts[] = count($rows) . ' ' . $tableSpecs[$tbl]['label'];
    }
    $subject = '⚠ Eşleştirme denetimi: ' . $totalOrphans . ' yetim (' . implode(', ', $summaryParts) . ')';

    $body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
        . '<h2 style="margin:0 0 6px">⚠ Eşleştirme tutarlılığı: ' . $totalOrphans . ' yetim/uyumsuz kayıt</h2>'
        . '<p style="color:#64716d;margin:0 0 10px">Bu satırlar silinmiş/başka ürüne ait bir oda tipi, fiyat planı, ürün veya kanala işaret ediyor; webhook yazımı bu kodlarda başarısız olabilir. Temizlik: <code>scripts/health-check.php --repair</code></p>';

    foreach ($allOrphans as $tbl => $rows) {
        $spec = $tableSpecs[$tbl];
        // Kanal/ürün bazında grupla — dağıtım merkezi bağlantısı için.
        $groups = [];
        foreach ($rows as $o) {
            $key = (int) $o['channel_connection_id'] . '|' . (int) $o['property_id'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'channel'    => trim((string) ($o['channel_name'] ?? '')) !== '' ? (string) $o['channel_name'] : ('#kanal ' . (int) $o['channel_connection_id']),
                    'property'   => trim((string) ($o['property_name'] ?? '')) !== '' ? (string) $o['property_name'] : ('#ürün ' . (int) $o['property_id']),
                    'conn_id'    => (int) $o['channel_connection_id'],
                    'prop_id'    => (int) $o['property_id'],
                    'count'      => 0,
                    'codes'      => [],
                ];
            }
            $groups[$key]['count']++;
            $groups[$key]['codes'][] = htmlspecialchars((string) $o['code']) . ' — ' . htmlspecialchars(orphanReason($o, $spec['reasonFn']));
        }
        uasort($groups, fn($a, $b) => $b['count'] - $a['count']);

        $body .= '<h3 style="margin:16px 0 6px;font-size:13px">' . htmlspecialchars($spec['label']) . ' — ' . count($rows) . ' yetim (' . count($groups) . ' kanal/ürün)</h3>';

        // Kanal/ürün grupları — her biri dağıtım merkezi bağlantısıyla.
        foreach ($groups as $g) {
            $distUrl = 'https://nexustraveltech.com/tedarikci/dagitim-merkezi?connection_id=' . $g['conn_id'] . '&property_id=' . $g['prop_id'] . '#sec-room-map';
            $body .= '<div style="margin:8px 0;padding:8px 12px;background:#fdf9f2;border:1px solid #e0c9a3;border-radius:6px">';
            $body .= '<b style="font-size:12px"><a href="' . $distUrl . '" style="color:#405b13;text-decoration:none">' . htmlspecialchars($g['channel']) . ' → ' . htmlspecialchars($g['property']) . '</a></b>';
            $body .= ' — <span style="color:#b0301a;font-weight:bold">' . $g['count'] . ' yetim</span>';
            $body .= ' <a href="' . $distUrl . '" style="font-size:11px;color:#405b13;text-decoration:underline;margin-left:6px">dağıtım merkezi →</a>';
            $body .= '<ul style="margin:4px 0 0;padding-left:18px;font-size:12px;color:#64716d">';
            foreach (array_slice($g['codes'], 0, 5) as $c) {
                $body .= '<li><code>' . $c . '</code></li>';
            }
            if (count($g['codes']) > 5) {
                $body .= '<li style="color:#999">… +' . (count($g['codes']) - 5) . ' daha</li>';
            }
            $body .= '</ul></div>';
        }
    }

    $body .= '<p style="margin-top:18px;font-size:12px;color:#64716d">Tek tablo denetimi: <code>php scripts/verify-platform.php</code> · Onarım: <code>php scripts/health-check.php --repair</code></p>';
    $body .= '</div>';

    queue_email($adminEmail, $subject, $body, 'room_mapping_audit');
    echo "Admin e-postası kuyruğa eklendi.\n";
} else {
    echo "admin_alert_email tanımsız — e-posta atlanıyor.\n";
}

exit($totalOrphans > 0 ? 1 : 0);
