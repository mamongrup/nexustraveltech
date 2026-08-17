<?php
declare(strict_types=1);

// Başarısız kanal webhook yüklerini otomatik yeniden deneme — kuyruğu geri al.
// channel_sync_logs'ta status='failed' olan pull yükleri deneme sayısı maksimuma
// (varsayılan 3, platform ayarı channel_webhook_max_retries — admin -> Kontrol merkezi)
// ulaşmadıysa geri adım aralıklarıyla tekrar 'queued' yapılır:
//   1. başarısızlık sonrası 5 dk, 2. başarısızlık sonrası 15 dk
// Deneme sayısı (attempt_count) korunur — process-channel-webhooks her alışta artırır.
// Maksimuma ulaşan satır failed kalır; uyarı görevleri (loop/sync-job) devreye girer.
//
// Zamanlayıcı: nexus-channel-webhook-retry (varsayılan: 5 dakikada bir).

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/platform_settings.php';

$pdo = db();
$maxAttempts = max(2, min(10, (int) platform_setting('channel_webhook_max_retries', 3)));
$limit = 100; // Tek çalıştırmada geri alınacak maksimum yük (kuyruk taşmasına karşı).

$backoff = $pdo->prepare(
    "SELECT id FROM channel_sync_logs
     WHERE direction='pull' AND status='failed' AND attempt_count < ?
       AND scope IN ('availability','rates','restrictions','reservations')
       AND completed_at < now() - CASE attempt_count
           WHEN 1 THEN interval '5 minutes'
           WHEN 2 THEN interval '15 minutes'
           ELSE interval '1 minute'
         END
     ORDER BY id ASC LIMIT ?"
);
$backoff->bindValue(1, $maxAttempts, PDO::PARAM_INT);
$backoff->bindValue(2, $limit, PDO::PARAM_INT);
$backoff->execute();
$ids = $backoff->fetchAll(PDO::FETCH_COLUMN);
if (!$ids) {
    echo "Yeniden denemeye hazır başarısız webhook yükü yok (maksimum {$maxAttempts} deneme).\n";
    exit(0);
}

$requeue = $pdo->prepare("UPDATE channel_sync_logs SET status='queued', completed_at=NULL WHERE id=?");
$n = 0;
foreach ($ids as $id) {
    $requeue->execute([(int) $id]);
    $n++;
}
echo "{$n} başarısız webhook yükü yeniden kuyruğa alındı (maksimum {$maxAttempts} deneme).\n";
