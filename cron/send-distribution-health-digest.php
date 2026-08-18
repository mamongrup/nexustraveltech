<?php
declare(strict_types=1);

// Dağıtım sağlığı haftalık özeti — iCal (villa/yat) ve kanal (otel) sağlığını tek
// e-postada birleştirir: pasif bağlantılar, 7+/30+ gün eski senkronlar, hiç senkron
// yapılmamış durumlar. Alıcı: admin_alert_email. Haftada bir idempotent
// (platform ayarı distribution_health_week = ISO yıl-hafta).
//
// Gerçek zamanlı uyarılar (nexus-ical-inactive-alerts / nexus-channel-inactive-alerts,
// 15 dakikada bir, tedarikçi panel bildirimi) ayrı çalışmaya devam eder.
//
// Zamanlayıcı: nexus-distribution-health-digest (varsayılan: pazartesi 08:00).

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/pdf.php';

$to = trim((string) platform_setting('admin_alert_email', ''));
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Dağıtım sağlık özeti gönderilmedi: admin_alert_email tanımlı değil (admin → Kontrol merkezi).\n");
    exit(0);
}

$week = date('o-W');
if (platform_setting('distribution_health_week', '') === $week) {
    echo "Bu hafta ($week) dağıtım sağlık özeti zaten kuyruğa eklendi; atlandı.\n";
    exit(0);
}

$pdo = db();

// --- 1) iCal sağlığı: yayındaki villa/yat ilanları ---
$icalRows = $pdo->query("
    SELECT p.id, p.name, p.property_type, p.supplier_id, p.city,
      COALESCE(NULLIF(p.product_details ->> 'home_port', ''), p.city, 'Bilinmeyen konum') AS location,
      s.company_name,
      (SELECT COUNT(*) FROM ical_connections c WHERE c.property_id=p.id) total_con,
      (SELECT COUNT(*) FROM ical_connections c WHERE c.property_id=p.id AND c.status='active') active_con,
      (SELECT MAX(c.last_sync_at) FROM ical_connections c WHERE c.property_id=p.id AND c.status='active') last_sync_at
    FROM properties p JOIN suppliers s ON s.id=p.supplier_id
    WHERE p.status='active' AND p.property_type IN ('villa','yacht')
    ORDER BY s.company_name, p.name
")->fetchAll();

// --- 2) Kanal sağlığı: yayındaki oteli olan tedarikçilerin kanal bağlantıları ---
$channelRows = $pdo->query("
    SELECT s.id supplier_id, s.company_name,
      (SELECT COUNT(*) FROM channel_connections c WHERE c.supplier_id=s.id) total_con,
      (SELECT COUNT(*) FROM channel_connections c WHERE c.supplier_id=s.id AND c.status='active') active_con,
      (SELECT MAX(c.last_sync_at) FROM channel_connections c WHERE c.supplier_id=s.id) last_sync_at,
      (SELECT COUNT(*) FROM properties p WHERE p.supplier_id=s.id AND p.status='active' AND p.property_type='hotel') active_hotels
    FROM suppliers s
    WHERE EXISTS (SELECT 1 FROM properties p WHERE p.supplier_id=s.id AND p.status='active' AND p.property_type='hotel')
    ORDER BY s.company_name
")->fetchAll();

// --- 3) Onay bekleyen eşleştirme önerileri (oda + fiyat planı) — tedarikçi bazında ---
$pendingRows = $pdo->query("
    SELECT s.id, s.company_name,
      (SELECT COUNT(*) FROM channel_room_mappings m JOIN channel_connections c ON c.id=m.channel_connection_id WHERE c.supplier_id=s.id AND m.status='suggested') room_sug,
      (SELECT COUNT(*) FROM channel_rate_plan_mappings p JOIN channel_connections c2 ON c2.id=p.channel_connection_id WHERE c2.supplier_id=s.id AND p.status='suggested') plan_sug
    FROM suppliers s
    WHERE EXISTS (SELECT 1 FROM channel_room_mappings m JOIN channel_connections c ON c.id=m.channel_connection_id WHERE c.supplier_id=s.id AND m.status='suggested')
       OR EXISTS (SELECT 1 FROM channel_rate_plan_mappings p JOIN channel_connections c2 ON c2.id=p.channel_connection_id WHERE c2.supplier_id=s.id AND p.status='suggested')
    ORDER BY s.company_name
")->fetchAll();
$pendingRoom = 0;
$pendingPlan = 0;
foreach ($pendingRows as $pr) {
    $pendingRoom += (int) $pr['room_sug'];
    $pendingPlan += (int) $pr['plan_sug'];
}
$pendingTotal = $pendingRoom + $pendingPlan;

// --- 4) Planı eksik eşleştirmeler: plan silindi (rate_plan_id NULL) veya pasifleştirildi.
// Webhook bu eşleştirmeleri ilk aktif plana yazar; birden çok aktif planı olan üründe
// yanlış plana yazma riski doğar. NULL durum yalnızca birden çok aktif plan varken uyarılır.
$planMissingRows = $pdo->query("
    SELECT m.id, m.external_room_id, m.room_type_id, m.property_id, m.rate_plan_id,
           c.display_name AS conn_name, rt.name AS room_name, rp.name AS plan_name, rp.status AS plan_status
    FROM channel_room_mappings m
    JOIN channel_connections c ON c.id=m.channel_connection_id
    LEFT JOIN room_types rt ON rt.id=m.room_type_id
    LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id
    WHERE m.status='confirmed'
      AND (
        (m.rate_plan_id IS NOT NULL AND rp.status<>'active')
        OR (m.rate_plan_id IS NULL AND (SELECT COUNT(*) FROM rate_plans ap WHERE ap.property_id=m.property_id AND ap.status='active') > 1)
      )
    ORDER BY c.display_name, m.external_room_id
")->fetchAll();

// --- 5) Yetim eşleştirmeler metrik: health-check ile aynı koşullar (3 tablo).
// Geçen haftayla karşılaştırma için tarihçe platform ayarında tutulur (trend).
$orphanRoom = 0; $orphanPlan = 0; $orphanProp = 0;
try {
    if ((bool) $pdo->query("SELECT 1 FROM pg_tables WHERE schemaname='public' AND tablename='channel_room_mappings'")->fetchColumn()) {
        $orphanRoom = (int) $pdo->query("SELECT COUNT(*) FROM channel_room_mappings m LEFT JOIN room_types rt ON rt.id=m.room_type_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id WHERE m.room_type_id>0 AND (rt.id IS NULL OR c.id IS NULL OR rt.property_id<>m.property_id OR (m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)))")->fetchColumn();
    }
    if ((bool) $pdo->query("SELECT 1 FROM pg_tables WHERE schemaname='public' AND tablename='channel_rate_plan_mappings'")->fetchColumn()) {
        $orphanPlan = (int) $pdo->query("SELECT COUNT(*) FROM channel_rate_plan_mappings m LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id WHERE (m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)) OR c.id IS NULL")->fetchColumn();
    }
    if ((bool) $pdo->query("SELECT 1 FROM pg_tables WHERE schemaname='public' AND tablename='channel_property_mappings'")->fetchColumn()) {
        $orphanProp = (int) $pdo->query("SELECT COUNT(*) FROM channel_property_mappings m LEFT JOIN properties p ON p.id=m.property_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id WHERE p.id IS NULL OR c.id IS NULL")->fetchColumn();
    }
} catch (Throwable $e) {
    $orphanRoom = 0; $orphanPlan = 0; $orphanProp = 0;
}
$orphanTotal = $orphanRoom + $orphanPlan + $orphanProp;

// Tarihçe: hafta → toplam yetim. Geçen haftayla delta e-postada trend olarak gösterilir.
$orphanHistory = platform_setting('distribution_health_orphan_history', []);
if (!is_array($orphanHistory)) $orphanHistory = [];
$prevWeek = date('o-W', time() - 7 * 86400);
$orphanPrev = array_key_exists($prevWeek, $orphanHistory) ? (int) $orphanHistory[$prevWeek] : null;
$orphanHistory[$week] = $orphanTotal;
if (count($orphanHistory) > 26) $orphanHistory = array_slice($orphanHistory, -26, null, true);
save_platform_setting('distribution_health_orphan_history', $orphanHistory);

// --- 6) Otomatik duraklatılmış iCal bağlantıları: alert-ical-repeat tarafından auto_pause ile durdurulan veya hata durumundaki.
$pausedIcalRows = [];
$errorIcalRows = [];
try {
    $pausedIcalRows = $pdo->query("
        SELECT c.id, c.label, c.last_error, c.last_sync_at, c.created_at,
               p.id AS property_id, p.name AS property_name, p.property_type,
               s.company_name, s.id AS supplier_id
        FROM ical_connections c
        JOIN properties p ON p.id=c.property_id
        JOIN suppliers s ON s.id=p.supplier_id
        WHERE c.status='paused' AND c.direction='import'
        ORDER BY s.company_name, p.name, c.created_at
    ")->fetchAll();
    $errorIcalRows = $pdo->query("
        SELECT c.id, c.label, c.last_error, c.last_sync_at, c.created_at,
               p.id AS property_id, p.name AS property_name, p.property_type,
               s.company_name, s.id AS supplier_id
        FROM ical_connections c
        JOIN properties p ON p.id=c.property_id
        JOIN suppliers s ON s.id=p.supplier_id
        WHERE c.status='error' AND c.direction='import'
        ORDER BY s.company_name, p.name, c.created_at
    ")->fetchAll();
} catch (Throwable $e) {
    $pausedIcalRows = [];
    $errorIcalRows = [];
}
$pausedTotal = count($pausedIcalRows);
$errorTotal = count($errorIcalRows);

$problems = [];

// iCal satırları: yalnızca sorunlu olanları topla.
foreach ($icalRows as $r) {
    $total = (int) $r['total_con'];
    $active = (int) $r['active_con'];
    $lastSync = $r['last_sync_at'] !== null ? strtotime((string) $r['last_sync_at']) : 0;
    $ageDays = $lastSync > 0 ? (int) floor((time() - $lastSync) / 86400) : null;
    $typeLabel = $r['property_type'] === 'yacht' ? 'Yat' : 'Villa';

    $loc = trim((string) ($r['location'] ?? '')) !== '' ? trim((string) $r['location']) : 'Bilinmeyen konum';
    if ($total > 0 && $active === 0) {
        $problems[] = ['cat' => 'iCal', 'name' => $r['name'] . ' (' . $typeLabel . ')', 'company' => $r['company_name'], 'location' => $loc, 'status' => 'Pasif bağlantı', 'age' => null, 'cls' => '#b0301a'];
    } elseif ($total === 0) {
        $problems[] = ['cat' => 'iCal', 'name' => $r['name'] . ' (' . $typeLabel . ')', 'company' => $r['company_name'], 'location' => $loc, 'status' => 'Bağlantı yok', 'age' => null, 'cls' => '#b0301a'];
    } elseif ($lastSync === 0) {
        $problems[] = ['cat' => 'iCal', 'name' => $r['name'] . ' (' . $typeLabel . ')', 'company' => $r['company_name'], 'location' => $loc, 'status' => 'Kırmızı · hiç senkron yok', 'age' => null, 'cls' => '#b0301a'];
    } elseif ($ageDays >= 30) {
        $problems[] = ['cat' => 'iCal', 'name' => $r['name'] . ' (' . $typeLabel . ')', 'company' => $r['company_name'], 'location' => $loc, 'status' => "Kırmızı · {$ageDays} gün", 'age' => $ageDays, 'cls' => '#b0301a'];
    } elseif ($ageDays >= 7) {
        $problems[] = ['cat' => 'iCal', 'name' => $r['name'] . ' (' . $typeLabel . ')', 'company' => $r['company_name'], 'location' => $loc, 'status' => "Sarı · {$ageDays} gün", 'age' => $ageDays, 'cls' => '#8a6100'];
    }
}

// Kanal satırları: yayındaki oteli olan ve bağlantısı tanımlı ama aktif olmayan tedarikçiler.
foreach ($channelRows as $r) {
    $total = (int) $r['total_con'];
    $active = (int) $r['active_con'];
    if ($total > 0 && $active === 0) {
        $problems[] = ['cat' => 'Kanal', 'name' => $r['company_name'] . ' (dağıtım)', 'company' => $r['company_name'] . ' · ' . $r['active_hotels'] . ' otel yayında', 'status' => 'Pasif bağlantı', 'age' => null, 'cls' => '#b0301a'];
    } elseif ($total === 0) {
        $problems[] = ['cat' => 'Kanal', 'name' => $r['company_name'] . ' (dağıtım)', 'company' => $r['company_name'] . ' · ' . $r['active_hotels'] . ' otel yayında', 'status' => 'Kanal bağlantısı yok', 'age' => null, 'cls' => '#b0301a'];
    } elseif ($r['last_sync_at'] !== null) {
        $lastSync = strtotime((string) $r['last_sync_at']);
        $ageDays = $lastSync > 0 ? (int) floor((time() - $lastSync) / 86400) : null;
        if ($ageDays >= 30) {
            $problems[] = ['cat' => 'Kanal', 'name' => $r['company_name'] . ' (dağıtım)', 'company' => $r['company_name'], 'status' => "Kırmızı · {$ageDays} gün", 'age' => $ageDays, 'cls' => '#b0301a'];
        } elseif ($ageDays >= 7) {
            $problems[] = ['cat' => 'Kanal', 'name' => $r['company_name'] . ' (dağıtım)', 'company' => $r['company_name'], 'status' => "Sarı · {$ageDays} gün", 'age' => $ageDays, 'cls' => '#8a6100'];
        }
    }
}

if ($problems === [] && $pendingTotal === 0 && $planMissingRows === [] && $orphanTotal === 0 && $pausedTotal === 0 && $errorTotal === 0) {
    save_platform_setting('distribution_health_week', $week);
    echo "Sorunlu dağıtım kaydı yok — özet gönderilmedi. (" . count($icalRows) . " villa/yat, " . count($channelRows) . " otel sahibi tedarikçi)\n";
    exit(0);
}

// Sorunları kategori + şiddete göre sırala (kırmızı önce).
usort($problems, function ($a, $b) {
    $o = strcmp($a['cat'], $b['cat']);
    if ($o !== 0) return $o;
    return $a['cls'] === '#b0301a' ? -1 : 1;
});

$icalCount = count(array_filter($problems, fn($p) => $p['cat'] === 'iCal'));
$channelCount = count(array_filter($problems, fn($p) => $p['cat'] === 'Kanal'));

$bodyRows = '';
$pdfRows = '';
foreach ($problems as $p) {
    $lastTxt = $p['age'] !== null ? $p['age'] . ' gün önce' : '—';
    $pdfRows .= '<tr><td>' . htmlspecialchars($p['name']) . '<br><small>' . htmlspecialchars($p['company']) . '</small></td><td>' . htmlspecialchars($p['cat']) . '</td><td>' . htmlspecialchars($p['status']) . '</td><td>' . $lastTxt . '</td></tr>';
    $bodyRows .= '<tr>'
        . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de"><b>' . htmlspecialchars($p['name']) . '</b><br><small style="color:#64716d">' . htmlspecialchars($p['company']) . '</small></td>'
        . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de"><span style="background:#eef1ea;border-radius:4px;padding:2px 7px;font-size:11px">' . htmlspecialchars($p['cat']) . '</span></td>'
        . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de;color:' . $p['cls'] . '"><b>' . htmlspecialchars($p['status']) . '</b></td>'
        . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de">' . $lastTxt . '</td>'
        . '</tr>';
}

// Onay bekleyen öneri satırı: özet e-postasında tedarikçi bazında listelenir.
$pendingHtml = '';
if ($pendingTotal > 0) {
    $pendingHtml .= '<div style="margin-top:14px;padding:10px 14px;background:#fff8e6;border:1px solid #ead9a8;border-radius:8px">'
        . '<h3 style="margin:0 0 4px;font-size:13px;color:#8a6100">⏳ Onay bekleyen eşleştirme önerileri: <b>' . $pendingTotal . '</b> (' . $pendingRoom . ' oda + ' . $pendingPlan . ' fiyat planı)</h3>';
    foreach ($pendingRows as $pr) {
        $prt = (int) $pr['room_sug'] + (int) $pr['plan_sug'];
        $supplierLink = 'https://nexustraveltech.com/admin/tedarikci-ilanlari?supplier_id=' . (int) $pr['id'];
        $pendingHtml .= '<p style="margin:3px 0;font-size:12px;color:#64716d"><a href="' . $supplierLink . '" style="color:#0d7a4a;font-weight:700;text-decoration:none;border-bottom:1px dotted #9cc2ae" title="Tedarikçinin ilanlarını yönetim panelinde görüntüle">' . htmlspecialchars((string) $pr['company_name']) . ' →</a> — <b>' . $prt . '</b> öneri (' . (int) $pr['room_sug'] . ' oda + ' . (int) $pr['plan_sug'] . ' plan)</p>';
    }
    // En çok tekrarlanan eşlenmemiş kodlar — tüm tedarikçilerde, sayaç DESC (üst 8).
    $topCodeRows = [];
    try {
        $topCodeRows = $pdo->query("
            SELECT 'oda' AS kind, m.external_room_id AS code, m.suggestion_count AS cnt, m.suggested_at AS seen, s.company_name
            FROM channel_room_mappings m
            JOIN channel_connections c ON c.id=m.channel_connection_id
            JOIN suppliers s ON s.id=c.supplier_id
            WHERE m.status='suggested' AND m.suggestion_count > 0
            UNION ALL
            SELECT 'plan', p.external_rate_plan_id, p.suggestion_count, p.suggested_at, s2.company_name
            FROM channel_rate_plan_mappings p
            JOIN channel_connections c3 ON c3.id=p.channel_connection_id
            JOIN suppliers s2 ON s2.id=c3.supplier_id
            WHERE p.status='suggested' AND p.suggestion_count > 0
            ORDER BY cnt DESC LIMIT 8
        ")->fetchAll();
    } catch (Throwable $e) {
        $topCodeRows = [];
    }
    if ($topCodeRows) {
        $pendingHtml .= '<div style="margin-top:12px"><b style="font-size:12px;color:#8a6100">🔁 En çok tekrarlanan eşlenmemiş kodlar:</b><table style="border-collapse:collapse;width:100%;max-width:560px;margin-top:6px">'
            . '<tr><th style="text-align:left;padding:5px 8px;background:#fdf3e3;font-size:10px;color:#8a6100">Kod</th><th style="text-align:left;padding:5px 8px;background:#fdf3e3;font-size:10px;color:#8a6100">Tür</th><th style="text-align:center;padding:5px 8px;background:#fdf3e3;font-size:10px;color:#8a6100">Tekrar</th><th style="text-align:left;padding:5px 8px;background:#fdf3e3;font-size:10px;color:#8a6100">Son görülme</th><th style="text-align:left;padding:5px 8px;background:#fdf3e3;font-size:10px;color:#8a6100">Tedarikçi</th></tr>';
        foreach ($topCodeRows as $tc) {
            $pendingHtml .= '<tr><td style="padding:5px 8px;border-bottom:1px solid #ead9a8;font-size:12px"><code>' . htmlspecialchars((string) $tc['code']) . '</code></td>'
                . '<td style="padding:5px 8px;border-bottom:1px solid #ead9a8;font-size:12px">' . htmlspecialchars((string) $tc['kind']) . '</td>'
                . '<td style="padding:5px 8px;border-bottom:1px solid #ead9a8;text-align:center;font-size:12px"><b>' . (int) $tc['cnt'] . '</b></td>'
                . '<td style="padding:5px 8px;border-bottom:1px solid #ead9a8;font-size:12px">' . ($tc['seen'] ? htmlspecialchars(date('d.m H:i', strtotime((string) $tc['seen']))) : '—') . '</td>'
                . '<td style="padding:5px 8px;border-bottom:1px solid #ead9a8;font-size:12px">' . htmlspecialchars((string) $tc['company_name']) . '</td></tr>';
        }
        $pendingHtml .= '</table></div>';
    }
    $pendingHtml .= '<p style="margin:6px 0 0;font-size:11px;color:#8a6100">Öneriler tedarikçi panellerinde Dağıtım & kanal merkezi → bölüm 3\'te onaylanır; onaylanana kadar webhook verisi yazılmaz.</p></div>';
}

// Yetim eşleştirmeler metrik bölümü — trend karşılaştırmalı.
$orphanHtml = '';
if ($orphanTotal > 0) {
    $trendTxt = '';
    if ($orphanPrev !== null) {
        $diff = $orphanTotal - $orphanPrev;
        if ($diff > 0) $trendTxt = ' · <span style="color:#b0301a">▲ +' . $diff . ' vs geçen hafta (' . $orphanPrev . ')</span>';
        elseif ($diff < 0) $trendTxt = ' · <span style="color:#0d7a4a">▼ ' . $diff . ' vs geçen hafta (' . $orphanPrev . ')</span>';
        else $trendTxt = ' · geçen haftayla aynı (' . $orphanPrev . ')';
    } else {
        $trendTxt = ' · ilk kayıt (trend 2. haftadan itibaren)';
    }
    $orphanHtml = '<div style="margin-top:14px;padding:10px 14px;background:#fdecea;border:1px solid #f0c4bc;border-radius:8px">'
        . '<h3 style="margin:0 0 4px;font-size:13px;color:#b0301a">🧹 Yetim eşleştirmeler: <b>' . $orphanTotal . '</b> (' . $orphanRoom . ' oda + ' . $orphanPlan . ' plan + ' . $orphanProp . ' ürün)' . $trendTxt . '</h3>'
        . '<p style="margin:3px 0;font-size:12px;color:#64716d">Silinmiş oda tipi / fiyat planı / kanal / ürüne işaret eden eşleştirmeler — webhook yazımı bu kodlarda başarısız olabilir. Temizlik: sunucuda <code>scripts/health-check.php --repair --yes</code> veya günlük sağlık e-postasındaki tek tıklık onay bağlantısı.</p></div>';
}

// Planı eksik eşleştirmeler bölümü — e-posta gövdesine eklenir.
$planHtml = '';
if ($planMissingRows) {
    $planHtml .= '<div style="margin-top:14px;padding:10px 14px;background:#fdecea;border:1px solid #f0c4bc;border-radius:8px">'
        . '<h3 style="margin:0 0 4px;font-size:13px;color:#b0301a">⚠ Planı eksik eşleştirmeler: <b>' . count($planMissingRows) . '</b></h3>'
        . '<p style="margin:3px 0;font-size:12px;color:#64716d">Planı silinen veya pasifleştirilen eşleştirmeler webhook verisini <b>ilk aktif plana</b> yazar — yanlış plana yazma riski. Dağıtım & kanal merkezi → bölüm 3, her eşleştirmeye plan atayın.</p>';
    $byConn = [];
    foreach ($planMissingRows as $pm) {
        $reason = $pm['rate_plan_id'] !== null
            ? 'plan pasif: ' . (string) ($pm['plan_name'] ?? '')
            : 'plan seçilmemiş (silinmiş olabilir)';
        $byConn[(string) $pm['conn_name']][] = (string) $pm['external_room_id'] . ' → ' . (string) ($pm['room_name'] ?? ('#' . (int) $pm['room_type_id'])) . ' · ' . $reason;
    }
    foreach ($byConn as $conn => $items) {
        $planHtml .= '<p style="margin:5px 0;font-size:12px"><b>' . htmlspecialchars((string) $conn) . '</b> — ' . count($items) . ' eşleştirme<br>'
            . '<span style="color:#8a6d00">' . htmlspecialchars(implode(' · ', array_slice($items, 0, 4))) . (count($items) > 4 ? ' … +' . (count($items) - 4) . ' daha' : '') . '</span></p>';
    }
    $planHtml .= '</div>';
}

// Konum bazlı kırılım: sorunlu iCal ilanlarını şehir/limana göre grupla (en çok sorun üstte).
$locGroups = [];
foreach ($problems as $p) {
    if ($p['cat'] !== 'iCal') continue;
    $loc = $p['location'] ?? 'Bilinmeyen konum';
    $locGroups[$loc][] = $p;
}
uksort($locGroups, fn($a, $b) => count($b) - count($a));
$locHtml = '';
if ($locGroups) {
    $locHtml .= '<h3 style="font-size:13px;margin:18px 0 6px;color:#10211f">📍 Konum bazlı kırılım</h3>';
    foreach ($locGroups as $loc => $locItems) {
        $locHtml .= '<p style="margin:4px 0;font-size:12px"><b>' . htmlspecialchars((string) $loc) . '</b> — ' . count($locItems) . ' sorunlu ilan<br>'
            . '<span style="color:#64716d">' . implode(' · ', array_map(fn($i) => htmlspecialchars((string) $i['name']) . ' (' . htmlspecialchars((string) $i['status']) . ')', array_slice($locItems, 0, 5))) . ($locItems > 5 ? ' … +' . (count($locItems) - 5) . ' daha' : '') . '</span></p>';
    }
}

// Otomatik duraklatılmış iCal bağlantıları bölümü.
$pausedIcalHtml = '';
if ($pausedTotal > 0 || $errorTotal > 0) {
    $pausedIcalHtml .= '<div style="margin-top:14px;padding:10px 14px;background:#fdecea;border:1px solid #f0c4bc;border-radius:8px">'
        . '<h3 style="margin:0 0 4px;font-size:13px;color:#b0301a">⏸ Duraklatılmış iCal bağlantıları: <b>' . ($pausedTotal + $errorTotal) . '</b> (' . $pausedTotal . ' duraklatıldı + ' . $errorTotal . ' hata)</h3>';
    if ($pausedTotal > 0) {
        $pausedIcalHtml .= '<p style="margin:6px 0 0;font-size:12px;color:#64716d">Otomatik duraklatılan bağlantılar (aynı hata tekrar sayısını aştı, <code>alert-ical-repeat</code>tetikledi). Yeniden etkinleştirmek için tedarikçi panelinden iCal takvimler sayfasına gidin.</p>';
        $pausedIcalHtml .= '<table style="border-collapse:collapse;width:100%;max-width:680px;margin-top:8px">';
        $pausedIcalHtml .= '<tr><th style="text-align:left;padding:5px 8px;background:#fdecea;font-size:10px;color:#b0301a">İlan / Tedarikçi</th><th style="text-align:left;padding:5px 8px;background:#fdecea;font-size:10px;color:#b0301a">Bağlantı</th><th style="text-align:left;padding:5px 8px;background:#fdecea;font-size:10px;color:#b0301a">Neden</th><th style="text-align:left;padding:5px 8px;background:#fdecea;font-size:10px;color:#b0301a">Duraklatma tarihi</th></tr>';
        foreach ($pausedIcalRows as $pc) {
            $ageSincePause = $pc['last_sync_at'] !== null ? (int) floor((time() - strtotime((string) $pc['last_sync_at'])) / 86400) : null;
            $ageTxt = $ageSincePause !== null ? $ageSincePause . ' gün' : 'hiç senkron yok';
            $reasonShort = mb_substr(trim((string) ($pc['last_error'] ?? '')), 0, 120);
            $supplierLink = 'https://nexustraveltech.com/admin/tedarikci-ilanlari?supplier_id=' . (int) $pc['supplier_id'];
            $pausedIcalHtml .= '<tr>'
                . '<td style="padding:5px 8px;border-bottom:1px solid #f0c4bc;font-size:12px"><b>' . htmlspecialchars((string) $pc['property_name']) . '</b><br><small style="color:#64716d"><a href="' . $supplierLink . '" style="color:#0d7a4a;text-decoration:none;border-bottom:1px dotted #9cc2ae">' . htmlspecialchars((string) $pc['company_name']) . '</a></small></td>'
                . '<td style="padding:5px 8px;border-bottom:1px solid #f0c4bc;font-size:12px">' . htmlspecialchars((string) $pc['label']) . '</td>'
                . '<td style="padding:5px 8px;border-bottom:1px solid #f0c4bc;font-size:12px;color:#b0301a"><b>' . htmlspecialchars($reasonShort) . '</b></td>'
                . '<td style="padding:5px 8px;border-bottom:1px solid #f0c4bc;font-size:12px">' . $ageTxt . '</td>'
                . '</tr>';
        }
        $pausedIcalHtml .= '</table>';
    }
    if ($errorTotal > 0) {
        $pausedIcalHtml .= '<p style="margin:10px 0 0;font-size:12px;color:#8a6100">⚠ Hata durumundaki bağlantılar: <b>' . $errorTotal . '</b> — henüz otomatik olarak duraklatılmadı ama senkron başarısız. <code>alert-ical-repeat</code>eşiği aşarsa otomatik duraklatılacak.</p>';
        $pausedIcalHtml .= '<ul style="margin:4px 0 0;padding-left:18px;font-size:12px;color:#64716d">';
        foreach ($errorIcalRows as $er) {
            $errShort = mb_substr(trim((string) ($er['last_error'] ?? '')), 0, 80);
            $pausedIcalHtml .= '<li><b>' . htmlspecialchars((string) $er['property_name']) . '</b> — ' . htmlspecialchars((string) $er['company_name']) . ' · ' . htmlspecialchars($errShort) . '</li>';
        }
        $pausedIcalHtml .= '</ul>';
    }
    $pausedIcalHtml .= '<p style="margin:8px 0 0;font-size:11px;color:#b0301a">Tedarikçiler otomatik duraklatma e-postasıyla ayrıca bilgilendirilir. iCal takvimler sayfasından bağlantı yeniden etkinleştirilebilir; hata devam ederse tekrar duraklatılır.</p></div>';
}

$body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
    . '<h2 style="margin:0 0 6px">📡 Dağıtım sağlığı · haftalık özet</h2>'
    . '<p style="color:#64716d;margin:0 0 10px">Bu hafta <b>' . count($problems) . '</b> sorun tespit edildi — iCal <b style="color:#b0301a">' . $icalCount . '</b> · Kanal <b style="color:#b0301a">' . $channelCount . '</b>. (7+ gün eski senkron sarı, 30+ gün veya pasif bağlantı kırmızı.)</p>'
    . '<table style="border-collapse:collapse;width:100%;max-width:680px">'
    . '<tr><th style="text-align:left;padding:7px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">İlan / Tedarikçi</th><th style="text-align:left;padding:7px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Tip</th><th style="text-align:left;padding:7px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Durum</th><th style="text-align:left;padding:7px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Son senkron</th></tr>'
    . $bodyRows
    . '</table>'
    . $locHtml
    . $pendingHtml
    . $planHtml
    . $pausedIcalHtml
    . $orphanHtml
    . '<p style="margin:14px 0 0;font-size:12px;color:#64716d">Gerçek zamanlı uyarılar (15 dk) tedarikçi panellerine ayrıca gider. Kırmızı durumlar için ilgili tedarikçiyle iletişime geçin veya iCal takvimler / Dağıtım & kanal merkezi sayfalarını denetleyin.</p>'
    . '<p style="margin-top:18px"><a href="https://nexustraveltech.com/admin/tedarikci-onaylari" style="color:#0d7a4a">Tedarikçi yönetimi →</a></p>'
    . '</div>';

// PDF eki (TCPDF kuruluysa): e-posta gövdesiyle aynı tablonun yazdırılabilir hali.
$attName = null;
$attBase64 = null;
$pdfLoc = '';
if ($locGroups) {
    $pdfLoc = '<h3>Konum bazlı kırılım</h3>';
    foreach ($locGroups as $loc => $locItems) {
        $pdfLoc .= '<p><b>' . htmlspecialchars((string) $loc) . '</b> — ' . count($locItems) . ' sorunlu ilan: '
            . htmlspecialchars(implode('; ', array_map(fn($i) => $i['name'] . ' (' . $i['status'] . ')', array_slice($locItems, 0, 8))))
            . (count($locItems) > 8 ? ' … +' . (count($locItems) - 8) . ' daha' : '') . '</p>';
    }
}
$pdf = pdf_build('<h2>Dağıtım sağlığı haftalık özeti — ' . date('d.m.Y') . '</h2>'
    . '<p style="color:#64716d">' . count($problems) . ' sorun — iCal ' . $icalCount . ', kanal ' . $channelCount . ($pendingTotal > 0 ? '. Onay bekleyen öneri: ' . $pendingTotal . ' (' . $pendingRoom . ' oda + ' . $pendingPlan . ' plan)' : '') . ($planMissingRows ? '. Planı eksik eşleştirme: ' . count($planMissingRows) : '') . (($pausedTotal + $errorTotal) > 0 ? '. Duraklatılmış iCal: ' . ($pausedTotal + $errorTotal) : '') . ($orphanTotal > 0 ? '. Yetim eşleştirme: ' . $orphanTotal . ($orphanPrev !== null ? ' (geçen hafta ' . $orphanPrev . ')' : '') : '') . '</p>'
    . '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;font-family:Arial,sans-serif;font-size:10px">'
    . '<tr style="background:#f2f4ef"><th align="left">İlan / Tedarikçi</th><th align="left">Tip</th><th align="left">Durum</th><th align="left">Son senkron</th></tr>'
    . $pdfRows
    . '</table>'
    . $pdfLoc);
if ($pdf !== null) {
    $attName = 'dagitim-sagligi-' . date('Y-m-d') . '.pdf';
    $attBase64 = base64_encode($pdf);
}

$subject = 'Dağıtım sağlığı özeti: ' . count($problems) . ' sorun (iCal ' . $icalCount . ' · kanal ' . $channelCount . ')' . ($pendingTotal > 0 ? ' · ⏳ ' . $pendingTotal . ' öneri' : '') . ($planMissingRows ? ' · ⚠ ' . count($planMissingRows) . ' planı eksik' : '') . (($pausedTotal + $errorTotal) > 0 ? ' · ⏸ ' . ($pausedTotal + $errorTotal) . ' iCal duraklatıldı' : '') . ($orphanTotal > 0 ? ' · 🧹 ' . $orphanTotal . ' yetim' : '');
queue_email($to, $subject, $body, 'distribution_health_digest', (int) str_replace('-', '', $week), $attName, $attBase64);
save_platform_setting('distribution_health_week', $week);
echo "Dağıtım sağlık özeti kuyruğa eklendi: " . count($problems) . " sorun (iCal {$icalCount}, kanal {$channelCount}" . ($pendingTotal > 0 ? ", ⏳ {$pendingTotal} onay bekleyen öneri" : '') . ($planMissingRows ? ', ⚠ ' . count($planMissingRows) . ' planı eksik eşleştirme' : '') . (($pausedTotal + $errorTotal) > 0 ? ', ⏸ ' . $pausedTotal . ' duraklatıldı + ' . $errorTotal . ' hata' : '') . ($orphanTotal > 0 ? ', 🧹 ' . $orphanTotal . ' yetim eşleştirme' : '') . ($attName ? ', PDF ekli' : ', PDF yok — TCPDF kurulu değil, HTML gövde gönderildi') . ").\n";
