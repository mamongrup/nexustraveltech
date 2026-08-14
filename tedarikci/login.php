<?php
require_once __DIR__ . '/../config/supplier_auth.php';
supplier_session();
if (supplier_user()) { header('Location: /nexustraveltech/tedarikci/'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
  $password = $_POST['password'] ?? '';
  if ($email && is_string($password)) {
    $query = db()->prepare("SELECT u.id, u.supplier_id, u.full_name, u.email, u.password_hash, u.role, s.company_name FROM supplier_users u JOIN suppliers s ON s.id=u.supplier_id WHERE u.email=? AND s.status IN ('pilot', 'active') LIMIT 1");
    $query->execute([$email]); $account = $query->fetch();
    if ($account && password_verify($password, $account['password_hash'])) {
      unset($account['password_hash']); $_SESSION['supplier_user'] = $account;
      db()->prepare('UPDATE supplier_users SET last_login_at=NOW() WHERE id=?')->execute([$account['id']]);
      header('Location: /nexustraveltech/tedarikci/'); exit;
    }
  }
  $error = 'E-posta veya şifre hatalı.';
}
?><!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>NEXUS Supply | Pilot giriş</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="/nexustraveltech/assets/supply.css"></head><body class="login-body"><main class="login-card"><a class="supply-brand dark" href="/nexustraveltech/">N<span>∿</span>XUS <small>SUPPLY</small></a><p class="eyeline">TEDARİKÇİ OPERASYON PLATFORMU</p><h1>Operasyonunuza<br>hoş geldiniz.</h1><p class="login-copy">Ürün, fiyat, kontenjan ve rezervasyon akışınızı NEXUS üzerinden yönetin.</p><?php if ($error): ?><p class="login-error"><?= htmlspecialchars($error) ?></p><?php endif; ?><form method="post"><label>İş e-postası<input name="email" type="email" required autocomplete="email" placeholder="ornek@sirketiniz.com"></label><label>Şifre<input name="password" type="password" required autocomplete="current-password"></label><button type="submit">Giriş yap →</button></form><div class="pilot-access"><b>Pilot erişimi</b><span>pilot@nexustraveltech.com</span><span>Şifre: NexusPilot2026!</span></div></main></body></html>
