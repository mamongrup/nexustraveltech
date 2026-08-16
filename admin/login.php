<?php

declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/throttle.php';
require __DIR__ . '/../config/audit.php';

admin_session();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = (string) ($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $key = throttle_key('admin', $username);

    $status = throttle_check($key);
    if (!$status['allowed']) {
        $error = 'Çok fazla hatalı deneme. ' . (int) ceil($status['retry_after'] / 60) . ' dakika sonra tekrar deneyin.';
    } else {
        $credentials = admin_credentials();
        if (hash_equals($credentials['username'], $username) && hash_equals($credentials['password'], $password)) {
            throttle_reset($key);
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $username;
            audit_log('admin.login', null, null, ['username' => $username]);
            header('Location: /nexustraveltech/admin/');
            exit;
        }

        throttle_hit($key);
        $error = 'Kullanici adi veya sifre hatali.';
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>NEXUS Admin Giris</title>
  <link rel="stylesheet" href="/nexustraveltech/assets/styles.css">
  <style>
    body{min-height:100vh;display:grid;place-items:center;background:#071412}.admin-card{width:min(420px,calc(100% - 32px));background:#fff;padding:32px;border:1px solid rgba(255,255,255,.18)}.admin-card h1{margin:0 0 8px;font-size:34px}.admin-card p{color:#64716d}.admin-card label{display:block;margin:18px 0 6px;font:700 12px "DM Sans",Arial}.admin-card input{width:100%;height:44px;border:1px solid #d8ded8;padding:0 12px}.admin-card button{margin-top:20px;width:100%;height:48px;border:0;background:#d7ff48;font-weight:800}.error{color:#e85f42}
  </style>
</head>
<body>
  <form class="admin-card" method="post">
    <h1>NEXUS Admin</h1>
    <p>Erken erisim basvurularini yonetin.</p>
    <?php if ($error !== ''): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <label>Kullanici adi</label>
    <input name="username" autocomplete="username" required>
    <label>Sifre</label>
    <input type="password" name="password" autocomplete="current-password" required>
    <button type="submit">Giris yap</button>
  </form>
</body>
</html>
