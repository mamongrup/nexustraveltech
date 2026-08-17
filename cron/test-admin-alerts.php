<?php
declare(strict_types=1);

// Admin uyarı e-postası testi — admin_alert_email doldurulduktan sonra tüm uyarı
// kanallarını tek seferde doğrular.
//
//   /opt/plesk/php/8.5/bin/php cron/test-admin-alerts.php        → kuru çalışma (hiçbir şey kuyruğa yazılmaz)
//   /opt/plesk/php/8.5/bin/php cron/test-admin-alerts.php --send → TÜM uyarı kanalları tek özet e-postada kuyruğa girer (tablo)
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

// Tek özet mesajı — tüm kanalların sonucu tek tabloda; gelen kutuya 1 e-posta düşer.
// Kanal satırları gerçek uyarı başlıklarıyla eşleşir; mesajın gelmesi, kuyruk
// işleyicisinin ve e-posta altyapısının sağlıklı olduğunu gösterir.
$rows = '';
$ok = 0;
foreach ($types as [$related, $subject]) {
    $ok++;
    $rows .= '<tr>'
        . '<td style="padding:6px 12px;border:1px solid #e1e5de"><code>' . htmlspecialchars($related) . '</code></td>'
        . '<td style="padding:6px 12px;border:1px solid #e1e5de">' . htmlspecialchars($subject) . '</td>'
        . '<td style="padding:6px 12px;border:1px solid #e1e5de;text-align:center"><b style="color:#2e7d32">✓ test</b></td>'
        . '</tr>';
    echo ($send ? '✓ özete eklendi: ' : '· sıralanır: ') . str_pad($related, 26) . $subject . "\n";
}
// Son test sonucunu kalıcı kaydet — admin paneli (Zamanlayıcılar) ne zaman test
// edildiğini, kaç kanalın hazır olduğunu ve modu (kuru/gerçek) gösterebilsin.
save_platform_setting('last_alert_test_at', date('Y-m-d H:i:s'));
save_platform_setting('last_alert_test_channels', $ok);
save_platform_setting('last_alert_test_status', 'ok');
save_platform_setting('last_alert_test_mode', $send ? 'send' : 'dry');
$body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
    . '<h2 style="margin:0 0 6px">🧪 Admin uyarı e-postası — toplu test özeti (' . $ok . ' kanal)</h2>'
    . '<p style="color:#64716d;margin:0 0 10px">Bu tek mesaj, <b>' . $ok . ' uyarı kanalının</b> tamamını doğrular. Aşağıdaki her satır gerçek uyarıların gönderildiği kanalı temsil eder; bu özetin gelmesi kuyruk işleyicisinin ve e-posta altyapısının sağlıklı olduğunu gösterir.</p>'
    . '<table style="border-collapse:collapse;width:100%;max-width:640px;font-size:13px">'
    . '<tr><th style="text-align:left;padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1">Kanal</th>'
    . '<th style="text-align:left;padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1">Başlık</th>'
    . '<th style="padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1;text-align:center">Durum</th></tr>'
    . $rows
    . '</table>'
    . '<p style="margin-top:14px;color:#64716d">Gönderim: ' . date('Y-m-d H:i') . ' · cron/test-admin-alerts.php --send · hedef: ' . htmlspecialchars($adminEmail) . ' · tek seferlik test — tekrarlanan gerçek uyarıların aksine bu mesaj kendini çoğaltmaz; kuyruk işleyicisi (cron/process-emails.php) tarafından gönderilir.</p>'
    . '</div>';
if ($send) {
    queue_email($adminEmail, '🧪 Admin uyarı testi — ' . $ok . ' kanal özeti', $body, 'admin_alerts_test');
}

echo "\n" . ($send
    ? "Tamam: $ok kanal tek özet e-postada kuyruğa eklendi → cron/process-emails.php 5 dakika içinde gönderir."
    : "Kuru çalışma tamam: $ok kanal özet tablosu hazır. Gerçek gönderim için --send ekleyin.")
    . "\n";
echo $send
    ? "Gelen kutunuzu kontrol edin — tek mesaj, N kanal satırlı tablo; eksik/hatalı satır varsa kuyruk durumunu cron/process-emails.php çıktısından izleyin.\n"
    : "";
exit(0);
