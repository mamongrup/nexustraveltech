<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

function ai_encryption_key(): string
{
    $settings = db_config();
    $secret = (string) ($settings['app_encryption_key'] ?? '');

    if (strlen($secret) < 32) {
        throw new RuntimeException('AI ayarları için sunucuda app_encryption_key tanımlanmalıdır.');
    }

    return hash('sha256', $secret, true);
}

function encrypt_ai_secret(string $value): string
{
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($value, 'aes-256-gcm', ai_encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);

    if ($ciphertext === false) {
        throw new RuntimeException('AI anahtarı şifrelenemedi.');
    }

    return base64_encode($iv . $tag . $ciphertext);
}

function decrypt_ai_secret(string $payload): string
{
    $raw = base64_decode($payload, true);
    if ($raw === false || strlen($raw) < 29) {
        throw new RuntimeException('Kayıtlı AI anahtarı okunamıyor.');
    }

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);
    $value = openssl_decrypt($ciphertext, 'aes-256-gcm', ai_encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);

    if ($value === false) {
        throw new RuntimeException('Kayıtlı AI anahtarı çözülemedi.');
    }

    return $value;
}

function ai_provider_settings(string $provider, string $defaultModel): array
{
    $query = db()->prepare('SELECT encrypted_api_key, model FROM ai_provider_settings WHERE provider = ? LIMIT 1');
    $query->execute([$provider]);
    $row = $query->fetch();

    return [
        'api_key' => !empty($row['encrypted_api_key']) ? decrypt_ai_secret((string) $row['encrypted_api_key']) : '',
        'model' => trim((string) ($row['model'] ?? '')) ?: $defaultModel,
    ];
}

function deepseek_settings(): array { return ai_provider_settings('deepseek', 'deepseek-chat'); }
function gemini_settings(): array { return ai_provider_settings('gemini', 'gemini-3.6-flash'); }

function netgsm_settings(): array
{
    $stored = ai_provider_settings('netgsm', 'https://api.netgsm.com.tr/sms/send/get/');
    $credentials = $stored['api_key'] !== '' ? json_decode($stored['api_key'], true) : [];
    $credentials = is_array($credentials) ? $credentials : [];

    return [
        'usercode' => (string) ($credentials['usercode'] ?? ''),
        'password' => (string) ($credentials['password'] ?? ''),
        'header' => (string) ($credentials['header'] ?? ''),
        'endpoint' => $stored['model'],
    ];
}
