<?php
declare(strict_types=1);

// fx_rates eksik kur çifti denetimi — günlük görev.
//
// 1) Kanıt: son 24 saatte webhook işlemede fx_rate_missing:FROM->TO hatası düşen çiftler
//    (channel_sync_logs.error_message içinden ayrıştırılır; fiyat satırı o yüzden yazılmamıştır).
// 2) Önleyici: aktif fiyat planlarının para birimi ile webhook tarafında görülen gelen para
//    birimleri (varsayılan ayar + son 30 günün yüklerindeki currency alanları) arasında,
//    bugün itibarıyla kapsanmayan çiftler (doğrudan ya da TRY üzerinden çapraz kur yok).
//
// Eksik çift varsa admin_alert_email'e özet e-postası gider; kur ekleme adresi
// admin/kur-yonetimi (manuel satır veya TCMB çekme). Kur yokken fiyat satırı yazılmaz.
//
// Zamanlayıcı: nexus-fx-missing-audit (varsayılan: her gün 06:15).

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/fx.php';

$pdo = db();
$adminEmail = trim((string) platform_setting('admin_alert_email', ''));

// 1) Kanıt — son 24 saatte başarısız olan çiftler.
$failed = []; // FROM->TO => ['count' => n, 'first' => ts, 'last' => ts]
$logRows = $pdo->query(
    "SELECT error_message, created_at
     FROM channel_sync_logs
     WHERE direction='pull' AND status='failed'
       AND error_message LIKE '%fx_rate_missing%'
       AND created_at >= now() - interval '24 hours'"
)->fetchAll();
foreach ($logRows as $r) {
    if (!preg_match_all('/fx_rate_missing:([A-Z]{3})->([A-Z]{3})/', (string) $r['error_message'], $m, PREG_SET_ORDER)) {
        continue;
    }
    $ts = strtotime((string) $r['created_at']) ?: time();
    foreach ($m as $pair) {
        $key = $pair[1] . '->' . $pair[2];
        if (!isset($failed[$key])) {
            $failed[$key] = ['count' => 0, 'first' => $ts, 'last' => $ts];
        }
        $failed[$key]['count']++;
        $failed[$key]['first'] = min($failed[$key]['first'], $ts);
        $failed[$key]['last'] = max($failed[$key]['last'], $ts);
    }
}

// 2) Önleyici — aktif yapılandırmanın gerektirdiği, bugün kapsanmayan çiftler.
$targets = $pdo->query("SELECT DISTINCT UPPER(currency) AS c FROM rate_plans WHERE status='active' AND currency IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
$targets = array_values(array_filter($targets, fn($c) => preg_match('/^[A-Z]{3}$/', (string) $c)));
$incoming = [];
$def = strtoupper((string) platform_setting('channel_webhook_default_currency', 'EUR'));
if (preg_match('/^[A-Z]{3}$/', $def)) {
    $incoming[$def] = true;
}
$payloadRows = $pdo->query(
    "SELECT request_payload FROM channel_sync_logs
     WHERE direction='pull' AND request_payload IS NOT NULL
       AND created_at >= now() - interval '30 days'"
)->fetchAll();
foreach ($payloadRows as $cr) {
    $dec = json_decode((string) $cr['request_payload'], true);
    if (!is_array($dec)) {
        continue;
    }
    if (isset($dec['currency']) && is_string($dec['currency'])) {
        $c = strtoupper($dec['currency']);
        if (preg_match('/^[A-Z]{3}$/', $c)) {
            $incoming[$c] = true;
        }
    }
    foreach ((array) ($dec['entries'] ?? []) as $en) {
        if (is_array($en) && isset($en['currency']) && is_string($en['currency'])) {
            $c = strtoupper($en['currency']);
            if (preg_match('/^[A-Z]{3}$/', $c)) {
                $incoming[$c] = true;
            }
        }
    }
}
$today = date('Y-m-d');
$preventive = [];
foreach (array_keys($incoming) as $from) {
    foreach ($targets as $to) {
        if ($from === $to) {
            continue;
        }
        if (fx_rate($from, $to, $today) > 0) {
            continue; // doğrudan veya TRY üzerinden kapsanıyor
        }
        $key = $from . '->' . $to;
        if (isset($failed[$key])) {
            continue; // kanıt listesinde zaten var
        }
        $preventive[$key] = 'aktif fiyat planı hedefi (' . $to . ')';
    }
}

$missing = $failed + $preventive;
if ($missing === []) {
    echo "Kur denetimi temiz: son 24 saatte eksik kur hatası yok; aktif planlar için gereken tüm çiftler kapsanıyor.\n";
    exit(0);
}

ksort($missing);
echo 'Eksik kur çifti: ' . count($missing) . "\n";
foreach ($missing as $key => $info) {
    if (is_array($info)) {
        echo '  ' . $key . ' — kanıt · ' . $info['count'] . ' başarısız işlem (' . date('Y-m-d H:i', $info['first']) . ' → ' . date('Y-m-d H:i', $info['last']) . ")\n";
    } else {
        echo '  ' . $key . ' — önleyici · ' . $info . "\n";
    }
}

if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    $rowsHtml = '';
    foreach ($missing as $key => $info) {
        if (is_array($info)) {
            $rowsHtml .= '<tr><td style="padding:7px 12px;border:1px solid #e1e5de"><b>' . htmlspecialchars($key) . '</b></td>'
                . '<td style="padding:7px 12px;border:1px solid #e1e5de;color:#b0301a">kanıt — ' . (int) $info['count'] . ' başarısız işlem</td>'
                . '<td style="padding:7px 12px;border:1px solid #e1e5de">' . date('Y-m-d H:i', $info['first']) . ' → ' . date('Y-m-d H:i', $info['last']) . '</td></tr>';
        } else {
            $rowsHtml .= '<tr><td style="padding:7px 12px;border:1px solid #e1e5de"><b>' . htmlspecialchars($key) . '</b></td>'
                . '<td style="padding:7px 12px;border:1px solid #e1e5de">önleyici — ' . htmlspecialchars($info) . '</td>'
                . '<td style="padding:7px 12px;border:1px solid #e1e5de">bugün</td></tr>';
        }
    }
    $body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
        . '<h2 style="margin:0 0 6px">💱 Eksik kur çiftleri (' . count($missing) . ')</h2>'
        . '<p style="color:#64716d;margin:0 0 10px">Webhook fiyat dönüşümünde ihtiyaç duyulan ama <b>fx_rates</b>\'te bulunmayan para birimi çiftleri. '
        . '<b style="color:#b0301a">Kanıt</b> = son 24 saatte bu yüzden başarısız olan işlem (fiyat satırı yazılmadı); '
        . '<b>önleyici</b> = aktif fiyat planları / görülen gelen birimler nedeniyle bugün gereken çift. Kur eklenene kadar fiyat satırı yazılmaz (yanlış birim engellenir).</p>'
        . '<table style="border-collapse:collapse;width:100%;max-width:640px;font-size:13px">'
        . '<tr><th style="text-align:left;padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1">Çift</th>'
        . '<th style="text-align:left;padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1">Neden</th>'
        . '<th style="text-align:left;padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1">Son görülme</th></tr>'
        . $rowsHtml
        . '</table>'
        . '<p style="margin-top:18px">Kur eklemek için: <a href="https://nexustraveltech.com/admin/kur-yonetimi" style="color:#b0301a">Kur yönetimi →</a> '
        . '(manuel satır veya TCMB bugünkü kuru çekme).</p>'
        . '</div>';
    queue_email($adminEmail, 'Eksik kur çiftleri: ' . count($missing) . ' para birimi eşleşmesi gerekli', $body, 'fx_missing_audit');
    echo "Admin e-postası kuyruğa eklendi.\n";
} else {
    echo "admin_alert_email tanımsız — e-posta atlanıyor.\n";
}
