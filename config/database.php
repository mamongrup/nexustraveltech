<?php

declare(strict_types=1);

function db_config(): array
{
    $path = __DIR__ . '/secrets.php';
    if (!is_file($path)) throw new RuntimeException('config/secrets.php bulunamadı. secrets.example.php dosyasını kopyalayın.');
    return require $path;
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = db_config();
    $dsn = 'pgsql:host=' . $config['db_host'] . ';port=' . $config['db_port'] . ';dbname=' . $config['db_name'];
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

require_once __DIR__ . '/error_handler.php';
if (!defined('NEXUS_NO_ERROR_HANDLER')) {
    nexus_register_error_handler();
}
