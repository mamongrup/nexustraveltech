<?php
declare(strict_types=1);

// Sunucu güncelleme sonrası sağlık kontrolü — tek komut:
//   /opt/plesk/php/8.5/bin/php scripts/health-check.php            → eksik migration'ları idempotent uygular ve raporlar
//   /opt/plesk/php/8.5/bin/php scripts/health-check.php --dry-run  → hiçbir şey uygulamaz, yalnızca durumu gösterir
//   /opt/plesk/php/8.5/bin/php scripts/health-check.php --repair   → yabancı şemalı BOŞ tabloları (örn. bozuk
//     channel_room_mappings) otomatik düşürüp migration'larla yeniden kurar; DOLU tablolara dokunmaz
//   /opt/plesk/php/8.5/bin/php scripts/health-check.php --fix      → eksik/geçersiz/tekrarlanan kanal tokenlarını
//     otomatik yeniler (64 hex, benzersiz); --dry-run ile yalnızca önizleme
//
// Bölümler: 1) tablolar   2) kritik kolonlar   2b) kanal token doğrulama   3) migration durumu   4) ortam.
// Herhangi bir sorun varsa çıkış kodu 1. Mantık, günlük zamanlayıcı göreviyle
// (cron/health-check-alert.php) paylaşılmak üzere config/health.php'ye taşındı.

require_once __DIR__ . '/../config/health.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$repair = in_array('--repair', $argv ?? [], true);
$fix = in_array('--fix', $argv ?? [], true);
$result = health_check_run($dryRun, $repair, $fix);

echo $result['output'];
exit($result['ok'] ? 0 : 1);
