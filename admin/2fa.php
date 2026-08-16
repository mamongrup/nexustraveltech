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
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>İki adımlı doğrulama | NEXUS Admin</title>
  <style>
    body{margin:0;font-family:Arial;background:#f7f7f2;color:#10211f}.w{width:min(520px,calc(100% - 32px));margin:45px auto}.c{background:#fff;border:1px solid #ddd;padding:22px;margin-top:16px}input,button{padding:10px;font:inherit;border:1px solid #d8ded8}.er{color:#8e2410;background:#ffe2de;padding:9px}.ok{background:#e6f8c7;padding:9px}button{background:#10211f;color:#fff;border:0;cursor:pointer;margin-top:10px}
  </style>
</head>
<body>
<main class="w"><a href="/nexustraveltech/admin/">← Panel</a><h1>İki adımlı doğrulama</h1>
<?php if ($message): ?><p class="ok"><?= htmlspecialchars($message) ?></p><?php endif; ?>
<?php if ($error): ?><p class="er"><?= htmlspecialchars($error) ?></p><?php endif; ?>

<?php if ($pending): ?>
<section class="c">
  <h2>Doğrulama kodu</h2>
  <p>Kimlik doğrulama uygulamanızdaki 6 haneli kodu girin.</p>
  <form method="post">
    <input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="123456" required>
    <br><button>Doğrula</button>
  </form>
</section>
<?php else: ?>
<?php $enabled = (bool) ($row['enabled'] ?? false); ?>
<section class="c">
  <?php if ($enabled): ?>
    <h2>Durum: AÇIK</h2>
    <p>Admin girişleri doğrulama kodu gerektiriyor.</p>
    <form method="post"><input type="hidden" name="action" value="disable"><button style="background:#8e2410">Kapat</button></form>
  <?php else: ?>
    <h2>Kurulum</h2>
    <?php if (!$row || $row['secret'] === ''): ?>
      <form method="post"><input type="hidden" name="action" value="new_secret"><button>Gizli anahtar oluştur</button></form>
    <?php else: ?>
      <?php $otpauth = totp_otpauth_url('NEXUS Admin', (string) $row['secret'], 'NEXUS'); ?>
      <p>1. Google Authenticator / Authy ile QR'ı okutun veya anahtarı elle girin.</p>
      <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?= rawurlencode($otpauth) ?>" alt="QR">
      <p><code><?= htmlspecialchars((string) $row['secret']) ?></code></p>
      <form method="post">
        <input type="hidden" name="action" value="new_secret"><button style="background:#a86026">Yeni anahtar üret</button>
      </form>
      <form method="post">
        <input type="hidden" name="action" value="enable">
        <input name="code" inputmode="numeric" maxlength="6" placeholder="Uygulamadaki 6 haneli kod" required>
        <br><button>Kodu doğrula &amp; etkinleştir</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php endif; ?>
</main>
</body>
</html>
