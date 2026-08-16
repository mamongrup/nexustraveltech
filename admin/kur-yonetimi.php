<?php

declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/fx.php';

require_admin();
if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));

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
                db()->prepare("INSERT INTO fx_rates(base_currency,quote_currency,rate,rate_date,source) VALUES(?,?,?,?,'manual') ON CONFLICT(base_currency,quote_currency,rate_date) DO UPDATE SET rate=EXCLUDED.rate")
                    ->execute([$base, $quote, round($rate, 6), $date]);
                $message = 'Kur kaydedildi: 1 ' . $base . ' = ' . number_format($rate, 4) . ' ' . $quote . ' (' . $date . ').';
            }
        }
        if ($action === 'tcmb') {
            try {
                $rows = fx_fetch_tcmb_today();
                $up = db()->prepare("INSERT INTO fx_rates(base_currency,quote_currency,rate,rate_date,source) VALUES(?,?,?,CURRENT_DATE,'tcmb') ON CONFLICT(base_currency,quote_currency,rate_date) DO UPDATE SET rate=EXCLUDED.rate");
                foreach ($rows as $row) {
                    $up->execute([$row['base'], $row['quote'], $row['rate']]);
                }
                $message = 'TCMB bugünkü kurları çekildi (' . count($rows) . ' parite kaydedildi).';
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$latest = db()->query("SELECT * FROM fx_rates WHERE rate_date=(SELECT MAX(rate_date) FROM fx_rates) ORDER BY base_currency,quote_currency")->fetchAll();
$recent = db()->query('SELECT * FROM fx_rates ORDER BY rate_date DESC,base_currency,quote_currency LIMIT 30')->fetchAll();
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Döviz kuru yönetimi | NEXUS Admin</title><style>body{margin:0;background:#f7f7f2;color:#10211f;font-family:Arial,sans-serif}.wrap{width:min(980px,calc(100% - 32px));margin:40px auto}.top{display:flex;justify-content:space-between;gap:20px;align-items:center}.brand{font-size:28px;font-weight:800}.brand span{color:#e85f42}.back{color:#10211f}.notice,.error{padding:11px}.notice{background:#e6f8c7}.error{background:#ffe2de}.card{background:#fff;border:1px solid #e1e5de;padding:20px;margin-top:16px}.form{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.form input,.form button{padding:10px;border:1px solid #d8ded8;font:inherit}.form button{background:#10211f;color:#fff;font-weight:700;border:0;cursor:pointer}table{width:100%;border-collapse:collapse;margin-top:10px}th,td{text-align:left;border-bottom:1px solid #e1e5de;padding:9px 10px;font-size:13px}th{font-size:11px;text-transform:uppercase;color:#64716d}.btn-tcmb{background:#0d7a4a;color:#fff;border:0;padding:10px 14px;font-weight:700;cursor:pointer;margin-top:12px}@media(max-width:700px){.form{grid-template-columns:1fr 1fr}}</style></head><body><main class="wrap"><div class="top"><div><div class="brand">N<span>∿</span>XUS Admin</div><p>Döviz kuru tablosu — EUR/TRY/USD dönüşümleri buradan beslenir</p></div><a class="back" href="/nexustraveltech/admin/">← Panele dön</a></div>
<?php if ($message): ?><p class="notice"><?=htmlspecialchars($message)?></p><?php endif; ?>
<?php if ($error): ?><p class="error"><?=htmlspecialchars($error)?></p><?php endif; ?>
<section class="card"><h2 style="margin:0 0 12px;font-size:18px">Manuel kur ekle</h2>
<form method="post" class="form"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="action" value="save">
<input name="base_currency" placeholder="EUR" maxlength="3" required><input name="quote_currency" placeholder="TRY" maxlength="3" required><input name="rate" type="number" step="0.000001" min="0.000001" placeholder="Kur" required><input name="rate_date" type="date" value="<?=date('Y-m-d')?>" required>
<button style="grid-column:1/-1">Kur kaydet</button></form>
<form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="action" value="tcmb"><button class="btn-tcmb" type="submit">TCMB bugünkü kurları çek (USD/EUR/GBP/CHF ↔ TRY)</button></form>
</section>
<section class="card"><h2 style="margin:0;font-size:18px">Güncel kurlar (<?=htmlspecialchars((string)($latest[0]['rate_date'] ?? date('Y-m-d')))?>)</h2>
<table><tr><th>Çift</th><th>Kur</th><th>Kaynak</th></tr><?php foreach ($latest as $l): ?><tr><td><?=htmlspecialchars($l['base_currency'])?> → <?=htmlspecialchars($l['quote_currency'])?></td><td><?=htmlspecialchars(rtrim(rtrim(number_format((float)$l['rate'], 6, '.', ''), '0'), '.'))?></td><td><?=htmlspecialchars($l['source'])?></td></tr><?php endforeach; ?></table></section>
<section class="card"><h2 style="margin:0;font-size:18px">Son kayıtlar</h2>
<table><tr><th>Tarih</th><th>Çift</th><th>Kur</th><th>Kaynak</th></tr><?php foreach ($recent as $l): ?><tr><td><?=htmlspecialchars((string)$l['rate_date'])?></td><td><?=htmlspecialchars($l['base_currency'])?> → <?=htmlspecialchars($l['quote_currency'])?></td><td><?=htmlspecialchars((string)$l['rate'])?></td><td><?=htmlspecialchars($l['source'])?></td></tr><?php endforeach; ?></table></section>
</main></body></html>
