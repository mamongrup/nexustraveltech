<?php
declare(strict_types=1);

// Günlük oda eşleştirme tutarlılık denetimi.
//
// channel_room_mappings'teki yetim/uyumsuz satırları tarar (oda tipi/kanal/plan silinmiş
// veya başka ürüne ait). Sorun varsa admin_alert_email'e tablolu özet e-postası gider;
// temizse yalnızca konsol çıktısı. room_type_id=0'lı onay bekleyen öneriler yetim sayılmaz.
//
// Zamanlayıcı: nexus-room-mapping-audit (varsayılan: her gün 05:30).

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/platform_settings.php';

$pdo = db();
$adminEmail = trim((string) platform_setting('admin_alert_email', ''));

try {
    $mappingCount = (int) $pdo->query('SELECT COUNT(*) FROM channel_room_mappings')->fetchColumn();
} catch (Throwable $e) {
    echo 'channel_room_mappings okunamadı: ' . $e->getMessage() . "\n";
    exit(0); // tablo henüz yoksa görev sessizce biter (health-check kurar)
}

$orphanSql = "SELECT m.id, m.external_room_id, m.room_type_id, m.property_id, m.channel_connection_id, m.status,
        rt.name AS room_name, rt.property_id AS room_property, c.display_name AS channel_name
     FROM channel_room_mappings m
     LEFT JOIN room_types rt ON rt.id=m.room_type_id
     LEFT JOIN channel_connections c ON c.id=m.channel_connection_id
     LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id
     WHERE m.room_type_id>0
       AND (rt.id IS NULL OR c.id IS NULL OR rt.property_id<>m.property_id
            OR (m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)))
     ORDER BY m.id";
$orphans = $pdo->query($orphanSql)->fetchAll();

if (!$orphans) {
    echo 'Oda eşleştirme denetimi temiz: ' . $mappingCount . ' kayıt, 0 yetim/uyumsuz.' . "\n";
    exit(0);
}

echo count($orphans) . " yetim/uyumsuz eşleştirme:\n";
foreach ($orphans as $o) {
    $why = $o['room_name'] === null ? 'oda tipi silinmiş' : ($o['channel_name'] === null ? 'kanal silinmiş' : (((int) $o['room_property'] !== (int) $o['property_id']) ? 'oda tipi başka ürüne ait' : 'fiyat planı silinmiş/başka ürüne ait'));
    echo '  #' . (int) $o['id'] . ' ' . htmlspecialchars((string) $o['external_room_id']) . ' — ' . $why . "\n";
}

if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    $rowsHtml = '';
    foreach ($orphans as $o) {
        $why = $o['room_name'] === null ? 'oda tipi silinmiş' : ($o['channel_name'] === null ? 'kanal silinmiş' : (((int) $o['room_property'] !== (int) $o['property_id']) ? 'oda tipi başka ürüne ait' : 'fiyat planı silinmiş/başka ürüne ait'));
        $rowsHtml .= '<tr>'
            . '<td style="padding:7px 12px;border:1px solid #e1e5de">#' . (int) $o['id'] . '</td>'
            . '<td style="padding:7px 12px;border:1px solid #e1e5de"><code>' . htmlspecialchars((string) $o['external_room_id']) . '</code></td>'
            . '<td style="padding:7px 12px;border:1px solid #e1e5de">' . htmlspecialchars((string) ($o['channel_name'] ?? '—')) . '</td>'
            . '<td style="padding:7px 12px;border:1px solid #e1e5de;color:#8e2410">' . htmlspecialchars($why) . '</td>'
            . '</tr>';
    }
    $body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
        . '<h2 style="margin:0 0 6px">⚠ Oda eşleştirme tutarlılığı: ' . count($orphans) . ' yetim/uyumsuz kayıt</h2>'
        . '<p style="color:#64716d;margin:0 0 10px">Bu satırlar silinmiş/başka ürüne ait bir oda tipi, kanal veya fiyat planına işaret ediyor; webhook yazımı bu kodlarda başarısız olabilir. Temizlik için: <code>scripts/health-check.php --repair</code> (yalnızca yetimleri siler).</p>'
        . '<table style="border-collapse:collapse;width:100%;max-width:640px;font-size:13px">'
        . '<tr><th style="text-align:left;padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1">ID</th>'
        . '<th style="text-align:left;padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1">Dış kod</th>'
        . '<th style="text-align:left;padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1">Kanal</th>'
        . '<th style="text-align:left;padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1">Sorun</th></tr>'
        . $rowsHtml
        . '</table>'
        . '</div>';
    queue_email($adminEmail, '⚠ Oda eşleştirme: ' . count($orphans) . ' yetim/uyumsuz kayıt', $body, 'room_mapping_audit');
    echo "Admin e-postası kuyruğa eklendi.\n";
} else {
    echo "admin_alert_email tanımsız — e-posta atlanıyor.\n";
}
exit(count($orphans) > 0 ? 1 : 0);
