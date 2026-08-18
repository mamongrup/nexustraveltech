<?php
declare(strict_types=1);

// Günlük otomatik modül testi — auto-test.php'yi çalıştırır, hata/uyarı varsa admin'e e-posta gönderir.
//
// Zamanlayıcı: nexus-daily-auto-test (varsayılan: her gün 07:30).

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/mailer.php';

$adminEmail = trim((string) platform_setting('admin_alert_email', ''));
if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    echo "admin_alert_email tanımsız — e-posta atlanıyor.\n";
}

// auto-test.php'yi çalıştır (--json ile makinece okunabilir çıktı al).
$script = __DIR__ . '/../scripts/auto-test.php';
if (!file_exists($script)) {
    echo "auto-test.php bulunamadı: $script\n";
    exit(1);
}

$output = [];
$exitCode = 0;
exec(PHP_BINARY . ' ' . escapeshellarg($script) . ' --json 2>&1', $output, $exitCode);
$outputText = implode("\n", $output);

// JSON çıktısını ayrıştır.
$jsonData = json_decode($outputText, true);
$oks = $jsonData['ok_count'] ?? 0;
$warns = $jsonData['warn_count'] ?? 0;
$fails = $jsonData['fail_count'] ?? 0;
$modules = $jsonData['modules'] ?? [];
$errors = $jsonData['errors'] ?? [];

// Hata/uyarı varsa e-posta gönder.
$hasProblems = $fails > 0 || $warns > 0;
if ($hasProblems && $adminEmail !== '') {
    $durumRenk = $fails > 0 ? '#b0301a' : '#8a6100';
    $durumIkon = $fails > 0 ? '✗' : '⚠';
    $durumMetni = $fails > 0 ? 'HATA' : 'UYARI';

    // Modül özetini HTML tabloya çevir (JSON verisinden).
    $modulRows = '';
    foreach ($modules as $mName => $mData) {
        $modTotal = $mData['total'] ?? 0;
        $modFails = $mData['fail'] ?? 0;
        $modWarns = $mData['warn'] ?? 0;
        $modIcon = $modFails > 0 ? '✗' : ($modWarns > 0 ? '⚠' : '✓');
        $modColor = $modFails > 0 ? '#b0301a' : ($modWarns > 0 ? '#8a6100' : '#2e7d32');
        $modulRows .= '<tr>'
            . '<td style="padding:6px 12px;border:1px solid #e1e5de"><b style="color:' . $modColor . '">' . $modIcon . '</b> ' . htmlspecialchars($mName) . '</td>'
            . '<td style="padding:6px 12px;border:1px solid #e1e5de;text-align:center">' . $modTotal . '</td>'
            . '<td style="padding:6px 12px;border:1px solid #e1e5de;text-align:center;color:#2e7d32">' . ($modTotal - $modFails - $modWarns) . '</td>'
            . '<td style="padding:6px 12px;border:1px solid #e1e5de;text-align:center;color:#8a6100">' . $modWarns . '</td>'
            . '<td style="padding:6px 12px;border:1px solid #e1e5de;text-align:center;color:#b0301a">' . $modFails . '</td>'
            . '</tr>';
    }

    // Hata/uyarı satırlarını topla (JSON errors dizisinden).
    $sorunSatirlari = '';
    foreach ($errors as $err) {
        $icon = ($err['status'] ?? 'fail') === 'fail' ? '✗' : '⚠';
        $color = $icon === '✗' ? '#b0301a' : '#8a6100';
        $ref = $err['ref'] ?? '';
        $refHtml = $ref !== '' ? ' <a href="https://nexustraveltech.com/admin/kullanim-kilavuzu" style="color:#0d7a4a;font-size:12px">' . htmlspecialchars($ref) . '</a>' : '';
        $sorunSatirlari .= '<div style="padding:4px 0;font-size:13px;color:' . $color . '">' . $icon . ' <b>' . htmlspecialchars($err['module'] ?? '') . '</b> · ' . htmlspecialchars($err['check'] ?? '') . ' — ' . htmlspecialchars($err['detail'] ?? '') . $refHtml . '</div>';
    }

    $body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
        . '<div style="border:2px solid ' . $durumRenk . ';border-radius:8px;padding:10px 14px;margin:0 0 12px;background:' . ($fails > 0 ? '#ffe2de' : '#fdf3e3') . '">'
        . '<b>' . $durumIkon . ' Günlük otomatik test: ' . $durumMetni . '</b>'
        . '<div style="margin-top:4px;font-size:13px">' . $oks . ' ✓ · ' . $warns . ' ⚠ · ' . $fails . ' ✗ — ' . date('Y-m-d H:i') . '</div>'
        . '</div>'
        . '<h2 style="margin:0 0 8px;font-size:16px">Modül özeti</h2>'
        . '<table style="border-collapse:collapse;width:100%;max-width:600px;font-size:13px">'
        . '<tr><th style="text-align:left;padding:6px 12px;border:1px solid #e1e5de;background:#f4f6f1">Modül</th>'
        . '<th style="padding:6px 12px;border:1px solid #e1e5de;background:#f4f6f1;text-align:center">Toplam</th>'
        . '<th style="padding:6px 12px;border:1px solid #e1e5de;background:#f4f6f1;text-align:center">OK</th>'
        . '<th style="padding:6px 12px;border:1px solid #e1e5de;background:#f4f6f1;text-align:center">⚠</th>'
        . '<th style="padding:6px 12px;border:1px solid #e1e5de;background:#f4f6f1;text-align:center">✗</th></tr>'
        . $modulRows
        . '</table>';

    if ($sorunSatirlari !== '') {
        $body .= '<h3 style="margin:16px 0 6px;font-size:14px;color:#b0301a">Sorunlar</h3>'
            . '<div style="background:#f8f6f4;border:1px solid #e1e5de;border-radius:8px;padding:10px 14px">' . $sorunSatirlari . '</div>';
    }

    $body .= '<p style="margin-top:14px;color:#64716d;font-size:12px">'
        . 'Komut: <code>php scripts/auto-test.php --json</code> · '
        . 'Çıkış kodu: ' . $exitCode . ' · '
        . 'Tam çıktı: sunucuda <code>scripts/auto-test.php --json</code>'
        . '</p>'
        . '</div>';

    $subject = '🔍 Günlük test raporu — ' . $durumMetni . ' (' . $fails . ' hata · ' . $warns . ' uyarı)';
    queue_email($adminEmail, $subject, $body, 'daily_auto_test');
    echo "E-posta kuyruğa eklendi: $adminEmail — $durumMetni\n";
} elseif ($hasProblems) {
    echo "admin_alert_email tanımsız — hata/uyarı e-postası gönderilemedi.\n";
} else {
    echo "Tüm testler başarılı ($oks ✓) — e-posta gönderilmedi.\n";
}

// Sonucu kaydet (Zamanlayıcılar sayfası için).
save_platform_setting('last_auto_test_at', date('Y-m-d H:i:s'));
save_platform_setting('last_auto_test_oks', $oks);
save_platform_setting('last_auto_test_warns', $warns);
save_platform_setting('last_auto_test_fails', $fails);
save_platform_setting('last_auto_test_elapsed_ms', $jsonData['elapsed_ms'] ?? 0);
save_platform_setting('last_auto_test_json', $jsonData);

// Denetim kaydı yaz.
try {
    require_once __DIR__ . '/../config/audit.php';
    $modDetails = [];
    foreach ($modules as $mName => $mData) {
        $modDetails[$mName] = ['total' => $mData['total'] ?? 0, 'fail' => $mData['fail'] ?? 0, 'warn' => $mData['warn'] ?? 0];
    }
    audit_log(
        'auto_test.daily',
        'auto_test',
        null,
        ['ok' => $oks, 'warn' => $warns, 'fail' => $fails, 'modules' => $modDetails, 'errors' => array_slice($errors, 0, 10), 'email_sent' => $hasProblems && $adminEmail !== ''],
        'system'
    );
} catch (Throwable $e) {}

echo $outputText . "\n";
exit($exitCode);
