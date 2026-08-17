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

// ---- Konsol raporu ----
echo "Admin uyarı e-postası teslimat doğrulaması\n";
echo str_repeat('-', 60) . "\n";
if ($status === 'none') {
    echo "Bekleyen test koşusu yok (son koşu: " . ($at !== '' ? $at : '—') . ").\n";
} else {
    echo 'Son test: ' . ($code !== '' ? $code : '—') . ' · ' . $at . ' · mod: ' . $mode . "\n";
    echo 'Durum: ' . strtoupper($status) . " — " . $reason . "\n";
    if ($status === 'delivered') echo 'Teslim: ' . ($deliveredAt !== '' ? $deliveredAt : '—') . "\n";
}
echo str_repeat('-', 60) . "\n";
echo "Tarihçe (son " . min(10, count($hist)) . " koşu):\n";
if ($hist === []) {
    echo "  (kayıt yok — henüz --send ile test kuyruğa eklenmedi)\n";
}
foreach (array_slice(array_reverse($hist), 0, 10) as $h) {
    if (!is_array($h)) continue;
    printf("  %s · %s · %s%s\n",
        str_pad((string) ($h['code'] ?? '—'), 18),
        str_pad((string) ($h['status'] ?? '?'), 10),
        (string) ($h['at'] ?? '—'),
        ($h['delivered_at'] ?? '') !== '' ? ' · teslim ' . $h['delivered_at'] : '');
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
                ? '<p style="background:#ffe2de;border:1px solid #f0c4bc;border-radius:8px;padding:10px 12px">Kuyruk işleyicisi çalışmıyor olabilir: <code>/opt/plesk/php/8.5/bin/php cron/tick.php</code> → <code>nexus-process-emails</code> satırını kontrol edin; ardından <code>cron/test-admin-alerts.php --send</code> ile yeniden test edin.</p>'
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
        queue_email($adminEmail, '📬 Test e-postası teslimat raporu — ' . $status, $body, 'alert_test_delivery');
        echo "\nTeslimat raporu kuyruğa eklendi: " . $adminEmail . "\n";
    } else {
        echo "\nadmin_alert_email tanımsız — e-posta raporu atlanıyor.\n";
    }
}

// Zamanlayıcı döngüsünde sorun sayılmasın: exit 1 yalnızca 'missed' durumunda (izlenebilirlik).
exit($status === 'missed' ? 1 : 0);
