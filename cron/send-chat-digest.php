<?php
declare(strict_types=1);

/**
 * Günlük ziyaretçi soru özeti — son 24 saatte en çok sorulan 5 soruyu
 * e-posta ile yönetime gönderir (günde bir kez, idempotent).
 *
 * Zamanlayıcı: nexus-chat-digest (varsayılan 45 8 * * *) — admin → Zamanlayıcılar.
 * Alıcı: platform ayarı admin_alert_email (admin → Kontrol merkezi).
 * E-posta email_outbox kuyruğuna eklenir; gönderim nexus-process-emails yapar.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/chat_topics.php';

$to = trim((string) platform_setting('admin_alert_email', ''));
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Ziyaretçi soru özeti gönderilmedi: admin_alert_email tanımlı değil (admin → Kontrol merkezi).\n");
    exit(0);
}

$dateKey = (int) date('Ymd');

// İdempotent: bugün için özet zaten kuyrukta mı?
$exists = db()->prepare("SELECT COUNT(*) FROM email_outbox WHERE related_type='chat_digest' AND related_id=?");
$exists->execute([$dateKey]);
if ((int) $exists->fetchColumn() > 0) {
    echo "Bugün için özet zaten kuyrukta; atlandı.\n";
    exit(0);
}

$since = date('Y-m-d H:i:s', time() - 86400);

// Kalitesiz girdiler (eşikler admin → Kontrol merkezi'nde) özetten çıkarılır.
$minLen = max(1, (int) platform_setting('chat_min_length', 5));
$requireSpace = (bool) platform_setting('chat_require_space', true);
$quality = 'CHAR_LENGTH(BTRIM(user_message)) >= ' . $minLen . ($requireSpace ? " AND POSITION(' ' IN BTRIM(user_message)) > 0" : '');

$topQ = db()->prepare(
    "SELECT LOWER(TRIM(user_message)) q, COUNT(*) c
       FROM public_chat_messages
      WHERE created_at>=? AND $quality
      GROUP BY 1 ORDER BY c DESC, MAX(created_at) DESC LIMIT 5"
);
$topQ->execute([$since]);
$top = $topQ->fetchAll();

$totalQ = db()->prepare("SELECT COUNT(*) FROM public_chat_messages WHERE created_at>=? AND $quality");
$totalQ->execute([$since]);
$total = (int) $totalQ->fetchColumn();

$ipQ = db()->prepare("SELECT COUNT(DISTINCT ip) FROM public_chat_messages WHERE created_at>=? AND $quality");
$ipQ->execute([$since]);
$ips = (int) $ipQ->fetchColumn();

// Konu dağılımı: son 24 saat + son 7 gün ile önceki 7 günün karşılaştırması.
$nowStr = date('Y-m-d H:i:s');
$prev7End = date('Y-m-d H:i:s', time() - 7 * 86400);
$prev7Start = date('Y-m-d H:i:s', time() - 14 * 86400);
$topicCountsFor = function (string $start, string $end) use ($quality): array {
    $counts = array_fill_keys(array_keys(chat_topic_defs()), 0);
    $q = db()->prepare("SELECT user_message FROM public_chat_messages WHERE created_at>=? AND created_at<? AND $quality LIMIT 20000");
    $q->execute([$start, $end]);
    foreach ($q->fetchAll() as $r) {
        foreach (chat_classify((string) $r['user_message']) as $t) {
            $counts[$t]++;
        }
    }
    return $counts;
};
$topic24h = $topicCountsFor($since, $nowStr);
$topic7 = $topicCountsFor($prev7End, $nowStr);
$topicPrev7 = $topicCountsFor($prev7Start, $prev7End);
$topicOrder = array_keys($topic7);
usort($topicOrder, fn($a, $b) => ($topic7[$b] <=> $topic7[$a]) ?: ($topic24h[$b] <=> $topic24h[$a]));
$topicRows = '';
foreach (array_slice($topicOrder, 0, 5) as $t) {
    $c24 = $topic24h[$t];
    $c7 = $topic7[$t];
    $cP = $topicPrev7[$t];
    if ($c7 === 0 && $c24 === 0) continue;
    if ($cP > 0) {
        $delta = (int) round(($c7 - $cP) / $cP * 100);
        $deltaTxt = ($delta >= 0 ? '▲ %' : '▼ %') . abs($delta);
        $deltaColor = $delta >= 0 ? '#0d7a4a' : '#b0301a';
    } elseif ($c7 > 0) {
        $deltaTxt = 'yeni';
        $deltaColor = '#0d7a4a';
    } else {
        $deltaTxt = '—';
        $deltaColor = '#64716d';
    }
    $topicRows .= '<tr><td style="padding:7px 12px;border-bottom:1px solid #e1e5de">' . htmlspecialchars($t) . '</td>'
        . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de;text-align:center">' . $c24 . '</td>'
        . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de;text-align:center">' . $c7 . '</td>'
        . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de;text-align:center">' . $cP . '</td>'
        . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de;text-align:center;color:' . $deltaColor . ';font-weight:700">' . $deltaTxt . '</td></tr>';
}

if ($total === 0) {
    echo "Son 24 saatte soru yok; e-posta gönderilmedi.\n";
    exit(0);
}

$rows = '';
foreach ($top as $r) {
    $rows .= '<tr><td style="padding:9px 12px;border-bottom:1px solid #e1e5de">'
        . htmlspecialchars(mb_substr((string) $r['q'], 0, 140))
        . '</td><td style="padding:9px 12px;border-bottom:1px solid #e1e5de;text-align:center"><b>' . (int) $r['c'] . '</b></td></tr>';
}

$body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
    . '<h2 style="margin:0 0 6px">Ziyaretçi soru özeti</h2>'
    . '<p style="color:#64716d;margin:0 0 16px">Son 24 saat · ' . $total . ' soru · ' . $ips . ' farklı IP</p>'
    . '<table style="border-collapse:collapse;width:100%;max-width:560px">'
    . '<tr><th style="text-align:left;padding:8px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">En çok sorulanlar</th>'
    . '<th style="padding:8px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Sayı</th></tr>'        . $rows
        . '</table>'
        . ($topicRows !== '' ? '<h3 style="margin:20px 0 6px">Konu dağılımı (haftalık karşılaştırma)</h3><table style="border-collapse:collapse;width:100%;max-width:640px"><tr><th style="text-align:left;padding:7px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Konu</th><th style="padding:7px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Son 24s</th><th style="padding:7px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Son 7 gün</th><th style="padding:7px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Geçen 7 gün</th><th style="padding:7px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Değişim</th></tr>' . $topicRows . '</table>' : '')
        . '<p style="margin-top:18px"><a href="https://nexustraveltech.com/admin/ziyaretci-sohbet" style="color:#0d7a4a">Tüm kayıtları görüntüle →</a></p>'
        . '</div>';

queue_email($to, 'Ziyaretçi soru özeti — ' . date('d.m.Y'), $body, 'chat_digest', $dateKey);
echo 'Özet kuyruğa eklendi: ' . $to . ' (' . count($top) . " soru, toplam {$total}).\n";
