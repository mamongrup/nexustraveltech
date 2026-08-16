<?php
declare(strict_types=1);
// Plesk cron: */1 * * * * /opt/plesk/php/8.5/bin/php /var/www/vhosts/nexustraveltech.com/httpdocs/cron/process-webhooks.php
require_once __DIR__ . '/../config/webhooks.php';
$result = process_webhook_deliveries((int) ($argv[1] ?? 25));
echo json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
