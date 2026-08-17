<?php
declare(strict_types=1);

// Kanal webhook işleyici — api/channel-webhook tarafından kuyruğa eklenen pull yönlü
// bildirimleri (availability/rates/restrictions/reservations) okur ve config/channel_webhook.php
// üzerinden NEXUS takvimi/fiyatlarına uygular. Sonuç channel_sync_logs'a yazılır.
//
// Zamanlayıcı: nexus-channel-webhook-process (varsayılan: 1 dakikada bir).

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/channel_webhook.php';

$pdo = db();
$limit = 50;

$jobs = $pdo->prepare(
    "SELECT * FROM channel_sync_logs
     WHERE direction='pull' AND status='queued' AND scope IN ('availability','rates','restrictions','reservations')
     ORDER BY id ASC LIMIT ?"
);
$jobs->bindValue(1, $limit, PDO::PARAM_INT);
$jobs->execute();
$rows = $jobs->fetchAll();

$ok = 0;
$failed = 0;
foreach ($rows as $job) {
    $jobId = (int) $job['id'];
    $pdo->prepare("UPDATE channel_sync_logs SET status='running', attempt_count=attempt_count+1 WHERE id=?")->execute([$jobId]);

    $payload = json_decode((string) ($job['request_payload'] ?? '{}'), true);
    if (!is_array($payload)) {
        $payload = [];
    }
    $result = channel_webhook_apply($job, $payload);

    if ($result['ok']) {
        $pdo->prepare("UPDATE channel_sync_logs SET status='success', response_payload=?::jsonb, fx_audit=?::jsonb, error_message=NULL, completed_at=now() WHERE id=?")
            ->execute([json_encode(['applied' => $result['applied'], 'message' => $result['message']]), json_encode($result['fx_audit'] ?? []), $jobId]);
        // Bağlantının son senkron zamanını tazele (sağlık kartları/özetler için).
        $pdo->prepare('UPDATE channel_connections SET last_sync_at=now(), last_error=NULL, last_sync_status=? WHERE id=?')
            ->execute(['success', (int) $job['channel_connection_id']]);
        $ok++;
        echo 'Webhook #' . $jobId . ' uygulandı: ' . $result['message'] . "\n";
    } else {
        $errMsg = $result['message'] . (isset($result['errors']) ? ' [' . implode(',', array_slice($result['errors'], 0, 4)) . ']' : '');
        $pdo->prepare("UPDATE channel_sync_logs SET status='failed', error_message=?, response_payload=?::jsonb, fx_audit=?::jsonb, completed_at=now() WHERE id=?")
            ->execute([mb_substr($errMsg, 0, 1000), json_encode(['message' => $result['message']]), json_encode($result['fx_audit'] ?? []), $jobId]);
        // Geçici eşleştirme hataları bağlantının sağlık durumunu bozmasın; yalnızca log'a düşer.
        $failed++;
        echo 'Webhook #' . $jobId . ' BAŞARISIZ: ' . $errMsg . "\n";
    }
}

if ($ok === 0 && $failed === 0) {
    echo "İşlenecek webhook bildirimi yok.\n";
} else {
    echo "Özet: {$ok} uygulandı, {$failed} başarısız.\n";
}
