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
<?php
require_once __DIR__ . '/layout.php';
admin_layout_start('Netgsm SMS Entegrasyonu', 'netgsm-ayarlari');
?>

<?php if ($message): ?><div class="sui-alert sui-alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="sui-alert sui-alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(320px, 1fr));gap:20px">
    <!-- Ayarlar -->
    <div class="sui-card">
        <div class="sui-card-header">
            <div>
                <h2 class="sui-card-title">📱 Netgsm API Ayarları</h2>
                <p style="color:var(--sui-muted);font-size:13px;margin:4px 0 0 0">
                    Rezervasyon ve 2FA SMS bildirimleri için Netgsm hesabı.
                </p>
            </div>
            <span class="sui-badge <?= $configured ? 'sui-badge-success' : 'sui-badge-warning' ?>">
                <?= $configured ? 'Yapılandırıldı' : 'Yapılandırılmadı' ?>
            </span>
        </div>

        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">

            <div style="margin-bottom:14px">
                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Netgsm API Kullanıcı Adı</label>
                <input name="usercode" class="sui-input" autocomplete="off" placeholder="<?= $configured ? 'Mevcut bilgiyi korumak için boş bırakın' : '850xxxxxxx' ?>">
            </div>

            <div style="margin-bottom:14px">
                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Netgsm API Şifresi</label>
                <input type="password" name="password" class="sui-input" autocomplete="new-password" placeholder="<?= $configured ? 'Mevcut şifreyi korumak için boş bırakın' : '••••••••' ?>">
            </div>

            <div style="margin-bottom:14px">
                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Onaylı Gönderici Başlığı (Originator / Header)</label>
                <input name="header" class="sui-input" maxlength="11" placeholder="NEXUS">
            </div>

            <div style="margin-bottom:16px">
                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">API Endpoint Adresi</label>
                <input name="endpoint" class="sui-input" value="<?= htmlspecialchars((string)($current['model'] ?? 'https://api.netgsm.com.tr/sms/send/get/')) ?>">
            </div>

            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:20px">
                <label style="font-size:13px;display:flex;align-items:center;gap:6px">
                    <input type="checkbox" name="enabled" <?= $enabled ? 'checked' : '' ?>> SMS Gönderimini Aktif Et
                </label>
                <button class="sui-btn sui-btn-primary">Ayarları Kaydet</button>
            </div>
        </form>
    </div>

    <!-- Kuyruk Durumu -->
    <div class="sui-card">
        <div class="sui-card-header">
            <h2 class="sui-card-title">📬 SMS Giden Kuyruğu (Outbox)</h2>
        </div>
        
        <div style="display:grid;gap:10px;margin-bottom:20px">
            <?php if (!$queue): ?>
                <div style="padding:14px;background:var(--sui-bg);border-radius:6px;color:var(--sui-muted);font-size:13px">
                    Kuyrukta bekleyen veya işlenen SMS yok.
                </div>
            <?php else: ?>
                <?php foreach ($queue as $item): ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:var(--sui-bg);border-radius:6px">
                        <span style="font-weight:600;font-size:13px"><?= htmlspecialchars($item['status']) ?></span>
                        <span class="sui-badge sui-badge-info"><?= (int)$item['total'] ?> Adet</span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <p style="font-size:13px;color:var(--sui-muted);line-height:1.5">
            Tedarikçi ve acentelere SMS kredisi tanımlamak için <a href="sms-yonetimi" style="color:var(--sui-primary);font-weight:600">SMS Paket & Hak Yönetimi</a> sayfasını kullanabilirsiniz.
        </p>
    </div>
</div>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>

