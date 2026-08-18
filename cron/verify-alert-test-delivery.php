<?php
declare(strict_types=1);

// Admin uyarı e-postası teslimat doğrulaması.
//
// cron/test-admin-alerts.php --send ile kuyruğa eklenen test e-postasının durumunu
// izler: mailer (config/mailer.php) e-postayı gerçekten gönderdiğinde
// last_alert_test_delivered_code'a doğrulama kodunu yazar; bu görev kodu eşleştirip
// rapor durumunu üretir:
//   delivered — kod eşleşti (e-posta kuyruk işleyicisinden geçti)
//   pending   — 30 dakikadan kısa süredir bekliyor (hâlâ işlenebilir)
//   missed    — 30 dakika geçti ama kod eşleşmedi (kuyruk/işleyici sorunu)
//
// Durum hem last_alert_test_status'a hem alert_test_history tarihçesine yazılır
// (son 20 koşu — Zamanlayıcılar sayfası ve --report çıktısı buradan okur).
//
// Kullanım:
//   /opt/plesk/php/8.5/bin/php cron/verify-alert-test-delivery.php          → konsol raporu
//   /opt/plesk/php/8.5/bin/php cron/verify-alert-test-delivery.php --email  → ayrıca admin_alert_email'e rapor
//
// Zamanlayıcı: nexus-alert-test-delivery (varsayılan: her 30 dakikada bir — bekleyen
// test yoksa sessizce biter).

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/platform_settings.php';

$email = in_array('--email', $argv ?? [], true);
$pdo = db();

const MISSED_AFTER = 1800; // 30 dakika — teslimat penceresi

$code = (string) platform_setting('last_alert_test_code', '');
$mode = (string) platform_setting('last_alert_test_mode', '');
$at = (string) platform_setting('last_alert_test_at', '');
$deliveredCode = (string) platform_setting('last_alert_test_delivered_code', '');
$deliveredAt = (string) platform_setting('last_alert_test_delivered_at', '');

// Tarihçeyi oku (test-admin-alerts.php'nin --send koşusunda eklenir).
$hist = platform_setting('alert_test_history', []);
if (!is_array($hist)) $hist = [];

$status = 'none';
$reason = '';
$changed = false;

if ($mode === 'send' && $code !== '' && $at !== '') {
    $delivered = $deliveredCode !== '' && $deliveredCode === $code;
    $age = time() - strtotime($at);
    if ($delivered) {
        $status = 'delivered';
        $reason = 'doğrulama kodu eşleşti — e-posta kuyruk işleyicisinden teslim edildi';
    } elseif ($age < MISSED_AFTER) {
        $status = 'pending';
        $reason = 'teslimat bekleniyor (' . max(0, (int) ceil((MISSED_AFTER - $age) / 60)) . ' dk penceresi kaldı)';
    } else {
        $status = 'missed';
        $reason = '30 dakika geçti, kod eşleşmedi — kuyruk işleyicisi (cron/process-emails.php) çalışmıyor olabilir';
    }

    // Önceki durumdan farklıysa kaydet.
    $prev = (string) platform_setting('last_alert_test_status', '');
    if ($prev !== $status) {
        save_platform_setting('last_alert_test_status', $status);
        save_platform_setting('last_alert_test_reason', $reason);
        $changed = true;
    }

    // Tarihçedeki koşuyu güncelle (kod = koşu anahtarı).
    foreach ($hist as &$h) {
        if (is_array($h) && ($h['code'] ?? '') === $code) {
            $h['status'] = $status;
            $h['reason'] = $reason;
            if ($delivered) $h['delivered_at'] = $deliveredAt !== '' ? $deliveredAt : date('Y-m-d H:i:s');
            $changed = true;
        }
    }
    unset($h);
    if ($changed) save_platform_setting('alert_test_history', $hist);
} elseif ($mode === 'dry') {
    $status = 'dry';
    $reason = 'kuru koşu — gerçek gönderim için cron/test-admin-alerts.php --send';
}

// ---- Otomatik yeniden deneme: missed durumunda kuyruk işleyicisini tetikle ----
$noRetry = in_array('--no-retry', $argv ?? [], true);
$retried = false;
if ($status === 'missed' && !$noRetry && $code !== '') {
    echo "\n⚠ MISSed — otomatik yeniden deneme başlatılıyor…\n";
    // 1) Kuyruk işleyicisini çalıştır (bekleyen e-postaları gönder).
    $processor = __DIR__ . '/process-emails.php';
    if (file_exists($processor)) {
        $out = [];
        $rc = 0;
        exec(PHP_BINARY . ' ' . escapeshellarg($processor) . ' 2>&1', $out, $rc);
        echo '  → process-emails: ' . ($rc === 0 ? 'tamamlandı' : 'hata (kod ' . $rc . ')') . (isset($out[0]) ? ' — ' . mb_substr($out[0], 0, 120) : '') . "\n";
    }
    // 2) Test e-postasını yeniden gönder.
    $tester = __DIR__ . '/test-admin-alerts.php';
    if (file_exists($tester)) {
        $out2 = [];
        $rc2 = 0;
        exec(PHP_BINARY . ' ' . escapeshellarg($tester) . ' --send 2>&1', $out2, $rc2);
        echo '  → test-admin-alerts --send: ' . ($rc2 === 0 ? 'kuyruğa eklendi' : 'hata (kod ' . $rc2 . ')') . (isset($out2[0]) ? ' — ' . mb_substr($out2[0], 0, 120) : '') . "\n";
        if ($rc2 === 0) {
            $retried = true;
            // Yeni kodu kaydet — 5 dk sonra gelecek doğrulama bunu kontrol edecek.
            save_platform_setting('last_alert_test_retry_at', date('Y-m-d H:i:s'));
            save_platform_setting('last_alert_test_retry_count', (int) platform_setting('last_alert_test_retry_count', 0) + 1);
            echo "  → 5 dk sonra yeniden doğrulanacak (sonraki otomatik çalışma veya manuel buton).\n";
        }
    }
    // Retry tarihçesine not ekle.
    foreach ($hist as &$h) {
        if (is_array($h) && ($h['code'] ?? '') === $code && ($h['status'] ?? '') === 'missed') {
            $h['retried'] = true;
            $h['retry_at'] = date('Y-m-d H:i:s');
        }
    }
    unset($h);
    save_platform_setting('alert_test_history', $hist);
}

// ---- Konsol raporu ----
echo "Admin uyarı e-postası teslimat doğrulaması\n";
echo str_repeat('-', 60) . "\n";
if ($status === 'none') {
    echo "Bekleyen test koşusu yok (son koşu: " . ($at !== '' ? $at : '—') . ").\n";
} else {
    echo 'Son test: ' . ($code !== '' ? $code : '—') . ' · ' . $at . ' · mod: ' . $mode . "\n";
    echo 'Durum: ' . strtoupper($status) . " — " . $reason . "\n";
    if ($status === 'delivered') echo 'Teslim: ' . ($deliveredAt !== '' ? $deliveredAt : '—') . "\n";
    if ($retried) echo 'Yeniden deneme: otomatik olarak kuyruk işleyicisi çalıştırıldı ve test yeniden gönderildi.' . "\n";
    $retryCount = (int) platform_setting('last_alert_test_retry_count', 0);
    if ($retryCount > 0) echo 'Toplam yeniden deneme sayısı: ' . $retryCount . "\n";
}
echo str_repeat('-', 60) . "\n";
echo "Tarihçe (son " . min(10, count($hist)) . " koşu):\n";
if ($hist === []) {
    echo "  (kayıt yok — henüz --send ile test kuyruğa eklenmedi)\n";
}
foreach (array_slice(array_reverse($hist), 0, 10) as $h) {
    if (!is_array($h)) continue;
    printf("  %s · %s · %s%s%s\n",
        str_pad((string) ($h['code'] ?? '—'), 18),
        str_pad((string) ($h['status'] ?? '?'), 10),
        (string) ($h['at'] ?? '—'),
        ($h['delivered_at'] ?? '') !== '' ? ' · teslim ' . $h['delivered_at'] : '',
        !empty($h['retried']) ? ' · yeniden gönderildi' : '');
}

// ---- E-posta raporu (--email) ----
if ($email && $status !== 'none') {
    $adminEmail = trim((string) platform_setting('admin_alert_email', ''));
    if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $rows = '';
        foreach (array_slice(array_reverse($hist), 0, 10) as $h) {
            if (!is_array($h)) continue;
            $st = (string) ($h['status'] ?? '?');
            $color = $st === 'delivered' ? '#2e7d32' : ($st === 'missed' ? '#b0301a' : ($st === 'pending' ? '#8a6100' : '#64716d'));
            $rows .= '<tr>'
                . '<td style="padding:6px 12px;border:1px solid #e1e5de"><code>' . htmlspecialchars((string) ($h['code'] ?? '—')) . '</code></td>'
                . '<td style="padding:6px 12px;border:1px solid #e1e5de">' . htmlspecialchars((string) ($h['at'] ?? '—')) . '</td>'
                . '<td style="padding:6px 12px;border:1px solid #e1e5de;text-align:center;color:' . $color . '"><b>' . htmlspecialchars($st) . '</b></td>'
                . '<td style="padding:6px 12px;border:1px solid #e1e5de">' . htmlspecialchars((string) ($h['delivered_at'] ?? '')) . '</td>'
                . '</tr>';
        }
        $body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
            . '<h2 style="margin:0 0 6px">📬 Test e-postası teslimat raporu</h2>'
            . '<p style="color:#64716d;margin:0 0 10px">Son test koşusu: <b>' . htmlspecialchars($code) . '</b> · ' . htmlspecialchars($at) . ' · durum: <b style="color:' . ($status === 'delivered' ? '#2e7d32' : ($status === 'missed' ? '#b0301a' : '#8a6100')) . '">' . strtoupper($status) . '</b></p>'
            . '<p>' . htmlspecialchars($reason) . '</p>'
            . ($status === 'missed'
                ? '<p style="background:#ffe2de;border:1px solid #f0c4bc;border-radius:8px;padding:10px 12px">'
                    . ($retried ? '🔄 <b>Otomatik yeniden gönderim başlatıldı</b> — kuyruk işleyicisi çalıştırıldı ve test e-postası yeniden kuyruğa eklendi. 5 dk sonra tekrar kontrol edin.<br>' : '')
                    . 'Kuyruk işleyicisi çalışmıyor olabilir: <code>/opt/plesk/php/8.5/bin/php cron/tick.php</code> → <code>nexus-process-emails</code> satırını kontrol edin; ardından <code>cron/test-admin-alerts.php --send</code> ile yeniden test edin.</p>'
                : '')
            . '<table style="border-collapse:collapse;width:100%;max-width:640px;font-size:13px;margin-top:12px">'
            . '<tr><th style="text-align:left;padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1">Kod</th>'
            . '<th style="text-align:left;padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1">Kuyruğa alındı</th>'
            . '<th style="padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1;text-align:center">Durum</th>'
            . '<th style="padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1;text-align:left">Teslim</th></tr>'
            . $rows
            . '</table>'
            . '<p style="margin-top:14px;color:#64716d;font-size:12px">Rapor: cron/verify-alert-test-delivery.php --email · tarihçe son 20 koşu tutulur.</p>'
            . '</div>';
        // Şablon değişkenleri hazırla — admin panelinden düzenlenebilir alert_test_delivery şablonu.
        $durumRenk = $status === 'delivered' ? '#2e7d32' : ($status === 'missed' ? '#b0301a' : '#8a6100');
        $uyariKutusu = '';
        if ($status === 'missed') {
            $uyariKutusu = '<p style="background:#ffe2de;border:1px solid #f0c4bc;border-radius:8px;padding:10px 12px">'
                . ($retried ? '🔄 <b>Otomatik yeniden gönderim başlatıldı</b> — kuyruk işleyicisi çalıştırıldı ve test e-postası yeniden kuyruğa eklendi. 5 dk sonra tekrar kontrol edin.<br>' : '')
                . 'Kuyruk işleyicisi çalışmıyor olabilir: <code>/opt/plesk/php/8.5/bin/php cron/tick.php</code> → <code>nexus-process-emails</code> satırını kontrol edin; ardından <code>cron/test-admin-alerts.php --send</code> ile yeniden test edin.</p>';
        }
        $tplVars = [
            'kod' => $code,
            'tarih' => $at,
            'durum' => strtoupper($status),
            'durum_renk' => $durumRenk,
            'neden' => $reason,
            'uyari_kutusu' => $uyariKutusu,
            'tablo_satirlari' => $rows,
        ];
        queue_email_with_template($adminEmail, 'alert_test_delivery', $tplVars, '📬 Test e-postası teslimat raporu — ' . $status, $body, 'alert_test_delivery');
        echo "\nTeslimat raporu kuyruğa eklendi: " . $adminEmail . "\n";
    } else {
        echo "\nadmin_alert_email tanımsız — e-posta raporu atlanıyor.\n";
    }
}

// Zamanlayıcı döngüsünde sorun sayılmasın: exit 1 yalnızca 'missed' durumunda (izlenebilirlik).
exit($status === 'missed' ? 1 : 0);
