<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

function platform_setting(string $key, mixed $fallback = null): mixed
{
    $query = db()->prepare('SELECT value FROM platform_settings WHERE setting_key=?');
    $query->execute([$key]);
    $raw = $query->fetchColumn();
    if ($raw === false) return $fallback;
    $value = json_decode((string) $raw, true);
    return json_last_error() === JSON_ERROR_NONE ? $value : $fallback;
}

function save_platform_setting(string $key, mixed $value): void
{
    db()->prepare("INSERT INTO platform_settings(setting_key,value,updated_at) VALUES (?,?::jsonb,now()) ON CONFLICT(setting_key) DO UPDATE SET value=EXCLUDED.value,updated_at=now()")
        ->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
}
