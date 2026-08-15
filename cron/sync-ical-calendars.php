<?php
declare(strict_types=1);
// Plesk cron: */15 * * * * /opt/plesk/php/8.5/bin/php /var/www/vhosts/nexustraveltech.com/httpdocs/cron/sync-ical-calendars.php
require_once __DIR__.'/../config/ical.php';
$rows=db()->query("SELECT id,supplier_id FROM ical_connections WHERE direction='import' AND status='active' ORDER BY id")->fetchAll();
foreach($rows as $row){$result=ical_import_connection((int)$row['id'],(int)$row['supplier_id']);echo '['.$row['id'].'] '.($result['ok']?'OK':'ERROR').' '.$result['message'].PHP_EOL;}
