<?php

declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/platform_settings.php';
require __DIR__ . '/../config/ai_settings.php';

require_admin();

$leads = db()
    ->query('SELECT * FROM early_access_leads ORDER BY created_at DESC LIMIT 200')
    ->fetchAll();

$total = (int) db()->query('SELECT COUNT(*) FROM early_access_leads')->fetchColumn();
$identityAlerts = (int) db()->query("SELECT COUNT(*) FROM admin_alerts WHERE is_read=false AND alert_type='identity_verification_failed'")->fetchColumn();
$blockCount = (int) db()->query("SELECT COUNT(*) FROM blocked_ips WHERE action='block'")->fetchColumn();
$flagCount = (int) db()->query("SELECT COUNT(*) FROM blocked_ips WHERE action='flag'")->fetchColumn();

// Sohbet ayarları özeti.
$chatMinLen = max(1, (int) platform_setting('chat_min_length', 5));
$chatRequireSpace = (bool) platform_setting('chat_require_space', true);
$chatBlocklist = array_values(array_filter(array_map('trim', (array) platform_setting('chat_blocklist', [])), fn($l) => $l !== ''));
$aiKeyReady = false;
try {
    $aiKeyReady = deepseek_settings()['api_key'] !== '';
} catch (Throwable $e) {
    $aiKeyReady = false;
}
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
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>NEXUS Admin Panel</title>
  <style>
    body{margin:0;background:#f7f7f2;color:#10211f;font-family:Arial,sans-serif}.wrap{width:min(1160px,calc(100% - 32px));margin:40px auto}.top{display:flex;justify-content:space-between;gap:20px;align-items:center}.brand{font-size:28px;font-weight:800}.brand span{color:#e85f42}.pill{display:inline-block;background:#d7ff48;padding:10px 14px;font-weight:800}.logout{color:#10211f}table{width:100%;border-collapse:collapse;margin-top:28px;background:#fff}th,td{text-align:left;border-bottom:1px solid #e1e5de;padding:13px;font-size:14px}th{font-size:12px;text-transform:uppercase;color:#64716d}tr:hover td{background:#fbfcf8}.empty{padding:30px;background:#fff;margin-top:28px}.muted{color:#64716d}.ip-stats{display:flex;gap:10px;flex-wrap:wrap;margin:18px 0}.ip-chip{display:inline-flex;align-items:center;gap:6px;padding:9px 14px;font-size:13px;font-weight:700;text-decoration:none;border-radius:6px}.ip-chip b{font-size:17px}.ip-block{background:#ffe2de;color:#8e2410}.ip-flag{background:#fdf0d8;color:#8a5a10}.ip-chip:hover{opacity:.85}.chat-card{background:#fff;border:1px solid #e1e5de;padding:14px 16px;margin:18px 0;display:flex;flex-wrap:wrap;gap:16px 28px;align-items:flex-start}.chat-card h3{margin:0 0 8px;font-size:14px;flex-basis:100%}.chat-card .item{font-size:12px;color:#64716d}.chat-card .item b{font-size:16px;display:block;color:#10211f;margin-top:2px}.chat-card .ok{color:#0d7a4a}.chat-card .warn{color:#a86026}.chat-card .edit{margin-left:auto;font-size:13px;font-weight:700;color:#0d7a4a;text-decoration:none;align-self:center}.chat-card .week{display:flex;align-items:flex-end;gap:3px;height:34px;margin-top:5px}.chat-card .week i{flex:1;background:#e8a33d;border-radius:2px 2px 0 0;display:block;min-width:5px}
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
    </div>

    <div class="chat-card">
      <h3>💬 Sohbet ayarları</h3>
      <div class="item">Minimum soru uzunluğu<b><?= (int)$chatMinLen ?> karakter</b></div>
      <div class="item">Tek kelime engeli<b><?= $chatRequireSpace ? 'Açık' : 'Kapalı' ?></b></div>
      <div class="item">Yasak kelime<b><?= count($chatBlocklist) ?> kayıt</b><?php if ($chatBlocklist): ?><span style="font-size:12px;color:#64716d;display:block;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars(implode(', ', array_slice($chatBlocklist, 0, 3)))?><?= count($chatBlocklist) > 3 ? '…' : '' ?></span><?php endif; ?></div>
      <div class="item">Bugünkü soru<b><?= (int)$chatToday ?></b></div>
      <div class="item" style="min-width:160px">Son 7 gün eğilimi<div class="week"><?php foreach ($chatWeek as $d => $c): ?><i title="<?=htmlspecialchars($d)?>: <?=$c?> soru" style="height:<?=max(2, (int) round($c / $chatWeekMax * 30))?>px"></i><?php endforeach; ?></div></div>
      <div class="item">DeepSeek anahtarı<b class="<?= $aiKeyReady ? 'ok' : 'warn' ?>"><?= $aiKeyReady ? '✅ Ayarlı' : '⚠ Eksik' ?></b></div>
      <a class="edit" href="/nexustraveltech/admin/kontrol-merkezi">Düzenle →</a>
    </div>

    <p><a class="logout" href="/nexustraveltech/admin/kontrol-merkezi">Platform kontrol merkezi →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/uyari-merkezi">Operasyon uyarıları →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/acenteler">Acente yönetimi →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/otel-siniflandirma">Otel tipleri, yıldızlar ve temaları yönet →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/otel-cevirileri">6 dilde çevirileri yönet →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/tedarikci-onaylari">Tedarikçi kimlik ve yetki onayları →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/ai-ayarlari">DeepSeek metin AI →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/gemini-ayarlari">Gemini görsel AI →</a></p>

    <p><a class="logout" href="/nexustraveltech/admin/timerlar">Zamanlayıcılar →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/netgsm-ayarlari">Netgsm SMS merkezi →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/sms-yonetimi">SMS paket ve kredi yönetimi →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/2fa">İki adımlı doğrulama →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/kvkk">KVKK veri aracı →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/eposta-sablonlari">E-posta şablonları →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/denetim-kayitlari">Denetim kayıtları →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/hata-izleme">Hata izleme →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/ziyaretci-sohbet">Ziyaretçi sohbet kayıtları →</a></p>

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
