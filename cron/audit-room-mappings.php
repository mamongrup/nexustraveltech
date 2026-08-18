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

// 3 tablonun tarama tanımı — birbirine paralel.
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
    // property
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
        $body .= '<h3 style="margin:16px 0 6px;font-size:13px">' . htmlspecialchars($spec['label']) . ' — ' . count($rows) . ' yetim</h3>';
        $body .= '<table style="border-collapse:collapse;width:100%;max-width:640px;font-size:13px">';
        $body .= '<tr><th style="text-align:left;padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1">ID</th>'
            . '<th style="text-align:left;padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1">Dış kod</th>'
            . '<th style="text-align:left;padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1">Kanal</th>'
            . '<th style="text-align:left;padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1">Sorun</th></tr>';
        foreach ($rows as $o) {
            $body .= '<tr>'
                . '<td style="padding:7px 12px;border:1px solid #e1e5de">#' . (int) $o['id'] . '</td>'
                . '<td style="padding:7px 12px;border:1px solid #e1e5de"><code>' . htmlspecialchars((string) $o['code']) . '</code></td>'
                . '<td style="padding:7px 12px;border:1px solid #e1e5de">' . htmlspecialchars((string) ($o['channel_name'] ?? '—')) . '</td>'
                . '<td style="padding:7px 12px;border:1px solid #e1e5de;color:#8e2410">' . htmlspecialchars(orphanReason($o, $spec['reasonFn'])) . '</td>'
                . '</tr>';
        }
        $body .= '</table>';
    }

    $body .= '<p style="margin-top:18px;font-size:12px;color:#64716d">Tek tablo denetimi: <code>php scripts/verify-platform.php</code> · Onarım: <code>php scripts/health-check.php --repair</code></p>';
    $body .= '</div>';

    queue_email($adminEmail, $subject, $body, 'room_mapping_audit');
    echo "Admin e-postası kuyruğa eklendi.\n";
} else {
    echo "admin_alert_email tanımsız — e-posta atlanıyor.\n";
}

exit($totalOrphans > 0 ? 1 : 0);
