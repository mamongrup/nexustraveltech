<?php
declare(strict_types=1);

// Sunucu güncelleme sonrası sağlık kontrolü — tek komut:
//   /opt/plesk/php/8.5/bin/php scripts/health-check.php            → eksik migration'ları idempotent uygular ve raporlar
//   /opt/plesk/php/8.5/bin/php scripts/health-check.php --dry-run  → hiçbir şey uygulamaz, yalnızca durumu gösterir
//
// Bölümler: 1) tablolar   2) kritik kolonlar   3) migration durumu   4) ortam.
// Herhangi bir sorun varsa çıkış kodu 1. Mantık, günlük zamanlayıcı göreviyle
// (cron/health-check-alert.php) paylaşılmak üzere config/health.php'ye taşındı.

require_once __DIR__ . '/../config/health.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$result = health_check_run($dryRun);

echo $result['output'];
exit($result['ok'] ? 0 : 1);
