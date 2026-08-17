<?php
declare(strict_types=1);
// Plesk cron: */15 * * * * /opt/plesk/php/8.5/bin/php /var/www/vhosts/nexustraveltech.com/httpdocs/cron/sync-ical-calendars.php
// Başarısız içe aktarmalar bir sonraki taramada OTOMATİK yeniden denenir (webhook retry mantığıyla aynı desen):
// son başarıdan bu yana art arda hata sayısına göre geri adım — 1 hata: 5 dk, 2 hata: 15 dk, 3+ hata: 60 dk.
// 'error' durumundaki bağlantılar da taranır (config/ical.php artık onları kabul eder); başarıda 'active'e döner,
// başarısızlıkta 'error' kalır ve bir sonraki taramada geri adım dolunca tekrar denenir.
require_once __DIR__.'/../config/ical.php';
// ical_sync_logs (migration 050) henüz yoksa geri adım bilgisi alınamaz — eski davranışa düş (yalnızca active, geri adımsız).
if(!(bool)db()->query("SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='ical_sync_logs'")->fetchColumn()){
    $rows=db()->query("SELECT id,supplier_id FROM ical_connections WHERE direction='import' AND status='active' ORDER BY id")->fetchAll();
    foreach($rows as $row){$result=ical_import_connection((int)$row['id'],(int)$row['supplier_id']);echo '['.$row['id'].'] '.($result['ok']?'OK':'ERROR').' '.$result['message'].PHP_EOL;}
    echo "Özet: ical_sync_logs eksik — geri adım uygulanmadan tarandı (migration 050 bekliyor).\n";
    exit(0);
}
$rows=db()->query("SELECT c.id,c.supplier_id,
   (SELECT COUNT(*) FROM ical_sync_logs l WHERE l.ical_connection_id=c.id AND l.status='failed' AND l.created_at>COALESCE((SELECT MAX(created_at) FROM ical_sync_logs WHERE ical_connection_id=c.id AND status='success'),'1970-01-01')) AS consecutive_fail,
   (SELECT MAX(created_at) FROM ical_sync_logs l WHERE l.ical_connection_id=c.id AND l.status='failed') AS last_fail_at
   FROM ical_connections c WHERE c.direction='import' AND c.status IN ('active','error') ORDER BY c.id")->fetchAll();
$now=time();$synced=0;$skipped=0;
foreach($rows as $row){
    $connId=(int)$row['id'];
    $consec=(int)$row['consecutive_fail'];
    $backoff=$consec===0?0:($consec===1?300:($consec===2?900:3600));
    $lastFail=$row['last_fail_at']?strtotime((string)$row['last_fail_at']):0;
    if($backoff>0&&$lastFail>0&&($lastFail+$backoff)>$now){
        $skipped++;
        echo '['.$connId.'] WAIT (geri adım '.round($backoff/60).' dk — art arda '.$consec.' hata, son deneme '.$row['last_fail_at'].")\n";
        continue;
    }
    $result=ical_import_connection($connId,(int)$row['supplier_id']);
    if($result['ok'])$synced++;
    echo '['.$connId.'] '.($result['ok']?'OK':'ERROR').' '.$result['message'].PHP_EOL;
}
echo "Özet: {$synced} içe aktarıldı, {$skipped} geri adım bekliyor.\n";
