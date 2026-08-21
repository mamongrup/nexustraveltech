<?php
declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/totp.php';
require __DIR__ . '/../config/audit.php';

admin_session();
$pending = ($_SESSION['admin_2fa_pending'] ?? false) === true;
if (!$pending && ($_SESSION['admin_logged_in'] ?? false) !== true) {
    header('Location: /nexustraveltech/admin/login');
    exit;
}

$error = '';
$message = '';
$row = null;
try {
    $row = db()->query('SELECT secret,enabled FROM admin_2fa WHERE id=1')->fetch() ?: null;
} catch (Throwable $e) {
    $row = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($pending) {
        $attempts = (int) ($_SESSION['admin_2fa_attempts'] ?? 0);
        if ($attempts >= 10) {
            $error = 'Çok fazla hatalı kod denemesi. Tekrar giriş yapın.';
        } elseif ($row && $row['secret'] !== '' && totp_verify((string) $row['secret'], (string) ($_POST['code'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = (string) ($_SESSION['admin_pending_username'] ?? 'admin');
            unset($_SESSION['admin_2fa_pending'], $_SESSION['admin_pending_username'], $_SESSION['admin_2fa_attempts']);
            audit_log('admin.login', null, null, ['username' => $_SESSION['admin_username'], 'mfa' => true]);
            header('Location: /nexustraveltech/admin/');
            exit;
        } else {
            $_SESSION['admin_2fa_attempts'] = $attempts + 1;
            $error = 'Kod hatalı.';
        }
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'new_secret') {
            $secret = totp_secret();
            db()->prepare("INSERT INTO admin_2fa(id,secret,enabled,updated_at) VALUES(1,?,false,now()) ON CONFLICT(id) DO UPDATE SET secret=EXCLUDED.secret,enabled=false,updated_at=now()")
                ->execute([$secret]);
            $message = 'Yeni gizli anahtar oluşturuldu. Uygulamaya ekleyip kodu doğrulayın.';
        } elseif ($action === 'enable') {
            if (!$row || $row['secret'] === '') {
                $error = 'Önce gizli anahtar oluşturun.';
            } elseif (totp_verify((string) $row['secret'], (string) ($_POST['code'] ?? ''))) {
                db()->prepare("INSERT INTO admin_2fa(id,secret,enabled,updated_at) VALUES(1,?,true,now()) ON CONFLICT(id) DO UPDATE SET enabled=true,updated_at=now()")
                    ->execute([$row['secret']]);
                audit_log('admin.2fa_enabled');
                $message = 'İki adımlı doğrulama etkinleştirildi.';
            } else {
                $error = 'Kod hatalı.';
            }
        } elseif ($action === 'disable') {
            db()->prepare("UPDATE admin_2fa SET enabled=false,updated_at=now() WHERE id=1")->execute();
            audit_log('admin.2fa_disabled');
            $message = 'İki adımlı doğrulama kapatıldı.';
        }
    }
    try {
        $row = db()->query('SELECT secret,enabled FROM admin_2fa WHERE id=1')->fetch() ?: null;
    } catch (Throwable $e) {
        $row = null;
    }
}
?>
<?php
require_once __DIR__ . '/layout.php';

if ($pending) {
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>2FA Giriş Doğrulama | NEXUS Admin</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="/nexustraveltech/assets/admin-softui.css">
</head>
<body class="sui" style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--sui-bg)">
  <div class="sui-card" style="width:min(420px, 92%);text-align:center;padding:32px">
    <div style="font-size:36px;margin-bottom:12px">🔐</div>
    <h2 style="margin:0 0 8px 0;font-size:20px">İki Adımlı Doğrulama</h2>
    <p style="color:var(--sui-muted);font-size:13px;margin:0 0 20px 0">Kimlik doğrulama uygulamanızdaki 6 haneli güvenlik kodunu girin.</p>
    
    <?php if ($error): ?><div class="sui-alert sui-alert-danger" style="text-align:left"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="post">
      <input name="code" class="sui-input" style="font-size:22px;letter-spacing:6px;text-align:center;font-weight:700;margin-bottom:16px" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="123456" autofocus required>
      <button class="sui-btn sui-btn-primary" style="width:100%">Girişi Doğrula</button>
    </form>
  </div>
</body>
</html>
<?php
exit;
}

admin_layout_start('İki Adımlı Doğrulama (2FA / MFA)', '2fa');
?>

<?php if ($message): ?><div class="sui-alert sui-alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="sui-alert sui-alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php $enabled = (bool) ($row['enabled'] ?? false); ?>

<div class="sui-card" style="max-width:650px">
    <div class="sui-card-header">
        <h2 class="sui-card-title">🔐 İki Adımlı Doğrulama (TOTP)</h2>
        <span class="sui-badge <?= $enabled ? 'sui-badge-success' : 'sui-badge-warning' ?>">
            <?= $enabled ? 'Aktif' : 'Devre Dışı' ?>
        </span>
    </div>

    <?php if ($enabled): ?>
        <p style="color:var(--sui-muted);font-size:14px;line-height:1.6">
            Admin paneli girişleriniz iki adımlı doğrulama ile güvence altındadır. Giriş yaparken Authenticator uygulamanızdaki tek kullanımlık 6 haneli kod istenecektir.
        </p>
        <form method="post" style="margin-top:20px">
            <input type="hidden" name="action" value="disable">
            <button class="sui-btn sui-btn-danger">2FA'yı Devre Dışı Bırak</button>
        </form>
    <?php else: ?>
        <p style="color:var(--sui-muted);font-size:14px">
            Hesabınızı daha güvenli hale getirmek için Google Authenticator, Microsoft Authenticator veya 1Password uygulamasıyla 2FA kurabilirsiniz.
        </p>
        <?php if (!$row || $row['secret'] === ''): ?>
            <form method="post" style="margin-top:16px">
                <input type="hidden" name="action" value="new_secret">
                <button class="sui-btn sui-btn-primary">Gizli Anahtar & QR Kod Oluştur</button>
            </form>
        <?php else: ?>
            <?php $otpauth = totp_otpauth_url('NEXUS Admin', (string) $row['secret'], 'NEXUS'); ?>
            <div style="background:var(--sui-bg);border-radius:var(--sui-radius-sm);padding:20px;margin:16px 0;text-align:center">
                <p style="font-weight:600;margin-top:0">1. QR Kodu Uygulamanızla Tarayın</p>
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?= rawurlencode($otpauth) ?>" alt="QR" style="border-radius:8px;border:1px solid var(--sui-border);padding:8px;background:#fff">
                <p style="margin:12px 0 0 0;font-size:12px;color:var(--sui-muted)">Manuel Anahtar: <code><?= htmlspecialchars((string) $row['secret']) ?></code></p>
            </div>
            
            <form method="post" style="margin-top:16px">
                <input type="hidden" name="action" value="enable">
                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:6px">2. Uygulamada Oluşan 6 Haneli Kodu Girin:</label>
                <div style="display:flex;gap:8px">
                    <input name="code" class="sui-input" style="max-width:200px;font-size:18px;letter-spacing:3px" inputmode="numeric" maxlength="6" placeholder="123456" required>
                    <button class="sui-btn sui-btn-success">Doğrula & Etkinleştir</button>
                </div>
            </form>
            <form method="post" style="margin-top:12px">
                <input type="hidden" name="action" value="new_secret">
                <button class="sui-btn sui-btn-outline sui-btn-sm">Farklı Anahtar Üret</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>

