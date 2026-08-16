<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/platform_settings.php';

const SCHEDULER_LOCK_KEY = 424242;

/** Varsayılan görevleri eksikse ekler (idempotent). */
function scheduler_seed_defaults(): void
{
    $defaults = [
        ['nexus-sync-ical', 'iCal senkronizasyonu', 'cron/sync-ical-calendars.php', '*/15 * * * *'],
        ['nexus-revenue-rec', 'Gelir önerisi üretimi', 'cron/generate-revenue-recommendations.php', '15 2 * * *'],
        ['nexus-netgsm-sms', 'Netgsm SMS işleme', 'cron/process-netgsm-sms.php', '* * * * *'],
        ['nexus-process-emails', 'E-posta kuyruğu', 'cron/process-emails.php', '*/5 * * * *'],
        ['nexus-process-webhooks', 'Webhook teslimatı', 'cron/process-webhooks.php', '*/1 * * * *'],
        ['nexus-welcome-emails', 'Hoş geldiniz e-postaları', 'cron/send-welcome-emails.php', '0 8 * * *'],
        ['nexus-notification-digest', 'Bildirim özeti', 'cron/send-notification-digest.php', '15 9 * * *'],
        ['nexus-expire-group-options', 'Grup opsiyon süresi', 'cron/expire-group-options.php', '30 3 * * *'],
        ['nexus-chat-digest', 'Ziyaretçi soru özeti', 'cron/send-chat-digest.php', '45 8 * * *'],
        ['nexus-flag-abusive-ips', 'Suiistimal IP taraması', 'cron/flag-abusive-ips.php', '6 3 * * *'],
        ['nexus-monthly-report', 'Aylık sohbet raporu', 'cron/send-monthly-report.php', '0 7 1 * *'],
    ];
    $q = db()->prepare('INSERT INTO scheduled_jobs(code,name,command,schedule) VALUES(?,?,?,?) ON CONFLICT(code) DO NOTHING');
    foreach ($defaults as $d) {
        $q->execute($d);
    }
}

function scheduler_jobs(): array
{
    return db()->query('SELECT * FROM scheduled_jobs ORDER BY id')->fetchAll();
}

// Tek bir cron alanını değerle eşleştirir (örn. '*/15', '0-30/5', '1,15').
function cron_field_matches(string $spec, int $value): bool
{
    foreach (explode(',', $spec) as $part) {
        $part = trim($part);
        if ($part === '') continue;
        $step = 1;
        if (str_contains($part, '/')) {
            [$range, $stepRaw] = array_pad(explode('/', $part, 2), 2, '1');
            $step = max(1, (int) $stepRaw);
        } else {
            $range = $part;
        }
        if (str_contains($range, '-')) {
            [$lo, $hi] = array_map('intval', explode('-', $range, 2));
        } elseif ($range === '*') {
            $lo = 0;
            $hi = 59;
        } else {
            $lo = $hi = (int) $range;
        }
        if ($value >= $lo && $value <= $hi && (($value - $lo) % $step) === 0) {
            return true;
        }
    }
    return false;
}

/** Beş alanlı cron ifadesinin verilen Unix zamanda eşleşip eşleşmediği. */
function cron_matches(string $expr, int $ts): bool
{
    $parts = preg_split('/\s+/', trim($expr));
    if (count($parts) !== 5) return false;
    [$min, $hour, $dom, $mon, $dow] = $parts;
    return cron_field_matches($min, (int) date('i', $ts))
        && cron_field_matches($hour, (int) date('G', $ts))
        && cron_field_matches($dom, (int) date('d', $ts))
        && cron_field_matches($mon, (int) date('n', $ts))
        && cron_field_matches($dow, (int) date('w', $ts));
}

/** İfadenin bir sonraki çalışma zamanını bulur (3 güne kadar tara; bulamazsa null). */
function scheduler_next_run(string $expr, ?int $from = null): ?string
{
    $from = $from ?? time();
    for ($t = $from + 60; $t <= $from + 3 * 86400; $t += 60) {
        if (cron_matches($expr, $t)) return date('Y-m-d H:i', $t);
    }
    return null;
}

/**
 * Bir görevi ayrı PHP sürecinde 120 saniyelik zaman aşımıyla çalıştırır.
 *
 * @return array{status: string, output: string}
 */
function scheduler_run_job(array $job): array
{
    $base = dirname(__DIR__);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($base . '/' . ltrim((string) $job['command'], '/'));
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, $base);
    if (!is_resource($proc)) {
        return ['status' => 'error', 'output' => 'proc_open başarısız (PHP CLI engellenmiş olabilir)'];
    }
    $output = '';
    $deadline = microtime(true) + 120;
    while (true) {
        $r = [$pipes[1], $pipes[2]];
        $w = null;
        $e = null;
        $n = @stream_select($r, $w, $e, 1);
        if ($n > 0) {
            foreach ($r as $p) {
                $output .= (string) stream_get_contents($p);
            }
        }
        $status = proc_get_status($proc);
        if (!$status['running']) {
            $output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            break;
        }
        if (microtime(true) > $deadline) {
            proc_terminate($proc);
            $output .= "\n[zaman aşımı]";
            break;
        }
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    return ['status' => ($exit === 0 ? 'ok' : 'error'), 'output' => trim($output)];
}

/**
 * Nabız: vadesi gelen aktif görevleri çalıştırır.
 * PostgreSQL advisory lock ile eşzamanlı tick'ler (cron + web) serileştirilir.
 */
function scheduler_tick(): array
{
    $pdo = db();
    // PostgreSQL boolean 't'/'f' dizesi döner — (bool) cast güvenilmezdir.
    $locked = $pdo->query('SELECT pg_try_advisory_lock(' . SCHEDULER_LOCK_KEY . ')')->fetchColumn() === 't';
    if (!$locked) {
        return ['locked' => true, 'ran' => []];
    }
    try {
        scheduler_seed_defaults();
        $now = time();
        $jobs = $pdo->query('SELECT * FROM scheduled_jobs WHERE enabled=true')->fetchAll();
        $ran = [];
        foreach ($jobs as $job) {
            if (!cron_matches((string) $job['schedule'], $now)) continue;
            $res = scheduler_run_job($job);
            $pdo->prepare('UPDATE scheduled_jobs SET last_run_at=now(),last_status=?,last_output=?,run_count=run_count+1 WHERE id=?')
                ->execute([$res['status'], mb_substr((string) $res['output'], 0, 2000), $job['id']]);
            $ran[] = ['code' => $job['code'], 'status' => $res['status']];
        }
        return ['locked' => false, 'ran' => $ran];
    } finally {
        $pdo->query('SELECT pg_advisory_unlock(' . SCHEDULER_LOCK_KEY . ')');
    }
}

/** URL görevi (Plesk "Request a URL") için paylaşımlı belirteci üretir. */
function scheduler_tick_token(): string
{
    $token = (string) platform_setting('tick_token', '');
    if ($token === '') {
        $token = bin2hex(random_bytes(24));
        save_platform_setting('tick_token', $token);
    }
    return $token;
}
