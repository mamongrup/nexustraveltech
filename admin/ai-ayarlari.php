<?php

declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/ai_settings.php';

require_admin();

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';
$current = db()->query("SELECT encrypted_api_key, model, updated_at FROM ai_provider_settings WHERE provider = 'deepseek' LIMIT 1")->fetch() ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['admin_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Güvenlik doğrulaması geçersiz. Sayfayı yenileyip tekrar deneyin.';
    } else {
        try {
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'save') {
                $apiKey = trim((string) ($_POST['api_key'] ?? ''));
                $model = trim((string) ($_POST['model'] ?? '')) ?: 'deepseek-chat';

                if ($apiKey !== '' && strlen($apiKey) < 16) {
                    throw new RuntimeException('Geçerli bir DeepSeek API anahtarı girin.');
                }
                if ($apiKey === '' && empty($current['encrypted_api_key'])) {
                    throw new RuntimeException('İlk kurulum için DeepSeek API anahtarı zorunludur.');
                }

                if ($apiKey !== '') {
                    db()->prepare("INSERT INTO ai_provider_settings (provider, encrypted_api_key, model, updated_at) VALUES ('deepseek', ?, ?, now()) ON CONFLICT (provider) DO UPDATE SET encrypted_api_key = EXCLUDED.encrypted_api_key, model = EXCLUDED.model, updated_at = now()")
                        ->execute([encrypt_ai_secret($apiKey), $model]);
                } else {
                    db()->prepare("UPDATE ai_provider_settings SET model = ?, updated_at = now() WHERE provider = 'deepseek'")
                        ->execute([$model]);
                }
                $message = 'DeepSeek ayarları güvenle kaydedildi.';
            }

            if ($action === 'remove') {
                db()->prepare("UPDATE ai_provider_settings SET encrypted_api_key = NULL, updated_at = now() WHERE provider = 'deepseek'")->execute();
                $message = 'DeepSeek API anahtarı silindi. AI çevirileri devre dışı bırakıldı.';
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
    $current = db()->query("SELECT encrypted_api_key, model, updated_at FROM ai_provider_settings WHERE provider = 'deepseek' LIMIT 1")->fetch() ?: [];
}

$hasKey = !empty($current['encrypted_api_key']);
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>DeepSeek AI ayarları | NEXUS Admin</title>
  <style>body{margin:0;background:#f7f7f2;color:#10211f;font-family:Arial,sans-serif}.wrap{width:min(760px,calc(100% - 32px));margin:40px auto}.top{display:flex;justify-content:space-between;gap:20px;align-items:center}.brand{font-size:28px;font-weight:800}.brand span{color:#e85f42}.back{color:#10211f}.card{background:#fff;border:1px solid #e1e5de;padding:24px;margin-top:24px}.muted{color:#64716d;line-height:1.55}.form{display:grid;gap:14px}.form label{display:grid;gap:7px;font-size:13px;font-weight:700}.form input{padding:11px;border:1px solid #d8ded8;background:#fff;font:inherit}.save,.danger{border:0;padding:11px 14px;font-weight:700;cursor:pointer}.save{background:#10211f;color:#fff}.danger{background:#ffe3dd;color:#8e2410}.notice{background:#e6f8c7;padding:11px}.error{background:#ffe2de;padding:11px}.status{display:flex;gap:9px;align-items:center}.dot{width:10px;height:10px;border-radius:50%;background:#d2644f}.dot.on{background:#3f9a65}.remove{margin-top:16px;padding-top:16px;border-top:1px solid #e8ece5}</style>
</head>
<body>
  <main class="wrap">
    <div class="top"><div><div class="brand">N<span>∿</span>XUS Admin</div><p class="muted">DeepSeek AI entegrasyonu</p></div><a class="back" href="/nexustraveltech/admin/">← Panele dön</a></div>
    <?php if ($message): ?><p class="notice"><?= htmlspecialchars($message) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <section class="card">
      <div class="status"><i class="dot <?= $hasKey ? 'on' : '' ?>"></i><strong><?= $hasKey ? 'API anahtarı kayıtlı' : 'API anahtarı eklenmedi' ?></strong></div>
      <p class="muted">Anahtar şifrelenerek saklanır ve güvenlik nedeniyle bu ekranda tekrar gösterilmez. Yeni bir anahtar girerseniz mevcut anahtarın yerini alır.</p>
      <form method="post" class="form"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="save"><label>DeepSeek API anahtarı<input type="password" name="api_key" autocomplete="new-password" placeholder="<?= $hasKey ? 'Değiştirmek için yeni anahtarı girin' : 'sk-...' ?>"></label><label>Kullanılacak model<input name="model" maxlength="80" value="<?= htmlspecialchars((string) ($current['model'] ?? 'deepseek-chat')) ?>" placeholder="deepseek-chat"></label><button class="save">Ayarları kaydet</button></form>
      <?php if ($hasKey): ?><form method="post" class="remove" onsubmit="return confirm('API anahtarı silinsin mi? AI çeviri işlemleri çalışmaz.');"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="remove"><button class="danger">API anahtarını sil</button></form><?php endif; ?>
    </section>
  </main>
<?php require_once __DIR__.'/../config/ai_widget.php'; ai_widget('/nexustraveltech/admin/ai-chat','admin_csrf'); ?></body>
</html>
