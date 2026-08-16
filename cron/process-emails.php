<?php
declare(strict_types=1);
// Plesk cron: */5 * * * * /opt/plesk/php/8.5/bin/php /var/www/vhosts/nexustraveltech.com/httpdocs/cron/process-emails.php
require_once __DIR__ . '/../config/mailer.php';
$result = process_email_outbox((int) ($argv[1] ?? 25));
echo json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
