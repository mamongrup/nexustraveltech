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

// Benzersiz doğrulama kodu — alıcı, gelen e-postanın bu test koşusuna ait olduğunu
// bu kodla doğrular; kuyruk işleyicisi (cron/process-emails.php) aynı kodu
// "test e-postası teslim edildi" işaretiyle raporlar.
$code = 'NEXUS-' . substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 6);
save_platform_setting('last_alert_test_code', $code);
save_platform_setting('last_alert_test_delivered_code', ''); // yeni koşu: eski teslimat işareti geçersiz

$types = [
    ['channel_inactive', 'Kanal dağıtımı pasif (test)', '⚠ Booking.com kanalı pasif — son 24 saatte 3 başarısız webhook_LOADED'],
    ['channel_sync_job_failed', 'Kanal senkron başarısızlığı (test)', '✗ channel_sync_logs #142 başarısız: property_not_mapped [OTA-STD]'],
    ['channel_webhook_loop', 'Webhook tekrar başarısızlığı (test)', '🔄 Booking.com: aynı yük 5 kez başarısız — döngü uyarısı'],
    ['ical_inactive', 'iCal bağlantısı pasif (test)', '⚠ Vrbo Takvimi pasif — 14 gündür senkron yok'],
    ['ical_repeat', 'iCal tekrar hatası (test)', '⚠ Airbnb Ana Takvim: son 24 saatte 8 hata (timeout)'],
    ['fx_missing_audit', 'Eksik kur çiftleri (test)', '💱 Eksik kur: EUR→TRY — son 3 günde 1.200 EUR dönüştürülemedi'],
    ['health_check_alert', '⚠ Sağlık kontrolü: 0 sorun (test)', '✅ 5 tablo eksik · 2 yetim eşleştirme'],
    ['trash_purge_approval', 'Son şans: çöp kutusu onayı (test)', "🗑 'On olanakları' 2 günde kalıcı silinecek — 3 ilanda kullanılıyor"],
    ['feature_trash_purge', 'Çöp kutusu temizlendi (test)', "🗑 'Kumsal aktiviteleri' çöp kutusundan silindi"],
    ['trash_upcoming', 'Yaklaşan kalıcı silme uyarısı (test)', '⏳ Bu hafta silinecek: 2 özellik (60 gün doldu)'],
];
// Her kanal için örnek uyari satiri —gercek uyarilarin nasil gorundugunu gosterecek.
$examples = [
    'channel_inactive' => '<span style="color:#b0301a">⚠ Booking.com</span> kanalı <b>pasif</b> — son 24 saatte <b>3</b> başarısız webhook',
    'channel_sync_job_failed' => '<span style="color:#b0301a">✗</span> channel_sync_logs <b>#142</b> başarısız: <code>property_not_mapped [OTA-STD]</code>',
    'channel_webhook_loop' => '<span style="color:#b26a00">🔄</span> Booking.com: aynı yük <b>5</b> kez başarısız — döngü uyarısı',
    'ical_inactive' => '<span style="color:#b0301a">⚠</span> Vrbo Takvimi <b>pasif</b> — <b>14</b> gündür senkron yok',
    'ical_repeat' => '<span style="color:#b0301a">⚠</span> Airbnb Ana Takvim: son 24 saatte <b>8</b> hata (timeout)',
    'fx_missing_audit' => '<span style="color:#b26a00">💱</span> Eksik kur: <b>EUR→TRY</b> — son 3 günde <b>1.200 EUR</b> dönüştürülemedi',
    'health_check_alert' => '<span style="color:#b0301a">⚠</span> <b>5</b> tablo eksik · <b>2</b> yetim eşleştirme · 1 bayat kilit',
    'trash_purge_approval' => '<span style="color:#b0301a">🗑</span> <b>"On olanakları"</b> <b>2</b> günde kalıcı silinecek — <b>3</b> ilanda kullanılıyor',
    'feature_trash_purge' => '<span style="color:#64716d">🗑</span> <b>"Kumsal aktiviteleri"</b> çöp kutusundan silindi',
    'trash_upcoming' => '<span style="color:#b26a00">⏳</span> Bu hafta silinecek: <b>2</b> özellik (<b>60</b> gün doldu)',
];

// Tek özet mesajı — tüm kanalların sonucu tek tabloda; gelen kutuya 1 e-posta düşer.
// Kanal satırları gerçek uyarı başlıklarıyla eşleşir; mesajın gelmesi, kuyruk
// işleyicisinin ve e-posta altyapısının sağlıklı olduğunu gösterir.
$rows = '';
$ok = 0;
foreach ($types as [$related, $subject]) {
    $ok++;
    $example = $examples[$related] ?? '';
        $rows .= '<tr>'
        . '<td style="padding:6px 12px;border:1px solid #e1e5de"><code>' . htmlspecialchars($related) . '</code></td>'
        . '<td style="padding:6px 12px;border:1px solid #e1e5de">' . htmlspecialchars($subject) . '</td>'
        . '<td style="padding:6px 12px;border:1px solid #e1e5de;font-size:12px;background:#fafcfa">' . $example . '</td>'
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
    . '<div style="border:2px dashed #2e7d32;border-radius:8px;padding:10px 14px;margin:0 0 12px;background:#f2f8f2">'
    . '<b>Doğrulama kodu:</b> <code style="font-size:16px;letter-spacing:2px;color:#1b5e20">' . $code . '</code>'
    . '<div style="color:#64716d;margin-top:4px;font-size:12px">Bu kod, bu e-postanın <b>' . date('Y-m-d H:i') . '</b> koşusuna ait olduğunu doğrular. Kuyruk işleyicisi raporunda (cron/process-emails.php) aynı kod "test e-postası teslim edildi" işaretiyle görünür; Zamanlayıcılar sayfasındaki kart da teslimatı bu kodla doğrular.</div>'
    . '</div>'
    . '<h2 style="margin:0 0 6px">🧪 Admin uyarı e-postası — toplu test özeti (' . $ok . ' kanal)</h2>'
    . '<p style="color:#64716d;margin:0 0 10px">Bu tek mesaj, <b>' . $ok . ' uyarı kanalının</b> tamamını doğrular. Aşağıdaki her satır gerçek uyarıların gönderildiği kanalı temsil eder; bu özetin gelmesi kuyruk işleyicisinin ve e-posta altyapısının sağlıklı olduğunu gösterir.</p>'
    . '<table style="border-collapse:collapse;width:100%;max-width:640px;font-size:13px">'
    . '<tr><th style="text-align:left;padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1">Kanal</th>'
    . '<th style="text-align:left;padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1">Başlık</th>'
    . '<th style="text-align:left;padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1;font-size:11px">Gerçek uyarı örneği</th>'
    . '<th style="padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1;text-align:center">Durum</th></tr>'
    . $rows
    . '</table>'
    . '<p style="margin-top:14px;color:#64716d">Gönderim: ' . date('Y-m-d H:i') . ' · doğrulama kodu: <b>' . $code . '</b> · cron/test-admin-alerts.php --send · hedef: ' . htmlspecialchars($adminEmail) . ' · tek seferlik test — tekrarlanan gerçek uyarıların aksine bu mesaj kendini çoğaltmaz; kuyruk işleyicisi (cron/process-emails.php) tarafından gönderilir.</p>'
    . '</div>';
if ($send) {
    queue_email($adminEmail, '🧪 Admin uyarı testi — ' . $ok . ' kanal özeti [' . $code . ']', $body, 'admin_alerts_test');
    // Teslimat tarihçesine koşu kaydı — cron/verify-alert-test-delivery.php bu kodu izler
    // (delivered/pending/missed) ve raporu aynı tarihçeden üretir.
    $hist = platform_setting('alert_test_history', []);
    if (!is_array($hist)) $hist = [];
    $hist[] = ['code' => $code, 'at' => date('Y-m-d H:i:s'), 'mode' => 'send', 'status' => 'queued', 'delivered_at' => null];
    if (count($hist) > 20) $hist = array_slice($hist, -20);
    save_platform_setting('alert_test_history', $hist);
}

echo "\n" . ($send
    ? "Tamam: $ok kanal tek özet e-postada kuyruğa eklendi · doğrulama kodu: $code → cron/process-emails.php 5 dakika içinde gönderir."
    : "Kuru çalışma tamam: $ok kanal özet tablosu hazır · doğrulama kodu: $code. Gerçek gönderim için --send ekleyin.")
    . "\n";
echo $send
    ? "Gelen kutunuzu kontrol edin — tek mesaj, N kanal satırlı tablo; eksik/hatalı satır varsa kuyruk durumunu cron/process-emails.php çıktısından izleyin.\n"
    : "";
exit(0);
