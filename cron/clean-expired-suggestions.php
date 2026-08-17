<?php
declare(strict_types=1);

// Onay bekleyen eşleştirme önerilerinin süresi dolanlarını otomatik temizler.
// channel_room_mappings / channel_rate_plan_mappings'te status='suggested' olan ve
// suggested_at'i TTL'den (varsayılan 30 gün, kontrol merkezi: channel_suggestion_ttl_days)
// eski olan satırlar silinir. Süresi dolmuş öneriler veri yazılmasını engellemez — yalnızca
// onay listesini ve rozet sayısını şişirir; tedarikçi onaylamadıysa sessizce düşer.
//
// Zamanlayıcı: nexus-suggestion-cleanup (varsayılan: her gün 05:00).

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/platform_settings.php';

$pdo = db();
$ttlDays = max(7, min(365, (int) platform_setting('channel_suggestion_ttl_days', 30)));
$cutoff = date('Y-m-d H:i:s', time() - $ttlDays * 86400);

$roomDeleted = 0;
$planDeleted = 0;
try {
    $delRoom = $pdo->prepare("DELETE FROM channel_room_mappings WHERE status='suggested' AND suggested_at < ?");
    $delRoom->execute([$cutoff]);
    $roomDeleted = $delRoom->rowCount();
} catch (Throwable $e) {
    echo '⚠ channel_room_mappings temizlenemedi: ' . $e->getMessage() . "\n";
}
try {
    $delPlan = $pdo->prepare("DELETE FROM channel_rate_plan_mappings WHERE status='suggested' AND suggested_at < ?");
    $delPlan->execute([$cutoff]);
    $planDeleted = $delPlan->rowCount();
} catch (Throwable $e) {
    echo '⚠ channel_rate_plan_mappings temizlenemedi: ' . $e->getMessage() . "\n";
}

// Kalan bekleyenler (bilgi satırı).
$leftRoom = 0;
$leftPlan = 0;
try {
    $leftRoom = (int) $pdo->query("SELECT COUNT(*) FROM channel_room_mappings WHERE status='suggested'")->fetchColumn();
    $leftPlan = (int) $pdo->query("SELECT COUNT(*) FROM channel_rate_plan_mappings WHERE status='suggested'")->fetchColumn();
} catch (Throwable $e) {
    // Bilgi amaçlı — hata değil.
}

echo "Öneri temizliği: {$roomDeleted} oda + {$planDeleted} fiyat planı önerisi silindi (TTL: {$ttlDays} gün, kesim: {$cutoff}). Kalan bekleyen: {$leftRoom} oda, {$leftPlan} plan önerisi.\n";
