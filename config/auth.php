<?php

declare(strict_types=1);

function admin_credentials(): array
{
    $path = __DIR__ . '/secrets.php';
    if (!is_file($path)) throw new RuntimeException('config/secrets.php bulunamadı.');
    $config = require $path;
    return ['username' => $config['admin_username'], 'password' => $config['admin_password']];
}

function admin_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('nexus_admin');
        session_start([
            'cookie_httponly' => true,
            'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'cookie_samesite' => 'Lax',
        ]);
    }
}

function require_admin(): void
{
    admin_session();

    if (($_SESSION['admin_logged_in'] ?? false) !== true) {
        header('Location: /nexustraveltech/admin/login');
        exit;
    }
}
