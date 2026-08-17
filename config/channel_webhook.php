<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/fx.php';
require_once __DIR__ . '/platform_settings.php';

/**
 * Kanal webhook yükünü NEXUS takvimi/fiyatlarına uygular.
 *
 * Beklenen yük (api/channel-webhook üzerinden gelen JSON):
 * {
 *   "scope": "availability" | "rates" | "restrictions" | "reservations",
 *   "external_property_id": "kanal-otel-kodu",
 *   "entries": [
 *     {
 *       "external_room_id": "kanal-oda-kodu",   // opsiyonel — boşsa ilanın ilk aktif oda tipi;
 *                                                 //   tanınmayan kod varsa (ayar açıksa) ilk aktif oda tipine otomatik eşleştirilir
 *       "date": "2026-09-01",                    // zorunlu
 *       "price": 185.50,                          // rates: gece fiyatı (opsiyonel)
 *       "currency": "EUR",                        // opsiyonel — fiyatın geldiği birim; yoksa yük/ayar varsayılanı kullanılır
 *                                                 //   ve takvimin fiyat planı birimine fx_rates üzerinden çevrilir
 *       "allotment": 5,                           // availability: kontenjan (opsiyonel)
 *       "stop_sale": false,                       // restrictions (opsiyonel)
 *       "min_stay": 2,                            // restrictions (opsiyonel)
 *       "max_stay": 7                             // restrictions (opsiyonel)
 *     }, ...
 *   ]
 * }
 *
 * @return array{ok: bool, message: string, applied: int, errors: string[]}
 */
function channel_webhook_apply(array $log, array $payload): array
{
    $pdo = db();
    $connId = (int) $log['channel_connection_id'];
    $propertyId = (int) ($log['property_id'] ?? 0);
    $scope = (string) ($payload['scope'] ?? ($log['scope'] ?? 'content'));
    $errors = [];
    // Tanınmayan dış oda kodu gelince ilk aktif oda tipiyle otomatik eşleştirme (admin -> Kontrol merkezi).
    $autoMap = (bool) platform_setting('channel_webhook_auto_map', true);
    $autoMapSt = $pdo->prepare('INSERT INTO channel_room_mappings(channel_connection_id, property_id, room_type_id, external_room_id) VALUES(?,?,?,?) ON CONFLICT(channel_connection_id, external_room_id) DO NOTHING');
    // Fiyatların varsayılan geldiği birim (admin -> Kontrol merkezi; kanal currency göndermezse kullanılır).
    $defaultCurrency = strtoupper((string) platform_setting('channel_webhook_default_currency', 'EUR'));
    if (!preg_match('/^[A-Z]{3}$/', $defaultCurrency)) $defaultCurrency = 'EUR';
    $payloadCurrency = strtoupper((string) ($payload['currency'] ?? ''));
    if (!preg_match('/^[A-Z]{3}$/', $payloadCurrency)) $payloadCurrency = '';

    if ($propertyId <= 0) {
        return ['ok' => false, 'message' => 'İlan eşleştirmesi yok — external_property_id, channel_property_mappings içinde tanımlı değil.', 'applied' => 0, 'errors' => ['property_not_mapped']];
    }
    if (!in_array($scope, ['availability', 'rates', 'restrictions', 'reservations'], true)) {
        return ['ok' => false, 'message' => 'Desteklenmeyen kapsam: ' . $scope, 'applied' => 0, 'errors' => ['unsupported_scope']];
    }

    // İlanın aktif oda tipleri ve fiyat planları.
    $rooms = $pdo->prepare("SELECT id, name FROM room_types WHERE property_id=? AND status='active' ORDER BY id");
    $rooms->execute([$propertyId]);
    $roomList = $rooms->fetchAll();
    if (!$roomList) {
        return ['ok' => false, 'message' => 'İlanda aktif oda/birim tipi yok — senkronize edilecek hedef yok.', 'applied' => 0, 'errors' => ['no_rooms']];
    }
    $plans = $pdo->prepare("SELECT id, name, currency FROM rate_plans WHERE property_id=? AND status='active' ORDER BY id LIMIT 1");
    $plans->execute([$propertyId]);
    $plan = $plans->fetch();
    if (!$plan) {
        return ['ok' => false, 'message' => 'İlanda aktif fiyat planı yok — fiyat/kontenjan yazılamaz.', 'applied' => 0, 'errors' => ['no_rate_plan']];
    }
    $targetCurrency = strtoupper((string) ($plan['currency'] ?? 'EUR'));
    if (!preg_match('/^[A-Z]{3}$/', $targetCurrency)) $targetCurrency = 'EUR';

    // Oda eşleştirmeleri (kanal dış kodu -> NEXUS room_type).
    $mapSt = $pdo->prepare('SELECT room_type_id, external_room_id FROM channel_room_mappings WHERE channel_connection_id=? AND property_id=?');
    $mapSt->execute([$connId, $propertyId]);
    $roomMap = [];
    foreach ($mapSt->fetchAll() as $m) {
        $roomMap[(string) $m['external_room_id']] = (int) $m['room_type_id'];
    }
    $fallbackRoom = (int) $roomList[0]['id'];
    $autoMapped = 0;
    $roomIdFor = function (string $ext) use ($roomMap, $fallbackRoom, $autoMapSt, $connId, $propertyId, &$autoMapped): int {
        if (isset($roomMap[$ext])) {
            return $roomMap[$ext];
        }
        // Tanınmayan kod: ilk aktif oda tipine otomatik eşleştir ve kalıcı yaz.
        if ($autoMap && $ext !== '') {
            $autoMapSt->execute([$connId, $propertyId, $fallbackRoom, $ext]);
            $roomMap[$ext] = $fallbackRoom;
            $autoMapped++;
        }
        return $fallbackRoom;
    };

    $entries = $payload['entries'] ?? null;
    if (!is_array($entries) || $entries === []) {
        return ['ok' => false, 'message' => 'Yük içinde entries bulunamadı.', 'applied' => 0, 'errors' => ['empty_entries']];
    }

    $upsert = $pdo->prepare(
        "INSERT INTO inventory_calendar(room_type_id, rate_plan_id, stay_date, allotment, sold, base_price, min_stay, max_stay, stop_sale)
         VALUES (?,?,?,?,?,?,?,?,?)
         ON CONFLICT(room_type_id, rate_plan_id, stay_date) DO UPDATE SET
           allotment = EXCLUDED.allotment,
           base_price = EXCLUDED.base_price,
           min_stay = EXCLUDED.min_stay,
           max_stay = EXCLUDED.max_stay,
           stop_sale = EXCLUDED.stop_sale"
    );
    $sellSt = $pdo->prepare('UPDATE inventory_calendar SET sold = sold + ? WHERE room_type_id=? AND rate_plan_id=? AND stay_date=?');

    $applied = 0;
    $limit = 2000; // Tek webhook için güvenlik sınırı.
    $dateMin = strtotime('today');
    $dateMax = strtotime('+730 days');

    foreach ($entries as $entry) {
        if ($applied >= $limit) {
            $errors[] = 'limit_exceeded';
            break;
        }
        if (!is_array($entry)) continue;
        $date = (string) ($entry['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $errors[] = 'invalid_date:' . $date;
            continue;
        }
        $ts = strtotime($date);
        if ($ts === false || $ts < $dateMin || $ts > $dateMax) {
            $errors[] = 'out_of_range:' . $date;
            continue;
        }

        $roomId = $roomIdFor((string) ($entry['external_room_id'] ?? ''));

        if ($scope === 'reservations') {
            $qty = max(1, (int) ($entry['qty'] ?? 1));
            $sellSt->execute([$qty, $roomId, (int) $plan['id'], $date]);
            $applied++;
            continue;
        }

        $base = [
            'allotment' => 0,
            'base_price' => 0.0,
            'min_stay' => 1,
            'max_stay' => null,
            'stop_sale' => false,
        ];
        // Kısmi güncellemeyi koru: mevcut satırı oku, gönderilmeyen alanları koru.
        $cur = $pdo->prepare('SELECT allotment, base_price, min_stay, max_stay, stop_sale FROM inventory_calendar WHERE room_type_id=? AND rate_plan_id=? AND stay_date=?');
        $cur->execute([$roomId, (int) $plan['id'], $date]);
        $existing = $cur->fetch();
        if ($existing) {
            $base['allotment'] = (int) $existing['allotment'];
            $base['base_price'] = (float) $existing['base_price'];
            $base['min_stay'] = (int) $existing['min_stay'];
            $base['max_stay'] = $existing['max_stay'] !== null ? (int) $existing['max_stay'] : null;
            $base['stop_sale'] = (bool) $existing['stop_sale'];
        }

        if ($scope === 'rates' || $scope === 'availability') {
            if (array_key_exists('allotment', $entry)) {
                $base['allotment'] = max(0, (int) $entry['allotment']);
            }
            if ($scope === 'rates' && array_key_exists('price', $entry)) {
                $rawPrice = max(0, (float) str_replace(',', '.', (string) $entry['price']));
                // Fiyatın geldiği birim: entry -> yük -> ayar varsayılanı.
                $inCur = strtoupper((string) ($entry['currency'] ?? ($payloadCurrency !== '' ? $payloadCurrency : $defaultCurrency)));
                if (!preg_match('/^[A-Z]{3}$/', $inCur)) $inCur = $defaultCurrency;
                if ($inCur !== $targetCurrency) {
                    // Takvimin fiyat planı birimine çevir; kur yoksa bu satırı yazma (yanlış birimde fiyat girilmesin).
                    $rate = fx_rate($inCur, $targetCurrency, $date);
                    if ($rate <= 0) {
                        $errors[] = 'fx_rate_missing:' . $inCur . '->' . $targetCurrency . ':' . $date;
                        continue;
                    }
                    $base['base_price'] = fx_convert_amount($rawPrice, $inCur, $targetCurrency, $rate);
                } else {
                    $base['base_price'] = $rawPrice;
                }
            }
        }
        if ($scope === 'restrictions') {
            if (array_key_exists('stop_sale', $entry)) {
                $base['stop_sale'] = (bool) $entry['stop_sale'];
            }
            if (array_key_exists('min_stay', $entry)) {
                $base['min_stay'] = max(1, min(365, (int) $entry['min_stay']));
            }
            if (array_key_exists('max_stay', $entry)) {
                $base['max_stay'] = $entry['max_stay'] === null || $entry['max_stay'] === '' ? null : max(1, min(365, (int) $entry['max_stay']));
            }
        }

        $upsert->execute([$roomId, (int) $plan['id'], $date, $base['allotment'], 0, $base['base_price'], $base['min_stay'], $base['max_stay'], $base['stop_sale']]);
        $applied++;
    }

    if ($applied === 0) {
        return ['ok' => false, 'message' => 'Hiçbir satır uygulanamadı. ' . implode('; ', array_slice($errors, 0, 5)), 'applied' => 0, 'errors' => $errors];
    }
    return ['ok' => true, 'message' => $applied . ' gün ' . $scope . ' kapsamında uygulandı' . ($autoMapped > 0 ? ' (+' . $autoMapped . ' yeni dış kod ilk aktif oda tipine otomatik eşleştirildi)' : '') . '.', 'applied' => $applied, 'errors' => $errors, 'auto_mapped' => $autoMapped];
}
