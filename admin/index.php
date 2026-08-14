<?php

declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';

require_admin();

$leads = db()
    ->query('SELECT * FROM early_access_leads ORDER BY created_at DESC LIMIT 200')
    ->fetchAll();

$total = (int) db()->query('SELECT COUNT(*) FROM early_access_leads')->fetchColumn();
$identityAlerts = (int) db()->query("SELECT COUNT(*) FROM admin_alerts WHERE is_read=false AND alert_type='identity_verification_failed'")->fetchColumn();
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>NEXUS Admin Panel</title>
  <style>
    body{margin:0;background:#f7f7f2;color:#10211f;font-family:Arial,sans-serif}.wrap{width:min(1160px,calc(100% - 32px));margin:40px auto}.top{display:flex;justify-content:space-between;gap:20px;align-items:center}.brand{font-size:28px;font-weight:800}.brand span{color:#e85f42}.pill{display:inline-block;background:#d7ff48;padding:10px 14px;font-weight:800}.logout{color:#10211f}table{width:100%;border-collapse:collapse;margin-top:28px;background:#fff}th,td{text-align:left;border-bottom:1px solid #e1e5de;padding:13px;font-size:14px}th{font-size:12px;text-transform:uppercase;color:#64716d}tr:hover td{background:#fbfcf8}.empty{padding:30px;background:#fff;margin-top:28px}.muted{color:#64716d}
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

    <p><a class="logout" href="/nexustraveltech/admin/kontrol-merkezi">Platform kontrol merkezi →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/uyari-merkezi">Operasyon uyarıları →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/acenteler">Acente yönetimi →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/otel-siniflandirma">Otel tipleri, yıldızlar ve temaları yönet →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/otel-cevirileri">6 dilde çevirileri yönet →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/tedarikci-onaylari">Tedarikçi kimlik ve yetki onayları →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/ai-ayarlari">DeepSeek metin AI →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/gemini-ayarlari">Gemini görsel AI →</a></p>

    <p><a class="logout" href="/nexustraveltech/admin/netgsm-ayarlari">Netgsm SMS merkezi →</a> &nbsp; | &nbsp; <a class="logout" href="/nexustraveltech/admin/sms-yonetimi">SMS paket ve kredi yönetimi →</a></p>

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
</body>
</html>
