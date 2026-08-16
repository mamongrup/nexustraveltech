<?php

declare(strict_types=1);

/**
 * Hata/exception'ları error_logs tablosuna yazar; tablo yoksa veya DB kapalıysa
 * PHP error_log'a düşer. İş akışını asla bozmaz (best-effort).
 */
function nexus_log_error(string $level, string $message, array $context = []): void
{
    $level = in_array($level, ['debug', 'info', 'warning', 'error', 'critical'], true) ? $level : 'error';
    try {
        db()->prepare('INSERT INTO error_logs(level,message,context,request_uri,ip,user_type,user_id) VALUES(?,?,?::jsonb,?,?,?,?)')
            ->execute([
                $level,
                mb_substr($message, 0, 4000),
                json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}',
                mb_substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 500),
                mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
                isset($_SESSION['user_type']) ? mb_substr((string) $_SESSION['user_type'], 0, 20) : null,
                isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            ]);
    } catch (Throwable $e) {
        error_log('[NEXUS] error_logs kaydı yazılamadı: ' . $message);
    }
}

/**
 * Yakalanan exception'ları hata merkezine bildirir (sayfa içi try/catch'lerden çağrılabilir).
 */
function nexus_log_exception(Throwable $e, string $level = 'error'): void
{
    nexus_log_error($level, $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 10),
    ]);
}

/**
 * Yakalanmayan exception'ları loglar; web'de sade bir 500 sayfası gösterir (güvenlik için
 * hata detayını ziyaretçiye sızdırmaz), CLI'da stderr'e yazar.
 */
function nexus_exception_handler(Throwable $e): void
{
    nexus_log_exception($e);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, '[NEXUS] ' . $e->getMessage() . PHP_EOL);
        return;
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Hata | NEXUS</title></head>'
        . '<body style="margin:0;font-family:Arial,sans-serif;background:#10211f;color:#fff;display:grid;place-items:center;min-height:100vh">'
        . '<div style="text-align:center"><h1>Beklenmeyen bir hata oluştu</h1>'
        . '<p style="color:#9fe8b8">Ekip bilgilendirildi. Lütfen birkaç dakika sonra tekrar deneyin.</p>'
        . '<a href="/" style="color:#d7ff48">Ana sayfaya dön</a></div></body></html>';
}

/**
 * PHP uyarı/notice'lerini hata merkezine yazar; varsayılan PHP davranışını bastırır.
 */
function nexus_error_handler(int $severity, string $message, string $file, int $line): bool
{
    if (!(error_reporting() & $severity)) {
        return false;
    }
    nexus_log_error('warning', $message, ['file' => $file, 'line' => $line]);
    return true;
}

function nexus_register_error_handler(): void
{
    set_exception_handler('nexus_exception_handler');
    set_error_handler('nexus_error_handler');
}
