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

// Konu dağılımı: tüm kaliteli sorular ortak sınıflandırıcıyla etiketlenir.
$topicCounts = array_fill_keys(array_keys(chat_topic_defs()), 0);
$allQ = db()->prepare("SELECT user_message FROM public_chat_messages WHERE created_at>=? AND $quality LIMIT 5000");
$allQ->execute([$since]);
foreach ($allQ->fetchAll() as $ar) {
    foreach (chat_classify((string) $ar['user_message']) as $t) {
        $topicCounts[$t]++;
    }
}
arsort($topicCounts);
$totalMatches = array_sum($topicCounts);
$topicTop = array_slice($topicCounts, 0, 5, true);
$topicRows = '';
if ($totalMatches > 0) {
    foreach ($topicTop as $t => $c) {
        $topicRows .= '<tr><td style="padding:7px 12px;border-bottom:1px solid #e1e5de">' . htmlspecialchars($t) . '</td>'
            . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de;text-align:center">%' . round($c / $totalMatches * 100) . ' (' . $c . ')</td></tr>';
    }
    $rest = $totalMatches - array_sum($topicTop);
    if ($rest > 0) {
        $topicRows .= '<tr><td style="padding:7px 12px;border-bottom:1px solid #e1e5de">Diğer</td>'
            . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de;text-align:center">%' . round($rest / $totalMatches * 100) . ' (' . $rest . ')</td></tr>';
    }
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
        . ($topicRows !== '' ? '<h3 style="margin:20px 0 6px">Konu dağılımı</h3><table style="border-collapse:collapse;width:100%;max-width:420px"><tr><th style="text-align:left;padding:7px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Konu</th><th style="padding:7px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Pay</th></tr>' . $topicRows . '</table>' : '')
        . '<p style="margin-top:18px"><a href="https://nexustraveltech.com/admin/ziyaretci-sohbet" style="color:#0d7a4a">Tüm kayıtları görüntüle →</a></p>'
        . '</div>';

queue_email($to, 'Ziyaretçi soru özeti — ' . date('d.m.Y'), $body, 'chat_digest', $dateKey);
echo 'Özet kuyruğa eklendi: ' . $to . ' (' . count($top) . " soru, toplam {$total}).\n";
