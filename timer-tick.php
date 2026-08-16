<?php
/**
 * Zamanlayıcı nabzı — CLI (cron/tick.php üzerinden) veya token korumalı HTTP
 * (Plesk "Request a URL" görevi) olarak çalışır.
 *
 *   CLI : php cron/tick.php
 *   HTTP: https://nexustraveltech.com/nexustraveltech/timer-tick.php?token=TOKEN
 *
 * Güvenlik: HTTP çağrıları yalnızca admin panelindeki "Zamanlayıcılar" sayfasında
 * gösterilen paylaşımlı belirteçle kabul edilir.
 */

declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/config/scheduler.php';

if (PHP_SAPI !== 'cli') {
    $token = (string) ($_GET['token'] ?? ($_SERVER['HTTP_X_NEXUS_TOKEN'] ?? ''));
    $expected = scheduler_tick_token();
    if ($expected === '' || !hash_equals($expected, $token)) {
        http_response_code(403);
        exit('forbidden');
    }
}

echo json_encode(scheduler_tick(), JSON_UNESCAPED_UNICODE) . PHP_EOL;
