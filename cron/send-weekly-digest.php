<?php
declare(strict_types=1);

/**
 * Haftalık sohbet özetleri — her pazartesi 08:00:
 *  1) Admin: son 7 günün ziyaretçi soru özeti (en çok sorulan 5 soru, konu dağılımı, yanıtlanamayan oran).
 *  2) Panele kayıtlı tedarikçi/acente hesapları: kendi panel asistanı kullanım özetleri (son 7 gün).
 *
 * Zamanlayıcı: nexus-chat-weekly (varsayılan 0 8 * * 1) — admin → Zamanlayıcılar.
 * Panel katılımı: tedarikci/sohbet-raporu ve acente/sohbet-raporu sayfalarındaki aç/kapat ile yönetilir
 * (platform ayarı panel_weekly_digest). Haftada bir kez idempotent; kayıt yoksa e-posta gönderilmez.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/chat_topics.php';
require_once __DIR__ . '/../config/chat_report.php';

$weekKey = (int) date('oW');

// ---- 1) Admin: ziyaretçi soru özeti (isteğe bağlı — admin e-postası yoksa atlanır). ----
$to = trim((string) platform_setting('admin_alert_email', ''));
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Admin haftalık özeti atlandı: admin_alert_email tanımlı değil (admin → Kontrol merkezi).\n");
} else {
    $exists = db()->prepare("SELECT COUNT(*) FROM email_outbox WHERE related_type='chat_weekly' AND related_id=?");
    $exists->execute([$weekKey]);
    if ((int) $exists->fetchColumn() > 0) {
        echo "Admin için bu hafta özet zaten kuyrukta; atlandı.\n";
    } else {
        $since = date('Y-m-d H:i:s', time() - 7 * 86400);
        $nowStr = date('Y-m-d H:i:s');
        $prevStart = date('Y-m-d H:i:s', time() - 14 * 86400);

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

        $c4 = db()->prepare("SELECT COUNT(*) FROM public_chat_messages WHERE created_at>=? AND created_at<? AND $quality AND (ai_reply LIKE '%nexustraveltech%' OR ai_reply LIKE '%Bu konuda size yardımcı olamıyorum%' OR ai_reply LIKE '%biraz daha açık yazabilir misiniz%')");
        $c4->execute([$since, $nowStr]);
        $redirected = (int) $c4->fetchColumn();

        $c5 = db()->prepare("SELECT COUNT(*) FROM error_logs WHERE created_at>=? AND created_at<? AND (message LIKE 'AI sohbet hız sınırı aşıldı%' OR message LIKE 'AI sohbet engellenen kelime%')");
        $c5->execute([$since, $nowStr]);
        $denied = (int) $c5->fetchColumn();

        $unansweredRate = ($total + $denied) > 0 ? round(($redirected + $denied) / ($total + $denied) * 100) : 0;

        $topicCountsFor = function (string $start, string $end) use ($quality): array {
            $counts = array_fill_keys(array_keys(chat_topic_defs()), 0);
            $q = db()->prepare("SELECT user_message FROM public_chat_messages WHERE created_at>=? AND created_at<? AND $quality LIMIT 20000");
            $q->execute([$start, $end]);
            foreach ($q->fetchAll() as $r) {
                foreach (chat_classify((string) $r['user_message']) as $t) $counts[$t]++;
            }
            return $counts;
        };
        $topicWeek = $topicCountsFor($since, $nowStr);
        $topicPrev = $topicCountsFor($prevStart, $since);
        $topicOrder = array_keys($topicWeek);
        usort($topicOrder, fn($a, $b) => ($topicWeek[$b] <=> $topicWeek[$a]) ?: ($topicPrev[$b] <=> $topicPrev[$a]));
        $topicRows = '';
        foreach (array_slice($topicOrder, 0, 5) as $t) {
            $cW = $topicWeek[$t];
            $cP = $topicPrev[$t];
            if ($cW === 0 && $cP === 0) continue;
            if ($cP > 0) {
                $delta = (int) round(($cW - $cP) / $cP * 100);
                $deltaTxt = ($delta >= 0 ? '▲ %' : '▼ %') . abs($delta);
                $deltaColor = $delta >= 0 ? '#0d7a4a' : '#b0301a';
            } elseif ($cW > 0) {
                $deltaTxt = 'yeni';
                $deltaColor = '#0d7a4a';
            } else {
                $deltaTxt = '—';
                $deltaColor = '#64716d';
            }
            $topicRows .= '<tr><td style="padding:7px 12px;border-bottom:1px solid #e1e5de">' . htmlspecialchars($t) . '</td>'
                . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de;text-align:center">' . $cW . '</td>'
                . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de;text-align:center">' . $cP . '</td>'
                . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de;text-align:center;color:' . $deltaColor . ';font-weight:700">' . $deltaTxt . '</td></tr>';
        }

        if ($total === 0 && $denied === 0) {
            echo "Son 7 günde ziyaretçi soru yok; admin özeti gönderilmedi.\n";
        } else {
            $rows = '';
            foreach ($top as $r) {
                $rows .= '<tr><td style="padding:9px 12px;border-bottom:1px solid #e1e5de">'
                    . htmlspecialchars(mb_substr((string) $r['q'], 0, 140))
                    . '</td><td style="padding:9px 12px;border-bottom:1px solid #e1e5de;text-align:center"><b>' . (int) $r['c'] . '</b></td></tr>';
            }
            $dateLabel = date('d.m.Y', strtotime($since)) . ' – ' . date('d.m.Y');
            $rateColor = $unansweredRate <= 30 ? '#0d7a4a' : ($unansweredRate <= 60 ? '#a86026' : '#b0301a');
            $body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
                . '<h2 style="margin:0 0 6px">Haftalık sohbet özeti</h2>'
                . '<p style="color:#64716d;margin:0 0 16px">' . $dateLabel . ' · ' . $total . ' soru · ' . $ips . ' farklı IP</p>'
                . ($rows !== '' ? '<table style="border-collapse:collapse;width:100%;max-width:560px"><tr><th style="text-align:left;padding:8px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">En çok sorulanlar</th><th style="padding:8px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Sayı</th></tr>' . $rows . '</table>' : '')
                . ($topicRows !== '' ? '<h3 style="margin:20px 0 6px">Konu dağılımı (haftalık karşılaştırma)</h3><table style="border-collapse:collapse;width:100%;max-width:560px"><tr><th style="text-align:left;padding:7px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Konu</th><th style="padding:7px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Bu hafta</th><th style="padding:7px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Geçen hafta</th><th style="padding:7px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Değişim</th></tr>' . $topicRows . '</table>' : '')
                . '<p style="margin:14px 0 0">Yönlendirilen yanıt: ' . $redirected . ' · Reddedilen istek: ' . $denied . ' · Yanıtlanamayan oran: <b style="color:' . $rateColor . '">%' . $unansweredRate . '</b></p>'
                . '<p style="margin-top:18px"><a href="https://nexustraveltech.com/admin/ziyaretci-sohbet" style="color:#0d7a4a">Tüm kayıtları görüntüle →</a></p>'
                . '</div>';
            queue_email($to, 'Haftalık sohbet özeti — ' . $dateLabel, $body, 'chat_weekly', $weekKey);
            echo 'Admin özeti kuyruğa eklendi: ' . $to . ' (' . count($top) . " soru, toplam {$total}).\n";
        }
    }
}

// ---- 2) Panele kayıtlı tedarikçi/acente haftalık özetleri (kendi panel asistanı kullanımı). ----
$digest = (array) platform_setting('panel_weekly_digest', []);
$supplierMap = (array) ($digest['supplier'] ?? []);
$agencyMap = (array) ($digest['agency'] ?? []);

$sendPanel = function (string $role, int $actorId, string $email, string $subjectPrefix, string $panelLink) use ($weekKey): void {
    $key = $weekKey * 100000 + $actorId;
    $exists = db()->prepare("SELECT COUNT(*) FROM email_outbox WHERE related_type='chat_weekly_panel' AND related_id=?");
    $exists->execute([$key]);
    if ((int) $exists->fetchColumn() > 0) {
        echo "Bu hafta için panel özeti zaten kuyrukta; atlandı ({$role}#{$actorId}).\n";
        return;
    }
    $d = panel_chat_weekly_data($role, $actorId);
    if ($d['total'] === 0) {
        echo "Son 7 günde panel mesajı yok; özet gönderilmedi ({$role}#{$actorId}).\n";
        return;
    }
    $body = panel_chat_weekly_html($d, $panelLink);
    queue_email($email, $subjectPrefix . ' — ' . $d['dateLabel'], $body, 'chat_weekly_panel', $key);
    echo 'Panel özeti kuyruğa eklendi: ' . $email . " ({$role}#{$actorId}, {$d['total']} mesaj).\n";
};

foreach ($supplierMap as $sid => $email) {
    $email = trim((string) $email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
    $sendPanel('supplier', (int) $sid, $email, 'Haftalık panel sohbet özeti', 'https://nexustraveltech.com/tedarikci/sohbet-raporu');
}
foreach ($agencyMap as $aid => $email) {
    $email = trim((string) $email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
    $sendPanel('agency', (int) $aid, $email, 'Haftalık panel sohbet özeti', 'https://nexustraveltech.com/acente/sohbet-raporu');
}
