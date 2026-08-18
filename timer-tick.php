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
 *
 * Kilit sağlığı: scheduler_tick() çalışmadan ÖNCE bayat kilit kontrolü yapılır.
 * Advisory kilit 10+ dk tutuluyorsa otomatik kırılır ve denetim kaydına yazılır.
 */

declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/config/scheduler.php';
require __DIR__ . '/config/tick_lock.php';

if (PHP_SAPI !== 'cli') {
    $token = (string) ($_GET['token'] ?? ($_SERVER['HTTP_X_NEXUS_TOKEN'] ?? ''));
    $expected = scheduler_tick_token();
    if ($expected === '' || !hash_equals($expected, $token)) {
        http_response_code(403);
        exit('forbidden');
    }
}

// Bayat kilit kontrolü: tick'ten önce kilit serbest olsun.
$lockResult = pre_tick_lock_check();

// Tick'i çalıştır (zaten kilitliyse locked=true döner).
$tickResult = scheduler_tick();

// Sonucu zenginleştir: bayat kilit kırıldıysa bilgi ekle.
if (!empty($lockResult['broken_pid'])) {
    $tickResult['stale_lock_broken'] = true;
    $tickResult['stale_lock_age'] = $lockResult['age'] ?? 0;
    $tickResult['stale_lock_pid'] = $lockResult['broken_pid'];
}

echo json_encode($tickResult, JSON_UNESCAPED_UNICODE) . PHP_EOL;
