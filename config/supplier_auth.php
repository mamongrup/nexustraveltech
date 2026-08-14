<?php
require_once __DIR__ . '/database.php';

function supplier_session(): void {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('nexus_supplier');
    session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);
  }
}

function supplier_user(): ?array {
  supplier_session();
  return $_SESSION['supplier_user'] ?? null;
}

function require_supplier(): array {
  $user = supplier_user();
  if (!$user) { header('Location: /nexustraveltech/tedarikci/login'); exit; }
  return $user;
}
