<?php
declare(strict_types=1);
require __DIR__ . '/../config/netgsm.php';
$result = process_netgsm_sms_outbox((int)($argv[1] ?? 25));
echo json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
