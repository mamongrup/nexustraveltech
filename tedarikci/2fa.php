<?php
require_once __DIR__ . '/../config/supplier_auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/totp.php';
supplier_session();
$pending = $_SESSION['supplier_2fa_pending'] ?? null;
$loggedIn = supplier_user();
if (!$pending && !$loggedIn) { header('Location: /nexustraveltech/tedarikci/login'); exit; }
$error = '';
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if ($pending) {
    $attempts = (int) ($_SESSION['supplier_2fa_attempts'] ?? 0);
    if ($attempts >= 10) $error = 'Çok fazla hatalı kod denemesi. Tekrar giriş yapın.';
    elseif (totp_verify((string) ($pending['totp_secret'] ?? ''), (string) ($_POST['code'] ?? ''))) {
      session_regenerate_id(true);
      $_SESSION['supplier_user'] = $pending;
      unset($_SESSION['supplier_2fa_pending'], $_SESSION['supplier_2fa_attempts']);
      db()->prepare('UPDATE supplier_users SET last_login_at=now() WHERE id=?')->execute([$pending['id']]);
      header('Location: /nexustraveltech/tedarikci/'); exit;
    } else { $_SESSION['supplier_2fa_attempts'] = $attempts + 1; $error = 'Kod hatalı.'; }
  } elseif ($loggedIn) {
    if ($action === 'new_secret') { $_SESSION['supplier_2fa_new_secret'] = totp_secret(); $message = 'Yeni gizli anahtar oluşturuldu. Uygulamaya ekleyip kodu doğrulayın.'; }
    if ($action === 'enable') {
      $secret = (string) ($_SESSION['supplier_2fa_new_secret'] ?? '');
      if ($secret === '') $error = 'Önce gizli anahtar oluşturun.';
      elseif (totp_verify($secret, (string) ($_POST['code'] ?? ''))) {
        db()->prepare('UPDATE supplier_users SET totp_secret=? WHERE id=?')->execute([$secret, $loggedIn['id']]);
        $_SESSION['supplier_user']['totp_secret'] = $secret;
        unset($_SESSION['supplier_2fa_new_secret']);
        $message = 'İki adımlı doğrulama etkinleştirildi. Bir sonraki girişte kod istenecek.';
      } else $error = 'Kod hatalı.';
    }
    if ($action === 'disable') {
      db()->prepare('UPDATE supplier_users SET totp_secret=NULL WHERE id=?')->execute([$loggedIn['id']]);
      $_SESSION['supplier_user']['totp_secret'] = null;
      $message = 'İki adımlı doğrulama kapatıldı.';
    }
  }
}
$secret = $_SESSION['supplier_2fa_new_secret'] ?? ($loggedIn['totp_secret'] ?? '');
$enabled = $loggedIn && !empty($loggedIn['totp_secret']) && empty($_SESSION['supplier_2fa_new_secret']);
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>İki adımlı doğrulama | NEXUS Supply</title><link rel="stylesheet" href="/nexustraveltech/assets/supply.css"></head><body class="login-body"><main class="login-card">
<?php if ($pending): ?>
<h1>Doğrulama kodu</h1><p class="login-copy">Kimlik doğrulama uygulamanızdaki 6 haneli kodu girin.</p>
<?php if ($error): ?><p class="login-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<form method="post"><label>Kod<input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="123456" required></label><button type="submit">Doğrula</button></form>
<?php else: ?>
<h1>İki adımlı doğrulama</h1>
<?php if ($message): ?><p class="save-success">✓ <?= htmlspecialchars($message) ?></p><?php endif; ?>
<?php if ($error): ?><p class="login-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<?php if ($enabled): ?>
<p class="login-copy">Durum: <b>AÇIK</b> — girişleriniz doğrulama kodu gerektirir.</p>
<form method="post"><input type="hidden" name="action" value="disable"><button type="submit" style="background:#8e2410">Kapat</button></form>
<?php elseif ($secret !== ''): ?>
<?php $otpauth = totp_otpauth_url('NEXUS Supply — ' . ($loggedIn['full_name'] ?? ''), $secret, 'NEXUS'); ?>
<p class="login-copy">1. Google Authenticator / Authy ile QR'ı okutun veya anahtarı elle girin.</p>
<img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?= rawurlencode($otpauth) ?>" alt="QR">
<p><code><?= htmlspecialchars($secret) ?></code></p>
<form method="post"><input type="hidden" name="action" value="new_secret"><button type="submit" style="background:#a86026">Yeni anahtar üret</button></form>
<form method="post"><input type="hidden" name="action" value="enable"><label>Kod<input name="code" inputmode="numeric" maxlength="6" placeholder="Uygulamadaki 6 haneli kod" required></label><button type="submit">Kodu doğrula &amp; etkinleştir</button></form>
<?php else: ?>
<p class="login-copy">Hesabınız henüz iki adımlı doğrulama kullanmıyor.</p>
<form method="post"><input type="hidden" name="action" value="new_secret"><button type="submit">Gizli anahtar oluştur</button></form>
<?php endif; ?>
<?php endif; ?>
<p><a href="<?= $pending ? '/nexustraveltech/tedarikci/login' : '/nexustraveltech/tedarikci/' ?>">← Geri</a></p>
</main></body></html>
