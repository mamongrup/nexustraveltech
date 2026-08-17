<?php
$active_module = 'properties';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/../config/product_types.php';
require_once __DIR__ . '/../config/supplier_verification.php';
require_once __DIR__ . '/../config/listing_integrity.php';
$u = $supplier_user;
$types = product_types();
$allowedTypes = supplier_allowed_product_types((int) $u['supplier_id']);
if (!$allowedTypes) {
  header('Location: /nexustraveltech/tedarikci/hesap-dogrulama');
  exit;
}
$types = array_intersect_key($types, array_flip($allowedTypes));
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim((string)($_POST['name'] ?? ''));
  $type = $_POST['property_type'] ?? 'hotel';
  $city = trim((string)($_POST['city'] ?? ''));
  $country = strtoupper(trim((string)($_POST['country_code'] ?? 'TR')));
  $duplicateKey = listing_duplicate_key((string) $type, $name, $city, $country);
  $details = [];
  $fieldError = '';
  if (!isset($types[$type])) { $fieldError = 'Ürün türü geçersiz.'; }
  else {
    foreach ($types[$type]['fields'] as $field) {
      $key = $field['key'];
      $value = trim((string)($_POST['details'][$key] ?? ''));
      if (($field['required'] ?? false) && $value === '') { $fieldError = ($field['label'] ?? $key) . ' alanı zorunludur.'; break; }
      if ($value === '') continue;
      if (($field['type'] ?? '') === 'number') {
        $num = str_replace(',', '.', $value);
        if (!is_numeric($num)) { $fieldError = ($field['label'] ?? $key) . ' alanı sayısal olmalıdır.'; break; }
        $num = (float)$num;
        $min = (float)($field['min'] ?? 0); $max = (float)($field['max'] ?? PHP_FLOAT_MAX);
        if ($num < $min || $num > $max) { $fieldError = ($field['label'] ?? $key) . ' alanı ' . ($field['min'] ?? 0) . '–' . ($field['max'] ?? 'sınırsız') . ' aralığında olmalıdır.'; break; }
        $details[$key] = floor($num) === $num ? (int)$num : $num;
      } else {
        $details[$key] = $value;
      }
    }
  }
  $duplicateCheck = db()->prepare('SELECT id FROM properties WHERE duplicate_key=? AND supplier_id<>? LIMIT 1');
  $duplicateCheck->execute([$duplicateKey, (int) $u['supplier_id']]);
  if ($fieldError !== '') { $error = $fieldError; }
  elseif ($name === '' || !isset($types[$type]) || !supplier_can_add_product_type((int) $u['supplier_id'], (string) $type) || strlen($country) !== 2) {
    $error = 'Lütfen tesis adı, yetkili ürün türü ve ülke bilgisini kontrol edin.';
  } elseif ($duplicateCheck->fetch()) {
    $error = 'Bu ürün tedarik zincirinde zaten kayıtlı. Aynı ilan farklı bir tedarikçi tarafından yeniden eklenemez.';
  } else {
    $pdo = db();
    $pdo->beginTransaction();
    try {
      $q = $pdo->prepare("INSERT INTO properties (supplier_id, property_type, name, city, country_code, product_details, duplicate_key, status) VALUES (?, ?, ?, ?, ?, ?::jsonb, ?, 'draft') RETURNING id");
      $q->execute([$u['supplier_id'], $type, $name, $city ?: null, $country, json_encode($details, JSON_UNESCAPED_UNICODE), $duplicateKey]);
      $propertyId = (int)$q->fetchColumn();
      record_audit_event('supplier', (int) $u['id'], 'property.created', 'property', $propertyId, ['type' => $type, 'duplicate_key' => $duplicateKey]);
      if ($types[$type]['room_setup'] ?? false) {
        $roomNames = $_POST['room_name'] ?? [];
        $roomCapacities = $_POST['room_capacity'] ?? [];
        $roomUnits = $_POST['room_units'] ?? [];
        $roomAreas = $_POST['room_area'] ?? [];
        $roomBeds = $_POST['room_bed'] ?? [];
        $roomBathrooms = $_POST['room_bathrooms'] ?? [];
        $roomEquipment = $_POST['room_equipment'] ?? [];
        $roomInsert = $pdo->prepare('INSERT INTO room_types (property_id, name, capacity_adults, total_units, room_details) VALUES (?, ?, ?, ?, ?)');
        foreach ($roomNames as $index => $roomName) {
          $roomName = trim((string)$roomName);
          if ($roomName === '') continue;
          $capacity = max(1, min(20, (int)($roomCapacities[$index] ?? 2)));
          $units = max(0, min(9999, (int)($roomUnits[$index] ?? 0)));
          $roomDetails = [
            'area_m2' => max(0, (int)($roomAreas[$index] ?? 0)),
            'bed_type' => trim((string)($roomBeds[$index] ?? '')),
            'bathrooms' => max(0, (int)($roomBathrooms[$index] ?? 1)),
            'equipment' => array_values(array_filter($roomEquipment[$index] ?? [], fn($item) => is_string($item) && $item !== '')),
          ];
          $roomInsert->execute([$propertyId, $roomName, $capacity, $units, json_encode($roomDetails, JSON_UNESCAPED_UNICODE)]);
        }
        $board = $details['board'] ?? ($type === 'hotel' ? 'Sadece oda' : 'Standart kiralama');
        $rate = $pdo->prepare('INSERT INTO rate_plans (property_id, name, currency, board_type) VALUES (?, ?, "EUR", ?)');
        $rate->execute([$propertyId, $board . ' Esnek Fiyat', $board]);
      }
      $pdo->commit();
    } catch (Throwable $exception) {
      $pdo->rollBack(); throw $exception;
    }
    $target = $type === 'hotel' ? 'otel-detay?product=' . $propertyId : ($type === 'villa' || $type === 'yacht' ? 'villa-detay?product=' . $propertyId : 'tesisler?created=1');
    // İlk kez gelen tedarikçiyi doğrudan ilk EKSİK bölüme yönlendir (örn. görseller → #sec-05).
    // Sıra: location → description → media (görseller) → rooms/inventory → rates; villa/yat'ta ek pool/liman/mürettebat.
    try {
        $pf = $pdo->prepare('SELECT * FROM properties WHERE id=?');
        $pf->execute([$propertyId]);
        $newProp = $pf->fetch();
        if ($newProp && in_array($type, ['hotel', 'villa', 'yacht'], true)) {
            $secOrder = ['location' => 'sec-01', 'description' => 'sec-02', 'media' => 'sec-05', 'rooms' => 'sec-04', 'inventory' => 'sec-04', 'rates' => 'sec-04'];
            if ($type !== 'hotel') {
                $secOrder['pool'] = 'sec-01';
                $secOrder['home_port'] = 'sec-01';
                $secOrder['crew'] = 'sec-01';
                $secOrder['ical'] = 'sec-04';
            }
            $rd = listing_readiness($newProp);
            foreach ($rd['items'] as $ri) {
                if (empty($ri['ok']) && isset($secOrder[$ri['key']])) {
                    $target .= '#' . $secOrder[$ri['key']];
                    break;
                }
            }
        }
    } catch (Throwable $e) {
        // çapa hesabı başarısız olursa düz yönlendirme korunur
    }
    header('Location: /nexustraveltech/tedarikci/' . $target); exit;
  }
}
supply_start('Yeni tesis ekle', $active_module); ?>
<section class="form-page"><div><p class="crumb">ÜRÜN KURULUMU / ADIM 1</p><h2>Kategoriye göre<br>doğru akış.</h2><p id="type-hint">Ürün türünüzü seçin; NEXUS gerekli operasyon adımlarını buna göre hazırlar.</p><ol class="setup-steps" id="setup-steps"></ol></div><form method="post" class="supply-form"><?php if($error): ?><p class="login-error"><?= htmlspecialchars($error) ?></p><?php endif; ?><div id="sec-01" class="setup-sec"><label>Ürün / işletme adı<input name="name" required maxlength="190" placeholder="Örn. Fethiye Körfez Butik Otel"></label><label>Ürün türü<select name="property_type" id="product-type"><?php foreach($types as $key=>$definition): ?><option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($definition['label']) ?></option><?php endforeach; ?></select></label><div class="form-row"><label>Şehir<input name="city" maxlength="100" placeholder="Örn. Fethiye"></label><label>Ülke kodu<input name="country_code" value="TR" maxlength="2" required></label></div><div id="type-fields"></div></div><div id="sec-02" class="setup-sec" hidden><div id="hotel-rooms" hidden><div class="room-builder-head"><div><p class="field-heading" id="room-builder-title">Oda ve villa tipleri</p><small>Her konaklama birimi için kapasite, m² ve ekipmanları seçin.</small></div><button type="button" id="add-room">+ Tip ekle</button></div><div id="room-list"></div></div></div><div class="form-actions"><a href="/nexustraveltech/tedarikci/tesisler">İptal</a><button type="submit">Ürün oluştur →</button></div></form></section><template id="room-row-template"><div class="room-row"><div class="room-row-top"><label class="room-label-unit">Oda / villa tipi<input name="room_name[]" placeholder="Örn. Deluxe deniz manzaralı oda"></label><label class="room-label-capacity">Yetişkin<input type="number" name="room_capacity[]" min="1" max="20" value="2"></label><label>Toplam adet<input type="number" name="room_units[]" min="0" value="0"></label><label>Alan (m²)<input type="number" name="room_area[]" min="0" placeholder="Örn. 32"></label><button type="button" class="remove-room" aria-label="Oda tipini sil">×</button></div><div class="room-row-bottom"><label>Yatak düzeni<select name="room_bed[]"><option>1 çift kişilik yatak</option><option>2 tek kişilik yatak</option><option>1 çift + 1 tek yatak</option><option>1 çift + 2 tek yatak</option><option>King-size yatak</option><option>Yatak düzeni özel</option></select></label><label>Banyo sayısı<input type="number" name="room_bathrooms[]" min="0" value="1"></label></div><fieldset class="equipment-set"><legend>Oda / villa özellikleri</legend><div class="equipment-options"><label><input type="checkbox" value="Klima">Klima</label><label><input type="checkbox" value="Wi-Fi">Wi-Fi</label><label><input type="checkbox" value="Televizyon">Televizyon</label><label><input type="checkbox" value="Minibar">Minibar</label><label><input type="checkbox" value="Kasa">Kasa</label><label><input type="checkbox" value="Balkon">Balkon</label><label><input type="checkbox" value="Teras">Teras</label><label><input type="checkbox" value="Deniz manzarası">Deniz manzarası</label><label><input type="checkbox" value="Bahçe manzarası">Bahçe manzarası</label><label><input type="checkbox" value="Özel havuz">Özel havuz</label><label><input type="checkbox" value="Jakuzi">Jakuzi</label><label><input type="checkbox" value="Mutfak">Mutfak</label><label><input type="checkbox" value="Çamaşır makinesi">Çamaşır makinesi</label><label><input type="checkbox" value="Engelli erişimi">Engelli erişimi</label></div></fieldset></div></template><script>const productTypes=<?= json_encode($types, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;const roomMeta={hotel:{unit:'Oda tipi',capacity:'Yetişkin',bed:true,defaults:[['Standart oda',2,0],['Aile odası',4,0]],equipment:['Klima','Wi-Fi','Televizyon','Minibar','Kasa','Balkon','Teras','Deniz manzarası','Bahçe manzarası','Özel havuz','Jakuzi','Mutfak','Çamaşır makinesi','Engelli erişimi']},villa:{unit:'Konaklama birimi',capacity:'Misafir',bed:true,defaults:[['Özel havuzlu villa',2,0]],equipment:['Klima','Wi-Fi','Televizyon','Mutfak','Bulaşık makinesi','Çamaşır makinesi','Özel havuz','Jakuzi','Bahçe','Teras','Mangal','Otopark','Güvenlik','Deniz manzarası','Şömine','Engelli erişimi']},yacht:{unit:'Kabin tipi',capacity:'Misafir',bed:false,defaults:[['Deluxe kabin',2,0]],equipment:['Klima','Wi-Fi','Kabin TV','Müzik sistemi','Su sporları ekipmanı','Balıkçılık ekipmanı','Şnorkel','Dalış ekipmanı','Mutfak','Buzdolabı','Barbekü','Yüzme merdiveni','Güneşlenme alanı']}};const picker=document.querySelector('#product-type'),fields=document.querySelector('#type-fields'),hint=document.querySelector('#type-hint'),steps=document.querySelector('#setup-steps'),hotelRooms=document.querySelector('#hotel-rooms'),roomList=document.querySelector('#room-list'),roomTemplate=document.querySelector('#room-row-template'),roomBuilderTitle=document.querySelector('#room-builder-title'),setupSec2=document.querySelector('#sec-02');function esc(v){const d=document.createElement('div');d.textContent=v;return d.innerHTML}function addRoom(name='',capacity=2,units=0){const meta=roomMeta[picker.value]||roomMeta.hotel,fragment=roomTemplate.content.cloneNode(true),row=fragment.querySelector('.room-row'),inputs=row.querySelectorAll('.room-row-top input'),index=roomList.children.length;inputs[0].value=name;inputs[1].value=capacity;inputs[2].value=units;row.querySelector('.room-label-unit').textContent=meta.unit;row.querySelector('.room-label-capacity').textContent=meta.capacity;row.querySelector('.room-row-bottom').hidden=!meta.bed;row.querySelector('.equipment-options').innerHTML=meta.equipment.map(e=>`<label><input type="checkbox" value="${esc(e)}" name="room_equipment[${index}][]">${esc(e)}</label>`).join('');row.querySelector('.remove-room').addEventListener('click',()=>row.remove());roomList.appendChild(fragment)}function render(){const p=productTypes[picker.value];hint.textContent=p.hint;steps.innerHTML=p.steps.map((x,i)=>{const sec=i===0?'sec-01':(i===1&&p.room_setup?'sec-02':null);const cls=(i===0?'current ':'')+(sec?'step-link':'step-later');return sec?`<li class="${cls}"><b>0${i+1}</b><a href="#${sec}">${esc(x)}</a></li>`:`<li class="${cls}"><b>0${i+1}</b>${esc(x)}</li>`}).join('');fields.innerHTML=`<p class="field-heading">${esc(p.label)} için temel bilgiler</p>`+p.fields.map(f=>{let input;if(f.type==='select'){input=`<select name="details[${esc(f.key)}]">${f.options.map(o=>`<option>${esc(o)}</option>`).join('')}</select>`}else{const attrs=[`type="${esc(f.type)}"`,`name="details[${esc(f.key)}]"`];if(f.placeholder)attrs.push(`placeholder="${esc(f.placeholder)}"`);if(f.min!==undefined)attrs.push(`min="${f.min}"`);if(f.max!==undefined)attrs.push(`max="${f.max}"`);if(f.step!==undefined)attrs.push(`step="${f.step}"`);if(f.required)attrs.push('required');input=`<input ${attrs.join(' ')}>`}return `<label>${esc(f.label)}${input}</label>`}).join('');hotelRooms.hidden=!p.room_setup;if(setupSec2)setupSec2.hidden=!p.room_setup;const meta=roomMeta[picker.value]||roomMeta.hotel;if(roomBuilderTitle)roomBuilderTitle.textContent=meta.unit+' tipleri';if(p.room_setup&&!roomList.children.length){meta.defaults.forEach(d=>addRoom(d[0],d[1],d[2]))}}picker.addEventListener('change',render);document.querySelector('#add-room').addEventListener('click',()=>addRoom());render();steps.addEventListener('click',function(e){const a=e.target.closest('a');if(!a)return;e.preventDefault();const el=document.querySelector(a.getAttribute('href'));if(el){const y=el.getBoundingClientRect().top+window.scrollY-110;window.scrollTo({top:y,behavior:'smooth'})}});if(location.hash&&document.querySelector(location.hash)){setTimeout(function(){const hEl=document.querySelector(location.hash);const hy=hEl.getBoundingClientRect().top+window.scrollY-110;window.scrollTo({top:hy,behavior:'smooth'})},80)}</script><?php supply_end(); ?>
