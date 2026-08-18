<?php
declare(strict_types=1);

// fx_rates eksik/bayat kur çifti denetimi — günlük görev.
//
// 1) Kanıt: son 24 saatte webhook işlemede fx_rate_missing:FROM->TO hatası düşen çiftler
//    (channel_sync_logs.error_message içinden ayrıştırılır; fiyat satırı o yüzden yazılmamıştır).
// 2) Önleyici: aktif fiyat planlarının para birimi ile webhook tarafında görülen gelen para
//    birimleri (varsayılan ayar + son 30 günün yüklerindeki currency alanları) arasında,
//    bugün itibarıyla kapsanmayan çiftler (doğrudan ya da TRY üzerinden çapraz kur yok).
// 3) Bayat: gereken çiftlerin en güncel kur kaydı 7+ günden eskiyse ayrı bölümde uyar
//    (doğrudan kayıt yoksa TRY çaprazını besleyen bacakların en eskisi esas alınır).
//
// Her çalıştırmada bugünün sonucu fx_audit_daily'ye yazılır (temiz günler dahil) —
// admin/kur-yonetimi zaman çizelgesini oradan okur. Eksik/bayat varsa admin_alert_email'e
// özet e-postası gider; kur ekleme adresi admin/kur-yonetimi (manuel satır, TCMB çekme
// veya "eksik çiftleri TCMB'den doldur").
//
// Zamanlayıcı: nexus-fx-missing-audit (varsayılan: her gün 06:15).

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/fx.php';

/**
 * Kur denetimi ana mantığı — CLI görevi (aşağıda) ve admin/kur-yonetimi 'yenile'
 * eylemi (bayat çift kurunu TCMB'den güncelledikten sonra yeniden denetim) aynı
 * fonksiyonu kullanır. Sonuç fx_audit_daily'ye yazılır; eksik/bayat varsa e-posta gider.
 *
 * @return array{ok: bool, missing: int, stale: int}
 */
function audit_fx_missing_run(PDO $pdo, string $adminEmail): array
{


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

// 3) Bayat — gereken çiftlerin en güncel kur kaydı 7+ günden eski.
$staleThreshold = date('Y-m-d', strtotime('-7 days'));
function fx_pair_latest_date(PDO $pdo, string $from, string $to): ?string
{
    $q = $pdo->prepare('SELECT MAX(rate_date) FROM fx_rates WHERE base_currency=? AND quote_currency=?');
    $q->execute([$from, $to]);
    $d = $q->fetchColumn();
    return ($d !== false && $d !== null) ? (string) $d : null;
}
$stale = [];
foreach (array_keys($incoming) as $from) {
    foreach ($targets as $to) {
        if ($from === $to) {
            continue;
        }
        $direct = fx_pair_latest_date($pdo, $from, $to);
        if ($direct !== null) {
            if ($direct < $staleThreshold) {
                $stale[$from . '->' . $to] = 'doğrudan kur ' . $direct . ' (' . (int) floor((time() - strtotime($direct)) / 86400) . ' gün önce)';
            }
            continue;
        }
        if ($from !== 'TRY' && $to !== 'TRY') {
            $leg1 = fx_pair_latest_date($pdo, $from, 'TRY');
            $leg2 = fx_pair_latest_date($pdo, $to, 'TRY');
            if ($leg1 !== null && $leg2 !== null) {
                $oldest = min($leg1, $leg2);
                if ($oldest < $staleThreshold) {
                    $stale[$from . '->' . $to] = 'TRY çapraz · en eski bacak ' . $oldest . ' (' . (int) floor((time() - strtotime($oldest)) / 86400) . ' gün önce)';
                }
            }
        }
    }
}

// 4) Geçmiş — her çalıştırmada bugünün sonucunu fx_audit_daily'ye yaz (temiz günler dahil).
$missing = $failed + $preventive;
$histUp = $pdo->prepare(
    "INSERT INTO fx_audit_daily(audit_date,missing_count,stale_count,details)
     VALUES(CURRENT_DATE,?,?,?::jsonb)
     ON CONFLICT(audit_date) DO UPDATE SET
       missing_count=EXCLUDED.missing_count, stale_count=EXCLUDED.stale_count,
       details=EXCLUDED.details, created_at=now()"
);
$histUp->execute([
    count($missing),
    count($stale),
    json_encode(['missing' => $missing, 'stale' => $stale], JSON_UNESCAPED_UNICODE),
]);

if ($missing === [] && $stale === []) {
    echo "Kur denetimi temiz: son 24 saatte eksik kur hatası yok; aktif planlar için gereken tüm çiftler kapsanıyor; 7 günden eski kur yok. (Geçmiş: " . date('Y-m-d') . " → 0 eksik / 0 bayat)\n";
    return ['ok' => true, 'missing' => 0, 'stale' => 0];
}

ksort($missing);
ksort($stale);
echo 'Kur denetimi: ' . count($missing) . ' eksik çift, ' . count($stale) . ' bayat çift (geçmiş: ' . date('Y-m-d') . ")\n";
foreach ($missing as $key => $info) {
    if (is_array($info)) {
        echo '  [EKSİK] ' . $key . ' — kanıt · ' . $info['count'] . ' başarısız işlem (' . date('Y-m-d H:i', $info['first']) . ' → ' . date('Y-m-d H:i', $info['last']) . ")\n";
    } else {
        echo '  [EKSİK] ' . $key . ' — önleyici · ' . $info . "\n";
    }
}
foreach ($stale as $key => $info) {
    echo '  [BAYAT] ' . $key . ' — ' . $info . "\n";
}

// 4b) Bayat kurla yapılan tahmini dönüşüm tutarı — son 7 günde o çiftle dönüştürülen
// toplam tutar ve günlük ortalama (channel_sync_logs.fx_audit JSONB'den). Bayat kur
// kullanılmaya devam ettiği için bu tutar, kur güncellenmezse risk altındaki değeri gösterir.
$fx7d = [];
$fx7dKey = function (string $from, string $to): string {
    return $from . '->' . $to;
};
try {
    $fxRows = $pdo->query(
        "SELECT fx->>'from' AS f, fx->>'to' AS t,
                COALESCE(SUM((fx->>'converted_total')::numeric), 0) AS total,
                COUNT(DISTINCT l.created_at::date) AS days
         FROM channel_sync_logs l, jsonb_array_elements(l.fx_audit) fx
         WHERE l.created_at >= now() - interval '7 days'
         GROUP BY 1, 2"
    )->fetchAll();
    foreach ($fxRows as $fxr) {
        $fx7d[$fx7dKey((string) $fxr['f'], (string) $fxr['t'])] = [
            'total' => (float) $fxr['total'],
            'days' => (int) $fxr['days'],
        ];
    }
} catch (Throwable $e) {
    // fx_audit kolonu/tablo yoksa sessizce geç — e-posta yine gider, tutar sütunu '—' olur.
}

if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    $rowsHtml = '';
    foreach ($missing as $key => $info) {
        if (is_array($info)) {
            $rowsHtml .= '<tr><td style="padding:7px 12px;border:1px solid #e1e5de"><b>' . htmlspecialchars($key) . '</b></td>'
                . '<td style="padding:7px 12px;border:1px solid #e1e5de;color:#b0301a">eksik — kanıt · ' . (int) $info['count'] . ' başarısız işlem</td>'
                . '<td style="padding:7px 12px;border:1px solid #e1e5de">' . date('Y-m-d H:i', $info['first']) . ' → ' . date('Y-m-d H:i', $info['last']) . '</td></tr>';
        } else {
            $rowsHtml .= '<tr><td style="padding:7px 12px;border:1px solid #e1e5de"><b>' . htmlspecialchars($key) . '</b></td>'
                . '<td style="padding:7px 12px;border:1px solid #e1e5de;color:#b0301a">eksik — önleyici · ' . htmlspecialchars($info) . '</td>'
                . '<td style="padding:7px 12px;border:1px solid #e1e5de">bugün</td></tr>';
        }
    }
    foreach ($stale as $key => $info) {
        $fxInfo = $fx7d[$key] ?? null;
        $fxCell = '—';
        if ($fxInfo !== null) {
            $fxCell = number_format($fxInfo['total'], 2, ',', '.') . ' ' . (string) explode('->', $key)[1]
                . ($fxInfo['days'] > 0 ? ' · günlük ort. ' . number_format($fxInfo['total'] / $fxInfo['days'], 2, ',', '.') : '');
        }
        $rowsHtml .= '<tr><td style="padding:7px 12px;border:1px solid #e1e5de"><b>' . htmlspecialchars($key) . '</b></td>'
            . '<td style="padding:7px 12px;border:1px solid #e1e5de;color:#8a6d00">bayat — ' . htmlspecialchars($info) . '</td>'
            . '<td style="padding:7px 12px;border:1px solid #e1e5de">en eski kayıt</td>'
            . '<td style="padding:7px 12px;border:1px solid #e1e5de">' . $fxCell . '</td></tr>';
    }
    $body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
        . '<h2 style="margin:0 0 6px">💱 Kur denetimi: ' . count($missing) . ' eksik · ' . count($stale) . ' bayat çift</h2>'
        . '<p style="color:#64716d;margin:0 0 10px">Webhook fiyat dönüşümünde ihtiyaç duyulan para birimi çiftleri. '
        . '<b style="color:#b0301a">Eksik</b> = fx_rates\'te bulunmayan çift (<b>kanıt</b>: son 24 saatte bu yüzden başarısız işlem — fiyat satırı yazılmadı; <b>önleyici</b>: aktif planlar / görülen gelen birimler nedeniyle bugün gereken); '
        . '<b style="color:#8a6d00">bayat</b> = kur kaydı var ama 7+ gün önce (eski kurla dönüşüm devam ediyor). Bayat çiftteki dönüşüm tutarı, son 7 günde o çiftle dönüştürülen toplam ve günlük ortalamadır — kur güncellenmezse bu değer eski kurla işlenmeye devam eder.</p>'
        . '<table style="border-collapse:collapse;width:100%;max-width:680px;font-size:13px">'
        . '<tr><th style="text-align:left;padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1">Çift</th>'
        . '<th style="text-align:left;padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1">Neden</th>'
        . '<th style="text-align:left;padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1">Son görülme</th>'
        . '<th style="text-align:left;padding:7px 12px;border:1px solid #e1e5de;background:#f4f6f1">Son 7 gün dönüşüm</th></tr>'
        . $rowsHtml
        . '</table>'
        . '<p style="margin-top:18px">Kur eklemek için: <a href="https://nexustraveltech.com/admin/kur-yonetimi" style="color:#b0301a">Kur yönetimi →</a> '
        . '(manuel satır, TCMB bugünkü kuru çekme veya "eksik çiftleri TCMB\'den doldur").</p>'
        . '</div>';
    queue_email($adminEmail, 'Kur denetimi: ' . count($missing) . ' eksik · ' . count($stale) . ' bayat çift', $body, 'fx_missing_audit');
    echo "Admin e-postası kuyruğa eklendi.\n";
} else {
    echo "admin_alert_email tanımsız — e-posta atlanıyor.\n";
}

    return ['ok' => ($missing === [] && $stale === []), 'missing' => count($missing), 'stale' => count($stale)];
}

// CLI girişi (zamanlayıcı: nexus-fx-missing-audit) — yalnızca doğrudan çalıştırılınca.
// admin/kur-yonetimi 'yenile' eylemi bu dosyayı require edip fonksiyonu kendisi çağırır;
// bu blok o durumda atlanır (admin sayfası exit ile kesilmesin).
if (PHP_SAPI === 'cli' && (!isset($_SERVER['argv']) || (string) ($_SERVER['argv'][0] ?? '') === __FILE__ || basename((string) ($_SERVER['argv'][0] ?? '')) === basename(__FILE__))) {
    $pdoCli = db();
    $adminCli = trim((string) platform_setting('admin_alert_email', ''));
    $resCli = audit_fx_missing_run($pdoCli, $adminCli);
    exit($resCli['ok'] ? 0 : 1);
}
