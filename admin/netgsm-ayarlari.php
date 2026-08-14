<?php
declare(strict_types=1);
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/ai_settings.php';
require __DIR__ . '/../config/platform_settings.php';

require_admin();
if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
$message = ''; $error = '';
$current = db()->query("SELECT encrypted_api_key,model FROM ai_provider_settings WHERE provider='netgsm' LIMIT 1")->fetch() ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['admin_csrf'], (string) ($_POST['csrf'] ?? ''))) $error = 'Güvenlik doğrulaması geçersiz.';
    else try {
        $usercode = trim((string) ($_POST['usercode'] ?? ''));
        $password = trim((string) ($_POST['password'] ?? ''));
        $header = trim((string) ($_POST['header'] ?? ''));
        $endpoint = trim((string) ($_POST['endpoint'] ?? '')) ?: 'https://api.netgsm.com.tr/sms/send/get/';
        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) throw new RuntimeException('Geçerli bir Netgsm API adresi girin.');
        if (empty($current['encrypted_api_key']) && ($usercode === '' || $password === '' || $header === '')) throw new RuntimeException('İlk kurulumda API kullanıcı adı, şifre ve onaylı gönderici başlığı zorunludur.');
        if (($usercode !== '' || $password !== '' || $header !== '') && ($usercode === '' || $password === '' || $header === '')) throw new RuntimeException('API bilgilerini birlikte girin; boş bırakırsanız önceki şifreli kayıt korunur.');
        if ($usercode !== '') {
            $secret = json_encode(['usercode' => $usercode, 'password' => $password, 'header' => $header], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            db()->prepare("INSERT INTO ai_provider_settings(provider,encrypted_api_key,model,updated_at) VALUES('netgsm',?,?,now()) ON CONFLICT(provider) DO UPDATE SET encrypted_api_key=EXCLUDED.encrypted_api_key,model=EXCLUDED.model,updated_at=now()")
                ->execute([encrypt_ai_secret($secret), $endpoint]);
        } else db()->prepare("UPDATE ai_provider_settings SET model=?,updated_at=now() WHERE provider='netgsm'")->execute([$endpoint]);
        save_platform_setting('netgsm_sms_enabled', isset($_POST['enabled']));
        $message = 'Netgsm ayarları kaydedildi.';
    } catch (Throwable $exception) { $error = $exception->getMessage(); }
    $current = db()->query("SELECT encrypted_api_key,model FROM ai_provider_settings WHERE provider='netgsm' LIMIT 1")->fetch() ?: [];
}
$configured = !empty($current['encrypted_api_key']);
$enabled = (bool) platform_setting('netgsm_sms_enabled', false);
$queue = db()->query("SELECT status,COUNT(*) AS total FROM sms_outbox GROUP BY status ORDER BY status")->fetchAll();
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Netgsm SMS | NEXUS Admin</title><style>body{margin:0;background:#f7f7f2;color:#10211f;font-family:Arial}.wrap{width:min(760px,calc(100% - 32px));margin:40px auto}.card{background:#fff;border:1px solid #e1e5de;padding:24px;margin-top:18px}.form{display:grid;gap:14px}.form input,.form button{padding:11px;border:1px solid #d8ded8;font:inherit}.form button{background:#10211f;color:#fff;font-weight:bold}.notice{background:#e6f8c7;padding:10px}.error{background:#ffe2de;padding:10px}.muted{color:#64716d;line-height:1.5}.back{color:#10211f}.status{display:flex;gap:9px;flex-wrap:wrap}.tag{background:#edf1eb;padding:6px 9px}</style></head><body><main class="wrap"><a class="back" href="/nexustraveltech/admin/">← Yönetim paneli</a><div class="card"><h1>Netgsm SMS merkezi</h1><p class="muted">Rezervasyon bildirimleri için Netgsm API hesabınızı tanımlayın. Anahtar bilgileri AES-256-GCM ile şifrelenir ve kayıttan sonra tekrar gösterilmez. Ticari iletiler için İYS yükümlülüklerinin işletmenizde kontrol edilmesi gerekir.</p><p>Yapılandırma: <b><?= $configured ? 'hazır' : 'bekliyor' ?></b> · Gönderim: <b><?= $enabled ? 'açık' : 'kapalı' ?></b></p><?php if($message): ?><p class="notice"><?= htmlspecialchars($message) ?></p><?php endif; ?><?php if($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?><form method="post" class="form"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><label>Netgsm API kullanıcı adı<input name="usercode" autocomplete="off" placeholder="Mevcut bilgiyi korumak için boş bırakın"></label><label>Netgsm API şifresi<input type="password" name="password" autocomplete="new-password" placeholder="Mevcut bilgiyi korumak için boş bırakın"></label><label>Onaylı gönderici başlığı<input name="header" maxlength="11" placeholder="Örn. NEXUS" ></label><label>API adresi<input name="endpoint" value="<?= htmlspecialchars((string)($current['model'] ?? 'https://api.netgsm.com.tr/sms/send/get/')) ?>"></label><label><input type="checkbox" name="enabled" <?= $enabled ? 'checked' : '' ?>> SMS gönderimini aktif et</label><button>Netgsm ayarlarını kaydet</button></form></div><div class="card"><h2>Kuyruk durumu</h2><div class="status"><?php if(!$queue): ?><span class="tag">Kuyruk boş</span><?php else: foreach($queue as $item): ?><span class="tag"><?= htmlspecialchars($item['status']) ?>: <?= (int)$item['total'] ?></span><?php endforeach; endif; ?></div><p class="muted">Paket, kredi ve bildirim telefonu tanımları için <a href="/nexustraveltech/admin/sms-yonetimi">SMS paket yönetimini</a> kullanın.</p></div></main></body></html>
