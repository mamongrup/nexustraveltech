<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/notifications.php';

/**
 * Webhook gövdesi için HMAC-SHA256 imzası üretir (saf fonksiyon).
 */
function webhook_signature(string $secret, string $body): string
{
    return hash_hmac('sha256', $body, $secret);
}

/**
 * Abone olunan olay listesinin bir olayı kapsayıp kapsamadığını kontrol eder (saf fonksiyon).
 */
function webhook_event_matches(array $subscribedEvents, string $event): bool
{
    return in_array($event, array_values($subscribedEvents), true);
}

/**
 * Olaya abone olan acentelerin webhook kuyruğuna teslimat ekler.
 */
function webhook_dispatch(string $event, array $payload): void
{
    $q = db()->prepare("SELECT id FROM webhook_subscriptions WHERE status='active' AND events @> ?::jsonb");
    $q->execute([json_encode([$event], JSON_UNESCAPED_UNICODE)]);
    $insert = db()->prepare('INSERT INTO webhook_deliveries(subscription_id,event,payload) VALUES(?,?,?::jsonb)');
    foreach ($q->fetchAll() as $row) {
        $insert->execute([(int) $row['id'], $event, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    }
}

/**
 * Kuyruktaki webhook'ları HMAC imzalı POST ile gönderir (cron).
 */
function process_webhook_deliveries(int $limit = 25): array
{
    $limit = max(1, min(100, $limit));
    $q = db()->prepare("SELECT d.id,d.subscription_id,d.event,d.payload,d.attempts,s.url,s.secret FROM webhook_deliveries d JOIN webhook_subscriptions s ON s.id=d.subscription_id WHERE d.status IN ('queued','sending') ORDER BY d.id ASC LIMIT ?");
    $q->bindValue(1, $limit, PDO::PARAM_INT);
    $q->execute();
    $sent = 0;
    $failed = 0;
    foreach ($q->fetchAll() as $d) {
        $body = (string) $d['payload'];
        $signature = webhook_signature((string) $d['secret'], $body);
        $ch = curl_init((string) $d['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-NEXUS-Event: ' . $d['event'],
                'X-NEXUS-Signature: ' . $signature,
                'X-NEXUS-Event-Id: ' . $d['id'],
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $attempts = (int) $d['attempts'] + 1;
        if ($err === '' && $http >= 200 && $http < 300) {
            db()->prepare("UPDATE webhook_deliveries SET status='sent',http_status=?,attempts=?,sent_at=now(),error_message=NULL WHERE id=?\n")->execute([$http ?: null, $attempts, $d['id']]);
            db()->prepare('UPDATE webhook_subscriptions SET last_sent_at=now() WHERE id=?')->execute([$d['subscription_id']]);
            $sent++;
        } else {
            $status = $attempts >= 5 ? 'failed' : 'sending';
            db()->prepare('UPDATE webhook_deliveries SET status=?,http_status=?,attempts=?,error_message=? WHERE id=?')
                ->execute([$status, $http ?: null, $attempts, mb_substr(($err !== '' ? $err : ('HTTP ' . $http)), 0, 500), $d['id']]);
            if ($status === 'failed') {
                try {
                    $agencyQ = db()->prepare('SELECT agency_id FROM webhook_subscriptions WHERE id=?');
                    $agencyQ->execute([$d['subscription_id']]);
                    $agencyId = $agencyQ->fetchColumn();
                    if ($agencyId) {
                        notify_user('agency', (int) $agencyId, 'webhook.failed', 'Webhook teslimatı başarısız: ' . $d['event'] . ' (5 deneme tamamlandı)', '/nexustraveltech/acente/webhooks');
                    }
                } catch (Throwable $e) {
                    // Bildirim best-effort'tur.
                }
            }
            $failed++;
        }
    }
    return ['sent' => $sent, 'failed' => $failed];
}
