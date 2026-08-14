<?php

declare(strict_types=1);

function admin_credentials(): array
{
    $path = __DIR__ . '/secrets.php';
    if (!is_file($path)) throw new RuntimeException('config/secrets.php bulunamadı.');
    $config = require $path;
    return ['username' => $config['admin_username'], 'password' => $config['admin_password']];
}

function require_admin(): void
{
    session_start();

    if (($_SESSION['admin_logged_in'] ?? false) !== true) {
        header('Location: /nexustraveltech/admin/login');
        exit;
    }
}
