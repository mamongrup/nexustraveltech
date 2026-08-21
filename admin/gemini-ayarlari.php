<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/ai_settings.php';
require_once __DIR__ . '/layout.php';

require_admin();

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';
$current = db()->query("SELECT encrypted_api_key, model FROM ai_provider_settings WHERE provider='gemini' LIMIT 1")->fetch() ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['admin_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Güvenlik doğrulaması geçersiz.';
    } else {
        try {
            $key = trim((string) ($_POST['api_key'] ?? ''));
            $model = trim((string) ($_POST['model'] ?? '')) ?: 'gemini-1.5-flash';
            if ($key !== '' && strlen($key) < 16) {
                throw new RuntimeException('Geçerli bir Gemini API anahtarı girin.');
            }
            if ($key === '' && empty($current['encrypted_api_key'])) {
                throw new RuntimeException('İlk kurulumda API anahtarı zorunludur.');
            }
            if ($key !== '') {
                db()->prepare("INSERT INTO ai_provider_settings(provider, encrypted_api_key, model, updated_at) VALUES('gemini', ?, ?, now()) ON CONFLICT(provider) DO UPDATE SET encrypted_api_key=EXCLUDED.encrypted_api_key, model=EXCLUDED.model, updated_at=now()")
                    ->execute([encrypt_ai_secret($key), $model]);
            } else {
                db()->prepare("UPDATE ai_provider_settings SET model=?, updated_at=now() WHERE provider='gemini'")
                    ->execute([$model]);
            }
            $message = 'Google Gemini görsel AI ayarları başarıyla kaydedildi.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        $current = db()->query("SELECT encrypted_api_key, model FROM ai_provider_settings WHERE provider='gemini' LIMIT 1")->fetch() ?: [];
    }
}

$has = !empty($current['encrypted_api_key']);

admin_layout_start('Google Gemini Görsel AI', 'gemini-ayarlari');
?>

<?php if ($message): ?><div class="sui-alert sui-alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="sui-alert sui-alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="sui-card" style="max-width:700px">
    <div class="sui-card-header">
        <div>
            <h2 class="sui-card-title">✨ Google Gemini Görsel & Multimodal AI</h2>
            <p style="color:var(--sui-muted);font-size:13px;margin:4px 0 0 0">
                Otel fotoğrafları kalite denetimi, oda tipleri eşleme ve görsel benzerlik tespiti.
            </p>
        </div>
        <span class="sui-badge <?= $has ? 'sui-badge-success' : 'sui-badge-warning' ?>">
            <?= $has ? 'Aktif' : 'Anahtar Bekliyor' ?>
        </span>
    </div>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">

        <div style="margin-bottom:16px">
            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:6px">Gemini API Anahtarı (AES-256 Şifrelenir)</label>
            <input type="password" name="api_key" class="sui-input" autocomplete="new-password" placeholder="<?= $has ? '••••••••••••••••••••••••••••••••' : 'AIza...' ?>">
            <small style="color:var(--sui-muted);font-size:11px;display:block;margin-top:4px">
                <?= $has ? '✓ Anahtar güvenli saklanıyor. Değiştirmek istemiyorsanız boş bırakın.' : 'Google AI Studio üzerinden oluşturduğunuz API Key.' ?>
            </small>
        </div>

        <div style="margin-bottom:16px">
            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:6px">Model Seçimi</label>
            <input type="text" name="model" class="sui-input" value="<?= htmlspecialchars((string) ($current['model'] ?? 'gemini-1.5-flash')) ?>" placeholder="gemini-1.5-flash">
        </div>

        <button class="sui-btn sui-btn-primary">Gemini Ayarlarını Kaydet</button>
    </form>
</div>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>

