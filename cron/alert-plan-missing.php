<?php
declare(strict_types=1);

// Planı eksik eşleştirme uyarıları — channel_room_mappings'te onaylı (confirmed) ama:
//  1) rate_plan_id'li plan silinmiş veya pasifleştirilmiş (rp.status <> 'active'), veya
//  2) rate_plan_id NULL ve üründe birden çok aktif plan var (webhook ilk aktif plana yazar —
//     yanlış plana yazma riski)
// tedarikçiye panel bildirimi + admin_alert_email'e e-posta gönderir.
// Koşullar haftalık dağıtım sağlık özetindeki sorguyla birebir aynıdır; bu görev 15 dakikada
// bir tarar ve aynı tedarikçiye 24 saatte bir kez bildirim üretir (notifications tipi
// plan_missing — süreklilik arz eden sorun spam yapmaz, çözülünce durur).
//
// Zamanlayıcı: nexus-plan-missing-alerts (varsayılan: 15 dakikada bir).

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/notifications.php';

$pdo = db();
$adminEmail = trim((string) platform_setting('admin_alert_email', ''));

try {
    $rows = $pdo->query("
        SELECT m.id, m.external_room_id, m.room_type_id, m.property_id, m.rate_plan_id,
               c.id AS connection_id, c.supplier_id, c.display_name AS conn_name,
               p.name AS property_name, s.company_name,
               rt.name AS room_name, rp.name AS plan_name, rp.status AS plan_status
        FROM channel_room_mappings m
        JOIN channel_connections c ON c.id=m.channel_connection_id
        JOIN properties p ON p.id=m.property_id
        JOIN suppliers s ON s.id=c.supplier_id
        LEFT JOIN room_types rt ON rt.id=m.room_type_id
        LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id
        WHERE m.status='confirmed'
          AND (
            (m.rate_plan_id IS NOT NULL AND rp.status<>'active')
            OR (m.rate_plan_id IS NULL AND (SELECT COUNT(*) FROM rate_plans ap WHERE ap.property_id=m.property_id AND ap.status='active') > 1)
          )
        ORDER BY c.supplier_id, c.display_name, m.external_room_id
    ")->fetchAll();
} catch (Throwable $e) {
    fwrite(STDERR, 'Planı eksik taraması yapılamadı (eşleştirme/plan tablosu yok veya eski şema): ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if (!$rows) {
    echo "Planı eksik eşleştirme yok.\n";
    exit(0);
}

$bySupplier = [];
foreach ($rows as $r) {
    $bySupplier[(int) $r['supplier_id']][] = $r;
}

// 24 saat içinde aynı tedarikçiye plan_missing bildirimi gittiyse atla (gürültü yok).
$recentCheck = $pdo->prepare("SELECT id FROM notifications WHERE user_type='supplier' AND user_id IN (SELECT id FROM supplier_users WHERE supplier_id=?) AND type='plan_missing' AND created_at > now() - interval '24 hours' LIMIT 1");

$notified = 0;
$emailed = 0;

foreach ($bySupplier as $supplierId => $list) {
    $recentCheck->execute([$supplierId]);
    if ($recentCheck->fetch()) {
        echo "[atlandı] tedarikçi #" . $supplierId . ' — son 24 saatte plan_missing bildirimi gitti.' . "\n";
        continue;
    }

    // Panel bildirimi: tüm sorunlu eşleştirmeler tek mesajda listelenir.
    $lines = [];
    foreach ($list as $r) {
        if ($r['rate_plan_id'] !== null && $r['rate_plan_id'] !== '') {
            $lines[] = '· "' . $r['external_room_id'] . '" → ' . $r['room_name'] . ' — plan "' . $r['plan_name'] . '" (' . $r['plan_status'] . ')';
        } else {
            $lines[] = '· "' . $r['external_room_id'] . '" → ' . $r['room_name'] . ' — planı silinmiş';
        }
    }
    $msg = 'Dağıtım uyarısı: ' . count($list) . ' oda eşleştirmesinin fiyat planı eksik/pasif — webhook bu eşleştirmeleri ilk aktif plana yazabilir; birden çok aktif planı olan üründe yanlış plana yazma riski doğar. Lütfen bölüm 3\'te eşleştirmelerin planını güncelleyin. ' . implode(' ', $lines);
    notify_supplier_users($supplierId, 'plan_missing', mb_substr($msg, 0, 500), '/nexustraveltech/tedarikci/dagitim-merkezi#sec-room-map');
    $notified++;

    if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $rowsHtml = '';
        foreach ($list as $r) {
            $planTxt = $r['rate_plan_id'] !== null && $r['rate_plan_id'] !== '' ? htmlspecialchars((string) $r['plan_name']) . ' (' . htmlspecialchars((string) $r['plan_status']) . ')' : '<b style="color:#b0301a">silinmiş</b>';
            $rowsHtml .= '<tr>'
                . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de;color:#64716d">' . htmlspecialchars((string) $r['conn_name']) . '</td>'
                . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de"><b>' . htmlspecialchars((string) $r['external_room_id']) . '</b></td>'
                . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de">' . htmlspecialchars((string) $r['room_name']) . '</td>'
                . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de">' . $planTxt . '</td>'
                . '</tr>';
        }
        $body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
            . '<h2 style="margin:0 0 6px">⚠ ' . count($list) . ' oda eşleştirmesinin planı eksik</h2>'
            . '<p style="color:#64716d;margin:0 0 10px">Tedarikçi <b>' . htmlspecialchars((string) $list[0]['company_name']) . '</b> (' . count($list) . ' eşleştirme) — webhook bu kodları ilk aktif plana yazabilir; birden çok aktif planı olan üründe yanlış plana yazma riski vardır. Düzeltme: dağıtım merkezi → bölüm 3 → eşleştirmenin planını güncelleyin.</p>'
            . '<table style="border-collapse:collapse;width:100%;max-width:640px"><tr>'
            . '<th style="padding:7px 12px;border-bottom:2px solid #d8ded8;text-align:left">Kanal</th>'
            . '<th style="padding:7px 12px;border-bottom:2px solid #d8ded8;text-align:left">Dış kod</th>'
            . '<th style="padding:7px 12px;border-bottom:2px solid #d8ded8;text-align:left">Oda tipi</th>'
            . '<th style="padding:7px 12px;border-bottom:2px solid #d8ded8;text-align:left">Plan durumu</th></tr>'
            . $rowsHtml . '</table>'
            . '<p style="margin-top:18px"><a href="https://nexustraveltech.com/tedarikci/dagitim-merkezi#sec-room-map" style="color:#4a6d8c">Dağıtım merkezi → bölüm 3</a></p>'
            . '</div>';
        queue_email($adminEmail, '⚠ ' . count($list) . ' oda eşleştirmesinin planı eksik — ' . htmlspecialchars((string) $list[0]['company_name']), $body, 'plan_missing', (int) $list[0]['property_id']);
        $emailed++;
    }

    echo 'Planı eksik uyarısı eklendi: tedarikçi #' . $supplierId . ' (' . count($list) . ' eşleştirme).' . "\n";
}

echo 'Özet: ' . $notified . ' tedarikçi bildirildi, ' . $emailed . ' admin e-postası kuyruğa eklendi (' . count($rows) . ' eşleştirme).' . "\n";
