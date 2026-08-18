<?php
declare(strict_types=1);

// Günlük uçtan uca doğrulama — scripts/verify-all.php'yi çalıştırır,
// hata varsa admin_alert_email'e özet e-postası gönderir.
//
// - verify-all.php ayrı süreçte çalıştırılır (kendi çıkış kodu: 0 temiz / 1 hata);
//   --run-jobs / --deep / --http bayrakları verilmez — yapı + şema + görev kaydı +
//   webhook akışı (salt, tek transaction, rollback) denetlenir.
// - Yalnızca SORUN varken e-posta gider; temizse yalnızca konsol çıktısı.
// - admin_alert_email tanımsızsa e-posta atlanır (görev yine de çalışır ve çıkış
//   kodu yine hata durumunda 1 olur — zamanlayıcı geçmişinde görünür).
//
// Zamanlayıcı: nexus-verify-all (varsayılan: her gün 07:10).

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/mailer.php';

$adminEmail = trim((string) platform_setting('admin_alert_email', ''));

// verify-all.php'yi ayrı süreçte çalıştır — çıktıyı ve çıkış kodunu yakala.
$base = dirname(__DIR__);
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($base . '/scripts/verify-all.php') . ' 2>&1';
$outLines = [];
$code = 0;
exec($cmd, $outLines, $code);
$out = implode("\n", $outLines);

echo $out . "\n";
if ($code === 0) {
    echo "Uçtan uca doğrulama temiz (çıkış 0) — e-posta gerekmiyor.\n";
    exit(0);
}

// Hata satırlarını çıkar (✗ ile başlayanlar) — hangi bölümde olduğuyla birlikte.
$failLines = [];
$section = '';
foreach ($outLines as $line) {
    $t = trim((string) $line);
    if (preg_match('/^===\s*(.+?)\s*===$/', $t, $m)) {
        $section = trim((string) $m[1]);
        continue;
    }
    if (str_starts_with($t, '✗')) {
        $failLines[] = ['section' => $section, 'line' => $t];
    }
}
if ($failLines === []) {
    // Çıkış 1 ama ✗ satırı yok — betik hatası (catch bloğu). Son satırlardan özet.
    $failLines[] = ['section' => 'betik hatası', 'line' => trim((string) end($outLines)) ?: 'bilinmeyen hata'];
}

echo "\n" . count($failLines) . ' hata satırı tespit edildi (çıkış ' . $code . ').';
if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    $rowsHtml = '';
    foreach (array_slice($failLines, 0, 40) as $f) {
        $sec = $f['section'] !== '' ? htmlspecialchars((string) $f['section']) : '—';
        $rowsHtml .= '<tr><td style="padding:6px 10px;border:1px solid #e1e5de;color:#64716d;white-space:nowrap">' . $sec . '</td>'
            . '<td style="padding:6px 10px;border:1px solid #e1e5de;color:#8e2410">' . htmlspecialchars((string) $f['line']) . '</td></tr>';
    }
    $extra = count($failLines) > 40
        ? '<tr><td colspan="2" style="padding:6px 10px;border:1px solid #e1e5de;color:#64716d">… ve ' . (count($failLines) - 40) . ' hata daha (tamamı konsol çıktısında).</td></tr>'
        : '';
    $body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
        . '<h2 style="margin:0 0 6px">⚠ Uçtan uca doğrulama: ' . count($failLines) . ' hata</h2>'
        . '<p style="color:#64716d;margin:0 0 10px">Günlük doğrulama (scripts/verify-all.php) ' . gmdate('d.m.Y H:i') . ' UTC çalıştı ve hatalı adım buldu. Bölüm + hata satırları aşağıdadır.</p>'
        . '<table style="border-collapse:collapse;width:100%;max-width:680px;font-size:12px">'
        . '<tr><th style="text-align:left;padding:7px 10px;border:1px solid #e1e5de;background:#f4f6f1">Bölüm</th><th style="text-align:left;padding:7px 10px;border:1px solid #e1e5de;background:#f4f6f1">Hata</th></tr>'
        . $rowsHtml . $extra
        . '</table>'
        . '<p style="margin-top:16px">Tam çıktı için sunucuda: <code style="background:#f2f4ef;padding:2px 5px">/opt/plesk/php/8.5/bin/php scripts/verify-all.php --run-jobs --deep --http</code></p>'
        . '</div>';
    queue_email($adminEmail, '⚠ Uçtan uca doğrulama: ' . count($failLines) . ' hata', $body, 'verify_all_alert');
    echo " Admin e-postası kuyruğa eklendi.\n";
} else {
    echo " admin_alert_email tanımsız — e-posta atlanıyor.\n";
}
exit(1);
