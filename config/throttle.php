<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

// Giriş brute-force koruması: pencere başına hatalı deneme sayısı ve kilit süresi.
const THROTTLE_MAX_ATTEMPTS = 5;
const THROTTLE_WINDOW_SECONDS = 900; // 15 dakika
const THROTTLE_LOCK_SECONDS = 1800;  // 30 dakika

/**
 * Kapsam (admin/agency/supplier) + IP + kimlikten benzersiz sayaç anahtarı üretir.
 */
function throttle_key(string $scope, string $identity): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return $scope . ':' . $ip . ':' . mb_strtolower(trim($identity));
}

/**
 * Giriş denemesine izin var mı?
 *
 * @return array{allowed: bool, retry_after: int} retry_after saniye cinsinden.
 */
function throttle_check(string $key): array
{
    $q = db()->prepare('SELECT attempts, window_start, locked_until FROM login_throttle WHERE bucket=?');
    $q->execute([$key]);
    $row = $q->fetch();
    if (!$row) {
        return ['allowed' => true, 'retry_after' => 0];
    }

    $now = time();
    $lockedUntil = $row['locked_until'] !== null ? (new DateTimeImmutable($row['locked_until']))->getTimestamp() : 0;
    if ($lockedUntil > $now) {
        return ['allowed' => false, 'retry_after' => $lockedUntil - $now];
    }
    if ($lockedUntil > 0) {
        // Kilit süresi doldu; sayaç sıfırlanır.
        db()->prepare('DELETE FROM login_throttle WHERE bucket=?')->execute([$key]);
        return ['allowed' => true, 'retry_after' => 0];
    }

    $windowStart = (new DateTimeImmutable($row['window_start']))->getTimestamp();
    if ($now - $windowStart > THROTTLE_WINDOW_SECONDS) {
        return ['allowed' => true, 'retry_after' => 0];
    }
    if ((int) $row['attempts'] >= THROTTLE_MAX_ATTEMPTS) {
        return ['allowed' => false, 'retry_after' => THROTTLE_WINDOW_SECONDS - ($now - $windowStart)];
    }
    return ['allowed' => true, 'retry_after' => 0];
}

/**
 * Hatalı denemeyi kaydeder; eşik aşılırsa hesabı kilitler.
 */
function throttle_hit(string $key): void
{
    db()->prepare(
        "INSERT INTO login_throttle (bucket, attempts, window_start, locked_until)
         VALUES (?, 1, now(), NULL)
         ON CONFLICT (bucket) DO UPDATE SET
           attempts     = CASE WHEN now() - login_throttle.window_start > make_interval(secs => ?) THEN 1 ELSE login_throttle.attempts + 1 END,
           window_start = CASE WHEN now() - login_throttle.window_start > make_interval(secs => ?) THEN now() ELSE login_throttle.window_start END,
           locked_until = CASE WHEN login_throttle.attempts + 1 >= ? THEN now() + make_interval(secs => ?) ELSE NULL END"
    )->execute([$key, THROTTLE_WINDOW_SECONDS, THROTTLE_WINDOW_SECONDS, THROTTLE_MAX_ATTEMPTS, THROTTLE_LOCK_SECONDS]);
}

/**
 * Başarılı girişte sayacı temizler.
 */
function throttle_reset(string $key): void
{
    db()->prepare('DELETE FROM login_throttle WHERE bucket=?')->execute([$key]);
}
