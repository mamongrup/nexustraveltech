<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/feature_lists.php';
require_once __DIR__ . '/../config/audit.php';
require_admin();
if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
$msg = '';
$err = '';
$pendingDelete = null;
$deletedAudit = null;
$typeLabel = fn($t) => ['hotel' => 'Otel', 'villa' => 'Villa', 'yacht' => 'Yat'][$t] ?? $t;
$taxonomies = ['property_type' => 'Tesis tipleri', 'star_rating' => 'Yıldız seviyeleri', 'theme' => 'Otel temaları'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['admin_csrf'], (string) ($_POST['csrf'] ?? ''))) {
    $err = 'Güvenlik doğrulaması geçersiz.';
  } else {
    try {
      $action = $_POST['action'] ?? '';
      if ($action === 'add') {
        $code = $_POST['code'] ?? '';
        $label = trim((string) ($_POST['label'] ?? ''));
        $group = trim((string) ($_POST['group'] ?? ''));
        $isHotelCat = in_array($code, ['amenity', 'activity', 'event'], true);
        if (!in_array($code, ['villa', 'yacht', 'amenity', 'activity', 'event'], true) || $label === '' || mb_strlen($label) > 120) throw new RuntimeException('Tür ve özellik adı gereklidir (en fazla 120 karakter).');
        if ($isHotelCat && $group === '') throw new RuntimeException('Otel hizmetleri için grup adı gereklidir.');
        $check = db()->prepare('SELECT id FROM property_feature_catalog WHERE code=? AND label=?');
        $check->execute([$code, $label]);
        if ($check->fetch()) throw new RuntimeException('Bu özellik zaten listede.');
        $max = db()->prepare('SELECT COALESCE(MAX(sort_order),0)+10 FROM property_feature_catalog WHERE code=? AND group_label=?');
        $max->execute([$code, $isHotelCat ? $group : '']);
        db()->prepare('INSERT INTO property_feature_catalog (code,group_label,label,sort_order) VALUES (?,?,?,?)')->execute([$code, $isHotelCat ? $group : '', $label, (int) $max->fetchColumn()]);
        audit_log('feature.add', 'feature_catalog', (int) db()->lastInsertId(), ['code' => $code, 'label' => $label, 'group' => $group]);
        $msg = 'Özellik eklendi.';
      } elseif ($action === 'delete') {
        $featureId = (int) ($_POST['id'] ?? 0);
        $featQ = db()->prepare('SELECT id, code, group_label, label, sort_order, is_active FROM property_feature_catalog WHERE id=?');
        $featQ->execute([$featureId]);
        $feat = $featQ->fetch();
        if (!$feat) throw new RuntimeException('Özellik bulunamadı.');
        // Etki analizi: bu özelliği kullanan kayıtlı villa/yat ilanları.
        $impactSql = "SELECT p.id, p.name, p.property_type, s.company_name, p.product_details FROM properties p JOIN suppliers s ON s.id=p.supplier_id WHERE p.property_type IN ('hotel','villa','yacht') AND (jsonb_exists(p.product_details -> 'service_pricing', ?) OR (p.product_details -> 'amenities') @> CAST(? AS jsonb) OR (p.product_details -> 'activities') @> CAST(? AS jsonb) OR (p.product_details -> 'events') @> CAST(? AS jsonb))";
        $impact = db()->prepare($impactSql);
        $impact->execute([$feat['label'], json_encode([$feat['label']]), json_encode([$feat['label']]), json_encode([$feat['label']])]);
        $affected = $impact->fetchAll();
        if (empty($_POST['confirmed'])) {
          // İlk adım: etki listesini göster, onay iste.
          $pendingDelete = ['id' => $featureId, 'label' => $feat['label'], 'affected' => $affected];
        } else {
          // Geri alınabilir silme: 1) yedek kaydı (özellik + ilan/bölüm anlık görüntüsü), 2) soft-delete, 3) ilanlardan kaldır.
          $backupProps = [];
          foreach ($affected as $a) {
            $pd = json_decode((string) ($a['product_details'] ?? '{}'), true) ?: [];
            $sections = [];
            $price = null;
            $sp = $pd['service_pricing'] ?? [];
            if (is_array($sp) && array_key_exists($feat['label'], $sp)) { $sections[] = 'service_pricing'; $price = (string) $sp[$feat['label']]; }
            foreach (['amenities', 'activities', 'events'] as $sec) {
              if (is_array($pd[$sec] ?? null) && in_array($feat['label'], $pd[$sec], true)) $sections[] = $sec;
            }
            $backupProps[] = ['id' => (int) $a['id'], 'name' => $a['name'], 'sections' => $sections, 'price' => $price];
          }
          db()->prepare('INSERT INTO feature_delete_backups(feature_id, code, group_label, label, sort_order, is_active, deleted_by, affected_properties) VALUES(?,?,?,?,?,?,?,?::jsonb)')
              ->execute([$featureId, $feat['code'], $feat['group_label'] ?? '', $feat['label'], (int) ($feat['sort_order'] ?? 100), (bool) ($feat['is_active'] ?? true), (string) ($_SESSION['admin_username'] ?? 'admin'), json_encode($backupProps)]);
          db()->prepare('UPDATE property_feature_catalog SET deleted_at=now() WHERE id=?')->execute([$featureId]);
          $stripSql = "UPDATE properties SET product_details = jsonb_set(jsonb_set(jsonb_set(jsonb_set(product_details, '{service_pricing}', COALESCE(product_details -> 'service_pricing', '{}'::jsonb) - ?, true), '{amenities}', COALESCE(product_details -> 'amenities', '[]'::jsonb) - ?, true), '{activities}', COALESCE(product_details -> 'activities', '[]'::jsonb) - ?, true), '{events}', COALESCE(product_details -> 'events', '[]'::jsonb) - ?, true) WHERE id = ?";
          $strip = db()->prepare($stripSql);
          foreach ($affected as $a) $strip->execute([$feat['label'], $feat['label'], $feat['label'], $feat['label'], (int) $a['id']]);
          audit_log('feature.delete', 'feature_catalog', $featureId, [
              'code' => $feat['code'],
              'label' => $feat['label'],
              'affected_count' => count($affected),
              'affected_listing_ids' => array_map(fn($a) => (int) $a['id'], $affected),
              'affected_listings' => array_map(fn($a) => $a['name'] . ' (' . $typeLabel($a['property_type']) . ')', $affected),
          ]);
          $msg = 'Özellik silindi' . ($affected ? ' ve ' . count($affected) . ' ilandan kaldırıldı: ' . implode(', ', array_map(fn($a) => $a['name'], $affected)) . '. ' : '. ') . 'Çöp kutusundan geri alınabilir.';
          $deletedAudit = ['id' => $featureId, 'label' => $feat['label'], 'affected' => $affected];
        }
      } elseif ($action === 'restore') {
        $featureId = (int) ($_POST['id'] ?? 0);
        $bkQ = db()->prepare('SELECT * FROM feature_delete_backups WHERE feature_id=? ORDER BY id DESC LIMIT 1');
        $bkQ->execute([$featureId]);
        $bk = $bkQ->fetch();
        if (!$bk) throw new RuntimeException('Geri alınacak kayıt bulunamadı.');
        $label = (string) $bk['label'];
        // 1) Katalog satırını geri getir (aynı id korunur, sıralama/durum geri gelir).
        db()->prepare('UPDATE property_feature_catalog SET deleted_at=NULL, group_label=?, label=?, sort_order=?, is_active=? WHERE id=?')
            ->execute([$bk['group_label'] ?? '', $label, (int) ($bk['sort_order'] ?? 100), (bool) ($bk['is_active'] ?? true), $featureId]);
        // 2) İlanlara bölüm bazlı geri ekle (zaten varsa dokunma).
        $props = json_decode((string) ($bk['affected_properties'] ?? '[]'), true) ?: [];
        $restored = 0;
        $restoreSp = db()->prepare("UPDATE properties SET product_details = jsonb_set(product_details, '{service_pricing}', COALESCE(product_details -> 'service_pricing', '{}'::jsonb) || jsonb_build_object(?, ?), true) WHERE id=? AND NOT jsonb_exists(COALESCE(product_details -> 'service_pricing', '{}'::jsonb), ?)");
        $restoreSec = [
          'amenities' => db()->prepare("UPDATE properties SET product_details = jsonb_set(product_details, '{amenities}', COALESCE(product_details -> 'amenities', '[]'::jsonb) || ?::jsonb, true) WHERE id=? AND NOT (COALESCE(product_details -> 'amenities', '[]'::jsonb) @> ?::jsonb)"),
          'activities' => db()->prepare("UPDATE properties SET product_details = jsonb_set(product_details, '{activities}', COALESCE(product_details -> 'activities', '[]'::jsonb) || ?::jsonb, true) WHERE id=? AND NOT (COALESCE(product_details -> 'activities', '[]'::jsonb) @> ?::jsonb)"),
          'events' => db()->prepare("UPDATE properties SET product_details = jsonb_set(product_details, '{events}', COALESCE(product_details -> 'events', '[]'::jsonb) || ?::jsonb, true) WHERE id=? AND NOT (COALESCE(product_details -> 'events', '[]'::jsonb) @> ?::jsonb)"),
        ];
        foreach ($props as $pr) {
          $sections = is_array($pr['sections'] ?? null) ? $pr['sections'] : [];
          $pid = (int) ($pr['id'] ?? 0);
          $price = (string) ($pr['price'] ?? '');
          foreach ($sections as $sec) {
            if ($sec === 'service_pricing') { $restoreSp->execute([$label, $price, $pid, $label]); $restored++; }
            elseif (isset($restoreSec[$sec])) { $restoreSec[$sec]->execute([json_encode([$label]), $pid, json_encode([$label])]); $restored++; }
          }
        }
        audit_log('feature.restore', 'feature_catalog', $featureId, [
            'code' => $bk['code'] ?? '',
            'label' => $label,
            'affected_count' => count($props),
            'affected_listing_ids' => array_map(fn($pr) => (int) ($pr['id'] ?? 0), $props),
            'restored_sections' => $restored,
        ]);
        $msg = 'Özellik geri yüklendi' . ($props ? ' ve ' . count($props) . ' ilana tekrar eklendi.' : '.');
      } elseif ($action === 'taxonomy_add') {
        $tx = $_POST['taxonomy_type'] ?? '';
        $name = trim((string) ($_POST['name'] ?? ''));
        if (!isset($taxonomies[$tx]) || $name === '' || mb_strlen($name) > 120) throw new RuntimeException('Sınıflandırma türü ve adı gereklidir (en fazla 120 karakter).');
        db()->prepare('INSERT INTO hotel_taxonomies (taxonomy_type,name,sort_order) VALUES (?,?,?) ON CONFLICT (taxonomy_type,name) DO NOTHING')->execute([$tx, $name, max(0, (int) ($_POST['sort_order'] ?? 100))]);
        $count = db()->prepare('SELECT COUNT(*) FROM hotel_taxonomies WHERE taxonomy_type=? AND name=?');
        $count->execute([$tx, $name]);
        if ((int) $count->fetchColumn() > 0) {
          $tid = db()->prepare('SELECT id FROM hotel_taxonomies WHERE taxonomy_type=? AND name=?');
          $tid->execute([$tx, $name]);
          audit_log('feature.taxonomy_add', 'hotel_taxonomy', (int) $tid->fetchColumn(), ['taxonomy_type' => $tx, 'name' => $name]);
          $msg = 'Sınıflandırma seçeneği eklendi.';
        } else {
          $msg = 'Bu seçenek zaten listede.';
        }
      } elseif ($action === 'taxonomy_toggle') {
        $txq = db()->prepare('SELECT id, taxonomy_type, name, is_active FROM hotel_taxonomies WHERE id=?');
        $txq->execute([(int) ($_POST['id'] ?? 0)]);
        $txr = $txq->fetch();
        if (!$txr) throw new RuntimeException('Sınıflandırma bulunamadı.');
        db()->prepare('UPDATE hotel_taxonomies SET is_active = NOT is_active WHERE id=?')->execute([(int) $txr['id']]);
        audit_log('feature.taxonomy_toggle', 'hotel_taxonomy', (int) $txr['id'], ['taxonomy_type' => $txr['taxonomy_type'], 'name' => $txr['name'], 'is_active' => !(bool) $txr['is_active']]);
        $msg = 'Sınıflandırma durumu güncellendi.';
      } elseif ($action === 'move') {
        $featureId = (int) ($_POST['id'] ?? 0);
        $dir = ($_POST['direction'] ?? '') === 'up' ? 'up' : 'down';
        $fq = db()->prepare('SELECT id, code, group_label, label FROM property_feature_catalog WHERE id=?');
        $fq->execute([$featureId]);
        $f = $fq->fetch();
        if (!$f) throw new RuntimeException('Özellik bulunamadı.');
        $q = db()->prepare('SELECT id, sort_order FROM property_feature_catalog WHERE code=? AND group_label=? ORDER BY sort_order, id');
        $q->execute([$f['code'], $f['group_label']]);
        $rows = $q->fetchAll();
        $pos = null;
        foreach ($rows as $i => $r) if ((int) $r['id'] === $featureId) { $pos = $i; break; }
        $target = $pos !== null ? ($dir === 'up' ? $pos - 1 : $pos + 1) : -1;
        if ($pos !== null && $target >= 0 && $target < count($rows) && $pos !== $target) {
          $upd = db()->prepare('UPDATE property_feature_catalog SET sort_order=? WHERE id=?');
          $upd->execute([$rows[$target]['sort_order'], $rows[$pos]['id']]);
          $upd->execute([$rows[$pos]['sort_order'], $rows[$target]['id']]);
          // Grubu temiz 10'luk adımlara yeniden numarala.
          $renum = db()->prepare('UPDATE property_feature_catalog SET sort_order=? WHERE id=?');
          $n = 10;
          $all = db()->prepare('SELECT id FROM property_feature_catalog WHERE code=? AND group_label=? ORDER BY sort_order, id');
          $all->execute([$f['code'], $f['group_label']]);
          foreach ($all->fetchAll(PDO::FETCH_COLUMN) as $rid) { $renum->execute([$n, $rid]); $n += 10; }
          audit_log('feature.move', 'feature_catalog', $featureId, ['code' => $f['code'], 'label' => $f['label'], 'group' => $f['group_label'], 'direction' => $dir]);
          $msg = 'Sıralama güncellendi.';
        } else {
          $msg = 'Özellik zaten listenin başında/sonunda.';
        }
      } elseif ($action === 'toggle') {
        $toggleQ = db()->prepare('SELECT id, code, label, is_active FROM property_feature_catalog WHERE id=?');
        $toggleQ->execute([(int) ($_POST['id'] ?? 0)]);
        $toggleF = $toggleQ->fetch();
        db()->prepare('UPDATE property_feature_catalog SET is_active = NOT is_active WHERE id=?')->execute([(int) ($_POST['id'] ?? 0)]);
        audit_log('feature.toggle', 'feature_catalog', (int) ($_POST['id'] ?? 0), ['code' => $toggleF['code'] ?? null, 'label' => $toggleF['label'] ?? null, 'is_active' => $toggleF ? !(bool) $toggleF['is_active'] : null]);
        $msg = 'Özellik durumu güncellendi.';
      } else {
        throw new RuntimeException('Geçersiz işlem.');
      }
    } catch (Throwable $e) {
      $err = $e->getMessage();
    }
  }
}
$rows = db()->query('SELECT id, code, group_label, label, is_active FROM property_feature_catalog WHERE deleted_at IS NULL ORDER BY code, group_label, sort_order, id')->fetchAll();
$byCode = ['villa' => [], 'yacht' => [], 'amenity' => [], 'activity' => [], 'event' => []];
foreach ($rows as $r) $byCode[$r['code']][] = $r;
$txRows = db()->query('SELECT id, taxonomy_type, name, is_active FROM hotel_taxonomies ORDER BY taxonomy_type, sort_order, name')->fetchAll();
$byTx = ['property_type' => [], 'star_rating' => [], 'theme' => []];
foreach ($txRows as $t) $byTx[$t['taxonomy_type']][] = $t;
$sectionTitles = ['villa' => 'Villa özellikleri', 'yacht' => 'Yat özellikleri', 'amenity' => 'Otel olanakları', 'activity' => 'Otel aktiviteleri', 'event' => 'Otel etkinlikleri'];
$trash = db()->query("SELECT f.id, f.code, f.group_label, f.label, f.deleted_at, COALESCE((SELECT jsonb_array_length(b.affected_properties) FROM feature_delete_backups b WHERE b.feature_id = f.id ORDER BY b.id DESC LIMIT 1), 0) AS affected_count FROM property_feature_catalog f WHERE f.deleted_at IS NOT NULL ORDER BY f.deleted_at DESC")->fetchAll();
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Özellik listeleri</title><style>body{font-family:Arial;background:#f7f7f2;color:#10211f;margin:0}.w{width:min(1000px,calc(100% - 30px));margin:35px auto}.c{background:#fff;border:1px solid #ddd;padding:18px;margin:16px 0;border-radius:8px}.f{display:grid;gap:9px}.r{display:flex;gap:9px;align-items:center;flex-wrap:wrap}input,select,button{padding:9px;font:inherit;border:1px solid #ddd;border-radius:5px}button{background:#10211f;color:#fff;font-weight:bold;border:0;cursor:pointer}.chip{display:inline-flex;align-items:center;gap:8px;border:1px solid #d5dccf;background:#fafbf8;border-radius:20px;padding:5px 10px;font-size:13px;margin:4px}.chip.off{opacity:.5;text-decoration:line-through}.chip form{display:inline}.mini{background:#fff;color:#10211f;border:1px solid #ddd;padding:4px 8px;font-size:11px}.del{background:#ffe2de;color:#9d3b1c;border:1px solid #f3c4ba}.ok{background:#e6f8c7;padding:9px;border-radius:5px}.er{background:#ffe2de;padding:9px;border-radius:5px}h2{letter-spacing:-.02em}.two{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media(max-width:700px){.two{grid-template-columns:1fr}}</style></head><body><main class="w"><a href="/nexustraveltech/admin/kontrol-merkezi">← Kontrol merkezi</a><h1>Katalog & sınıflandırma yönetimi</h1><p>Tek sayfa: otel sınıflandırmaları (tesis tipleri, yıldız seviyeleri, temalar) + villa/yat özellikleri + otel olanak/aktivite/etkinlik katalogları. Pasifleştirilen seçenek formlarda görünmez; silinen özellik kullanıldığı ilanlardan da kaldırılır ve çöp kutusundan tek tıkla geri alınabilir. Tüm işlemler denetim kaydına yazılır.</p>
<?php if ($msg): ?><p class="ok">✓ <?= htmlspecialchars($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="er"><?= htmlspecialchars($err) ?></p><?php endif; ?>
<?php if (!empty($pendingDelete)): ?>
<div class="c" style="border-color:#f3c4ba;background:#fff7f5"><h2>⚠ Silinecek: "<?= htmlspecialchars($pendingDelete['label']) ?>"</h2><?php if ($pendingDelete['affected']): ?><p>Bu özellik <b><?= count($pendingDelete['affected']) ?></b> kayıtlı ilanda kullanılıyor. Silerseniz bu ilanlardan da kaldırılır:</p><ul><?php foreach ($pendingDelete['affected'] as $a): ?><li><b><?= htmlspecialchars($a['name']) ?></b> <small style="color:#6b7774">(<?= $typeLabel($a['property_type']) ?> · <?= htmlspecialchars($a['company_name']) ?>)</small></li><?php endforeach; ?></ul><p style="color:#9d3b1c">İlanı etkilenen tedarikçilerin panellerinde bu özellik artık görünmeyecek.</p><p style="color:#6b7774;font-size:13px">Silme geri alınabilir: özellik çöp kutusuna taşınır, ilanlara tek tıkla geri yüklenebilir.</p><?php else: ?><p>Bu özellik şu an hiçbir ilanda kullanılmıyor — güvenle silinebilir.</p><?php endif; ?><form method="post" style="display:flex;gap:9px;margin-top:12px"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $pendingDelete['id'] ?>"><input type="hidden" name="confirmed" value="1"><button style="background:#9d3b1c">Evet, sil ve ilanlardan kaldır</button></form><a href="/nexustraveltech/admin/ozellik-listeleri" style="display:inline-block;margin-top:10px;color:#6b7774;font-size:13px">Vazgeç</a></div>
<?php endif; ?>
<?php if (!empty($deletedAudit)): ?>
<div class="c" style="border-color:#bcd98a;background:#f4fbea"><h2>✓ "<?= htmlspecialchars($deletedAudit['label']) ?>" silindi</h2><?php if ($deletedAudit['affected']): ?><p>Kaldırıldığı ilanlar (<b><?= count($deletedAudit['affected']) ?></b>):</p><ul><?php foreach ($deletedAudit['affected'] as $a): ?><li><b><?= htmlspecialchars($a['name']) ?></b> <small style="color:#6b7774">(<?= $typeLabel($a['property_type']) ?> · <?= htmlspecialchars($a['company_name']) ?>)</small></li><?php endforeach; ?></ul><?php else: ?><p>Hiçbir ilanda kullanılmıyordu.</p><?php endif; ?><form method="post" style="margin-top:12px"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?= (int) $deletedAudit['id'] ?>"><button style="background:#405b13">↩ Geri al — özelliği ve ilanları geri yükle</button></form></div>
<?php endif; ?>
<form class="c f" method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="add"><div class="r"><select name="code"><option value="villa">Villa</option><option value="yacht">Yat</option><option value="amenity">Otel olanakları</option><option value="activity">Otel aktiviteleri</option><option value="event">Otel etkinlikleri</option></select><input name="label" placeholder="Yeni özellik adı" maxlength="120" required style="flex:1"><input name="group" placeholder="Grup (yalnızca otel hizmetleri; Örn. Spa & spor)" maxlength="120" style="flex:1"><button>Özellik ekle</button></div></form>
<h2 style="margin:26px 0 4px;border-top:1px solid #e2e6df;padding-top:20px">Otel sınıflandırmaları</h2>
<div class="two"><?php foreach ($taxonomies as $txKey => $txTitle): ?>
<div class="c"><h2><?= htmlspecialchars($txTitle) ?> <small style="color:#6b7774;font-weight:normal">(<?= count($byTx[$txKey]) ?>)</small></h2><form class="r" method="post" style="margin-bottom:12px"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="taxonomy_add"><input type="hidden" name="taxonomy_type" value="<?= htmlspecialchars($txKey) ?>"><input name="name" placeholder="Yeni seçenek adı" maxlength="120" required style="flex:1"><input name="sort_order" type="number" value="100" min="0" title="Sıra" style="width:80px"><button>+ Ekle</button></form><?php foreach (($byTx[$txKey] ?? []) as $tx): ?><span class="chip <?= $tx['is_active'] ? '' : 'off' ?>"><?= htmlspecialchars($tx['name']) ?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="taxonomy_toggle"><input type="hidden" name="id" value="<?= (int) $tx['id'] ?>"><button class="mini" title="Aktif/pasif"><?= $tx['is_active'] ? 'Pasifleştir' : 'Aktifleştir' ?></button></form></span><?php endforeach; ?><?php if (!$byTx[$txKey]): ?><p style="color:#6b7774">Liste boş.</p><?php endif; ?></div>
<?php endforeach; ?></div>
<h2 style="margin:26px 0 4px;border-top:1px solid #e2e6df;padding-top:20px">Özellik katalogları</h2>
<div class="two"><?php foreach ($sectionTitles as $code => $title): $isHotelCat = in_array($code, ['amenity', 'activity', 'event'], true); $grouped = []; foreach (($byCode[$code] ?? []) as $item) $grouped[$item['group_label'] ?: 'Genel'][] = $item; ?>
<div class="c"><h2><?= $title ?> <small style="color:#6b7774;font-weight:normal">(<?= count($byCode[$code]) ?>)</small></h2><?php if (!$byCode[$code]): ?><p style="color:#6b7774">Liste boş — yukarıdan ekleyin.</p><?php endif; ?>
<?php foreach ($grouped as $groupName => $groupItems): ?><?php if ($isHotelCat): ?><h3 style="font-size:13px;margin:14px 0 4px;color:#405b13"><?= htmlspecialchars($groupName) ?></h3><?php endif; ?><?php foreach ($groupItems as $item): ?><span class="chip <?= $item['is_active'] ? '' : 'off' ?>"><?= htmlspecialchars($item['label']) ?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="direction" value="up"><button class="mini" title="Yukarı taşı">↑</button></form><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="direction" value="down"><button class="mini" title="Aşağı taşı">↓</button></form><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><button class="mini" title="Aktif/pasif"><?= $item['is_active'] ? 'Pasifleştir' : 'Aktifleştir' ?></button></form><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><button class="mini del" onclick="return confirm('Bu özellik silinsin mi?');">×</button></form></span><?php endforeach; ?><?php endforeach; ?></div>
<?php endforeach; ?></div>
<?php if ($trash): ?><h2 style="margin:26px 0 4px;border-top:1px solid #e2e6df;padding-top:20px">🗑 Çöp kutusu — geri alınabilir silmeler</h2><div class="c" style="border-color:#e0c9a3;background:#fdf9f2"><p style="color:#6b7774;font-size:13px;margin-top:0">Silinen özellikler burada durur ve tek tıkla geri yüklenebilir; kaldırıldığı ilanlara aynı bölüm ve fiyat durumuyla geri eklenir.</p><?php foreach ($trash as $t): ?><span class="chip" style="opacity:1;border-color:#e0c9a3"><?= htmlspecialchars($t['label']) ?> <small style="color:#6b7774">(<?= htmlspecialchars($sectionTitles[$t['code']] ?? (string) $t['code']) ?> · <?= (int) $t['affected_count'] ?> ilan · <?= htmlspecialchars(mb_substr((string) $t['deleted_at'], 0, 16)) ?>)</small><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="mini" style="background:#e6f8c7;color:#10211f;border:1px solid #bcd98a" title="Geri yükle">↩ Geri yükle</button></form></span><?php endforeach; ?></div><?php endif; ?>
</main><?php require_once __DIR__ . '/../config/ai_widget.php'; ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); ?></body></html>
