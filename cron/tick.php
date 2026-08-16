<?php
/**
 * Tek sistem cron satırı — panel yönetimli zamanlayıcıyı tetikler.
 *
 * Plesk Scheduled Tasks (veya crontab):
 *   * * * * * /opt/plesk/php/8.5/bin/php /var/www/vhosts/nexustraveltech.com/httpdocs/cron/tick.php >/dev/null 2>&1
 *
 * Görev tanımları ve zamanlamalar artık admin panelinden yönetilir:
 *   /nexustraveltech/admin/timerlar
 */

declare(strict_types=1);

require __DIR__ . '/../timer-tick.php';
