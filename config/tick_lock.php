<?php
/**
 * tick.php'ye eklenen on-kilit sağlık kontrolü.
 * scheduler_tick() çalışmadan ÖNCE bayat kilitleri tespit edip kırar,
 * böylece tick her koşulda çalışabilir.
 *
 * Fonksiyon: pre_tick_lock_check()
 *   - Advisory kilidi tutan PID'i pg_locks + pg_stat_activity'ten bulur
 *   - state_change yaşı 10 dk'yı aşıyorsa pg_terminate_backend ile kırar
 *   - Kırma işlemini admin_audit_logs'a yazar
 *   - Maks 3 deneme yapar (race condition koruması)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/platform_settings.php';

/**
 * Pre-tick bayat kilit kontrolü: tick'ten önce bayat kilitleri kırar.
 *
 * @return array{ok: bool, message: string, broken_pid?: int, age?: int}
 */
function pre_tick_lock_check(): array
{
    $pdo = db();
    $lockKey = SCHEDULER_LOCK_KEY;
    $STALE_SECONDS = 600;
    $MAX_RETRIES = 3;

    for ($attempt = 1; $attempt <= $MAX_RETRIES; $attempt++) {
        // Önce kilidi deneyin — serbestse zaten iyi.
        $locked = $pdo->query('SELECT pg_try_advisory_lock(' . $lockKey . ')')->fetchColumn() === 't';
        if ($locked) {
            return ['ok' => true, 'message' => 'Kilit serbest, devam edilebilir.'];
        }

        // Kilit tutuluyor — sahibini denetleyin.
        try {
            $holder = $pdo->query("
                SELECT l.pid, a.state, a.query_start, a.state_change,
                       a.usename, a.client_addr, a.application_name
                FROM pg_locks l
                JOIN pg_stat_activity a ON a.pid = l.pid
                WHERE l.locktype = 'advisory'
                  AND l.classid = 0
                  AND l.objid = " . $lockKey . "
                  AND l.granted = true
                ORDER BY l.pid
                LIMIT 1
            ")->fetch();

            if (!$holder) {
                // pg_locks'ta kilit görünmüyor ama try_advisory_lock başarısız —
                // race condition veya kısa süreli kilit. Kısa bekleme后再 deneyin.
                if ($attempt < $MAX_RETRIES) {
                    usleep(200_000); // 200 ms
                    continue;
                }
                return ['ok' => false, 'message' => 'Kilit tutuluyor ancak sahip bulunamadı (race condition).'];
            }

            $stateChangeTs = strtotime((string) ($holder['state_change'] ?? ''));
            $age = $stateChangeTs > 0 ? time() - $stateChangeTs : 0;

            if ($age < $STALE_SECONDS) {
                // Kilit taze — bekleyin.
                return [
                    'ok' => false,
                    'message' => sprintf(
                        'Kilit %d sn önce tutulmuş (%s, PID %d) — henüz bayat değil.',
                        $age,
                        (string) ($holder['application_name'] ?? '?'),
                        (int) $holder['pid']
                    ),
                    'age' => $age,
                ];
            }

            // Bayat kilit — kır!
            $pid = (int) $holder['pid'];
            $appName = (string) ($holder['application_name'] ?? 'unknown');
            $user = (string) ($holder['usename'] ?? 'unknown');

            $pdo->exec("SELECT pg_terminate_backend($pid)");

            // Denetim kaydı yaz.
            try {
                $pdo->prepare("
                    INSERT INTO admin_audit_logs(action, entity_type, entity_id, details, created_by)
                    VALUES('scheduler.stale_lock_break', 'scheduler', 0, ?::jsonb, 'system')
                ")->execute([json_encode([
                    'lock_key' => $lockKey,
                    'terminated_pid' => $pid,
                    'age_seconds' => $age,
                    'application_name' => $appName,
                    'usename' => $user,
                    'attempt' => $attempt,
                ], JSON_UNESCAPED_UNICODE)]);
            } catch (Throwable $logErr) {
                // Audit log yazma başarısızlığı tick'i engellemesin.
            }

            // Kilit artık serbest — yeniden deneyin.
            $locked = $pdo->query('SELECT pg_try_advisory_lock(' . $lockKey . ')')->fetchColumn() === 't';
            if ($locked) {
                return [
                    'ok' => true,
                    'message' => sprintf('Bayat kilit kırıldı (PID %d, %d sn, %s), devam edilebilir.', $pid, $age, $appName),
                    'broken_pid' => $pid,
                    'age' => $age,
                ];
            }
            // Hâlâ kilitli — bir daha deneyin.
        } catch (Throwable $e) {
            if ($attempt === $MAX_RETRIES) {
                return ['ok' => false, 'message' => 'Bayat kilit kontrolü başarısız: ' . $e->getMessage()];
            }
        }
    }

    return ['ok' => false, 'message' => $MAX_RETRIES . ' deneme sonrası kilit alınamadı.'];
}
