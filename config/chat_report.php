<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/platform_settings.php';
require_once __DIR__ . '/chat_topics.php';

/**
 * Panel (tedarikçi/acente) AI sohbet raporu verisi — rol + hesap kimliğine göre
 * daraltılmış aylık görünüm: toplam/kaliteli mesaj, aktif gün, konu trendi ve
 * en çok sorulan 5 soru. tedarikci/sohbet-raporu ve acente/sohbet-raporu kullanır.
 */
function panel_chat_report_data(string $role, int $actorId, string $ay): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $ay)) $ay = date('Y-m');
    $start = $ay . '-01 00:00:00';
    $end = date('Y-m-01 00:00:00', strtotime($start . ' +1 month'));

    $minLen = max(1, (int) platform_setting('chat_min_length', 5));
    $requireSpace = (bool) platform_setting('chat_require_space', true);
    $quality = 'CHAR_LENGTH(BTRIM(user_message)) >= ' . $minLen . ($requireSpace ? " AND POSITION(' ' IN BTRIM(user_message)) > 0" : '');

    $scope = 'role=? AND created_at>=? AND created_at<?';
    $params = [$role, $start, $end];
    if ($role === 'supplier' && $actorId > 0) {
        $scope = 'role=? AND supplier_id=? AND created_at>=? AND created_at<?';
        $params = [$role, $actorId, $start, $end];
    } elseif ($role === 'agency' && $actorId > 0) {
        $scope = 'role=? AND agency_id=? AND created_at>=? AND created_at<?';
        $params = [$role, $actorId, $start, $end];
    }

    $c = db()->prepare("SELECT COUNT(*) FROM panel_chat_messages WHERE $scope");
    $c->execute($params);
    $totalRows = (int) $c->fetchColumn();

    $c2 = db()->prepare("SELECT COUNT(*) FROM panel_chat_messages WHERE $scope AND $quality");
    $c2->execute($params);
    $qualityRows = (int) $c2->fetchColumn();

    $c3 = db()->prepare("SELECT COUNT(DISTINCT created_at::date) FROM panel_chat_messages WHERE $scope");
    $c3->execute($params);
    $activeDays = (int) $c3->fetchColumn();

    $topicWeek = [];
    $topicTotal = array_fill_keys(array_keys(chat_topic_defs()), 0);
    for ($w = 1; $w <= 5; $w++) {
        foreach (array_keys(chat_topic_defs()) as $t) $topicWeek[$t][$w] = 0;
    }
    $q = db()->prepare("SELECT user_message, created_at FROM panel_chat_messages WHERE $scope AND $quality LIMIT 20000");
    $q->execute($params);
    foreach ($q->fetchAll() as $r) {
        $w = min(5, intdiv((int) date('j', strtotime((string) $r['created_at'])) - 1, 7) + 1);
        foreach (chat_classify((string) $r['user_message']) as $t) {
            $topicWeek[$t][$w]++;
            $topicTotal[$t]++;
        }
    }
    arsort($topicTotal);
    $topicTopKeys = array_slice(array_keys($topicTotal), 0, 8, true);

    $topQ = db()->prepare("SELECT LOWER(TRIM(user_message)) q, COUNT(*) c FROM panel_chat_messages WHERE $scope AND $quality GROUP BY 1 ORDER BY c DESC, MAX(created_at) DESC LIMIT 5");
    $topQ->execute($params);
    $topQuestions = $topQ->fetchAll();

    $monthLabel = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'][(int) substr($ay, 5, 2)] . ' ' . substr($ay, 0, 4);

    return compact('ay', 'start', 'end', 'monthLabel', 'totalRows', 'qualityRows', 'activeDays', 'topicWeek', 'topicTotal', 'topicTopKeys', 'topQuestions');
}

/**
 * Panel raporunu yazdırılabilir/e-postalanabilir HTML'e çevirir (PDF üretimi için de kullanılır).
 */
function panel_chat_report_html(array $d): string
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
    return '<h1 style="font-family:Arial;color:#10211f">Panel sohbet raporu — ' . htmlspecialchars($d['monthLabel']) . '</h1>'
        . '<p style="font-family:Arial;color:#64716d">Dönem: ' . htmlspecialchars($d['start']) . ' – ' . htmlspecialchars($d['end']) . '</p>'
        . '<table style="border-collapse:collapse;font-family:Arial;color:#10211f">'
        . $sum('Toplam mesaj', $d['totalRows'])
        . $sum('Kaliteli mesaj', $d['qualityRows'])
        . $sum('Aktif gün', $d['activeDays'])
        . '</table>'
        . '<h2 style="font-family:Arial;color:#10211f">Konu trendi</h2>'
        . '<table style="border-collapse:collapse;font-family:Arial;color:#10211f"><tr>' . $weekHead . '</tr>' . $topicRows . '</table>'
        . ($qRows !== '' ? '<h2 style="font-family:Arial;color:#10211f">En çok sorulan 5 soru</h2><table style="border-collapse:collapse;font-family:Arial;color:#10211f"><tr><th style="border:1px solid #ccc;padding:5px">#</th><th style="border:1px solid #ccc;padding:5px">Soru</th><th style="border:1px solid #ccc;padding:5px">Tekrar</th></tr>' . $qRows . '</table>' : '');
}


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

    // Gün bazında trafik: soru / yönlendirme / red — tek tabloda günlük kırılım.
    $daily = [];
    $daysInMonth = (int) date('t', strtotime($start));
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $daily[sprintf('%s-%02d', $ay, $day)] = ['soru' => 0, 'yon' => 0, 'red' => 0];
    }
    $fillDaily = function (array $rows) use (&$daily): void {
        foreach ($rows as $r) {
            $d = (string) $r['d'];
            if (isset($daily[$d])) $daily[$d]['soru'] = (int) $r['c'];
        }
    };
    $d1 = db()->prepare('SELECT created_at::date d, COUNT(*) c FROM public_chat_messages WHERE created_at>=? AND created_at<? GROUP BY 1');
    $d1->execute([$start, $end]);
    $fillDaily($d1->fetchAll());
    $d2 = db()->prepare("SELECT created_at::date d, COUNT(*) c FROM public_chat_messages WHERE created_at>=? AND created_at<? AND $quality AND (ai_reply LIKE '%nexustraveltech%' OR ai_reply LIKE '%Bu konuda size yardımcı olamıyorum%' OR ai_reply LIKE '%biraz daha açık yazabilir misiniz%') GROUP BY 1");
    $d2->execute([$start, $end]);
    foreach ($d2->fetchAll() as $r) {
        $dd = (string) $r['d'];
        if (isset($daily[$dd])) $daily[$dd]['yon'] = (int) $r['c'];
    }
    $d3 = db()->prepare("SELECT created_at::date d, COUNT(*) c FROM error_logs WHERE created_at>=? AND created_at<? AND (message LIKE 'AI sohbet hız sınırı aşıldı%' OR message LIKE 'AI sohbet engellenen kelime%') GROUP BY 1");
    $d3->execute([$start, $end]);
    foreach ($d3->fetchAll() as $r) {
        $dd = (string) $r['d'];
        if (isset($daily[$dd])) $daily[$dd]['red'] = (int) $r['c'];
    }

    $monthLabel = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'][(int) substr($ay, 5, 2)] . ' ' . substr($ay, 0, 4);

    return compact('ay', 'start', 'end', 'monthLabel', 'totalRows', 'qualityRows', 'ips', 'redirected', 'denied', 'redirectRate', 'unansweredRate', 'topicWeek', 'topicTotal', 'topicTopKeys', 'topQuestions', 'daily');
}

/**
 * Panel (tedarikçi/acente) haftalık özet verisi — son 7 gün, rol + hesap kimliğine göre.
 * Haftalık e-posta görevi (cron/send-weekly-digest.php) kullanır.
 */
function panel_chat_weekly_data(string $role, int $actorId): array
{
    $since = date('Y-m-d H:i:s', time() - 7 * 86400);
    $nowStr = date('Y-m-d H:i:s');
    $prevStart = date('Y-m-d H:i:s', time() - 14 * 86400);

    $minLen = max(1, (int) platform_setting('chat_min_length', 5));
    $requireSpace = (bool) platform_setting('chat_require_space', true);
    $quality = 'CHAR_LENGTH(BTRIM(user_message)) >= ' . $minLen . ($requireSpace ? " AND POSITION(' ' IN BTRIM(user_message)) > 0" : '');

    $scope = 'role=? AND created_at>=?';
    $params = [$role, $since];
    $prevScope = 'role=? AND created_at>=? AND created_at<?';
    $prevParams = [$role, $prevStart, $since];
    if ($role === 'supplier' && $actorId > 0) {
        $scope = 'role=? AND supplier_id=? AND created_at>=?';
        $params = [$role, $actorId, $since];
        $prevScope = 'role=? AND supplier_id=? AND created_at>=? AND created_at<?';
        $prevParams = [$role, $actorId, $prevStart, $since];
    } elseif ($role === 'agency' && $actorId > 0) {
        $scope = 'role=? AND agency_id=? AND created_at>=?';
        $params = [$role, $actorId, $since];
        $prevScope = 'role=? AND agency_id=? AND created_at>=? AND created_at<?';
        $prevParams = [$role, $actorId, $prevStart, $since];
    }

    $q = db()->prepare("SELECT COUNT(*) FROM panel_chat_messages WHERE $scope");
    $q->execute($params);
    $total = (int) $q->fetchColumn();

    $q2 = db()->prepare("SELECT COUNT(*) FROM panel_chat_messages WHERE $scope AND $quality");
    $q2->execute($params);
    $qualityRows = (int) $q2->fetchColumn();

    $q3 = db()->prepare("SELECT COUNT(DISTINCT created_at::date) FROM panel_chat_messages WHERE $scope");
    $q3->execute($params);
    $activeDays = (int) $q3->fetchColumn();

    $topQ = db()->prepare("SELECT LOWER(TRIM(user_message)) q, COUNT(*) c FROM panel_chat_messages WHERE $scope AND $quality GROUP BY 1 ORDER BY c DESC, MAX(created_at) DESC LIMIT 5");
    $topQ->execute($params);
    $topQuestions = $topQ->fetchAll();

    $topicCounts = function (string $sqlScope, array $sqlParams) use ($quality): array {
        $counts = array_fill_keys(array_keys(chat_topic_defs()), 0);
        $q = db()->prepare("SELECT user_message FROM panel_chat_messages WHERE $sqlScope AND $quality LIMIT 20000");
        $q->execute($sqlParams);
        foreach ($q->fetchAll() as $r) {
            foreach (chat_classify((string) $r['user_message']) as $t) $counts[$t]++;
        }
        return $counts;
    };
    $topics = $topicCounts($scope, $params);
    $topicsPrev = $topicCounts($prevScope, $prevParams);
    arsort($topics);

    // Geçen hafta karşılaştırması: mesaj sayısı değişimi (▲/▼ % veya 'yeni').
    $p = db()->prepare("SELECT COUNT(*) FROM panel_chat_messages WHERE $prevScope");
    $p->execute($prevParams);
    $totalPrev = (int) $p->fetchColumn();

    if ($totalPrev > 0) {
        $totalDelta = (int) round(($total - $totalPrev) / $totalPrev * 100);
        $totalDeltaTxt = ($totalDelta >= 0 ? '▲ %' : '▼ %') . abs($totalDelta);
        $totalDeltaColor = $totalDelta >= 0 ? '#0d7a4a' : '#b0301a';
    } elseif ($total > 0) {
        $totalDeltaTxt = 'yeni';
        $totalDeltaColor = '#0d7a4a';
    } else {
        $totalDeltaTxt = '—';
        $totalDeltaColor = '#64716d';
    }

    $dateLabel = date('d.m.Y', strtotime($since)) . ' – ' . date('d.m.Y');
    return compact('since', 'nowStr', 'dateLabel', 'total', 'qualityRows', 'activeDays', 'topQuestions', 'topics', 'topicsPrev', 'totalPrev', 'totalDeltaTxt', 'totalDeltaColor');
}

/**
 * Panel haftalık özetini e-posta gövdesine çevirir.
 */
function panel_chat_weekly_html(array $d, string $panelLink, string $company = '', int $linkedCount = 0, string $linkedLabel = 'tesis', int $pendingRoom = 0, int $pendingPlan = 0, int $weekRoomAppr = 0, int $weekRoomRej = 0, int $weekPlanAppr = 0, int $weekPlanRej = 0, array $topCodes = []): string
{
    // En çok tekrarlanan eşlenmemiş kodlar — sayaç DESC (zaten sorguda sıralı).
    $topCodeHtml = '';
    if ($topCodes) {
        $rows = '';
        foreach ($topCodes as $tc) {
            $rows .= '<tr><td style="padding:5px 8px;border-bottom:1px solid #ead9a8;font-size:12px"><code>' . htmlspecialchars((string) $tc['code']) . '</code></td>'
                . '<td style="padding:5px 8px;border-bottom:1px solid #ead9a8;font-size:12px">' . htmlspecialchars((string) ($tc['kind'] ?? 'oda')) . '</td>'
                . '<td style="padding:5px 8px;border-bottom:1px solid #ead9a8;text-align:center;font-size:12px"><b>' . (int) ($tc['cnt'] ?? 0) . '</b></td>'
                . '<td style="padding:5px 8px;border-bottom:1px solid #ead9a8;font-size:12px">' . (!empty($tc['seen']) ? htmlspecialchars(date('d.m H:i', strtotime((string) $tc['seen']))) : '—') . '</td></tr>';
        }
        $topCodeHtml = '<div style="margin:10px 0 4px;padding:9px 14px;background:#fdf6ea;border:1px solid #ead9a8;border-radius:8px">'
            . '<b style="font-size:12px;color:#8a6100">🔁 En çok tekrarlanan eşlenmemiş kodlar</b>'
            . '<table style="border-collapse:collapse;width:100%;max-width:560px;margin-top:6px"><tr><th style="text-align:left;padding:5px 8px;background:#fdf3e3;font-size:10px;color:#8a6100">Kod</th><th style="text-align:left;padding:5px 8px;background:#fdf3e3;font-size:10px;color:#8a6100">Tür</th><th style="text-align:center;padding:5px 8px;background:#fdf3e3;font-size:10px;color:#8a6100">Tekrar</th><th style="text-align:left;padding:5px 8px;background:#fdf3e3;font-size:10px;color:#8a6100">Son görülme</th></tr>' . $rows . '</table></div>';
    }
    $pendingTotal = $pendingRoom + $pendingPlan;
    // Bekleyen kalıcı silme onayları
    $pendingPurgeCount = 0;
    try { $pendingPurgeCount = (int) db()->query("SELECT COUNT(*) FROM pending_trash_purges WHERE approved_at IS NULL AND expires_at > now()")->fetchColumn(); } catch (Throwable $e) {}
    $pendingHtml = '';
    if ($pendingTotal > 0 || $pendingPurgeCount > 0) {
        $pendingHtml = '<div style="margin:14px 0 4px;padding:10px 14px;background:#fff8e6;border:1px solid #ead9a8;border-radius:8px">'
            . '<h3 style="margin:0 0 4px;font-size:13px;color:#8a6100">⏳ Onay bekleyen işlemler</h3>'
            . '<ul style="margin:4px 0 0;padding-left:18px;font-size:12px;color:#64716d">'
            . ($pendingTotal > 0 ? '<li><b>' . $pendingTotal . '</b> eşleştirme önerisi (' . $pendingRoom . ' oda + ' . $pendingPlan . ' fiyat planı) — Dağıtım merkezi bölüm 3</li>' : '')
            . ($pendingPurgeCount > 0 ? '<li><b>' . $pendingPurgeCount . '</b> özellik kalıcı silme onayı bekliyor — <a href="https://nexustraveltech.com/admin/ozellik-listeleri#trash" style="color:#8a6100;font-weight:bold">Çöp kutusu</a></li>' : '')
            . '</ul></div>';
    }
    // Son 7 gün eşleştirme işlemleri (onay/red) — denetim kayıtlarından.
    $weekTotal = $weekRoomAppr + $weekRoomRej + $weekPlanAppr + $weekPlanRej;
    $weekHtml = '';
    if ($weekTotal > 0) {
        $weekHtml = '<div style="margin:10px 0 4px;padding:9px 14px;background:#f0f7f4;border:1px solid #cfe4da;border-radius:8px;font-size:12px;color:#10211f">'
            . '<b>🔄 Son 7 günde eşleştirme işlemleri:</b> '
            . ($weekRoomAppr > 0 ? '✅ <b style="color:#0d7a4a">' . $weekRoomAppr . '</b> oda onayı · ' : '')
            . ($weekPlanAppr > 0 ? '✅ <b style="color:#0d7a4a">' . $weekPlanAppr . '</b> plan onayı · ' : '')
            . ($weekRoomRej > 0 ? '❌ <b style="color:#b0301a">' . $weekRoomRej . '</b> oda reddi · ' : '')
            . ($weekPlanRej > 0 ? '❌ <b style="color:#b0301a">' . $weekPlanRej . '</b> plan reddi · ' : '')
            . 'toplam <b>' . $weekTotal . '</b></div>';
    }
    $qRows = '';
    foreach ($d['topQuestions'] as $i => $row) {
        $qRows .= '<tr><td style="padding:9px 12px;border-bottom:1px solid #e1e5de">' . htmlspecialchars(mb_substr((string) $row['q'], 0, 140)) . '</td>'
            . '<td style="padding:9px 12px;border-bottom:1px solid #e1e5de;text-align:center"><b>' . (int) $row['c'] . '</b></td></tr>';
    }
    $topicRows = '';
    $topicCount = 0;
    foreach (array_slice($d['topics'], 0, 5, true) as $t => $c) {
        $cP = (int) ($d['topicsPrev'][$t] ?? 0);
        if ($c === 0 && $cP === 0) continue;
        $topicCount++;
        if ($cP > 0) {
            $delta = (int) round(($c - $cP) / $cP * 100);
            $deltaTxt = ($delta >= 0 ? '▲ %' : '▼ %') . abs($delta);
            $deltaColor = $delta >= 0 ? '#0d7a4a' : '#b0301a';
        } elseif ($c > 0) {
            $deltaTxt = 'yeni';
            $deltaColor = '#0d7a4a';
        } else {
            $deltaTxt = '—';
            $deltaColor = '#64716d';
        }
        $topicRows .= '<tr><td style="padding:7px 12px;border-bottom:1px solid #e1e5de">' . htmlspecialchars($t) . '</td>'
            . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de;text-align:center">' . (int) $c . '</td>'
            . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de;text-align:center">' . (int) $cP . '</td>'
            . '<td style="padding:7px 12px;border-bottom:1px solid #e1e5de;text-align:center;color:' . $deltaColor . ';font-weight:700">' . $deltaTxt . '</td></tr>';
    }
    $topicsHtml = $topicCount > 0
        ? '<h3 style="margin:18px 0 4px">Konu dağılımı (haftalık karşılaştırma)</h3><table style="border-collapse:collapse;width:100%;max-width:560px"><tr><th style="text-align:left;padding:7px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Konu</th><th style="padding:7px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d;text-align:center">Bu hafta</th><th style="padding:7px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d;text-align:center">Geçen hafta</th><th style="padding:7px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d;text-align:center">Değişim</th></tr>' . $topicRows . '</table>'
        : '';
    $context = $company !== ''
        ? '<p style="color:#10211f;margin:0 0 4px;font-size:14px"><b>' . htmlspecialchars($company) . '</b>' . ($linkedCount > 0 ? ' · ' . (int) $linkedCount . ' bağlı ' . htmlspecialchars($linkedLabel) : '') . '</p>'
        : '';
    return '<div style="font-family:Arial,sans-serif;color:#10211f">'
        . '<h2 style="margin:0 0 6px">Haftalık panel sohbet özeti</h2>'
        . $context
        . '<p style="color:#64716d;margin:0 0 16px">' . $d['dateLabel'] . ' · ' . $d['total'] . ' mesaj · ' . $d['activeDays'] . ' aktif gün · geçen hafta: ' . $d['totalPrev'] . ' mesaj <b style="color:' . $d['totalDeltaColor'] . '">(' . $d['totalDeltaTxt'] . ')</b></p>'
        . ($qRows !== '' ? '<table style="border-collapse:collapse;width:100%;max-width:560px"><tr><th style="text-align:left;padding:8px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">En çok sorulanlar</th><th style="padding:8px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Sayı</th></tr>' . $qRows . '</table>' : '')
        . $topicsHtml
        . $pendingHtml
        . $topCodeHtml
        . $weekHtml
        . '<p style="margin-top:18px"><a href="' . htmlspecialchars($panelLink) . '" style="color:#0d7a4a">Aylık rapor sayfası →</a></p>'
        . '</div>';
}

/**
 * Gün bazında trafik tablosunu HTML satırlarına çevirir (ekran + PDF ortak).
 */
function chat_report_daily_html(array $d): string
{
    if (empty($d['daily'])) return '<p style="font-family:Arial;color:#64716d">Günlük veri yok.</p>';
    $rows = '';
    foreach ($d['daily'] as $date => $v) {
        $rows .= '<tr><td style="border:1px solid #ccc;padding:5px">' . htmlspecialchars(substr((string) $date, 8, 2)) . '</td>'
            . '<td style="border:1px solid #ccc;padding:5px;text-align:center">' . (int) $v['soru'] . '</td>'
            . '<td style="border:1px solid #ccc;padding:5px;text-align:center">' . (int) $v['yon'] . '</td>'
            . '<td style="border:1px solid #ccc;padding:5px;text-align:center">' . (int) $v['red'] . '</td></tr>';
    }
    return '<table style="border-collapse:collapse;font-family:Arial;color:#10211f"><tr>'
        . '<th style="border:1px solid #ccc;padding:5px">Gün</th>'
        . '<th style="border:1px solid #ccc;padding:5px">Soru</th>'
        . '<th style="border:1px solid #ccc;padding:5px">Yönlendirme</th>'
        . '<th style="border:1px solid #ccc;padding:5px">Red</th></tr>'
        . $rows . '</table>';
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
        . '<h2 style="font-family:Arial;color:#10211f">Gün bazında trafik</h2>'
        . chat_report_daily_html($d)
        . '<h2 style="font-family:Arial;color:#10211f">Konu trendi</h2>'
        . '<table style="border-collapse:collapse;font-family:Arial;color:#10211f"><tr>' . $weekHead . '</tr>' . $topicRows . '</table>'
        . ($qRows !== '' ? '<h2 style="font-family:Arial;color:#10211f">En çok sorulan 10 soru</h2><table style="border-collapse:collapse;font-family:Arial;color:#10211f"><tr><th style="border:1px solid #ccc;padding:5px">#</th><th style="border:1px solid #ccc;padding:5px">Soru</th><th style="border:1px solid #ccc;padding:5px">Tekrar</th></tr>' . $qRows . '</table>' : '');
}
