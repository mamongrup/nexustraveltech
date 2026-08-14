<?php
$active_module = 'properties';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/../config/hotel_form.php';
require_once __DIR__ . '/../config/distribution_channels.php';
$u = $supplier_user; $channels = distribution_channels(); $error = ''; $notice = isset($_GET['saved']) ? 'Yayın kanalı tercihleri kaydedildi.' : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_valid($_POST['csrf_token'] ?? null)) $error = 'Güvenlik doğrulaması yenilendi. Lütfen tekrar deneyin.';
  else {
    $propertyId = (int)($_POST['property_id'] ?? 0);
    $selected = array_values(array_intersect($_POST['channels'] ?? [], array_keys($channels)));
    $q = db()->prepare('SELECT product_details FROM properties WHERE id=? AND supplier_id=? LIMIT 1'); $q->execute([$propertyId, $u['supplier_id']]); $property = $q->fetch();
    if (!$property) $error = 'Ürün bulunamadı.';
    else {
      $details = json_decode($property['product_details'] ?? '{}', true) ?: [];
      $details['distribution_channels'] = $selected;
      db()->prepare('UPDATE properties SET product_details=?::jsonb WHERE id=? AND supplier_id=?')->execute([json_encode($details, JSON_UNESCAPED_UNICODE), $propertyId, $u['supplier_id']]);
      header('Location: /nexustraveltech/tedarikci/yayin-kanallari?saved=1'); exit;
    }
  }
}
$q = db()->prepare('SELECT * FROM properties WHERE supplier_id=? ORDER BY id DESC'); $q->execute([$u['supplier_id']]); $properties = $q->fetchAll();
supply_start('Yayın kanalları', $active_module); ?>
<section class="page-intro"><p>Her ürünün hangi kanallarda satışa açılacağını seçin. Dış kanal seçimi, yalnızca ilgili sözleşme ve teknik entegrasyon aktifse yayına dönüşür.</p></section>
<?php if($error): ?><p class="login-error"><?= htmlspecialchars($error) ?></p><?php endif; ?><?php if($notice): ?><p class="save-success">✓ <?= htmlspecialchars($notice) ?></p><?php endif; ?>
<style>.channel-card{background:#fff;border:1px solid var(--line);border-radius:10px;padding:25px;margin-bottom:14px}.channel-card h2{font-size:19px;margin:0 0 5px}.channel-card>p{margin:0 0 18px;color:var(--muted);font-size:13px}.channel-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.channel-grid label{border:1px solid var(--line);border-radius:6px;padding:11px;display:grid;grid-template-columns:18px 1fr;gap:4px 8px;font-size:13px}.channel-grid input{grid-row:1/3;margin:3px 0}.channel-grid small{color:var(--muted);line-height:1.4}.channel-card button{margin-top:16px;border:0;border-radius:6px;background:var(--navy);color:#fff;padding:11px 14px;font-weight:700}@media(max-width:650px){.channel-grid{grid-template-columns:1fr}}</style>
<?php foreach($properties as $property): $details=json_decode($property['product_details'] ?? '{}', true) ?: []; $selected=$details['distribution_channels'] ?? ['nexus_b2b']; ?><form method="post" class="channel-card"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="property_id" value="<?= (int)$property['id'] ?>"><h2><?= htmlspecialchars($property['name']) ?></h2><p><?= htmlspecialchars($property['property_type']) ?> · <?= htmlspecialchars($property['city'] ?: 'Konum girilmedi') ?></p><div class="channel-grid"><?php foreach($channels as $key=>$channel): ?><label><input type="checkbox" name="channels[]" value="<?= htmlspecialchars($key) ?>" <?= in_array($key,$selected,true)?'checked':'' ?>><b><?= htmlspecialchars($channel['name']) ?></b><small><?= htmlspecialchars($channel['description']) ?></small></label><?php endforeach; ?></div><button>Yayın tercihlerini kaydet →</button></form><?php endforeach; ?>
<?php supply_end(); ?>
