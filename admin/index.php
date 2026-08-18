<?php

declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/platform_settings.php';
require __DIR__ . '/../config/ai_settings.php';
require __DIR__ . '/../config/chat_topics.php';
require __DIR__ . '/../config/scheduler.php';
require __DIR__ . '/../config/mapping_suggestions.php';

require_admin();

$leads = db()
    ->query('SELECT * FROM early_access_leads ORDER BY created_at DESC LIMIT 200')
    ->fetchAll();

$total = (int) db()->query('SELECT COUNT(*) FROM early_access_leads')->fetchColumn();
$identityAlerts = (int) db()->query("SELECT COUNT(*) FROM admin_alerts WHERE is_read=false AND alert_type='identity_verification_failed'")->fetchColumn();
$blockCount = (int) db()->query("SELECT COUNT(*) FROM blocked_ips WHERE action='block'")->fetchColumn();
$flagCount = (int) db()->query("SELECT COUNT(*) FROM blocked_ips WHERE action='flag'")->fetchColumn();
$adminPending = admin_pending_mapping_suggestions(db());
// Yaklasan kalici silme uyarisi — 3 gun icinde silinecek ozellikler
$upcomingPurge = ['count' => 0, 'items' => []];
try {
  $ttlTmp = max(7, (int) platform_setting('feature_trash_ttl_days', 30));
  $upRows = db()->query("SELECT id, label, code, deleted_at, purge_at FROM property_feature_catalog WHERE deleted_at IS NOT NULL ORDER BY deleted_at")->fetchAll();
  foreach ($upRows as $ur) {
    $dTs = strtotime((string)$ur['deleted_at']) ?: 0;
    if ($dTs <= 0) continue;
    $custom = !empty($ur['purge_at']);
    $pTs = $custom ? (strtotime((string)$ur['purge_at']) ?: 0) : 0;
    if ($pTs <= 0) $pTs = $dTs + $ttlTmp * 86400;
    $diff = $pTs - time();
    $upWarnDays = max(1, (int) platform_setting('trash_upcoming_warning_days', 3));
    if ($diff <= 0 || $diff > $upWarnDays * 86400) continue;
    $upcomingPurge['count']++;
    $upcomingPurge['items'][] = ['label'=>(string)$ur['label'],'purge_date'=>date('Y-m-d',$pTs),'remain'=>max(1,(int)ceil($diff/86400))];
  }
} catch (Throwable $e) {}


// Tüm tedarikçilerdeki toplam yetim eşleştirme sayısı.
$orphanTotal = 0;
$orphanBreakdown = [];
try {
    $pdo = db();
    $orphanQueries = [
        ['channel_room_mappings', 'Oda', "m.room_type_id>0 AND (rt.id IS NULL OR c.id IS NULL OR rt.property_id<>m.property_id OR (m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)))",
            'LEFT JOIN room_types rt ON rt.id=m.room_type_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id'],
        ['channel_rate_plan_mappings', 'Plan', '(m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)) OR c.id IS NULL',
            'LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id'],
        ['channel_property_mappings', 'Ürün', 'p.id IS NULL OR c.id IS NULL',
            'LEFT JOIN properties p ON p.id=m.property_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id'],
        ['ical_connections', 'iCal', 'p.id IS NULL', 'LEFT JOIN properties p ON p.id=c.property_id'],
    ];
    foreach ($orphanQueries as [$tbl, $label, $where, $join]) {
        $tblOk = (bool) $pdo->query("SELECT 1 FROM pg_tables WHERE schemaname='public' AND tablename='$tbl'")->fetchColumn();
        if (!$tblOk) continue;
        try {
            $cnt = (int) $pdo->query("SELECT COUNT(*) FROM $tbl m $join WHERE $where")->fetchColumn();
            if ($cnt > 0) { $orphanTotal += $cnt; $orphanBreakdown[] = $label . ': ' . $cnt; }
        } catch (Throwable $e) {}
    }
} catch (Throwable $e) {}

// Sohbet ayarları özeti.
$chatMinLen = max(1, (int) platform_setting('chat_min_length', 5));
$chatRequireSpace = (bool) platform_setting('chat_require_space', true);
$chatBlocklist = array_values(array_filter(array_map('trim', (array) platform_setting('chat_blocklist', [])), fn($l) => $l !== ''));
$topicRespCount = count(array_filter((array) platform_setting('chat_topic_responses', []), fn($c) => trim((string) ($c['text'] ?? '')) !== '' || trim((string) ($c['link'] ?? '')) !== ''));
$panelWeekly = (array) platform_setting('panel_weekly_digest', []);
$panelWeeklySup = count((array) ($panelWeekly['supplier'] ?? []));
$panelWeeklyAgy = count((array) ($panelWeekly['agency'] ?? []));
$aiKeyReady = false;
try {
    $aiKeyReady = deepseek_settings()['api_key'] !== '';
} catch (Throwable $e) {
    $aiKeyReady = false;
}
// Konu etiketleme: ortak sınıflandırıcı (config/chat_topics.php).
$topicsCount = array_fill_keys(array_keys(chat_topic_defs()), 0);
foreach (db()->query('SELECT user_message FROM public_chat_messages WHERE created_at >= CURRENT_DATE - 29 LIMIT 10000')->fetchAll() as $tr) {
    $m = trim((string) $tr['user_message']);
    if (mb_strlen($m) < $chatMinLen || ($chatRequireSpace && !str_contains($m, ' '))) continue;
    foreach (chat_classify($m) as $topic) {
        $topicsCount[$topic]++;
    }
}
arsort($topicsCount);
$topTopics = array_slice($topicsCount, 0, 3, true);
$topTopicsTotal = array_sum($topTopics);

$chatToday = (int) db()->query('SELECT COUNT(*) FROM public_chat_messages WHERE created_at >= CURRENT_DATE')->fetchColumn();
$chatWeek = [];
for ($i = 6; $i >= 0; $i--) {
    $chatWeek[date('Y-m-d', time() - $i * 86400)] = 0;
}
foreach (db()->query('SELECT created_at::date d, COUNT(*) c FROM public_chat_messages WHERE created_at >= CURRENT_DATE - 6 GROUP BY created_at::date')->fetchAll() as $r) {
    $d = (string) $r['d'];
    if (isset($chatWeek[$d])) $chatWeek[$d] = (int) $r['c'];
}
$chatWeekMax = max(1, max($chatWeek));

// Zamanlayıcı süre özeti kartı: son 30 günün günlük ortalama süresi (görev seçiciyle).
$timerChartJob = (int) ($_GET['timer_chart_job'] ?? 0);
$schedulerJobs = scheduler_jobs();
$durWhere = $timerChartJob > 0 ? 'WHERE job_id=? AND created_at >= CURRENT_DATE - 29' : 'WHERE created_at >= CURRENT_DATE - 29';
$durParams = $timerChartJob > 0 ? [$timerChartJob] : [];
$durQ = db()->prepare("SELECT created_at::date d, COALESCE(AVG(duration_ms),0)::int avg_ms, COUNT(*) FILTER (WHERE status='error') err FROM scheduled_job_runs $durWhere GROUP BY 1");
$durQ->execute($durParams);
$durMap = [];
foreach ($durQ->fetchAll() as $r) $durMap[(string) $r['d']] = ['avg_ms' => (int) $r['avg_ms'], 'err' => (int) $r['err']];
$durChart = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', time() - $i * 86400);
    $durChart[$d] = $durMap[$d] ?? ['avg_ms' => 0, 'err' => 0];
}
$durMax = max(1, max(array_column($durChart, 'avg_ms')));
$durErrDays = count(array_filter($durChart, fn($v) => $v['err'] > 0));
$timerAvg7 = (int) db()->query("SELECT COALESCE(AVG(duration_ms),0) FROM scheduled_job_runs WHERE created_at >= now() - interval '7 days'")->fetchColumn();
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>NEXUS Admin Panel</title>
  <style>
    body{margin:0;background:#f7f7f2;color:#10211f;font-family:Arial,sans-serif}.wrap{width:min(1160px,calc(100% - 32px));margin:40px auto}.top{display:flex;justify-content:space-between;gap:20px;align-items:center}.brand{font-size:28px;font-weight:800}.brand span{color:#e85f42}.pill{display:inline-block;background:#d7ff48;padding:10px 14px;font-weight:800}.logout{color:#10211f}table{width:100%;border-collapse:collapse;margin-top:28px;background:#fff}th,td{text-align:left;border-bottom:1px solid #e1e5de;padding:13px;font-size:14px}th{font-size:12px;text-transform:uppercase;color:#64716d}tr:hover td{background:#fbfcf8}.empty{padding:30px;background:#fff;margin-top:28px}.muted{color:#64716d}.ip-stats{display:flex;gap:10px;flex-wrap:wrap;margin:18px 0}.ip-chip{display:inline-flex;align-items:center;gap:6px;padding:9px 14px;font-size:13px;font-weight:700;text-decoration:none;border-radius:6px}.ip-chip b{font-size:17px}.ip-block{background:#ffe2de;color:#8e2410}.ip-flag{background:#fdf0d8;color:#8a5a10}.ip-chip:hover{opacity:.85}.chat-card{background:#fff;border:1px solid #e1e5de;padding:14px 16px;margin:18px 0;display:flex;flex-wrap:wrap;gap:16px 28px;align-items:flex-start}.chat-card h3{margin:0 0 8px;font-size:14px;flex-basis:100%}.chat-card .item{font-size:12px;color:#64716d}.chat-card .item b{font-size:16px;display:block;color:#10211f;margin-top:2px}.chat-card .ok{color:#0d7a4a}.chat-card .warn{color:#a86026}.chat-card .edit{margin-left:auto;font-size:13px;font-weight:700;color:#0d7a4a;text-decoration:none;align-self:center}.chat-card .week{display:flex;align-items:flex-end;gap:3px;height:34px;margin-top:5px}.chat-card .week i{flex:1;background:#e8a33d;border-radius:2px 2px 0 0;display:block;min-width:5px}.tchip{display:inline-block;background:#f2f4ef;border:1px solid #e1e5de;padding:3px 10px;border-radius:13px;font-size:12px;margin:4px 6px 0 0;color:#10211f}.tchip b{display:inline;font-size:12px;color:#0d7a4a}
    .badge-hover{position:relative;display:inline-block;cursor:default}.badge-hover .badge-hover-pop{display:none;position:absolute;z-index:60;left:50%;bottom:calc(100% + 9px);transform:translateX(-50%);min-width:280px;max-width:380px;background:#10211f;color:#eef3ee;border-radius:8px;padding:10px 12px;font-size:12px;line-height:1.55;box-shadow:0 6px 18px rgba(16,33,31,.28);white-space:normal;text-align:left}.badge-hover .badge-hover-pop::after{content:"";position:absolute;top:100%;left:50%;transform:translateX(-50%);border:6px solid transparent;border-top-color:#10211f}.badge-hover:hover .badge-hover-pop,.badge-hover:focus-within .badge-hover-pop{display:block}.badge-hover .hover-list-title{margin:6px 0 4px;font-weight:700;color:#d9c48a}.badge-hover .hover-list-title:first-child{margin-top:0}.badge-hover .hover-list-row{padding:2px 0;word-break:break-word}.badge-hover .hover-list-row code{background:rgba(255,255,255,.12);border-radius:4px;padding:0 4px;font-family:monospace}.badge-hover .hover-list-row small{color:#9fb3ad}
  </style>
</head>
<body>
  <main class="wrap">
    <div class="top">
      <div>
        <div class="brand">N<span>∿</span>XUS Admin</div>
        <p class="muted">nexustraveltech.com erken erisim basvurulari</p>
      </div>
      <div>
        <span class="pill"><?= $total ?> basvuru</span>
        <?php if ($identityAlerts): ?><a class="logout" href="/nexustraveltech/admin/tedarikci-onaylari">⚠ <?= $identityAlerts ?> kimlik uyarısı</a><?php endif; ?>
        <a class="logout" href="/nexustraveltech/admin/logout">Cikis</a>
      </div>
    </div>

    <div class="ip-stats">
      <a class="ip-chip ip-block" href="/nexustraveltech/admin/ziyaretci-sohbet" title="Kötü niyetli trafik için engellenen IP'ler">🚫 Engelli IP: <b><?= (int)$blockCount ?></b></a>
      <a class="ip-chip ip-flag" href="/nexustraveltech/admin/ziyaretci-sohbet" title="İzlemeye alınan (bayraklı) IP'ler">⚠ Bayraklı IP: <b><?= (int)$flagCount ?></b></a>
      <?php if ($adminPending['total'] > 0): ?><span class="badge-hover"><a class="ip-chip" style="background:#fff6ef;border:1px solid #e0c9a3" href="/nexustraveltech/admin/tedarikci-ilanlari" title="Tüm tedarikçilerin onay bekleyen oda/fiyat planı eşleştirme önerileri — onay tedarikçi panelinde (dağıtım merkezi → bölüm 3)">⏳ Bekleyen öneri: <b><?= (int)$adminPending['total'] ?></b></a><span class="badge-hover-pop"><?= admin_mapping_suggestions_hover_html($adminPending) ?></span></span><?php if ($upcomingPurge["count"] > 0): ?><span class="badge-hover"><a class="ip-chip" style="background:#fff3f1;border:1px solid #f3c4ba" href="/nexustraveltech/admin/ozellik-listeleri#trash" title="3 gun icinde kalici silinecek ozellikler">&#x23F3; Yaklasan silme: <b><?= (int)$upcomingPurge["count"] ?></b></a><span class="badge-hover-pop"><div class="hover-list-title">&#x26A0; Yaklasan kalici silme (3 gun)</div><div style="margin-top:6px"><a href="/nexustraveltech/admin/ozellik-listeleri#trash" style="color:#d9f0b4;font-weight:bold;text-decoration:none">Cöp kutusuna git →</a></div></span></span><?php endif; ?><?php if ($orphanTotal > 0): ?><span class="badge-hover"><a class="ip-chip" style="background:#ffe2de;border:1px solid #e8b4b0" href="/nexustraveltech/admin/orphan-mappings" title="Silinmiş oda/plan/kanal/ürüne ait bozuk eşleştirme satırları">🧹 Yetim: <b><?= (int)$orphanTotal ?></b></a><span class="badge-hover-pop"><div class="hover-list-title">⚠ Yetim eşleştirmeler (<?= (int)$orphanTotal ?>)</div><?php foreach ($orphanBreakdown as $ob): ?><div class="hover-list-row">· <?= htmlspecialchars($ob) ?></div><?php endforeach; ?><div style="margin-top:6px"><a href="/nexustraveltech/admin/orphan-mappings" style="color:#d9f0b4;font-weight:bold;text-decoration:none">Yönetim sayfasına git →</a></div></span></span><?php endif; ?>
<?php endif; ?>
    </div>

    <div class="chat-card">
      <h3>💬 Sohbet ayarları</h3>
      <div class="item">Minimum soru uzunluğu<b><?= (int)$chatMinLen ?> karakter</b></div>
      <div class="item">Tek kelime engeli<b><?= $chatRequireSpace ? 'Açık' : 'Kapalı' ?></b></div>
      <div class="item">Yasak kelime<b><?= count($chatBlocklist) ?> kayıt</b><?php if ($chatBlocklist): ?><span style="font-size:12px;color:#64716d;display:block;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars(implode(', ', array_slice($chatBlocklist, 0, 3)))?><?= count($chatBlocklist) > 3 ? '…' : '' ?></span><?php endif; ?></div>
      <div class="item">Konu yanıtı<b><?= (int)$topicRespCount ?>/<?= count(chat_topic_defs()) ?> tanımlı</b></div>
      <div class="item">Haftalık panel özeti<b><?= (int)$panelWeeklySup ?> tedarikçi · <?= (int)$panelWeeklyAgy ?> acente</b></div>
      <div class="item">Bugünkü soru<b><?= (int)$chatToday ?></b></div>
      <div class="item" style="min-width:160px">Son 7 gün eğilimi<div class="week"><?php foreach ($chatWeek as $d => $c): ?><i title="<?=htmlspecialchars($d)?>: <?=$c?> soru" style="height:<?=max(2, (int) round($c / $chatWeekMax * 30))?>px"></i><?php endforeach; ?></div></div>
      <div class="item" style="flex-basis:100%">Popüler konular (son 30 gün)<?php if ($topTopicsTotal > 0): ?><?php foreach ($topTopics as $topic => $c): if ($c === 0) continue; ?><span class="tchip"><?=htmlspecialchars($topic)?> <b><?=(int)$c?></b></span><?php endforeach; ?><?php else: ?><span class="muted" style="margin-left:8px">henüz veri yok</span><?php endif; ?></div>
      <div class="item">DeepSeek anahtarı<b class="<?= $aiKeyReady ? 'ok' : 'warn' ?>"><?= $aiKeyReady ? '✅ Ayarlı' : '⚠ Eksik' ?></b></div>
      <a class="edit" href="/nexustraveltech/admin/kontrol-merkezi">Düzenle →</a>
    </div>

    <div class="chat-card">
      <h3>⏱ Zamanlayıcı süreleri (son 30 gün)</h3>
      <form method="get" action="/nexustraveltech/admin/" style="flex-basis:100%;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <select name="timer_chart_job" onchange="this.form.submit()" style="padding:7px;border:1px solid #d8ded8;font:inherit">
          <option value="0">Tüm görevler</option>
          <?php foreach ($schedulerJobs as $sj): ?>
            <option value="<?= (int) $sj['id'] ?>" <?= $timerChartJob === (int) $sj['id'] ? 'selected' : '' ?>><?= htmlspecialchars($sj['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="item" style="font-size:12px;color:#64716d">7 gün ort. <b style="color:#10211f"><?= number_format($timerAvg7) ?> ms</b> · <b style="color:#b0301a"><?= (int)$durErrDays ?> gün hatalı</b></span>
        <a class="edit" href="/nexustraveltech/admin/zamanlayici-gecmisi">Geçmiş →</a>
      </form>
      <div style="flex-basis:100%;display:flex;gap:2px;align-items:flex-end;height:58px;margin-top:6px">
        <?php foreach ($durChart as $d => $v): ?>
          <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:2px;min-width:4px" title="<?=htmlspecialchars($d)?>: <?= (int)$v['avg_ms'] ?> ms<?= (int)$v['err'] > 0 ? ', ' . (int)$v['err'] . ' hata' : '' ?>">
            <i style="display:block;width:100%;background:<?= (int)$v['avg_ms'] > 0 ? '#10211f' : '#e1e5de' ?>;border-radius:2px 2px 0 0;height:<?= max(2, (int) round((int)$v['avg_ms'] / $durMax * 50)) ?>px"></i>
            <?php if ((int) $v['err'] > 0): ?><span style="width:5px;height:5px;border-radius:50%;background:#b0301a"></span><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <p><a class="logout" href="/nexustraveltech/admin/kontrol-merkezi">Platform kontrol merkezi →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/uyari-merkezi">Operasyon uyarıları →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/acenteler">Acente yönetimi →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/ozellik-listeleri">Katalog & sınıflandırma yönetimi (tipler, yıldızlar, temalar, özellikler) →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/otel-cevirileri">Sınıflandırma çevirileri (6 dil) →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/dagitim-sagligi">Dağıtım sağlığı →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/tedarikci-onaylari">Tedarikçi kimlik ve yetki onayları →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/ai-ayarlari">DeepSeek metin AI →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/gemini-ayarlari">Gemini görsel AI →</a></p>

    <p><a class="logout" href="/nexustraveltech/admin/kullanim-kilavuzu" title="Sunucu kurulum, migration, webhook test, hata giderme kılavuzu">📖 Kullanım kılavuzu →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/timerlar">Zamanlayıcılar →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/zamanlayici-gecmisi">Zamanlayıcı geçmişi →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/migration-durumu">Migration durumu →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/netgsm-ayarlari">Netgsm SMS merkezi →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/sms-yonetimi">SMS paket ve kredi yönetimi →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/2fa">İki adımlı doğrulama →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/kvkk">KVKK veri aracı →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/eposta-sablonlari">E-posta şablonları →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/denetim-kayitlari">Denetim kayıtları →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/hata-izleme">Hata izleme →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/ziyaretci-sohbet">Ziyaretçi sohbet kayıtları →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/sohbet-raporu">Aylık sohbet raporu →</a></p>

    <?php if ($leads === []): ?>
      <div class="empty">Henuz basvuru yok.</div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Tarih</th>
            <th>E-posta</th>
            <th>Rol</th>
            <th>Dil</th>
            <th>Para</th>
            <th>IP</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($leads as $lead): ?>
            <tr>
              <td><?= htmlspecialchars((string) $lead['created_at']) ?></td>
              <td><?= htmlspecialchars((string) $lead['email']) ?></td>
              <td><?= htmlspecialchars((string) $lead['role']) ?></td>
              <td><?= htmlspecialchars((string) $lead['language']) ?></td>
              <td><?= htmlspecialchars((string) $lead['currency']) ?></td>
              <td><?= htmlspecialchars((string) $lead['ip_address']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </main>
<?php require_once __DIR__.'/../config/ai_widget.php'; ai_widget('/nexustraveltech/admin/ai-chat','admin_csrf'); ?></body>
</html>
