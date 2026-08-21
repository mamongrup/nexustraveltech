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
require_once __DIR__ . '/layout.php';
admin_layout_start('DeepSeek AI Yapılandırması', 'ai-ayarlari');
?>
<?php if ($message): ?><div class="sui-alert sui-alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="sui-alert sui-alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="sui-card" style="max-width:700px">
    <div class="sui-card-header">
        <div>
            <h2 class="sui-card-title">🤖 DeepSeek AI Motoru</h2>
            <p style="color:var(--sui-muted);font-size:13px;margin:4px 0 0 0">
                Otel açıklamaları, çok dilli çeviriler ve akıllı gelir önerilerini besleyen yapay zeka entegrasyonu.
            </p>
        </div>
        <span class="sui-badge <?= $hasKey ? 'sui-badge-success' : 'sui-badge-warning' ?>">
            <?= $hasKey ? 'API Bağlı' : 'Anahtar Yok' ?>
        </span>
    </div>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
        <input type="hidden" name="action" value="save">

        <div style="margin-bottom:16px">
            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:6px">DeepSeek API Anahtarı (AES-256 Şifrelenir)</label>
            <input type="password" name="api_key" class="sui-input" placeholder="<?= $hasKey ? '••••••••••••••••••••••••••••••••' : 'sk-...' ?>" autocomplete="new-password">
            <small style="color:var(--sui-muted);font-size:11px;display:block;margin-top:4px">
                <?= $hasKey ? '✓ Anahtar güvenli vault içinde şifreli saklanıyor. Değiştirmek istemiyorsanız boş bırakın.' : 'DeepSeek platformundan aldığınız API key.' ?>
            </small>
        </div>

        <div style="margin-bottom:16px">
            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:6px">Model Seçimi</label>
            <input type="text" name="model" class="sui-input" value="<?= htmlspecialchars((string) ($current['model'] ?? 'deepseek-chat')) ?>" placeholder="deepseek-chat">
        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:20px">
            <button class="sui-btn sui-btn-primary">Ayarları Kaydet</button>
        </div>
    </form>

    <?php if ($hasKey): ?>
        <div style="margin-top:16px;border-top:1px solid var(--sui-border);padding-top:14px">
            <form method="post" onsubmit="return confirm('API anahtarını silmek istediğinize emin misiniz?');">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                <input type="hidden" name="action" value="remove">
                <button class="sui-btn sui-btn-danger sui-btn-sm">Anahtarı Sil ve Devre Dışı Bırak</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>
