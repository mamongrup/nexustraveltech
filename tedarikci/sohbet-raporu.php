<?php $active_module = 'chat_report'; require_once __DIR__ . '/layout.php'; $u = $supplier_user;
require_once __DIR__ . '/../config/chat_report.php';
require_once __DIR__ . '/../config/pdf.php';
require_once __DIR__ . '/../config/platform_settings.php';

// Haftalık panel özeti tercihi (aç/kapat) — bu sayfadan yönetilir.
if (empty($_SESSION['supplier_csrf'])) $_SESSION['supplier_csrf'] = bin2hex(random_bytes(32));
$digestMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'weekly_digest') {
    if (hash_equals($_SESSION['supplier_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $digest = (array) platform_setting('panel_weekly_digest', []);
        $sid = (string) $u['supplier_id'];
        if (!empty($_POST['enabled'])) {
            $digest['supplier'][$sid] = (string) $u['email'];
        } else {
            unset($digest['supplier'][$sid]);
        }
        save_platform_setting('panel_weekly_digest', $digest);
        $digestMsg = 'Haftalık özet tercihi kaydedildi.';
    }
}
$weeklyEnabled = isset(((array) platform_setting('panel_weekly_digest', []))['supplier'][(string) $u['supplier_id']]);

$ay = trim((string) ($_GET['ay'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $ay)) $ay = date('Y-m');
$d = panel_chat_report_data('supplier', (int) $u['supplier_id'], $ay);
$prev = date('Y-m', strtotime($d['start'] . ' -1 day'));
$next = date('Y-m', strtotime($d['start'] . ' +1 month'));

// CSV dışa aktarma: özet + konu trendi + en çok sorulan 5 soru.
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="panel-sohbet-raporu-' . $ay . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['PANEL SOHBET RAPORU', $d['monthLabel']]);
    fputcsv($out, ['Dönem', $d['start'] . ' – ' . $d['end']]);
    fputcsv($out, ['Toplam mesaj', $d['totalRows']]);
    fputcsv($out, ['Kaliteli mesaj', $d['qualityRows']]);
    fputcsv($out, ['Aktif gün', $d['activeDays']]);
    fputcsv($out, []);
    fputcsv($out, ['KONU TRENDİ']);
    $head = ['Konu'];
    for ($w = 1; $w <= 5; $w++) $head[] = 'Hafta ' . $w;
    $head[] = 'Toplam';
    fputcsv($out, $head);
    foreach ($d['topicTopKeys'] as $t) {
        $row = [$t];
        for ($w = 1; $w <= 5; $w++) $row[] = $d['topicWeek'][$t][$w];
        $row[] = $d['topicTotal'][$t];
        fputcsv($out, $row);
    }
    fputcsv($out, []);
    fputcsv($out, ['EN ÇOK SORULAN 5 SORU']);
    fputcsv($out, ['Soru', 'Tekrar']);
    foreach ($d['topQuestions'] as $row) fputcsv($out, [(string) $row['q'], (int) $row['c']]);
    fclose($out);
    exit;
}

// PDF dışa aktarma (TCPDF yoksa yazdırılabilir HTML indirir).
if (($_GET['export'] ?? '') === 'pdf') {
    pdf_download(panel_chat_report_html($d), 'panel-sohbet-raporu-' . $ay);
}

supply_start('Sohbet raporu', $active_module); ?>
<style>
.rp{display:grid;gap:14px;max-width:980px}
.rp-top{display:flex;gap:10px;align-items:center;flex-wrap:wrap;background:#fff;border:1px solid #e1e5de;padding:12px 14px}
.rp-top a,.rp-top form{color:#10211f;font-size:13px;text-decoration:none;font-weight:600}
.rp-top input{border:1px solid #d8ded8;padding:8px;font:inherit}
.rp-top button{background:#10211f;color:#fff;border:0;padding:8px 12px;cursor:pointer;font:inherit;font-weight:700}
.rp-stats{display:flex;gap:10px;flex-wrap:wrap}
.rp-stat{background:#fff;border:1px solid #e1e5de;padding:12px 16px;min-width:140px}
.rp-stat span{font-size:11px;text-transform:uppercase;color:#64716d;letter-spacing:.5px}
.rp-stat b{display:block;font-size:22px;margin-top:4px}
.rp-panel{background:#fff;border:1px solid #e1e5de;padding:16px}
.rp-panel h2{margin:0 0 10px;font-size:15px}
.rp table{width:100%;border-collapse:collapse}
.rp th,.rp td{text-align:left;border-bottom:1px solid #e1e5de;padding:9px 11px;font-size:13px}
.rp th{font-size:11px;text-transform:uppercase;color:#64716d}
.rp .num{text-align:center}
.rp .muted{color:#64716d;font-size:13px}
@media(max-width:640px){.rp-stats{flex-direction:column}}
</style>
<section class="rp">
  <?php if ($digestMsg): ?><div style="background:#e6f8c7;border:1px solid #cfe8a8;padding:9px 12px;font-size:13px"><?=htmlspecialchars($digestMsg)?></div><?php endif; ?>
  <form method="post" style="background:#fff;border:1px solid #e1e5de;padding:10px 14px;font-size:13px">
    <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['supplier_csrf'])?>">
    <input type="hidden" name="action" value="weekly_digest">
    <label style="display:flex;gap:9px;align-items:center;cursor:pointer"><input type="checkbox" name="enabled" <?= $weeklyEnabled ? 'checked' : '' ?> onchange="this.form.submit()"> Her pazartesi <b>haftalık sohbet özetimi</b> e-postama gönder (<?=htmlspecialchars($u['email'])?>)</label>
  </form>
  <div class="rp-top">
    <a href="/nexustraveltech/tedarikci/">← Panel</a>
    <a href="?ay=<?=htmlspecialchars($prev)?>">← Önceki ay</a>
    <form method="get" action="/nexustraveltech/tedarikci/sohbet-raporu" style="display:flex;gap:6px;align-items:center">
      <input type="month" name="ay" value="<?=htmlspecialchars($ay)?>">
      <button>Göster</button>
    </form>
    <a href="?ay=<?=htmlspecialchars($next)?>">Sonraki ay →</a>
    <a href="?ay=<?=htmlspecialchars($ay)?>&export=csv">⬇ CSV</a>
    <a href="?ay=<?=htmlspecialchars($ay)?>&export=pdf">⬇ PDF</a>
    <b style="margin-left:auto"><?=htmlspecialchars($d['monthLabel'])?></b>
  </div>

  <div class="rp-stats">
    <div class="rp-stat"><span>Toplam mesaj</span><b><?= (int)$d['totalRows'] ?></b></div>
    <div class="rp-stat"><span>Kaliteli mesaj</span><b><?= (int)$d['qualityRows'] ?></b></div>
    <div class="rp-stat"><span>Aktif gün</span><b><?= (int)$d['activeDays'] ?></b></div>
  </div>

  <div class="rp-panel"><h2>Konu bazında haftalık trend</h2>
    <?php if ($d['totalRows'] === 0): ?><p class="muted">Bu ay panel asistanı kullanımı yok. Ekibiniz sağ alttaki NEXUS AI butonuyla soru sordukça bura dolar.</p>
    <?php else: ?>
    <table><tr><th>Konu</th><?php for ($w = 1; $w <= 5; $w++): ?><th class="num">Hafta <?= $w ?></th><?php endfor; ?><th class="num">Toplam</th></tr>
    <?php foreach ($d['topicTopKeys'] as $t): ?>
      <tr><td><?=htmlspecialchars($t)?></td><?php for ($w = 1; $w <= 5; $w++): ?><td class="num"><?= (int)$d['topicWeek'][$t][$w] ?></td><?php endfor; ?><td class="num"><b><?= (int)$d['topicTotal'][$t] ?></b></td></tr>
    <?php endforeach; ?>
    </table><?php endif; ?>
  </div>

  <div class="rp-panel"><h2>En çok sorulan 5 soru</h2>
    <?php if (!$d['topQuestions']): ?><p class="muted">Bu ay kaliteli soru kaydı yok.</p>
    <?php else: ?>
    <table><tr><th>#</th><th>Soru</th><th class="num">Tekrar</th></tr>
    <?php foreach ($d['topQuestions'] as $i => $row): ?>
      <tr><td class="num"><?= $i + 1 ?></td><td><?=htmlspecialchars((string) $row['q'])?></td><td class="num"><?= (int)$row['c'] ?></td></tr>
    <?php endforeach; ?>
    </table><?php endif; ?>
  </div>
</section>
<?php supply_end(); ?>
