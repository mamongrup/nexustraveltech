<?php
declare(strict_types=1);

// Admin uyarı e-postası testi — admin_alert_email doldurulduktan sonra tüm uyarı
// kanallarını tek seferde doğrular.
//
//   /opt/plesk/php/8.5/bin/php cron/test-admin-alerts.php        → kuru çalışma (hiçbir şey kuyruğa yazılmaz)
//   /opt/plesk/php/8.5/bin/php cron/test-admin-alerts.php --send → her uyarı türü için bir test e-postası kuyruğa girer
//
// Konular ve related_type değerleri, gerçek görevlerin kullandıklarıyla birebirdir;
// böylece e-posta şablonları (varsa), kuyruk işleyicisi ve teslimat uçtan uca test edilir.

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/platform_settings.php';

$send = in_array('--send', $argv ?? [], true);
$adminEmail = trim((string) platform_setting('admin_alert_email', ''));

echo "Admin uyarı e-postası testi — " . ($send ? 'GERÇEK gönderim' : 'kuru çalışma (--send ile kuyruğa yazar)') . "\n";

if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    echo "admin_alert_email tanımsız veya geçersiz: '$adminEmail'\n";
    echo "Önce Admin → Kontrol merkezi → 'Yönetici uyarı e-postası' alanını doldurun.\n";
    exit(1);
}
echo 'Hedef: ' . $adminEmail . "\n\n";

$types = [
    ['channel_inactive', 'Kanal dağıtımı pasif (test)'],
    ['channel_sync_job_failed', 'Kanal senkron başarısızlığı (test)'],
    ['channel_webhook_loop', 'Webhook tekrar başarısızlığı (test)'],
    ['ical_inactive', 'iCal bağlantısı pasif (test)'],
    ['ical_repeat', 'iCal tekrar hatası (test)'],
    ['fx_missing_audit', 'Eksik kur çiftleri (test)'],
    ['health_check_alert', '⚠ Sağlık kontrolü: 0 sorun (test)'],
    ['trash_purge_approval', 'Son şans: çöp kutusu onayı (test)'],
    ['feature_trash_purge', 'Çöp kutusu temizlendi (test)'],
    ['trash_upcoming', 'Yaklaşan kalıcı silme uyarısı (test)'],
];

$ok = 0;
foreach ($types as [$related, $subject]) {
    $body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
        . '<h2 style="margin:0 0 6px">🧪 Admin uyarı e-postası testi</h2>'
        . '<p style="color:#64716d;margin:0 0 10px">Bu, <b>' . htmlspecialchars($related) . '</b> kanalının doğru çalıştığını doğrulamak için gönderilen bir test mesajıdır. '
        . 'Gerçek uyarılar bu başlık/kanalla gelir; bu mesajı alabiliyorsanız kanal sağlıklıdır.</p>'
        . '<table style="border-collapse:collapse;font-size:13px"><tr>'
        . '<td style="padding:6px 12px;border:1px solid #e1e5de;background:#f4f6f1">Kanal</td>'
        . '<td style="padding:6px 12px;border:1px solid #e1e5de"><code>' . htmlspecialchars($related) . '</code></td></tr>'
        . '<tr><td style="padding:6px 12px;border:1px solid #e1e5de;background:#f4f6f1">Gönderim</td>'
        . '<td style="padding:6px 12px;border:1px solid #e1e5de">' . date('Y-m-d H:i') . ' · cron/test-admin-alerts.php</td></tr>'
        . '</table>'
        . '<p style="margin-top:16px;color:#64716d">Tekrarlanan gerçek uyarıların aksine bu test mesajı tek seferliktir; kuyruk işleyicisi (cron/process-emails.php) tarafından gönderilir.</p>'
        . '</div>';
    if ($send) {
        queue_email($adminEmail, $subject, $body, $related);
    }
    echo ($send ? '✓ kuyruğa yazıldı: ' : '· sıralanır: ') . str_pad($related, 26) . $subject . "\n";
    $ok++;
}

echo "\n" . ($send
    ? "Tamam: $ok uyarı türü kuyruğa eklendi → cron/process-emails.php 5 dakika içinde gönderir."
    : "Kuru çalışma tamam: $ok tür hazır. Gerçek gönderim için --send ekleyin.")
    . "\n";
echo $send
    ? "Gelen kutunuzu kontrol edin; eksik/geçersiz kanal varsa kuyruk durumunu cron/process-emails.php çıktısından izleyin.\n"
    : "";
exit(0);
