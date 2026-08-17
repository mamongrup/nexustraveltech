<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/feature_lists.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_admin();
if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
$msg = '';
$err = '';
$pendingDelete = null;
$deletedAudit = null;
$pendingBulk = null;
$typeLabel = fn($t) => ['hotel' => 'Otel', 'villa' => 'Villa', 'yacht' => 'Yat'][$t] ?? $t;
$taxonomies = ['property_type' => 'Tesis tipleri', 'star_rating' => 'Yıldız seviyeleri', 'theme' => 'Otel temaları'];
// Tekil silme işlemcisi — geri alınabilir silme: yedek + soft-delete + ilanlardan kaldırma + denetim.
// Hem tekil silme hem toplu silme aynı yolu kullanır.
$deleteFeature = function (int $featureId) use ($typeLabel): array {
    $pdo = db();
    $featQ = $pdo->prepare('SELECT id, code, group_label, label, sort_order, is_active FROM property_feature_catalog WHERE id=?');
    $featQ->execute([$featureId]);
    $feat = $featQ->fetch();
    if (!$feat) throw new RuntimeException('Özellik bulunamadı.');
    $impactSql = "SELECT p.id, p.name, p.property_type, s.id AS supplier_id, s.company_name, p.product_details FROM properties p JOIN suppliers s ON s.id=p.supplier_id WHERE p.property_type IN ('hotel','villa','yacht') AND (jsonb_exists(p.product_details -> 'service_pricing', ?) OR (p.product_details -> 'amenities') @> CAST(? AS jsonb) OR (p.product_details -> 'activities') @> CAST(? AS jsonb) OR (p.product_details -> 'events') @> CAST(? AS jsonb))";
    $impact = $pdo->prepare($impactSql);
    $impact->execute([$feat['label'], json_encode([$feat['label']]), json_encode([$feat['label']]), json_encode([$feat['label']])]);
    $affected = $impact->fetchAll();
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
    $pdo->prepare('INSERT INTO feature_delete_backups(feature_id, code, group_label, label, sort_order, is_active, deleted_by, affected_properties) VALUES(?,?,?,?,?,?,?,?::jsonb)')
        ->execute([$featureId, $feat['code'], $feat['group_label'] ?? '', $feat['label'], (int) ($feat['sort_order'] ?? 100), (bool) ($feat['is_active'] ?? true), (string) ($_SESSION['admin_username'] ?? 'admin'), json_encode($backupProps)]);
    $pdo->prepare('UPDATE property_feature_catalog SET deleted_at=now() WHERE id=?')->execute([$featureId]);
    $stripSql = "UPDATE properties SET product_details = jsonb_set(jsonb_set(jsonb_set(jsonb_set(product_details, '{service_pricing}', COALESCE(product_details -> 'service_pricing', '{}'::jsonb) - ?, true), '{amenities}', COALESCE(product_details -> 'amenities', '[]'::jsonb) - ?, true), '{activities}', COALESCE(product_details -> 'activities', '[]'::jsonb) - ?, true), '{events}', COALESCE(product_details -> 'events', '[]'::jsonb) - ?, true) WHERE id = ?";
    $strip = $pdo->prepare($stripSql);
    foreach ($affected as $a) $strip->execute([$feat['label'], $feat['label'], $feat['label'], $feat['label'], (int) $a['id']]);
    audit_log('feature.delete', 'feature_catalog', $featureId, [
        'code' => $feat['code'],
        'label' => $feat['label'],
        'affected_count' => count($affected),
        'affected_listing_ids' => array_map(fn($a) => (int) $a['id'], $affected),
        'affected_listings' => array_map(fn($a) => $a['name'] . ' (' . $typeLabel($a['property_type']) . ')', $affected),
    ]);
    return ['id' => $featureId, 'label' => $feat['label'], 'affected' => $affected];
};
// CSV dışa aktarma — silme onay ekranından tedarikçi bazlı etki listesi indirilir.
// Tekil: ?export=delete_impact&feature_id=N · Toplu: ?export=bulk_impact&ids[]=N&ids[]=M
$export = (string) ($_GET['export'] ?? '');
if (($export === 'delete_impact' || $export === 'bulk_impact') && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $secLabels = ['service_pricing' => 'fiyatlandırma', 'amenities' => 'olanaklar', 'activities' => 'aktiviteler', 'events' => 'etkinlikler'];
    $typeLabelCsv = fn($t) => ['hotel' => 'Otel', 'villa' => 'Villa', 'yacht' => 'Yat'][$t] ?? $t;
    $sectionsOf = function (array $a, string $label): array {
        $pd = json_decode((string) ($a['product_details'] ?? '{}'), true) ?: [];
        $sec = [];
        $sp = $pd['service_pricing'] ?? [];
        if (is_array($sp) && array_key_exists($label, $sp)) $sec[] = 'service_pricing';
        foreach (['amenities', 'activities', 'events'] as $s) {
            if (is_array($pd[$s] ?? null) && in_array($label, $pd[$s], true)) $sec[] = $s;
        }
        return $sec;
    };
    $rows = [];
    $fileName = 'etki-export.csv';
    if ($export === 'delete_impact') {
        $fid = (int) ($_GET['feature_id'] ?? 0);
        $fq = db()->prepare('SELECT label FROM property_feature_catalog WHERE id=?');
        $fq->execute([$fid]);
        $label = (string) ($fq->fetchColumn() ?: '');
        if ($fid <= 0 || $label === '') { http_response_code(404); exit('Özellik bulunamadı.'); }
        $fileName = 'etki-' . preg_replace('/[^a-z0-9]+/i', '-', $label) . '.csv';
        $iq = db()->prepare("SELECT p.id, p.name, p.property_type, s.id AS supplier_id, s.company_name, p.product_details FROM properties p JOIN suppliers s ON s.id=p.supplier_id WHERE p.property_type IN ('hotel','villa','yacht') AND (jsonb_exists(p.product_details -> 'service_pricing', ?) OR (p.product_details -> 'amenities') @> CAST(? AS jsonb) OR (p.product_details -> 'activities') @> CAST(? AS jsonb) OR (p.product_details -> 'events') @> CAST(? AS jsonb))");
        $iq->execute([$label, json_encode([$label]), json_encode([$label]), json_encode([$label])]);
        foreach ($iq->fetchAll() as $a) { $sec = $sectionsOf($a, $label); $rows[] = ['Tedarikçi', $a['company_name'] ?? '', $a['name'], $typeLabelCsv($a['property_type']), count($sec), implode(', ', array_map(fn($s) => $secLabels[$s] ?? $s, $sec)), $a['id']]; }
    } else {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_GET['ids'] ?? [])), fn($i) => $i > 0)));
        if (!$ids) { http_response_code(400); exit('Geçersiz özellik listesi.'); }
        $fileName = 'etki-toplu-silme.csv';
        $fq = db()->prepare('SELECT id, label FROM property_feature_catalog WHERE id=?');
        $iq = db()->prepare("SELECT p.id, p.name, p.property_type, s.id AS supplier_id, s.company_name, p.product_details FROM properties p JOIN suppliers s ON s.id=p.supplier_id WHERE p.property_type IN ('hotel','villa','yacht') AND (jsonb_exists(p.product_details -> 'service_pricing', ?) OR (p.product_details -> 'amenities') @> CAST(? AS jsonb) OR (p.product_details -> 'activities') @> CAST(? AS jsonb) OR (p.product_details -> 'events') @> CAST(? AS jsonb))");
        foreach ($ids as $fid) {
            $fq->execute([$fid]);
            $label = (string) ($fq->fetchColumn() ?: '');
            if ($label === '') continue;
            $iq->execute([$label, json_encode([$label]), json_encode([$label]), json_encode([$label])]);
            foreach ($iq->fetchAll() as $a) { $sec = $sectionsOf($a, $label); $rows[] = ['Özellik', $label, $a['company_name'] ?? '', $a['name'], $typeLabelCsv($a['property_type']), count($sec), implode(', ', array_map(fn($s) => $secLabels[$s] ?? $s, $sec)), $a['id']]; }
        }
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM — Excel'de Türkçe karakterler
    if ($export === 'delete_impact') {
        fputcsv($out, ['Tedarikçi', 'İlan', 'Tür', 'Etkilenen bölüm sayısı', 'Bölümler', 'İlan ID']);
    } else {
        fputcsv($out, ['Özellik', 'Tedarikçi', 'İlan', 'Tür', 'Etkilenen bölüm sayısı', 'Bölümler', 'İlan ID']);
    }
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}

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
        $impactSql = "SELECT p.id, p.name, p.property_type, s.id AS supplier_id, s.company_name, p.product_details FROM properties p JOIN suppliers s ON s.id=p.supplier_id WHERE p.property_type IN ('hotel','villa','yacht') AND (jsonb_exists(p.product_details -> 'service_pricing', ?) OR (p.product_details -> 'amenities') @> CAST(? AS jsonb) OR (p.product_details -> 'activities') @> CAST(? AS jsonb) OR (p.product_details -> 'events') @> CAST(? AS jsonb))";
        $impact = db()->prepare($impactSql);
        $impact->execute([$feat['label'], json_encode([$feat['label']]), json_encode([$feat['label']]), json_encode([$feat['label']])]);
        $affected = $impact->fetchAll();
        if (empty($_POST['confirmed'])) {
          // İlk adım: etki listesini göster, onay iste. İlan başına hangi bölümlerin
          // etkilendiğini de hesapla (service_pricing/amenities/activities/events).
          foreach ($affected as &$a) {
            $pd = json_decode((string) ($a['product_details'] ?? '{}'), true) ?: [];
            $sec = [];
            $sp = $pd['service_pricing'] ?? [];
            if (is_array($sp) && array_key_exists($feat['label'], $sp)) $sec[] = 'service_pricing';
            foreach (['amenities', 'activities', 'events'] as $s) {
              if (is_array($pd[$s] ?? null) && in_array($feat['label'], $pd[$s], true)) $sec[] = $s;
            }
            $a['sections'] = $sec;
          }
          unset($a);
          $pendingDelete = ['id' => $featureId, 'label' => $feat['label'], 'affected' => $affected];
        } else {
          $res = $deleteFeature($featureId);
          $msg = 'Özellik silindi' . ($res['affected'] ? ' ve ' . count($res['affected']) . ' ilandan kaldırıldı: ' . implode(', ', array_map(fn($a) => $a['name'], $res['affected'])) . '. ' : '. ') . 'Çöp kutusundan geri alınabilir.';
          $deletedAudit = ['id' => $featureId, 'label' => $res['label'], 'affected' => $res['affected']];
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
        // Özellik çöp kutusundan çıktığı için bekleyen "son şans" onay kaydını temizle.
        db()->prepare('DELETE FROM pending_trash_purges WHERE feature_id=?')->execute([$featureId]);
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
      } elseif ($action === 'bulk') {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), fn($i) => $i > 0)));
        $sub = in_array($_POST['sub'] ?? '', ['deactivate', 'activate'], true) ? (string) $_POST['sub'] : 'delete';
        if (!$ids) throw new RuntimeException('En az bir özellik seçin.');
        if ($sub === 'delete' && empty($_POST['confirm'])) {
          // Adım 1: etki özeti (önce onay, sonra uygula).
          $fq = db()->prepare('SELECT id, label FROM property_feature_catalog WHERE id=?');
          $iq = db()->prepare("SELECT p.id, p.name, p.property_type FROM properties p WHERE p.property_type IN ('hotel','villa','yacht') AND (jsonb_exists(p.product_details -> 'service_pricing', ?) OR (p.product_details -> 'amenities') @> CAST(? AS jsonb) OR (p.product_details -> 'activities') @> CAST(? AS jsonb) OR (p.product_details -> 'events') @> CAST(? AS jsonb))");
          $items = [];
          $totalAffected = 0;
          $byType = ['hotel' => 0, 'villa' => 0, 'yacht' => 0];
          foreach ($ids as $fid) {
            $fq->execute([$fid]);
            $f = $fq->fetch();
            if (!$f) continue;
            $iq->execute([$f['label'], json_encode([$f['label']]), json_encode([$f['label']]), json_encode([$f['label']])]);
            $aff = $iq->fetchAll();
            foreach ($aff as $a) { if (isset($byType[$a['property_type']])) $byType[$a['property_type']]++; }
            $items[] = ['label' => $f['label'], 'affected_count' => count($aff), 'affected_names' => array_map(fn($a) => $a['name'], array_slice($aff, 0, 5))];
            $totalAffected += count($aff);
          }
          $pendingBulk = ['ids' => $ids, 'items' => $items, 'total_affected' => $totalAffected, 'by_type' => $byType];
        } else {
          // Adım 2: uygula (her özellik için tekil akışın aynısı + toplu denetim kaydı).
          $done = 0; $removed = 0; $skipped = 0; $errors = [];
          $curQ = db()->prepare('SELECT is_active FROM property_feature_catalog WHERE id=?');
          $updQ = db()->prepare('UPDATE property_feature_catalog SET is_active=? WHERE id=?');
          foreach ($ids as $fid) {
            try {
              if ($sub === 'deactivate' || $sub === 'activate') {
                $newState = $sub === 'activate';
                $curQ->execute([$fid]);
                $cur = $curQ->fetchColumn();
                // Idempotent davranış: zaten hedef durumda olan özellik işlenmez, sayılmaz.
                if ($cur === null) { $errors[] = "Özellik #$fid bulunamadı."; continue; }
                if ((bool) $cur === $newState) { $skipped++; continue; }
                $updQ->execute([$newState, $fid]);
                audit_log('feature.toggle', 'feature_catalog', $fid, ['is_active' => $newState, 'bulk' => true]);
              } else {
                $res = $deleteFeature($fid);
                $removed += count($res['affected']);
              }
              $done++;
            } catch (Throwable $e) { $errors[] = $e->getMessage(); }
          }
          audit_log($sub === 'delete' ? 'feature.bulk_delete' : ($sub === 'activate' ? 'feature.bulk_activate' : 'feature.bulk_deactivate'), 'feature_catalog', null, [
              'count' => $done,
              'feature_ids' => $ids,
              'skipped_unchanged' => $skipped,
              'affected_count' => $removed,
          ]);
          $msg = $sub === 'delete'
              ? "$done özellik silindi, $removed ilandan kaldırıldı. Çöp kutusundan geri alınabilir."
              : ($sub === 'activate'
                  ? "$done özellik aktifleştirildi" . ($skipped ? ", $skipped özellik zaten aktif olduğu için atlandı (sayılmadı)" : '') . '.'
                  : "$done özellik pasifleştirildi" . ($skipped ? ", $skipped özellik zaten pasif olduğu için atlandı (sayılmadı)" : '') . '.');
          if ($errors) $msg .= ' Hatalar: ' . implode('; ', $errors) . '.';
        }
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
<div class="c" style="border-color:#f3c4ba;background:#fff7f5"><h2>⚠ Silinecek: "<?= htmlspecialchars($pendingDelete['label']) ?>"</h2><?php if ($pendingDelete['affected']): ?><p>Bu özellik <b><?= count($pendingDelete['affected']) ?></b> kayıtlı ilanda kullanılıyor — <b><?= count(array_unique(array_column($pendingDelete['affected'], 'company_name'))) ?></b> tedarikçi etkilenir. Silerseniz aşağıdaki ilanlardan da kaldırılır (çöp kutusundan tek tıkla geri yüklenebilir):</p><?php $bySupplier = []; foreach ($pendingDelete['affected'] as $a) { $bySupplier[(int) ($a['supplier_id'] ?? 0)][] = $a; } foreach ($bySupplier as $sid => $list): $company = (string) ($list[0]['company_name'] ?? ''); ?><div style="border:1px solid #f3c4ba;border-radius:8px;padding:10px 12px;margin:10px 0;background:#fff"><b>🏢 <?= htmlspecialchars($company) ?></b> <small style="color:#6b7774">— <a href="/nexustraveltech/admin/tedarikci-ilanlari?supplier_id=<?= (int) $sid ?>" style="color:#405b13;font-weight:bold;text-decoration:none" title="Bu tedarikçinin ilanlarını görüntüle"><?= count($list) ?> ilan →</a></small><?php if ($sid > 0): ?>&nbsp;<small style="color:#6b7774"><a href="/nexustraveltech/tedarikci/" target="_blank" rel="noopener" style="color:#6b7774">panel ↗</a></small><?php endif; ?><ul style="margin:8px 0 0"><?php foreach ($list as $a): ?><li><b><?= htmlspecialchars($a['name']) ?></b> <small style="color:#6b7774">(<?= $typeLabel($a['property_type']) ?>)</small><?php $secLabels = ['service_pricing' => 'fiyatlandırma', 'amenities' => 'olanaklar', 'activities' => 'aktiviteler', 'events' => 'etkinlikler']; if (!empty($a['sections'])): ?> <small style="color:#9d3b1c">· <?= count($a['sections']) ?> bölüm (<?= htmlspecialchars(implode(', ', array_map(fn($s) => $secLabels[$s] ?? $s, $a['sections']))) ?>)</small><?php endif; ?></li><?php endforeach; ?></ul></div><?php endforeach; ?><p style="color:#9d3b1c">Etkilenen tedarikçilerin panellerinde bu özellik artık görünmeyecek; yine de silme geri alınabilir — özellik çöp kutusuna taşınır, ilanlara aynı bölüm ve fiyat durumuyla geri yüklenebilir.</p><?php else: ?><p>Bu özellik şu an hiçbir ilanda kullanılmıyor — güvenle silinebilir.</p><?php endif; ?><form method="post" style="display:flex;gap:9px;margin-top:12px"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $pendingDelete['id'] ?>"><input type="hidden" name="confirmed" value="1"><button style="background:#9d3b1c">Evet, sil ve ilanlardan kaldır</button><a href="/nexustraveltech/admin/ozellik-listeleri?export=delete_impact&feature_id=<?= (int) $pendingDelete['id'] ?>" style="display:inline-block;margin-top:10px;background:#f4f6f1;border:1px solid #d5dccf;color:#405b13;padding:8px 12px;font-size:13px;font-weight:bold;text-decoration:none;border-radius:5px">⬇ CSV indir (tedarikçi bazlı)</a></form><a href="/nexustraveltech/admin/ozellik-listeleri" style="display:inline-block;margin-top:10px;color:#6b7774;font-size:13px">Vazgeç</a></div>
<?php endif; ?>
<?php if (!empty($pendingBulk)): ?>
<div class="c" style="border-color:#f3c4ba;background:#fff7f5"><h2>⚠ <?= count($pendingBulk['items']) ?> özellik silinecek — toplam <?= (int) $pendingBulk['total_affected'] ?> ilan etkilenecek</h2><table style="border-collapse:collapse;margin:12px 0;font-size:13px;min-width:280px"><tr><th style="text-align:left;padding:7px 10px;border:1px solid #e1e5de;background:#f7f7f2">Tür</th><th style="text-align:right;padding:7px 10px;border:1px solid #e1e5de;background:#f7f7f2">İlan sayısı</th></tr><?php foreach (['hotel' => 'Otel', 'villa' => 'Villa', 'yacht' => 'Yat'] as $tk => $tl): ?><tr><td style="padding:7px 10px;border:1px solid #e1e5de"><?= $tl ?></td><td style="padding:7px 10px;border:1px solid #e1e5de;text-align:right"><?= (int) ($pendingBulk['by_type'][$tk] ?? 0) ?></td></tr><?php endforeach; ?><tr><td style="padding:7px 10px;border:1px solid #e1e5de;font-weight:bold;background:#f7f7f2">Toplam</td><td style="padding:7px 10px;border:1px solid #e1e5de;text-align:right;font-weight:bold;background:#f7f7f2"><?= (int) $pendingBulk['total_affected'] ?></td></tr></table><p style="margin:8px 0 4px;font-weight:bold">Özellik bazında etki:</p><ul><?php foreach ($pendingBulk['items'] as $bi): ?><li><b><?= htmlspecialchars($bi['label']) ?></b> — <?= (int) $bi['affected_count'] ?> ilan<?php if ($bi['affected_names']): ?> (<?= htmlspecialchars(implode(', ', $bi['affected_names'])) ?><?= $bi['affected_count'] > 5 ? ', …' : '' ?>)<?php endif; ?></li><?php endforeach; ?></ul><p style="color:#9d3b1c">Silinen özellikler çöp kutusuna taşınır ve tek tıkla geri yüklenebilir.</p><form method="post" style="margin-top:12px"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="bulk"><input type="hidden" name="sub" value="delete"><input type="hidden" name="confirm" value="1"><?php foreach ($pendingBulk['ids'] as $fid): ?><input type="hidden" name="ids[]" value="<?= (int) $fid ?>"><?php endforeach; ?><button style="background:#9d3b1c">Evet, <?= count($pendingBulk['ids']) ?> özelliği sil</button><a href="/nexustraveltech/admin/ozellik-listeleri?export=bulk_impact<?php foreach ($pendingBulk['ids'] as $fid): ?>&ids[]=<?= (int) $fid ?><?php endforeach; ?>" style="display:inline-block;margin-top:10px;background:#f4f6f1;border:1px solid #d5dccf;color:#405b13;padding:8px 12px;font-size:13px;font-weight:bold;text-decoration:none;border-radius:5px">⬇ CSV indir (tedarikçi bazlı)</a></form><a href="/nexustraveltech/admin/ozellik-listeleri" style="display:inline-block;margin-top:10px;color:#6b7774;font-size:13px">Vazgeç</a></div>
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
<div id="bulkBar" style="display:none;position:sticky;top:0;z-index:5;background:#10211f;color:#fff;padding:10px 14px;border-radius:8px;margin:14px 0;align-items:center;gap:12px;flex-wrap:wrap"><b id="bulkCount">0</b> özellik seçildi <span style="display:inline-flex;gap:6px;align-items:center"><span class="bulk-badge" data-b="on" style="display:none;background:#2e7d32;color:#fff;border-radius:12px;padding:2px 10px;font-size:12px;font-weight:bold"></span><span class="bulk-badge" data-b="off" style="display:none;background:#b26a00;color:#fff;border-radius:12px;padding:2px 10px;font-size:12px;font-weight:bold"></span></span><button type="button" class="mini" style="background:#d9f0b4;color:#10211f;font-weight:bold" onclick="bulkGo('activate')">Aktifleştir</button><button type="button" class="mini" style="background:#e6f8c7;color:#10211f;font-weight:bold" onclick="bulkGo('deactivate')">Pasifleştir</button><button type="button" class="mini" style="background:#b0301a;color:#fff;font-weight:bold" onclick="bulkGo('delete')">Sil</button><button type="button" class="mini" style="background:#33424a;color:#fff" onclick="clearBulk()">Seçimi temizle</button></div>
<form id="bulkForm" method="post" style="display:none"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="bulk"><input type="hidden" name="sub" id="bulkSub" value="delete"></form>
<div class="two"><?php foreach ($sectionTitles as $code => $title): $isHotelCat = in_array($code, ['amenity', 'activity', 'event'], true); $grouped = []; foreach (($byCode[$code] ?? []) as $item) $grouped[$item['group_label'] ?: 'Genel'][] = $item; ?>
<div class="c"><h2><?= $title ?> <small style="color:#6b7774;font-weight:normal">(<?= count($byCode[$code]) ?>)</small></h2><label style="display:inline-flex;align-items:center;gap:4px;font-size:12px;color:#6b7774;margin:0 0 6px"><input type="checkbox" class="selall" data-code="<?= htmlspecialchars($code) ?>"> Tümünü seç</label><?php if (!$byCode[$code]): ?><p style="color:#6b7774">Liste boş — yukarıdan ekleyin.</p><?php endif; ?>
<?php foreach ($grouped as $groupName => $groupItems): ?><?php if ($isHotelCat): ?><h3 style="font-size:13px;margin:14px 0 4px;color:#405b13"><?= htmlspecialchars($groupName) ?></h3><?php endif; ?><?php foreach ($groupItems as $item): ?><span class="chip <?= $item['is_active'] ? '' : 'off' ?>"><label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer;margin-right:2px" title="Toplu işlem için seç"><input type="checkbox" class="feat-check" data-code="<?= htmlspecialchars($code) ?>" data-state="<?= $item['is_active'] ? 'on' : 'off' ?>" value="<?= (int) $item['id'] ?>"></label><?= htmlspecialchars($item['label']) ?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="direction" value="up"><button class="mini" title="Yukarı taşı">↑</button></form><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="direction" value="down"><button class="mini" title="Aşağı taşı">↓</button></form><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><button class="mini" title="Aktif/pasif"><?= $item['is_active'] ? 'Pasifleştir' : 'Aktifleştir' ?></button></form><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><button class="mini del" onclick="return confirm('Bu özellik silinsin mi?');">×</button></form></span><?php endforeach; ?><?php endforeach; ?></div>
<?php endforeach; ?></div>
<?php if ($trash): $ttlDays = max(7, (int) platform_setting('feature_trash_ttl_days', 30)); ?><h2 style="margin:26px 0 4px;border-top:1px solid #e2e6df;padding-top:20px">🗑 Çöp kutusu — geri alınabilir silmeler</h2><div class="c" style="border-color:#e0c9a3;background:#fdf9f2"><p style="color:#6b7774;font-size:13px;margin-top:0">Silinen özellikler burada durur ve tek tıkla geri yüklenebilir; kaldırıldığı ilanlara aynı bölüm ve fiyat durumuyla geri eklenir. Her özellik <b>silinme + <?= (int) $ttlDays ?> gün TTL</b> sonra kalıcı silinir (geri alınamaz).</p><?php foreach ($trash as $t): $delTs = strtotime((string) $t['deleted_at']) ?: time(); $purgeTs = $delTs + $ttlDays * 86400; $remainDays = (int) ceil(($purgeTs - time()) / 86400); $urgent = $remainDays <= 3; ?><span class="chip" style="opacity:1;border-color:#e0c9a3"><?= htmlspecialchars($t['label']) ?> <small style="color:#6b7774">(<?= htmlspecialchars($sectionTitles[$t['code']] ?? (string) $t['code']) ?> · <?= (int) $t['affected_count'] ?> ilan · silindi <?= htmlspecialchars(mb_substr((string) $t['deleted_at'], 0, 16)) ?> · <b style="color:<?= $urgent ? '#b0301a' : '#8a6100' ?>">kalıcı silme <?= date('Y-m-d', $purgeTs) ?> (<?= max(0, $remainDays) ?> gün)</b>)</small><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="mini" style="background:#e6f8c7;color:#10211f;border:1px solid #bcd98a" title="Geri yükle">↩ Geri yükle</button></form></span><?php endforeach; ?></div><?php endif; ?>
</main><script>
function bulkRefresh(){var sel=[].filter.call(document.querySelectorAll('.feat-check'),function(x){return x.checked});var c=sel.length;document.getElementById('bulkCount').textContent=c;var bar=document.getElementById('bulkBar');bar.style.display=c?'flex':'none';var on=sel.filter(function(x){return x.dataset.state==='on'}).length,off=c-on;var onBadge=bar.querySelector('[data-b="on"]'),offBadge=bar.querySelector('[data-b="off"]');onBadge.style.display=on?'inline-block':'none';onBadge.textContent='● '+on+' Aktif';offBadge.style.display=off?'inline-block':'none';offBadge.textContent='○ '+off+' Pasif';document.querySelectorAll('.selall').forEach(function(sa){var code=sa.dataset.code,all=document.querySelectorAll('.feat-check[data-code="'+code+'"]'),ch=document.querySelectorAll('.feat-check[data-code="'+code+'"]:checked');sa.checked=all.length>0&&ch.length===all.length})}
function clearBulk(){document.querySelectorAll('.feat-check:checked').forEach(function(c){c.checked=false});bulkRefresh()}
function bulkGo(sub){var ids=[].map.call(document.querySelectorAll('.feat-check:checked'),function(x){return x.value});if(!ids.length)return;if(sub==='delete'&&!confirm(ids.length+' özellik silinecek. Önce etki özeti gösterilecek, devam edilsin mi?'))return;var f=document.getElementById('bulkForm');f.querySelectorAll('input[name="ids[]"]').forEach(function(x){x.remove()});ids.forEach(function(v){var i=document.createElement('input');i.type='hidden';i.name='ids[]';i.value=v;f.appendChild(i)});document.getElementById('bulkSub').value=sub;f.submit()}
document.querySelectorAll('.feat-check').forEach(function(c){c.addEventListener('change',bulkRefresh)});
document.querySelectorAll('.selall').forEach(function(sa){sa.addEventListener('change',function(){var code=sa.dataset.code;document.querySelectorAll('.feat-check[data-code="'+code+'"]').forEach(function(c){c.checked=sa.checked});bulkRefresh()})});
</script><?php require_once __DIR__ . '/../config/ai_widget.php'; ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); ?></body></html>
