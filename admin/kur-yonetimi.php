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
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Döviz kuru yönetimi | NEXUS Admin</title><style>body{margin:0;background:#f7f7f2;color:#10211f;font-family:Arial,sans-serif}.wrap{width:min(980px,calc(100% - 32px));margin:40px auto}.top{display:flex;justify-content:space-between;gap:20px;align-items:center}.brand{font-size:28px;font-weight:800}.brand span{color:#e85f42}.back{color:#10211f}.notice,.error{padding:11px}.notice{background:#e6f8c7}.error{background:#ffe2de}.card{background:#fff;border:1px solid #e1e5de;padding:20px;margin-top:16px}.form{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.form input,.form button{padding:10px;border:1px solid #d8ded8;font:inherit}.form button{background:#10211f;color:#fff;font-weight:700;border:0;cursor:pointer}table{width:100%;border-collapse:collapse;margin-top:10px}th,td{text-align:left;border-bottom:1px solid #e1e5de;padding:9px 10px;font-size:13px}th{font-size:11px;text-transform:uppercase;color:#64716d}.btn-tcmb{background:#0d7a4a;color:#fff;border:0;padding:10px 14px;font-weight:700;cursor:pointer;margin-top:12px}.btn-fill{background:#b26a00;color:#fff;border:0;padding:10px 14px;font-weight:700;cursor:pointer;margin-top:8px;display:inline-block}.tcmb-status{display:block;font-size:12px;margin-top:8px}.tcmb-ok{color:#0d7a4a}.tcmb-err{color:#b0301a;font-weight:700}.quick{display:grid;gap:8px;max-width:440px}.quick-row{display:flex;align-items:center;gap:10px}.quick-row label{min-width:120px;font-size:13px;font-weight:700}.quick-row input{flex:1;padding:9px;border:1px solid #d8ded8;font:inherit}.quick button{padding:10px;background:#10211f;color:#fff;font-weight:700;border:0;cursor:pointer;justify-self:start}.fx-hist{display:flex;align-items:flex-end;gap:3px;height:90px;margin-top:12px;overflow-x:auto}.fx-hist-col{display:flex;flex-direction:column;align-items:center;justify-content:flex-end;min-width:14px;flex:1}.fx-hist-bar{width:10px;border-radius:2px 2px 0 0}.fx-hist-bar.miss{background:#b0301a}.fx-hist-bar.stale{background:#e0a800}.fx-hist-day{font-size:9px;color:#64716d;margin-top:3px;white-space:nowrap}.fx-hist-wrap{display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%}.fx-legend{font-size:12px;color:#64716d;margin-top:6px}.report-link{display:inline-block;margin-top:10px;color:#0d7a4a;font-weight:700;text-decoration:none}.report-link:hover{text-decoration:underline}@media(max-width:700px){.form{grid-template-columns:1fr 1fr}}</style></head><body><main class="wrap"><div class="top"><div><div class="brand">N<span>∿</span>XUS Admin</div><p>Döviz kuru tablosu — EUR/TRY/USD dönüşümleri buradan beslenir</p></div><div style="text-align:right"><a class="back" href="/nexustraveltech/admin/">← Panele dön</a><br><a class="report-link" href="/nexustraveltech/admin/fx-rapor.php">📊 Aylık dönüşüm raporu →</a></div></div>
<?php if ($message): ?><p class="notice"><?=htmlspecialchars($message)?></p><?php endif; ?>
<?php if ($error): ?><p class="error"><?=htmlspecialchars($error)?></p><?php endif; ?>
<section class="card"><h2 style="margin:0 0 12px;font-size:18px">Manuel kur ekle</h2>
<form method="post" class="form"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="action" value="save">
<input name="base_currency" placeholder="EUR" maxlength="3" required><input name="quote_currency" placeholder="TRY" maxlength="3" required><input name="rate" type="number" step="0.000001" min="0.000001" placeholder="Kur" required><input name="rate_date" type="date" value="<?=date('Y-m-d')?>" required>
<button style="grid-column:1/-1">Kur kaydet</button></form>
</section>
<section class="card"><h2 style="margin:0 0 6px;font-size:18px">TCMB bugünkü kuru</h2><p style="color:#64716d;margin:0 0 12px;font-size:13px">USD / EUR / GBP / CHF ↔ TRY pariteleri resmî TCMB XML'inden çekilir. <b>Eksik çiftleri doldur</b>, günlük denetimin bildirdiği (aktif planlar + görülen gelen birimler arasındaki) eksik çiftleri TCMB kuru üzerinden çapraz hesaplayıp otomatik ekler.</p>
<form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="action" value="tcmb"><button class="btn-tcmb" type="submit">TCMB bugünkü kurları çek</button></form>
<form method="post" style="display:inline;margin-left:8px"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="action" value="fill_missing"><button class="btn-fill" type="submit">⚡ Eksik çiftleri TCMB'den doldur</button></form>
<?php if ($tcmbOk !== null): ?><span class="tcmb-status tcmb-ok">✅ Son başarılı çekme: <?=htmlspecialchars(date('d.m.Y H:i', strtotime((string) $tcmbOk)))?> · kaynak: TCMB<?= $tcmbLastDate ? ' (kur tarihi ' . htmlspecialchars((string) $tcmbLastDate) . ')' : '' ?></span><?php endif; ?>
<?php if ($tcmbBad): ?><span class="tcmb-status tcmb-err">⚠ Son çekme başarısız (<?=htmlspecialchars(date('d.m.Y H:i', strtotime((string) $tcmbFail)))?>): <?=htmlspecialchars($tcmbErr !== '' ? $tcmbErr : 'bilinmeyen hata')?></span><?php endif; ?>
<?php if ($tcmbOk === null && $tcmbFail === null): ?><span class="tcmb-status" style="color:#64716d">Henüz TCMB çekmesi yapılmadı — ilk çekme sonrası burada durum görünür.</span><?php endif; ?>
</section>
<section class="card"><h2 style="margin:0 0 6px;font-size:18px">Hızlı giriş — EUR / USD / GBP → TRY</h2><p style="color:#64716d;margin:0 0 12px;font-size:13px">TCMB dışında günlük kurları elle girmek için opsiyonel satırlar; her satır <b>TRY → ters kuru</b> da otomatik yazar (çapraz kur hesaplarında kullanılır). Boş bırakılan satır kaydedilmez. <b>Kutular son kaydedilen değerlerle önceden doludur</b> — bugünün kuru aynıysa olduğu gibi "Hızlı kurları kaydet" ile güncelleyebilirsiniz.</p>
<form method="post" class="quick"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="action" value="quick"><input type="hidden" name="rate_date" value="<?=date('Y-m-d')?>">
<div class="quick-row"><label>EUR → TRY</label><input name="quick_eur" type="number" step="0.000001" min="0.000001" placeholder="Örn. 38.50" value="<?=htmlspecialchars($quickPrefill['EUR'] ?? '')?>"></div>
<div class="quick-row"><label>USD → TRY</label><input name="quick_usd" type="number" step="0.000001" min="0.000001" placeholder="Örn. 33.20" value="<?=htmlspecialchars($quickPrefill['USD'] ?? '')?>"></div>
<div class="quick-row"><label>GBP → TRY</label><input name="quick_gbp" type="number" step="0.000001" min="0.000001" placeholder="Örn. 42.80" value="<?=htmlspecialchars($quickPrefill['GBP'] ?? '')?>"></div>
<button>Hızlı kurları kaydet</button></form>
</section>
<section class="card"><h2 style="margin:0;font-size:18px">Güncel kurlar (<?=htmlspecialchars((string) ($latest[0]['rate_date'] ?? date('Y-m-d')))?>)</h2>
<table><tr><th>Çift</th><th>Kur</th><th>Kaynak</th></tr><?php foreach ($latest as $l): ?><tr><td><?=htmlspecialchars($l['base_currency'])?> → <?=htmlspecialchars($l['quote_currency'])?></td><td><?=htmlspecialchars(rtrim(rtrim(number_format((float) $l['rate'], 6, '.', ''), '0'), '.'))?></td><td><?=htmlspecialchars($l['source'])?></td></tr><?php endforeach; ?></table></section>
<section class="card"><h2 style="margin:0;font-size:18px">Denetim geçmişi — günlük eksik/bayat kur</h2><p style="color:#64716d;margin:4px 0 0;font-size:13px">cron/audit-fx-missing.php her gün çalıştığında sonucu fx_audit_daily'ye yazar (temiz günler dahil). Kırmızı = eksik çift, kehribar = 7+ gün eski kur. Çubuğa gelince o günün detayını görürsünüz.</p>
<?php if ($history): ?><div class="fx-hist"><?php foreach (array_reverse($history) as $h): ?><div class="fx-hist-col" title="<?=htmlspecialchars((string) $h['audit_date'])?>: <?=(int) $h['missing_count']?> eksik · <?=(int) $h['stale_count']?> bayat"><div class="fx-hist-wrap"><?php if ((int) $h['missing_count'] > 0): ?><div class="fx-hist-bar miss" style="height:<?=max(3, (int) round(((int) $h['missing_count'] / $histMax) * 60))?>px"></div><?php endif; ?><?php if ((int) $h['stale_count'] > 0): ?><div class="fx-hist-bar stale" style="height:<?=max(3, (int) round(((int) $h['stale_count'] / $histMax) * 60))?>px"></div><?php endif; ?><?php if ((int) $h['missing_count'] === 0 && (int) $h['stale_count'] === 0): ?><div class="fx-hist-bar miss" style="height:2px;background:#d8ded8"></div><?php endif; ?></div><span class="fx-hist-day"><?=htmlspecialchars(substr((string) $h['audit_date'], 8, 2))?>.<?=htmlspecialchars(substr((string) $h['audit_date'], 5, 2))?></span></div><?php endforeach; ?></div>
<div class="fx-legend">■ eksik · ■ bayat · 0/0 günler gri nokta</div>
<table><tr><th>Tarih</th><th>Eksik</th><th>Bayat</th><th>Detay</th></tr><?php foreach ($history as $h): $det = json_decode((string) ($h['details'] ?? '{}'), true); $det = is_array($det) ? $det : []; ?><tr><td><?=htmlspecialchars((string) $h['audit_date'])?></td><td><?=(int) $h['missing_count']?></td><td><?=(int) $h['stale_count']?></td><td style="font-size:12px;color:#64716d"><?php $items = array_merge(array_keys((array) ($det['missing'] ?? [])), array_map(fn($k) => $k . ' (bayat)', array_keys((array) ($det['stale'] ?? [])))); echo $items ? htmlspecialchars(implode(', ', $items)) : 'temiz'; ?></td></tr><?php endforeach; ?></table>
<?php else: ?><p style="color:#64716d">Henüz denetim kaydı yok — günlük görev ilk çalıştığında burada görünür.</p><?php endif; ?></section>
<section class="card"><h2 style="margin:0;font-size:18px">Son kayıtlar</h2>
<table><tr><th>Tarih</th><th>Çift</th><th>Kur</th><th>Kaynak</th></tr><?php foreach ($recent as $l): ?><tr><td><?=htmlspecialchars((string) $l['rate_date'])?></td><td><?=htmlspecialchars($l['base_currency'])?> → <?=htmlspecialchars($l['quote_currency'])?></td><td><?=htmlspecialchars((string) $l['rate'])?></td><td><?=htmlspecialchars($l['source'])?></td></tr><?php endforeach; ?></table></section>
</main><?php require_once __DIR__ . '/../config/ai_widget.php'; ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); ?></body></html>
