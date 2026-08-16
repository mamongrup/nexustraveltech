<?php
require_once __DIR__ . '/../config/agency_auth.php';
require_once __DIR__ . '/../config/totp.php';
agency_session();
$pending = $_SESSION['agency_2fa_pending'] ?? null;
$loggedIn = agency_user();
if (!$pending && !$loggedIn) { header('Location: /nexustraveltech/acente/login'); exit; }
$error = '';
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if ($pending) {
    $attempts = (int) ($_SESSION['agency_2fa_attempts'] ?? 0);
    if ($attempts >= 10) $error = 'Çok fazla hatalı kod denemesi. Tekrar giriş yapın.';
    elseif (totp_verify((string) ($pending['totp_secret'] ?? ''), (string) ($_POST['code'] ?? ''))) {
      session_regenerate_id(true);
      $_SESSION['agency_user'] = $pending;
      unset($_SESSION['agency_2fa_pending'], $_SESSION['agency_2fa_attempts']);
      db()->prepare('UPDATE agency_users SET last_login_at=now() WHERE id=?')->execute([$pending['id']]);
      header('Location: /nexustraveltech/acente/'); exit;
    } else { $_SESSION['agency_2fa_attempts'] = $attempts + 1; $error = 'Kod hatalı.'; }
  } elseif ($loggedIn) {
    if ($action === 'new_secret') { $_SESSION['agency_2fa_new_secret'] = totp_secret(); $message = 'Yeni gizli anahtar oluşturuldu. Uygulamaya ekleyip kodu doğrulayın.'; }
    if ($action === 'enable') {
      $secret = (string) ($_SESSION['agency_2fa_new_secret'] ?? '');
      if ($secret === '') $error = 'Önce gizli anahtar oluşturun.';
      elseif (totp_verify($secret, (string) ($_POST['code'] ?? ''))) {
        db()->prepare('UPDATE agency_users SET totp_secret=? WHERE id=?')->execute([$secret, $loggedIn['id']]);
        $_SESSION['agency_user']['totp_secret'] = $secret;
        unset($_SESSION['agency_2fa_new_secret']);
        $message = 'İki adımlı doğrulama etkinleştirildi. Bir sonraki girişte kod istenecek.';
      } else $error = 'Kod hatalı.';
    }
    if ($action === 'disable') {
      db()->prepare('UPDATE agency_users SET totp_secret=NULL WHERE id=?')->execute([$loggedIn['id']]);
      $_SESSION['agency_user']['totp_secret'] = null;
      $message = 'İki adımlı doğrulama kapatıldı.';
    }
  }
}
$secret = $_SESSION['agency_2fa_new_secret'] ?? ($loggedIn['totp_secret'] ?? '');
$enabled = $loggedIn && !empty($loggedIn['totp_secret']) && empty($_SESSION['agency_2fa_new_secret']);
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>İki adımlı doğrulama | NEXUS Acenta</title><style>body{min-height:100vh;margin:0;display:grid;place-items:center;font-family:Arial;background:#10211f}.c{background:#fff;width:min(440px,calc(100% - 30px));padding:30px}.c input,.c button{width:100%;box-sizing:border-box;padding:12px;margin:7px 0}.c button{background:#d7ff48;border:0;font-weight:bold}.c button.off{background:#8e2410;color:#fff}.e{color:#c33}.ok{color:#0d7a4a}</style></head><body>
<?php if ($pending): ?>
<form method="post" class="c"><h1>Doğrulama kodu</h1><p>Uygulamanızdaki 6 haneli kodu girin.</p><?php if ($error): ?><p class="e"><?= htmlspecialchars($error) ?></p><?php endif; ?><input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="123456" required><button>Doğrula</button><p><a href="/nexustraveltech/acente/login" style="color:#0d7a4a">← Girişe dön</a></p></form>
<?php else: ?>
<div class="c"><h1>İki adımlı doğrulama</h1><?php if ($message): ?><p class="ok">✓ <?= htmlspecialchars($message) ?></p><?php endif; ?><?php if ($error): ?><p class="e"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<?php if ($enabled): ?>
<p>Durum: <b>AÇIK</b> — girişleriniz kod gerektirir.</p>
<form method="post"><input type="hidden" name="action" value="disable"><button class="off">Kapat</button></form>
<?php elseif ($secret !== ''): ?>
<?php $otpauth = totp_otpauth_url('NEXUS Acenta — ' . ($loggedIn['full_name'] ?? ''), $secret, 'NEXUS'); ?>
<p>1. QR'ı okutun veya anahtarı elle girin.</p>
<img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?= rawurlencode($otpauth) ?>" alt="QR" style="display:block;margin:8px auto">
<p style="text-align:center"><code><?= htmlspecialchars($secret) ?></code></p>
<form method="post"><input type="hidden" name="action" value="new_secret"><button style="background:#a86026;color:#fff">Yeni anahtar üret</button></form>
<form method="post"><input type="hidden" name="action" value="enable"><input name="code" inputmode="numeric" maxlength="6" placeholder="Uygulamadaki 6 haneli kod" required><button>Kodu doğrula &amp; etkinleştir</button></form>
<?php else: ?>
<p>Hesabınız henüz iki adımlı doğrulama kullanmıyor.</p>
<form method="post"><input type="hidden" name="action" value="new_secret"><button>Gizli anahtar oluştur</button></form>
<?php endif; ?>
<p><a href="/nexustraveltech/acente/" style="color:#0d7a4a">← Panele dön</a></p></div>
<?php endif; ?>
</body></html>
