<?php
$active_module = 'properties';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/../config/product_types.php';
$u = $supplier_user;
$types = product_types();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim((string)($_POST['name'] ?? ''));
  $type = $_POST['property_type'] ?? 'hotel';
  $city = trim((string)($_POST['city'] ?? ''));
  $country = strtoupper(trim((string)($_POST['country_code'] ?? 'TR')));
  if ($name === '' || !isset($types[$type]) || strlen($country) !== 2) {
    $error = 'Lütfen tesis adı, türü ve ülke bilgisini kontrol edin.';
  } else {
    $details = [];
    foreach ($types[$type]['fields'] as $field) {
      $value = trim((string)($_POST['details'][$field['key']] ?? ''));
      if ($value !== '') $details[$field['key']] = $value;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
      $q = $pdo->prepare("INSERT INTO properties (supplier_id, property_type, name, city, country_code, product_details, status) VALUES (?, ?, ?, ?, ?, ?::jsonb, 'draft') RETURNING id");
      $q->execute([$u['supplier_id'], $type, $name, $city ?: null, $country, json_encode($details, JSON_UNESCAPED_UNICODE)]);
      $propertyId = (int)$q->fetchColumn();
      if ($type === 'hotel') {
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
        $board = $details['board'] ?? 'Sadece oda';
        $rate = $pdo->prepare('INSERT INTO rate_plans (property_id, name, currency, board_type) VALUES (?, ?, "EUR", ?)');
        $rate->execute([$propertyId, $board . ' Esnek Fiyat', $board]);
      }
      $pdo->commit();
    } catch (Throwable $exception) {
      $pdo->rollBack(); throw $exception;
    }
    header('Location: /nexustraveltech/tedarikci/' . ($type === 'hotel' ? 'otel-detay?product=' . $propertyId : 'tesisler?created=1')); exit;
  }
}
supply_start('Yeni tesis ekle', $active_module); ?>
<section class="form-page"><div><p class="crumb">ÜRÜN KURULUMU / ADIM 1</p><h2>Kategoriye göre<br>doğru akış.</h2><p id="type-hint">Ürün türünüzü seçin; NEXUS gerekli operasyon adımlarını buna göre hazırlar.</p><ol class="setup-steps" id="setup-steps"></ol></div><form method="post" class="supply-form"><?php if($error): ?><p class="login-error"><?= htmlspecialchars($error) ?></p><?php endif; ?><label>Ürün / işletme adı<input name="name" required maxlength="190" placeholder="Örn. Fethiye Körfez Butik Otel"></label><label>Ürün türü<select name="property_type" id="product-type"><?php foreach($types as $key=>$definition): ?><option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($definition['label']) ?></option><?php endforeach; ?></select></label><div class="form-row"><label>Şehir<input name="city" maxlength="100" placeholder="Örn. Fethiye"></label><label>Ülke kodu<input name="country_code" value="TR" maxlength="2" required></label></div><div id="type-fields"></div><div id="hotel-rooms" hidden><div class="room-builder-head"><div><p class="field-heading">Oda ve villa tipleri</p><small>Her konaklama birimi için kapasite, m² ve ekipmanları seçin.</small></div><button type="button" id="add-room">+ Tip ekle</button></div><div id="room-list"></div></div><div class="form-actions"><a href="/nexustraveltech/tedarikci/tesisler">İptal</a><button type="submit">Ürün oluştur →</button></div></form></section><template id="room-row-template"><div class="room-row"><div class="room-row-top"><label>Oda / villa tipi<input name="room_name[]" placeholder="Örn. Deluxe deniz manzaralı oda"></label><label>Yetişkin<input type="number" name="room_capacity[]" min="1" max="20" value="2"></label><label>Toplam adet<input type="number" name="room_units[]" min="0" value="0"></label><label>Alan (m²)<input type="number" name="room_area[]" min="0" placeholder="Örn. 32"></label><button type="button" class="remove-room" aria-label="Oda tipini sil">×</button></div><div class="room-row-bottom"><label>Yatak düzeni<select name="room_bed[]"><option>1 çift kişilik yatak</option><option>2 tek kişilik yatak</option><option>1 çift + 1 tek yatak</option><option>1 çift + 2 tek yatak</option><option>King-size yatak</option><option>Yatak düzeni özel</option></select></label><label>Banyo sayısı<input type="number" name="room_bathrooms[]" min="0" value="1"></label></div><fieldset class="equipment-set"><legend>Oda / villa özellikleri</legend><div class="equipment-options"><label><input type="checkbox" value="Klima">Klima</label><label><input type="checkbox" value="Wi-Fi">Wi-Fi</label><label><input type="checkbox" value="Televizyon">Televizyon</label><label><input type="checkbox" value="Minibar">Minibar</label><label><input type="checkbox" value="Kasa">Kasa</label><label><input type="checkbox" value="Balkon">Balkon</label><label><input type="checkbox" value="Teras">Teras</label><label><input type="checkbox" value="Deniz manzarası">Deniz manzarası</label><label><input type="checkbox" value="Bahçe manzarası">Bahçe manzarası</label><label><input type="checkbox" value="Özel havuz">Özel havuz</label><label><input type="checkbox" value="Jakuzi">Jakuzi</label><label><input type="checkbox" value="Mutfak">Mutfak</label><label><input type="checkbox" value="Çamaşır makinesi">Çamaşır makinesi</label><label><input type="checkbox" value="Engelli erişimi">Engelli erişimi</label></div></fieldset></div></template><script>const productTypes=<?= json_encode($types, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;const picker=document.querySelector('#product-type'),fields=document.querySelector('#type-fields'),hint=document.querySelector('#type-hint'),steps=document.querySelector('#setup-steps'),hotelRooms=document.querySelector('#hotel-rooms'),roomList=document.querySelector('#room-list'),roomTemplate=document.querySelector('#room-row-template');function esc(v){const d=document.createElement('div');d.textContent=v;return d.innerHTML}function addRoom(name='',capacity=2,units=0){const fragment=roomTemplate.content.cloneNode(true),row=fragment.querySelector('.room-row'),inputs=fragment.querySelectorAll('input'),index=roomList.children.length;inputs[0].value=name;inputs[1].value=capacity;inputs[2].value=units;fragment.querySelectorAll('.equipment-options input').forEach(input=>input.name=`room_equipment[${index}][]`);row.querySelector('.remove-room').addEventListener('click',()=>row.remove());roomList.appendChild(fragment)}function render(){const p=productTypes[picker.value];hint.textContent=p.hint;steps.innerHTML=p.steps.map((x,i)=>`<li class="${i===0?'current':''}"><b>0${i+1}</b>${esc(x)}</li>`).join('');fields.innerHTML=`<p class="field-heading">${esc(p.label)} için temel bilgiler</p>`+p.fields.map(f=>{const input=f.type==='select'?`<select name="details[${esc(f.key)}]">${f.options.map(o=>`<option>${esc(o)}</option>`).join('')}</select>`:`<input type="${esc(f.type)}" name="details[${esc(f.key)}]" ${f.placeholder?`placeholder="${esc(f.placeholder)}"`:''}>`;return `<label>${esc(f.label)}${input}</label>`}).join('');hotelRooms.hidden=!p.room_setup;if(p.room_setup&&!roomList.children.length){addRoom('Standart oda',2,0);addRoom('Aile odası',4,0)}}picker.addEventListener('change',render);document.querySelector('#add-room').addEventListener('click',()=>addRoom());render();</script><?php supply_end(); ?>
