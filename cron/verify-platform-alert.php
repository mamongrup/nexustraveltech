<?php
declare(strict_types=1);

// Günlük platform doğrulaması — scripts/verify-platform.php'yi --json ile çalıştırır,
// şema uyumsuzluğu / eksik tablo-kolon / bekleyen migration varsa admin_alert_email'e
// özet e-postası gönderir.
//
// - verify-platform.php ayrı süreçte çalıştırılır (çıkış kodu: 0 temiz / 1 sorun);
//   --json çıktısı ayrıştırılarak bölüm bazında (tables/columns/room_mappings/
//   integration/migrations/env) durum satırları üretilir.
// - Yalnızca SORUN varken e-posta gider; temizse yalnızca konsol çıktısı.
// - JSON ayrıştırılamazsa (eski kod / betik hatası) ham çıktının ✗ satırlarına düşer.
// - admin_alert_email tanımsızsa e-posta atlanır; görev yine çıkış 1 ile döner
//   (zamanlayıcı geçmişinde görünür).
//
// Zamanlayıcı: nexus-verify-platform (varsayılan: her gün 07:25).

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/mailer.php';

$adminEmail = trim((string) platform_setting('admin_alert_email', ''));

// verify-platform.php'yi --json ile ayrı süreçte çalıştır — çıktıyı ve çıkış kodunu yakala.
$base = dirname(__DIR__);
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($base . '/scripts/verify-platform.php') . ' --json 2>&1';
$outLines = [];
$code = 0;
exec($cmd, $outLines, $code);
$out = implode("\n", $outLines);

echo $out . "\n";
if ($code === 0) {
    echo "Platform doğrulaması temiz (çıkış 0) — e-posta gerekmiyor.\n";
    exit(0);
}

// JSON bloğunu ayıkla — --json modunda insan çıktısı satırları JSON'dan ÖNCE gelir
// (json_encode en sonda basılır). Son '{' satırından itibaren al.
$json = null;
$start = strrpos($out, "\n{");
$candidate = $start !== false ? substr($out, $start + 1) : (str_starts_with($out, '{') ? $out : null);
if ($candidate !== null) {
    $json = json_decode($candidate, true);
}

$checkLabels = [
    'tables'        => 'Tablolar',
    'columns'       => 'Kolonlar',
    'room_mappings' => 'Oda eşleştirme',
    'integration'   => 'Entegrasyon kolonları',
    'repair_audit'  => 'Onarım veri denetimi',
    'migrations'    => 'Migration durumu',
    'env'           => 'Ortam',
];

/** Bölüm bazlı tek satırlık detay — checks.<name> içeriğine göre. */
function verify_platform_check_detail(array $check, string $name): string
{
    if (($check['status'] ?? '') === 'ok') return '';
    switch ($name) {
        case 'tables':
            return 'Eksik tablolar: ' . implode(', ', array_map('strval', (array) ($check['missing'] ?? [])));
        case 'columns':
            $parts = [];
            foreach ((array) ($check['missing'] ?? []) as $tbl => $cols) {
                $parts[] = $tbl . ': ' . implode(', ', array_map('strval', (array) $cols));
            }
            return 'Eksik kolonlar — ' . implode(' · ', $parts);
        case 'room_mappings':
            if (!empty($check['missing_columns'])) {
                return 'ŞEMA UYUMSUZ: eksik kolonlar ' . implode(', ', array_map('strval', (array) $check['missing_columns'])) . ' — scripts/health-check.php --repair gerekli';
            }
            return 'Yetim/uyumsuz eşleştirme: ' . (int) ($check['orphans'] ?? 0);
        case 'integration':
            return (int) ($check['ready'] ?? 0) . '/' . (int) ($check['total'] ?? 0) . ' hazır — eksik: ' . implode('; ', array_map('strval', (array) ($check['missing'] ?? [])));
        case 'migrations':
            return count((array) ($check['pending'] ?? [])) . ' migration bekliyor: ' . implode(', ', array_map('strval', (array) ($check['pending'] ?? [])));
        case 'env':
            $flags = [];
            foreach (['app_encryption_key', 'curl', 'pdo_pgsql'] as $f) {
                if (empty($check[$f])) $flags[] = $f;
            }
            return 'Eksik ortam: ' . implode(', ', $flags);
        default:
            return '';
    }
}

$rowsHtml = '';
$errorCount = 0;
if (is_array($json)) {
    // checks içindeki her hatalı bölüm → satır.
    foreach ($checkLabels as $key => $label) {
        $chk = $json['checks'][$key] ?? null;
        if (!is_array($chk) || ($chk['status'] ?? '') === 'ok') continue;
        $errorCount++;
        $detail = verify_platform_check_detail($chk, $key);
        $rowsHtml .= '<tr>'
            . '<td style="padding:6px 10px;border:1px solid #e1e5de;color:#64716d;white-space:nowrap">' . htmlspecialchars($label) . '</td>'
            . '<td style="padding:6px 10px;border:1px solid #e1e5de;color:#8e2410">' . ($detail !== '' ? htmlspecialchars($detail) : 'başarısız') . '</td>'
            . '</tr>';
    }
    $errList = (array) ($json['errors'] ?? []);
    if ($errorCount === 0 && $errList !== []) $errorCount = count($errList);
} else {
    // JSON yok — eski kod/betik hatası: ham çıktının ✗ satırlarına düş.
    foreach ($outLines as $line) {
        if (str_starts_with(trim((string) $line), '✗')) {
            $errorCount++;
            $rowsHtml .= '<tr>'
                . '<td style="padding:6px 10px;border:1px solid #e1e5de;color:#64716d;white-space:nowrap">çıktı</td>'
                . '<td style="padding:6px 10px;border:1px solid #e1e5de;color:#8e2410">' . htmlspecialchars(trim((string) $line)) . '</td>'
                . '</tr>';
        }
    }
    if ($errorCount === 0) {
        $errorCount = 1;
        $rowsHtml .= '<tr>'
            . '<td style="padding:6px 10px;border:1px solid #e1e5de;color:#64716d;white-space:nowrap">betik hatası</td>'
            . '<td style="padding:6px 10px;border:1px solid #e1e5de;color:#8e2410">' . htmlspecialchars(trim((string) end($outLines)) ?: 'bilinmeyen hata') . '</td>'
            . '</tr>';
    }
}

echo "\n" . $errorCount . ' sorun tespit edildi (çıkış ' . $code . ').';
if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    // Ayrıntılı hata satırları (JSON errors listesi) — ilk 40.
    $errRows = '';
    if (is_array($json)) {
        $errList = array_slice((array) ($json['errors'] ?? []), 0, 40);
        foreach ($errList as $e) {
            $errRows .= '<tr><td style="padding:6px 10px;border:1px solid #e1e5de;color:#8e2410">' . htmlspecialchars((string) $e) . '</td></tr>';
        }
        if (count((array) ($json['errors'] ?? [])) > 40) {
            $errRows .= '<tr><td style="padding:6px 10px;border:1px solid #e1e5de;color:#64716d">… ve ' . (count((array) $json['errors']) - 40) . ' hata daha (tamamı konsol çıktısında).</td></tr>';
        }
    }
    $body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
        . '<h2 style="margin:0 0 6px">⚠ Platform doğrulaması: ' . $errorCount . ' sorun</h2>'
        . '<p style="color:#64716d;margin:0 0 10px">Günlük doğrulama (scripts/verify-platform.php) ' . gmdate('d.m.Y H:i') . ' UTC çalıştı ve şema/migration uyumsuzluğu buldu. Bölüm + durum aşağıdadır.</p>'
        . '<table style="border-collapse:collapse;width:100%;max-width:680px;font-size:12px">'
        . '<tr><th style="text-align:left;padding:7px 10px;border:1px solid #e1e5de;background:#f4f6f1">Bölüm</th><th style="text-align:left;padding:7px 10px;border:1px solid #e1e5de;background:#f4f6f1">Durum</th></tr>'
        . $rowsHtml
        . '</table>';
    if ($errRows !== '') {
        $body .= '<p style="margin:14px 0 6px;color:#10211f;font-weight:bold">Hata ayrıntıları</p>'
            . '<table style="border-collapse:collapse;width:100%;max-width:680px;font-size:12px">' . $errRows . '</table>';
    }
    $body .= '<p style="margin-top:16px">Önerilen düzeltme: <code style="background:#f2f4ef;padding:2px 5px">/opt/plesk/php/8.5/bin/php scripts/health-check.php --repair --backup-schema --yes</code> — tam çıktı: <code style="background:#f2f4ef;padding:2px 5px">/opt/plesk/php/8.5/bin/php scripts/verify-platform.php</code></p>'
        . '</div>';
    queue_email($adminEmail, '⚠ Platform doğrulaması: ' . $errorCount . ' sorun (şema/migration)', $body, 'verify_platform_alert');
    echo " Admin e-postası kuyruğa eklendi.\n";
} else {
    echo " admin_alert_email tanımsız — e-posta atlanıyor.\n";
}
exit(1);
