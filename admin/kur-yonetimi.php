<?php

declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/fx.php';
require_once __DIR__ . '/../config/platform_settings.php';

require_admin();
if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));

$pdo = db();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['admin_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Güvenlik doğrulaması geçersiz.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save') {
            $base = mb_strtoupper(trim((string) ($_POST['base_currency'] ?? '')));
            $quote = mb_strtoupper(trim((string) ($_POST['quote_currency'] ?? '')));
            $rate = (float) str_replace(',', '.', (string) ($_POST['rate'] ?? 0));
            $date = (string) ($_POST['rate_date'] ?? date('Y-m-d'));
            if (!preg_match('/^[A-Z]{3}$/', $base) || !preg_match('/^[A-Z]{3}$/', $quote) || $rate <= 0 || $base === $quote) {
                $error = 'Geçerli para birimi çifti ve pozitif kur girin.';
            } else {
                $pdo->prepare("INSERT INTO fx_rates(base_currency,quote_currency,rate,rate_date,source) VALUES(?,?,?,?,'manual') ON CONFLICT(base_currency,quote_currency,rate_date) DO UPDATE SET rate=EXCLUDED.rate")
                    ->execute([$base, $quote, round($rate, 6), $date]);
                $message = 'Kur kaydedildi: 1 ' . $base . ' = ' . number_format($rate, 4) . ' ' . $quote . ' (' . $date . ').';
            }
        }
        if ($action === 'quick') {
            $date = (string) ($_POST['rate_date'] ?? date('Y-m-d'));
            $saved = 0;
            $up = $pdo->prepare("INSERT INTO fx_rates(base_currency,quote_currency,rate,rate_date,source) VALUES(?,?,?,?,'manual') ON CONFLICT(base_currency,quote_currency,rate_date) DO UPDATE SET rate=EXCLUDED.rate");
            foreach (['EUR', 'USD', 'GBP'] as $base) {
                $raw = trim((string) ($_POST['quick_' . strtolower($base)] ?? ''));
                if ($raw === '') {
                    continue;
                }
                $rate = (float) str_replace(',', '.', $raw);
                if ($rate <= 0) {
                    $error = $base . ' → TRY kuru geçerli değil.';
                    continue;
                }
                $up->execute([$base, 'TRY', round($rate, 6), $date]);
                $up->execute(['TRY', $base, round(1 / $rate, 6), $date]); // ters parite de yazılır
                $saved++;
            }
            if ($saved > 0 && $error === '') {
                $message = $saved . ' hızlı kur kaydedildi (' . $date . ').';
            } elseif ($saved === 0 && $error === '') {
                $error = 'Kaydedilecek kur girilmedi (en az bir satır doldurun).';
            }
        }
        if ($action === 'refresh_pair') {
            // Bayat çift kurunu TCMB'den güncelle (doğrudan veya TRY çaprazı) ve yeniden denetim çalıştır.
            $pair = strtoupper(trim((string) ($_POST['pair'] ?? '')));
            if (!preg_match('/^[A-Z]{3}->[A-Z]{3}$/', $pair)) {
                $error = 'Geçersiz çift: ' . htmlspecialchars($pair);
            } else {
                [$from, $to] = explode('->', $pair);
                try {
                    $rows = fx_fetch_tcmb_today();
                    $tcmbMap = [];
                    foreach ($rows as $tr) {
                        $tcmbMap[$tr['base'] . '->' . $tr['quote']] = (float) $tr['rate'];
                    }
                    $rate = 0.0;
                    if (isset($tcmbMap[$pair])) {
                        $rate = $tcmbMap[$pair];
                    } elseif (isset($tcmbMap[$from . '->TRY']) && isset($tcmbMap[$to . '->TRY'])) {
                        $rate = round($tcmbMap[$from . '->TRY'] / $tcmbMap[$to . '->TRY'], 6);
                    }
                    if ($rate <= 0) {
                        $error = $pair . ' için TCMB bugünkü kur hesaplanamadı (yalnızca USD/EUR/GBP/CHF ↔ TRY arası hesaplanabilir).';
                    } else {
                        $pdo->prepare("INSERT INTO fx_rates(base_currency,quote_currency,rate,rate_date,source) VALUES(?,?,?,CURRENT_DATE,'tcmb') ON CONFLICT(base_currency,quote_currency,rate_date) DO UPDATE SET rate=EXCLUDED.rate")
                            ->execute([$from, $to, $rate]);
                        save_platform_setting('fx_tcmb_last_ok', date('Y-m-d H:i:s'));
                        // Yeniden denetim — güncel sonuç fx_audit_daily'ye yazılır; bayat liste güncellenir.
                        require_once __DIR__ . '/../cron/audit-fx-missing.php';
                        $audit = audit_fx_missing_run($pdo, trim((string) platform_setting('admin_alert_email', '')));
                        $message = $pair . ' kuru TCMB\'den güncellendi (' . number_format($rate, 4) . ') ve denetim yeniden çalıştırıldı — ' . (int) $audit['missing'] . ' eksik · ' . (int) $audit['stale'] . ' bayat.';
                    }
                } catch (Throwable $e) {
                    save_platform_setting('fx_tcmb_last_fail', date('Y-m-d H:i:s'));
                    save_platform_setting('fx_tcmb_last_error', $e->getMessage());
                    $error = $e->getMessage();
                }
            }
        }
        if ($action === 'tcmb' || $action === 'fill_missing') {
            try {
                $rows = fx_fetch_tcmb_today();
                if ($action === 'tcmb') {
                    $up = $pdo->prepare("INSERT INTO fx_rates(base_currency,quote_currency,rate,rate_date,source) VALUES(?,?,?,CURRENT_DATE,'tcmb') ON CONFLICT(base_currency,quote_currency,rate_date) DO UPDATE SET rate=EXCLUDED.rate");
                    foreach ($rows as $row) {
                        $up->execute([$row['base'], $row['quote'], $row['rate']]);
                    }
                    $message = 'TCMB bugünkü kurları çekildi (' . count($rows) . ' parite kaydedildi).';
                } else {
                    // Eksik çiftleri TCMB bugünkü kuru üzerinden (TRY çaprazı dahil) doldur.
                    $tcmbMap = [];
                    foreach ($rows as $tr) {
                        $tcmbMap[$tr['base'] . '->' . $tr['quote']] = (float) $tr['rate'];
                    }
                    // Gerekli çiftler — günlük denetim göreviyle aynı mantık.
                    $tg = $pdo->query("SELECT DISTINCT UPPER(currency) AS c FROM rate_plans WHERE status='active' AND currency IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
                    $tg = array_values(array_filter($tg, fn($c) => preg_match('/^[A-Z]{3}$/', (string) $c)));
                    $inc = [];
                    $dflt = strtoupper((string) platform_setting('channel_webhook_default_currency', 'EUR'));
                    if (preg_match('/^[A-Z]{3}$/', $dflt)) $inc[$dflt] = true;
                    $pr = $pdo->query("SELECT request_payload FROM channel_sync_logs WHERE direction='pull' AND request_payload IS NOT NULL AND created_at >= now() - interval '30 days'")->fetchAll();
                    foreach ($pr as $cr) {
                        $dec = json_decode((string) $cr['request_payload'], true);
                        if (!is_array($dec)) continue;
                        foreach ((array) ($dec['entries'] ?? []) as $en) {
                            if (is_array($en) && isset($en['currency']) && is_string($en['currency'])) {
                                $c = strtoupper($en['currency']);
                                if (preg_match('/^[A-Z]{3}$/', $c)) $inc[$c] = true;
                            }
                        }
                    }
                    $missing = [];
                    foreach (array_keys($inc) as $from) {
                        foreach ($tg as $to) {
                            if ($from === $to) continue;
                            if (fx_rate($from, $to) > 0) continue;
                            $missing[$from . '->' . $to] = true;
                        }
                    }
                    if (!$missing) {
                        $message = 'Eksik çift yok — gerekli tüm çiftler kapsanıyor.';
                    } else {
                        $computed = [];
                        foreach (array_keys($missing) as $pair) {
                            [$from, $to] = explode('->', $pair);
                            $rate = 0.0;
                            if (isset($tcmbMap[$from . '->' . $to])) {
                                $rate = $tcmbMap[$from . '->' . $to];
                            } elseif (isset($tcmbMap[$from . '->TRY']) && isset($tcmbMap[$to . '->TRY'])) {
                                $rate = round($tcmbMap[$from . '->TRY'] / $tcmbMap[$to . '->TRY'], 6);
                            }
                            if ($rate > 0) $computed[$pair] = $rate;
                        }
                        $up = $pdo->prepare("INSERT INTO fx_rates(base_currency,quote_currency,rate,rate_date,source) VALUES(?,?,?,CURRENT_DATE,'tcmb') ON CONFLICT(base_currency,quote_currency,rate_date) DO UPDATE SET rate=EXCLUDED.rate");
                        $filled = 0;
                        foreach ($computed as $pair => $rate) {
                            [$from, $to] = explode('->', $pair);
                            $up->execute([$from, $to, $rate]);
                            $filled++;
                        }
                        $unfilled = array_values(array_diff(array_keys($missing), array_keys($computed)));
                        if ($filled > 0) {
                            $message = $filled . ' eksik çift TCMB bugünkü kurundan dolduruldu: ' . implode(', ', array_keys($computed))
                                . ($unfilled ? ' — TCMB pariteleriyle hesaplanamayan: ' . implode(', ', $unfilled) : '');
                        } else {
                            $error = 'Eksik çiftler TCMB pariteleriyle hesaplanamadı: ' . implode(', ', array_keys($missing)) . ' (yalnızca USD/EUR/GBP/CHF ↔ TRY arası hesaplanabilir).';
                        }
                    }
                }
                // Çekme durumu — başarılı sayılır (veri alındı ve işlendi).
                save_platform_setting('fx_tcmb_last_ok', date('Y-m-d H:i:s'));
                save_platform_setting('fx_tcmb_last_fail', null);
                save_platform_setting('fx_tcmb_last_error', '');
            } catch (Throwable $e) {
                save_platform_setting('fx_tcmb_last_fail', date('Y-m-d H:i:s'));
                save_platform_setting('fx_tcmb_last_error', $e->getMessage());
                $error = $e->getMessage();
            }
        }
    }
}

$latest = $pdo->query("SELECT * FROM fx_rates WHERE rate_date=(SELECT MAX(rate_date) FROM fx_rates) ORDER BY base_currency,quote_currency")->fetchAll();
$recent = $pdo->query('SELECT * FROM fx_rates ORDER BY rate_date DESC,base_currency,quote_currency LIMIT 30')->fetchAll();
// Hızlı giriş ön dolumu — son kaydedilen EUR/USD/GBP → TRY değerleri.
$quickPrefill = [];
foreach (['EUR', 'USD', 'GBP'] as $qb) {
    $q = $pdo->prepare("SELECT rate FROM fx_rates WHERE base_currency=? AND quote_currency='TRY' ORDER BY rate_date DESC,id DESC LIMIT 1");
    $q->execute([$qb]);
    $v = $q->fetchColumn();
    $quickPrefill[$qb] = ($v !== false && $v !== null) ? rtrim(rtrim(number_format((float) $v, 6, '.', ''), '0'), '.') : '';
}
// TCMB çekme durumu — son başarılı/başarısız deneme + kaynak.
$tcmbOk = platform_setting('fx_tcmb_last_ok', null);
$tcmbFail = platform_setting('fx_tcmb_last_fail', null);
$tcmbErr = (string) platform_setting('fx_tcmb_last_error', '');
$tcmbLastDate = $pdo->query("SELECT MAX(rate_date) FROM fx_rates WHERE source='tcmb'")->fetchColumn();
$tcmbBad = $tcmbFail !== null && ($tcmbOk === null || strtotime((string) $tcmbFail) > strtotime((string) $tcmbOk));
// Denetim geçmişi — günlük eksik/bayat özeti (zaman çizelgesi).
$history = $pdo->query("SELECT audit_date, missing_count, stale_count, details FROM fx_audit_daily ORDER BY audit_date DESC LIMIT 30")->fetchAll();
$histMax = 1;
foreach ($history as $h) $histMax = max($histMax, (int) $h['missing_count'], (int) $h['stale_count']);
// Zaman çizelgesi e-posta durumu — o gün (UTC'ye göre) fx_missing_audit e-postası kuyruğa
// alınmış mı? 0 eksik/0 bayat günlerde e-posta GİTMEZ (betik erken döner) — o günler boş kalır.
$histEmailDays = [];
try {
    $heRows = $pdo->query("SELECT DISTINCT (created_at AT TIME ZONE 'UTC')::date AS d FROM email_outbox WHERE related_type='fx_missing_audit' AND created_at >= now() - interval '60 days'")->fetchAll();
    foreach ($heRows as $he) $histEmailDays[(string) $he['d']] = true;
} catch (Throwable $e) {
    $histEmailDays = [];
}
// Son denetim bulguları — fx_missing_audit'in en güncel çalıştırma sonucu.
$lastAudit = null;
$lastAuditMissing = [];
$lastAuditStale = [];
$lastAuditRow = $pdo->query("SELECT audit_date, missing_count, stale_count, details FROM fx_audit_daily ORDER BY audit_date DESC LIMIT 1")->fetch();
if ($lastAuditRow) {
    $det = json_decode((string) ($lastAuditRow['details'] ?? '{}'), true);
    $det = is_array($det) ? $det : [];
    $lastAuditMissing = (array) ($det['missing'] ?? []);
    $lastAuditStale = (array) ($det['stale'] ?? []);
    $lastAudit = $lastAuditRow;
}
$lastAuditEmail = $pdo->query("SELECT id, subject, created_at FROM email_outbox WHERE related_type='fx_missing_audit' ORDER BY id DESC LIMIT 1")->fetch();
// E-posta geçmişi — her çift için, o çifti içeren SON fx_missing_audit e-postası.
// ?view_fx_email=ID ile aynı sayfada gövde gösterilir (body_html LIKE ile çift eşleşmesi).
$fxEmails = $pdo->query("SELECT id, subject, created_at, body_html FROM email_outbox WHERE related_type='fx_missing_audit' ORDER BY id DESC LIMIT 30")->fetchAll();
$pairEmail = [];
foreach ($fxEmails as $fe) {
    foreach (array_merge(array_keys($lastAuditMissing), array_keys($lastAuditStale)) as $pp) {
        if (isset($pairEmail[$pp])) continue;
        if (str_contains((string) $fe['body_html'], (string) $pp)) {
            $pairEmail[$pp] = (int) $fe['id'];
        }
    }
}
$viewFxEmail = null;
if (isset($_GET['view_fx_email'])) {
    $veq = $pdo->prepare("SELECT id, subject, created_at, body_html FROM email_outbox WHERE id=? AND related_type='fx_missing_audit'");
    $veq->execute([(int) $_GET['view_fx_email']]);
    $viewFxEmail = $veq->fetch();
}
?>
<?php
require_once __DIR__ . '/layout.php';
admin_layout_start('Döviz Kuru ve Çapraz Kur Yönetimi', 'kur-yonetimi');
?>

<?php if ($message): ?><div class="sui-alert sui-alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="sui-alert sui-alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- TCMB ve Aksiyon Kartı -->
<div class="sui-card" style="margin-bottom:24px">
    <div class="sui-card-header">
        <div>
            <h2 class="sui-card-title">🏦 TCMB Canlı Döviz Kurları</h2>
            <p style="color:var(--sui-muted);font-size:13px;margin:4px 0 0 0">
                USD / EUR / GBP / CHF ↔ TRY pariteleri resmî TCMB XML servisinden çekilir.
            </p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <form method="post" style="margin:0">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                <input type="hidden" name="action" value="tcmb">
                <button class="sui-btn sui-btn-success sui-btn-sm" type="submit">🔄 TCMB Kurlarını Çek</button>
            </form>
            <form method="post" style="margin:0">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                <input type="hidden" name="action" value="fill_missing">
                <button class="sui-btn sui-btn-primary sui-btn-sm" type="submit">⚡ Eksik Çiftleri TCMB'den Doldur</button>
            </form>
        </div>
    </div>
    
    <?php if ($tcmbOk !== null): ?>
        <p style="font-size:12px;color:var(--sui-success);margin:0">
            ✓ Son başarılı çekme: <b><?= htmlspecialchars(date('d.m.Y H:i', strtotime((string) $tcmbOk))) ?></b>
            <?= $tcmbLastDate ? ' (Kur Tarihi: ' . htmlspecialchars((string) $tcmbLastDate) . ')' : '' ?>
        </p>
    <?php endif; ?>
    <?php if ($tcmbBad): ?>
        <p style="font-size:12px;color:var(--sui-danger);margin:4px 0 0 0">
            ⚠ Son çekme başarısız (<?= htmlspecialchars(date('d.m.Y H:i', strtotime((string) $tcmbFail))) ?>): <?= htmlspecialchars($tcmbErr ?: 'bilinmeyen hata') ?>
        </p>
    <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(320px, 1fr));gap:20px;margin-bottom:24px">
    <!-- Manuel Kur Girişi -->
    <div class="sui-card">
        <div class="sui-card-header">
            <h2 class="sui-card-title">➕ Manuel Kur Ekle</h2>
        </div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
            <input type="hidden" name="action" value="save">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
                <div>
                    <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Kaynak (Base)</label>
                    <input name="base_currency" class="sui-input" placeholder="EUR" maxlength="3" required>
                </div>
                <div>
                    <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Hedef (Quote)</label>
                    <input name="quote_currency" class="sui-input" placeholder="TRY" maxlength="3" required>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
                <div>
                    <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Kur Değeri</label>
                    <input name="rate" type="number" step="0.000001" min="0.000001" class="sui-input" placeholder="38.500000" required>
                </div>
                <div>
                    <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Kur Tarihi</label>
                    <input name="rate_date" type="date" value="<?= date('Y-m-d') ?>" class="sui-input" required>
                </div>
            </div>

            <button class="sui-btn sui-btn-primary" style="width:100%">Kuru Kaydet</button>
        </form>
    </div>

    <!-- Hızlı Giriş -->
    <div class="sui-card">
        <div class="sui-card-header">
            <h2 class="sui-card-title">⚡ Hızlı Kur Girişi (TRY)</h2>
        </div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
            <input type="hidden" name="action" value="quick">
            <input type="hidden" name="rate_date" value="<?= date('Y-m-d') ?>">

            <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:10px;margin-bottom:14px">
                <div>
                    <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">EUR → TRY</label>
                    <input name="quick_eur" type="number" step="0.000001" min="0.000001" class="sui-input" placeholder="38.50" value="<?= htmlspecialchars($quickPrefill['EUR'] ?? '') ?>">
                </div>
                <div>
                    <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">USD → TRY</label>
                    <input name="quick_usd" type="number" step="0.000001" min="0.000001" class="sui-input" placeholder="33.20" value="<?= htmlspecialchars($quickPrefill['USD'] ?? '') ?>">
                </div>
                <div>
                    <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">GBP → TRY</label>
                    <input name="quick_gbp" type="number" step="0.000001" min="0.000001" class="sui-input" placeholder="42.80" value="<?= htmlspecialchars($quickPrefill['GBP'] ?? '') ?>">
                </div>
            </div>

            <button class="sui-btn sui-btn-outline" style="width:100%">Hızlı Kurları Kaydet</button>
        </form>
    </div>
</div>

<!-- Güncel Kurlar -->
<div class="sui-card">
    <div class="sui-card-header">
        <h2 class="sui-card-title">📈 Güncel Kurlar (<?= htmlspecialchars((string) ($latest[0]['rate_date'] ?? date('Y-m-d'))) ?>)</h2>
        <a href="fx-rapor.php" class="sui-btn sui-btn-outline sui-btn-sm">📊 Aylık Dönüşüm Raporu →</a>
    </div>

    <div style="overflow-x:auto">
        <table class="sui-table">
            <thead>
                <tr>
                    <th>Döviz Çifti</th>
                    <th>Kur Değeri</th>
                    <th>Kaynak</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($latest as $l): ?>
                    <tr>
                        <td><b><?= htmlspecialchars($l['base_currency']) ?> → <?= htmlspecialchars($l['quote_currency']) ?></b></td>
                        <td style="font-size:14px;font-weight:700"><?= htmlspecialchars(rtrim(rtrim(number_format((float) $l['rate'], 6, '.', ''), '0'), '.')) ?></td>
                        <td><span class="sui-badge <?= $l['source'] === 'tcmb' ? 'sui-badge-info' : 'sui-badge-warning' ?>"><?= htmlspecialchars($l['source']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>

