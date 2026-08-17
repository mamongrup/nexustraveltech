<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/feature_lists.php';
require_admin();
if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
$msg = '';
$err = '';
$pendingDelete = null;
$deletedAudit = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['admin_csrf'], (string) ($_POST['csrf'] ?? ''))) {
    $err = 'Güvenlik doğrulaması geçersiz.';
  } else {
    try {
      $action = $_POST['action'] ?? '';
      if ($action === 'add') {
        $code = $_POST['code'] ?? '';
        $label = trim((string) ($_POST['label'] ?? ''));
        if (!in_array($code, ['villa', 'yacht'], true) || $label === '' || mb_strlen($label) > 120) throw new RuntimeException('Tür ve özellik adı gereklidir (en fazla 120 karakter).');
        $check = db()->prepare('SELECT id FROM property_feature_catalog WHERE code=? AND label=?');
        $check->execute([$code, $label]);
        if ($check->fetch()) throw new RuntimeException('Bu özellik zaten listede.');
        $max = db()->prepare('SELECT COALESCE(MAX(sort_order),0)+10 FROM property_feature_catalog WHERE code=?');
        $max->execute([$code]);
        db()->prepare('INSERT INTO property_feature_catalog (code,label,sort_order) VALUES (?,?,?)')->execute([$code, $label, (int) $max->fetchColumn()]);
        $msg = 'Özellik eklendi.';
      } elseif ($action === 'delete') {
        $featureId = (int) ($_POST['id'] ?? 0);
        $featQ = db()->prepare('SELECT id, code, label FROM property_feature_catalog WHERE id=?');
        $featQ->execute([$featureId]);
        $feat = $featQ->fetch();
        if (!$feat) throw new RuntimeException('Özellik bulunamadı.');
        // Etki analizi: bu özelliği kullanan kayıtlı villa/yat ilanları.
        $impactSql = "SELECT p.id, p.name, p.property_type, s.company_name FROM properties p JOIN suppliers s ON s.id=p.supplier_id WHERE p.property_type IN ('villa','yacht') AND (jsonb_exists(p.product_details -> 'service_pricing', ?) OR (p.product_details -> 'amenities') @> CAST(? AS jsonb))";
        $impact = db()->prepare($impactSql);
        $impact->execute([$feat['label'], json_encode([$feat['label']])]);
        $affected = $impact->fetchAll();
        if (empty($_POST['confirmed'])) {
          // İlk adım: etki listesini göster, onay iste.
          $pendingDelete = ['id' => $featureId, 'label' => $feat['label'], 'affected' => $affected];
        } else {
          db()->prepare('DELETE FROM property_feature_catalog WHERE id=?')->execute([$featureId]);
          $stripSql = "UPDATE properties SET product_details = jsonb_set(jsonb_set(product_details, '{service_pricing}', COALESCE(product_details -> 'service_pricing', '{}'::jsonb) - ?, true), '{amenities}', COALESCE(product_details -> 'amenities', '[]'::jsonb) - ?, true) WHERE id = ?";
          $strip = db()->prepare($stripSql);
          foreach ($affected as $a) $strip->execute([$feat['label'], $feat['label'], (int) $a['id']]);
          $msg = 'Özellik silindi' . ($affected ? ' ve ' . count($affected) . ' ilandan kaldırıldı: ' . implode(', ', array_map(fn($a) => $a['name'], $affected)) . '.' : '.');
          $deletedAudit = ['label' => $feat['label'], 'affected' => $affected];
        }
      } elseif ($action === 'toggle') {
        db()->prepare('UPDATE property_feature_catalog SET is_active = NOT is_active WHERE id=?')->execute([(int) ($_POST['id'] ?? 0)]);
        $msg = 'Özellik durumu güncellendi.';
      } else {
        throw new RuntimeException('Geçersiz işlem.');
      }
    } catch (Throwable $e) {
      $err = $e->getMessage();
    }
  }
}
$rows = db()->query('SELECT id, code, label, is_active FROM property_feature_catalog ORDER BY code, sort_order, id')->fetchAll();
$byCode = ['villa' => [], 'yacht' => []];
foreach ($rows as $r) $byCode[$r['code']][] = $r;
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Özellik listeleri</title><style>body{font-family:Arial;background:#f7f7f2;color:#10211f;margin:0}.w{width:min(1000px,calc(100% - 30px));margin:35px auto}.c{background:#fff;border:1px solid #ddd;padding:18px;margin:16px 0;border-radius:8px}.f{display:grid;gap:9px}.r{display:flex;gap:9px;align-items:center;flex-wrap:wrap}input,select,button{padding:9px;font:inherit;border:1px solid #ddd;border-radius:5px}button{background:#10211f;color:#fff;font-weight:bold;border:0;cursor:pointer}.chip{display:inline-flex;align-items:center;gap:8px;border:1px solid #d5dccf;background:#fafbf8;border-radius:20px;padding:5px 10px;font-size:13px;margin:4px}.chip.off{opacity:.5;text-decoration:line-through}.chip form{display:inline}.mini{background:#fff;color:#10211f;border:1px solid #ddd;padding:4px 8px;font-size:11px}.del{background:#ffe2de;color:#9d3b1c;border:1px solid #f3c4ba}.ok{background:#e6f8c7;padding:9px;border-radius:5px}.er{background:#ffe2de;padding:9px;border-radius:5px}h2{letter-spacing:-.02em}.two{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media(max-width:700px){.two{grid-template-columns:1fr}}</style></head><body><main class="w"><a href="/nexustraveltech/admin/kontrol-merkezi">← Kontrol merkezi</a><h1>Villa / yat özellik listeleri</h1><p>Bu listeler villa/yat ilan detay sayfasındaki "Özellikler & hizmetler" bölümünü besler. Pasifleştirilen özellik formda görünmez; silinen özellik daha önce kaydedilmiş ilanlardan da kalkar.</p>
<?php if ($msg): ?><p class="ok">✓ <?= htmlspecialchars($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="er"><?= htmlspecialchars($err) ?></p><?php endif; ?>
<?php if (!empty($pendingDelete)): ?>
<div class="c" style="border-color:#f3c4ba;background:#fff7f5"><h2>⚠ Silinecek: "<?= htmlspecialchars($pendingDelete['label']) ?>"</h2><?php if ($pendingDelete['affected']): ?><p>Bu özellik <b><?= count($pendingDelete['affected']) ?></b> kayıtlı ilanda kullanılıyor. Silerseniz bu ilanlardan da kaldırılır:</p><ul><?php foreach ($pendingDelete['affected'] as $a): ?><li><b><?= htmlspecialchars($a['name']) ?></b> <small style="color:#6b7774">(<?= $a['property_type'] === 'yacht' ? 'Yat' : 'Villa' ?> · <?= htmlspecialchars($a['company_name']) ?>)</small></li><?php endforeach; ?></ul><p style="color:#9d3b1c">İlanı etkilenen tedarikçilerin panellerinde bu özellik artık görünmeyecek.</p><?php else: ?><p>Bu özellik şu an hiçbir ilanda kullanılmıyor — güvenle silinebilir.</p><?php endif; ?><form method="post" style="display:flex;gap:9px;margin-top:12px"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $pendingDelete['id'] ?>"><input type="hidden" name="confirmed" value="1"><button style="background:#9d3b1c">Evet, sil ve ilanlardan kaldır</button></form><a href="/nexustraveltech/admin/ozellik-listeleri" style="display:inline-block;margin-top:10px;color:#6b7774;font-size:13px">Vazgeç</a></div>
<?php endif; ?>
<?php if (!empty($deletedAudit)): ?>
<div class="c" style="border-color:#bcd98a;background:#f4fbea"><h2>✓ "<?= htmlspecialchars($deletedAudit['label']) ?>" silindi</h2><?php if ($deletedAudit['affected']): ?><p>Kaldırıldığı ilanlar (<b><?= count($deletedAudit['affected']) ?></b>):</p><ul><?php foreach ($deletedAudit['affected'] as $a): ?><li><b><?= htmlspecialchars($a['name']) ?></b> <small style="color:#6b7774">(<?= $a['property_type'] === 'yacht' ? 'Yat' : 'Villa' ?> · <?= htmlspecialchars($a['company_name']) ?>)</small></li><?php endforeach; ?></ul><?php else: ?><p>Hiçbir ilanda kullanılmıyordu.</p><?php endif; ?></div>
<?php endif; ?>
<form class="c f" method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="add"><div class="r"><select name="code"><option value="villa">Villa</option><option value="yacht">Yat</option></select><input name="label" placeholder="Yeni özellik adı (Örn. Deniz manzaralı teras)" maxlength="120" required style="flex:1"><button>Özellik ekle</button></div></form>
<div class="two"><?php foreach (['villa' => 'Villa özellikleri', 'yacht' => 'Yat özellikleri'] as $code => $title): ?>
<div class="c"><h2><?= $title ?> <small style="color:#6b7774;font-weight:normal">(<?= count($byCode[$code]) ?>)</small></h2><?php if (!$byCode[$code]): ?><p style="color:#6b7774">Liste boş — yukarıdan ekleyin.</p><?php endif; ?>
<?php foreach ($byCode[$code] as $item): ?><span class="chip <?= $item['is_active'] ? '' : 'off' ?>"><?= htmlspecialchars($item['label']) ?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><button class="mini" title="Aktif/pasif"><?= $item['is_active'] ? 'Pasifleştir' : 'Aktifleştir' ?></button></form><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><button class="mini del" onclick="return confirm('Bu özellik silinsin mi?');">×</button></form></span><?php endforeach; ?></div>
<?php endforeach; ?></div>
</main><?php require_once __DIR__ . '/../config/ai_widget.php'; ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); ?></body></html>
