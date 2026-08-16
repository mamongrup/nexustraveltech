<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/platform_settings.php';
require_once __DIR__ . '/chat_topics.php';

/**
 * Aylık ziyaretçi sohbet raporu verisi — admin/sohbet-raporu sayfası ve
 * cron/send-monthly-report.php aynı fonksiyonu kullanır (tek kaynak).
 */
function chat_report_data(string $ay): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $ay)) $ay = date('Y-m');
    $start = $ay . '-01 00:00:00';
    $end = date('Y-m-01 00:00:00', strtotime($start . ' +1 month'));

    $minLen = max(1, (int) platform_setting('chat_min_length', 5));
    $requireSpace = (bool) platform_setting('chat_require_space', true);
    $quality = 'CHAR_LENGTH(BTRIM(user_message)) >= ' . $minLen . ($requireSpace ? " AND POSITION(' ' IN BTRIM(user_message)) > 0" : '');

    $c = db()->prepare('SELECT COUNT(*) FROM public_chat_messages WHERE created_at>=? AND created_at<?');
    $c->execute([$start, $end]);
    $totalRows = (int) $c->fetchColumn();

    $c2 = db()->prepare("SELECT COUNT(*) FROM public_chat_messages WHERE created_at>=? AND created_at<? AND $quality");
    $c2->execute([$start, $end]);
    $qualityRows = (int) $c2->fetchColumn();

    $c3 = db()->prepare('SELECT COUNT(DISTINCT ip) FROM public_chat_messages WHERE created_at>=? AND created_at<?');
    $c3->execute([$start, $end]);
    $ips = (int) $c3->fetchColumn();

    $c4 = db()->prepare("SELECT COUNT(*) FROM public_chat_messages WHERE created_at>=? AND created_at<? AND $quality AND (ai_reply LIKE '%nexustraveltech%' OR ai_reply LIKE '%Bu konuda size yardımcı olamıyorum%' OR ai_reply LIKE '%biraz daha açık yazabilir misiniz%')");
    $c4->execute([$start, $end]);
    $redirected = (int) $c4->fetchColumn();

    $c5 = db()->prepare("SELECT COUNT(*) FROM error_logs WHERE created_at>=? AND created_at<? AND (message LIKE 'AI sohbet hız sınırı aşıldı%' OR message LIKE 'AI sohbet engellenen kelime%')");
    $c5->execute([$start, $end]);
    $denied = (int) $c5->fetchColumn();

    $redirectRate = $qualityRows > 0 ? round($redirected / $qualityRows * 100) : 0;
    $unansweredRate = ($qualityRows + $denied) > 0 ? round(($redirected + $denied) / ($qualityRows + $denied) * 100) : 0;

    $topicWeek = [];
    $topicTotal = array_fill_keys(array_keys(chat_topic_defs()), 0);
    for ($w = 1; $w <= 5; $w++) {
        foreach (array_keys(chat_topic_defs()) as $t) $topicWeek[$t][$w] = 0;
    }
    $q = db()->prepare("SELECT user_message, created_at FROM public_chat_messages WHERE created_at>=? AND created_at<? AND $quality LIMIT 20000");
    $q->execute([$start, $end]);
    foreach ($q->fetchAll() as $r) {
        $w = min(5, intdiv((int) date('j', strtotime((string) $r['created_at'])) - 1, 7) + 1);
        foreach (chat_classify((string) $r['user_message']) as $t) {
            $topicWeek[$t][$w]++;
            $topicTotal[$t]++;
        }
    }
    arsort($topicTotal);
    $topicTopKeys = array_slice(array_keys($topicTotal), 0, 8, true);

    $topQ = db()->prepare("SELECT LOWER(TRIM(user_message)) q, COUNT(*) c FROM public_chat_messages WHERE created_at>=? AND created_at<? AND $quality GROUP BY 1 ORDER BY c DESC, MAX(created_at) DESC LIMIT 10");
    $topQ->execute([$start, $end]);
    $topQuestions = $topQ->fetchAll();

    $monthLabel = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'][(int) substr($ay, 5, 2)] . ' ' . substr($ay, 0, 4);

    return compact('ay', 'start', 'end', 'monthLabel', 'totalRows', 'qualityRows', 'ips', 'redirected', 'denied', 'redirectRate', 'unansweredRate', 'topicWeek', 'topicTotal', 'topicTopKeys', 'topQuestions');
}

/**
 * Raporu yazdırılabilir/e-postalanabilir HTML'e çevirir (PDF üretimi için de kullanılır).
 */
function chat_report_html(array $d): string
{
    $topicRows = '';
    foreach ($d['topicTopKeys'] as $t) {
        $topicRows .= '<tr><td style="border:1px solid #ccc;padding:5px">' . htmlspecialchars($t) . '</td>';
        for ($w = 1; $w <= 5; $w++) $topicRows .= '<td style="border:1px solid #ccc;padding:5px;text-align:center">' . $d['topicWeek'][$t][$w] . '</td>';
        $topicRows .= '<td style="border:1px solid #ccc;padding:5px;text-align:center"><b>' . $d['topicTotal'][$t] . '</b></td></tr>';
    }
    $weekHead = '<th style="border:1px solid #ccc;padding:5px">Konu</th>';
    for ($w = 1; $w <= 5; $w++) $weekHead .= '<th style="border:1px solid #ccc;padding:5px">Hafta ' . $w . '</th>';
    $weekHead .= '<th style="border:1px solid #ccc;padding:5px">Toplam</th>';
    $qRows = '';
    foreach ($d['topQuestions'] as $i => $row) {
        $qRows .= '<tr><td style="border:1px solid #ccc;padding:5px;text-align:center">' . ($i + 1) . '</td>'
            . '<td style="border:1px solid #ccc;padding:5px">' . htmlspecialchars((string) $row['q']) . '</td>'
            . '<td style="border:1px solid #ccc;padding:5px;text-align:center">' . (int) $row['c'] . '</td></tr>';
    }
    $sum = function (string $label, $value): string {
        return '<tr><td style="border:1px solid #ccc;padding:5px">' . $label . '</td><td style="border:1px solid #ccc;padding:5px">' . $value . '</td></tr>';
    };
    return '<h1 style="font-family:Arial;color:#10211f">Sohbet raporu — ' . htmlspecialchars($d['monthLabel']) . '</h1>'
        . '<p style="font-family:Arial;color:#64716d">Dönem: ' . htmlspecialchars($d['start']) . ' – ' . htmlspecialchars($d['end']) . '</p>'
        . '<table style="border-collapse:collapse;font-family:Arial;color:#10211f">'
        . $sum('Kayıtlı soru', $d['totalRows'])
        . $sum('Kaliteli soru', $d['qualityRows'])
        . $sum('Farklı IP', $d['ips'])
        . $sum('Yönlendirildi', $d['redirected'])
        . $sum('Reddedilen istek', $d['denied'])
        . '<tr><td style="border:1px solid #ccc;padding:5px"><b>Yanıtlanamayan/Yönlendirme oranı</b></td><td style="border:1px solid #ccc;padding:5px"><b>%' . $d['unansweredRate'] . '</b></td></tr>'
        . '</table>'
        . '<h2 style="font-family:Arial;color:#10211f">Konu trendi</h2>'
        . '<table style="border-collapse:collapse;font-family:Arial;color:#10211f"><tr>' . $weekHead . '</tr>' . $topicRows . '</table>'
        . ($qRows !== '' ? '<h2 style="font-family:Arial;color:#10211f">En çok sorulan 10 soru</h2><table style="border-collapse:collapse;font-family:Arial;color:#10211f"><tr><th style="border:1px solid #ccc;padding:5px">#</th><th style="border:1px solid #ccc;padding:5px">Soru</th><th style="border:1px solid #ccc;padding:5px">Tekrar</th></tr>' . $qRows . '</table>' : '');
}
