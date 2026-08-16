<?php

declare(strict_types=1);

/**
 * TOTP (RFC 6238) — bağımlılıksız iki adımlı doğrulama.
 * Secret: Base32 kodlu 20 bayt; kod: 30 saniye pencere, 6 hane, HMAC-SHA1.
 */

function totp_base32_decode(string $input): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $input = strtoupper(trim($input));
    $buffer = 0;
    $bitsLeft = 0;
    $out = '';
    foreach (str_split($input) as $char) {
        $pos = strpos($alphabet, $char);
        if ($pos === false) continue;
        $buffer = ($buffer << 5) | $pos;
        $bitsLeft += 5;
        if ($bitsLeft >= 8) {
            $out .= chr(($buffer >> ($bitsLeft - 8)) & 0xFF);
            $bitsLeft -= 8;
        }
    }
    return $out;
}

function totp_base32_encode(string $input): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = '';
    $buffer = 0;
    $bitsLeft = 0;
    foreach (str_split($input) as $byte) {
        $buffer = ($buffer << 8) | ord($byte);
        $bitsLeft += 8;
        while ($bitsLeft >= 5) {
            $out .= $alphabet[($buffer >> ($bitsLeft - 5)) & 0x1F];
            $bitsLeft -= 5;
        }
    }
    if ($bitsLeft > 0) {
        $out .= $alphabet[($buffer << (5 - $bitsLeft)) & 0x1F];
    }
    return $out;
}

/** Yeni rastgele TOTP gizli anahtarı üretir (Base32, 32 karakter). */
function totp_secret(): string
{
    return totp_base32_encode(random_bytes(20));
}

/** Verilen zamandaki (Unix saniye) 6 haneli TOTP kodunu üretir. */
function totp_code(string $secret, ?int $timestamp = null): string
{
    $key = totp_base32_decode($secret);
    $time = ($timestamp ?? time()) / 30;
    $counter = pack('N*', 0, (int) floor($time));
    $hash = hash_hmac('sha1', $counter, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $value = ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF);
    return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Kodu ±window pencere (varsayılan ±1 = 90 saniye) içinde doğrular. */
function totp_verify(string $secret, string $code, int $window = 1): bool
{
    $code = trim((string) $code);
    if ($code === '' || !preg_match('/^\d{6}$/', $code)) return false;
    $now = time();
    for ($i = -max(0, $window); $i <= max(0, $window); $i++) {
        if (hash_equals(totp_code($secret, $now + $i * 30), $code)) return true;
    }
    return false;
}

/** Google Authenticator / Authy uyumlu otpauth:// URI üretir. */
function totp_otpauth_url(string $label, string $secret, string $issuer = 'NEXUS'): string
{
    return 'otpauth://totp/' . rawurlencode($label)
        . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer)
        . '&period=30&digits=6&algorithm=SHA1';
}
