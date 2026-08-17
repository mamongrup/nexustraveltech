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
 *                                                 //   tanınmayan kod varsa (ayar açıksa) onay bekleyen öneri oluşturulur,
 *                                                 //   satır yazılmaz (dağıtım merkezi bölüm 3'ten onaylanır)
 *       "external_rate_plan_id": "kanal-plan-kodu", // opsiyonel — boşsa oda eşleştirmesindeki plan / ilk aktif plan;
 *                                                 //   tanınmayan kod varsa (ayar açıksa) oda eşleştirmesiyle aynı onay akışı:
 *                                                 //   onay bekleyen fiyat planı önerisi oluşturulur, satır yazılmaz
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
    // Tanınmayan dış oda kodu: onay bekleyen eşleştirme önerisi oluştur (admin -> Kontrol merkezi; onay dağıtım merkezi bölüm 3).
    // Ayar kapalıysa eski güvenli varsayılan korunur: ilk aktif oda tipine yazılır (öneri oluşturulmaz).
    $autoMap = (bool) platform_setting('channel_webhook_auto_map', true);
    // Reddedilen kod karalistesi — aynı kod tekrar gelirse yeniden öneri oluşturulmaz
    // (manuel eşleştirme gerekir; elle kaydetmek karalisteyi otomatik temizler).
    $blacklist = ['room' => [], 'plan' => []];
    try {
        $blSt = $pdo->prepare('SELECT code_type, external_code FROM channel_mapping_blacklist WHERE channel_connection_id=?');
        $blSt->execute([$connId]);
        foreach ($blSt->fetchAll() as $bl) {
            $blacklist[(string) $bl['code_type']][(string) $bl['external_code']] = true;
        }
    } catch (Throwable $e) {
        // Karaliste tablosu yoksa sessiz geç (migration bekliyor) — eski davranış korunur.
    }
    $suggestSt = $pdo->prepare("INSERT INTO channel_room_mappings(channel_connection_id, property_id, room_type_id, rate_plan_id, external_room_id, status, suggested_at, suggestion_count, suggestion_score)
        VALUES(?,?,?,?,?,'suggested',now(),1,?)
        ON CONFLICT(channel_connection_id, external_room_id) DO UPDATE SET status='suggested', suggested_at=now(), suggestion_count=channel_room_mappings.suggestion_count+1, room_type_id=EXCLUDED.room_type_id, property_id=EXCLUDED.property_id, rate_plan_id=EXCLUDED.rate_plan_id, suggestion_score=EXCLUDED.suggestion_score");
    // Tanınmayan dış fiyat planı kodu: oda eşleştirmesiyle aynı onay akışı — status='suggested' satır,
    // dağıtım merkezi bölüm 3'ten onaylanır/reddedilir; onaylanana kadar o koda ait satır yazılmaz.
    $planSuggestSt = $pdo->prepare("INSERT INTO channel_rate_plan_mappings(channel_connection_id, property_id, rate_plan_id, external_rate_plan_id, status, suggested_at, suggestion_count, suggestion_score)
        VALUES(?,?,?,?,'suggested',now(),1,?)
        ON CONFLICT(channel_connection_id, external_rate_plan_id) DO UPDATE SET status='suggested', suggested_at=now(), suggestion_count=channel_rate_plan_mappings.suggestion_count+1, rate_plan_id=EXCLUDED.rate_plan_id, property_id=EXCLUDED.property_id, suggestion_score=EXCLUDED.suggestion_score");
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
    $plans = $pdo->prepare("SELECT id, name, currency FROM rate_plans WHERE property_id=? AND status='active' ORDER BY id");
    $plans->execute([$propertyId]);
    $planRows = $plans->fetchAll();
    if (!$planRows) {
        return ['ok' => false, 'message' => 'İlanda aktif fiyat planı yok — fiyat/kontenjan yazılamaz.', 'applied' => 0, 'errors' => ['no_rate_plan']];
    }
    // Eşleştirmede plan belirtilmezse ilk aktif plan kullanılır (geriye dönük uyumlu).
    $fallbackPlan = $planRows[0];
    $plansById = [];
    foreach ($planRows as $pr) {
        $plansById[(int) $pr['id']] = $pr;
    }

    // Oda eşleştirmeleri (kanal dış kodu -> NEXUS room_type + rate_plan çifti).
    $mapSt = $pdo->prepare('SELECT room_type_id, rate_plan_id, external_room_id FROM channel_room_mappings WHERE channel_connection_id=? AND property_id=?');
    $mapSt->execute([$connId, $propertyId]);
    $roomMap = [];
    foreach ($mapSt->fetchAll() as $m) {
        $roomMap[(string) $m['external_room_id']] = [
            'room' => (int) $m['room_type_id'],
            'plan' => $m['rate_plan_id'] !== null ? (int) $m['rate_plan_id'] : null,
        ];
    }
    // Fiyat planı eşleştirmeleri (kanal dış fiyat planı kodu -> NEXUS rate_plan; yalnızca onaylılar).
    $planMapSt = $pdo->prepare("SELECT rate_plan_id, external_rate_plan_id FROM channel_rate_plan_mappings WHERE channel_connection_id=? AND property_id=? AND status='confirmed' AND rate_plan_id IS NOT NULL");
    $planMapSt->execute([$connId, $propertyId]);
    $planMap = [];
    foreach ($planMapSt->fetchAll() as $pm) {
        $planMap[(string) $pm['external_rate_plan_id']] = (int) $pm['rate_plan_id'];
    }
    // İsim benzerliği: dış kod ile oda tipi adını karşılaştırır (Türkçe normalizasyon + token/bigram).
    $nameSim = function (string $a, string $b): float {
        $norm = fn(string $s): string => strtolower(preg_replace('/[^a-z0-9 ]+/i', ' ', strtr($s, ['ç' => 'c', 'Ç' => 'C', 'ğ' => 'g', 'Ğ' => 'G', 'ı' => 'i', 'İ' => 'i', 'I' => 'i', 'ö' => 'o', 'Ö' => 'O', 'ş' => 's', 'Ş' => 'S', 'ü' => 'u', 'Ü' => 'U'])));
        $at = preg_split('/\s+/', trim((string) $norm($a))) ?: [];
        $bt = preg_split('/\s+/', trim((string) $norm($b))) ?: [];
        $at = array_values(array_filter($at, fn($t) => $t !== ''));
        $bt = array_values(array_filter($bt, fn($t) => $t !== ''));
        if (!$at || !$bt) return 0.0;
        // Token eşleşmesi: ortak token / toplam (kısmi token eşleşmesi dahil).
        $tokScore = 0.0;
        foreach ($at as $ta) {
            $best = 0.0;
            foreach ($bt as $tb) {
                $la = strlen($ta);
                $lb = strlen($tb);
                if ($la === 0 || $lb === 0) continue;
                if ($ta === $tb) { $best = 1.0; break; }
                if (str_starts_with($ta, $tb) || str_starts_with($tb, $ta)) { $best = max($best, 0.8); continue; }
                // bigram Jaccard
                $big = function (string $t): array { $g = []; for ($i = 0; $i < strlen($t) - 1; $i++) $g[] = substr($t, $i, 2); return $g; };
                $ga = $big($ta); $gb = $big($tb);
                $inter = count(array_intersect($ga, $gb));
                $union = count(array_unique(array_merge($ga, $gb)));
                if ($union > 0) $best = max($best, $inter / $union);
            }
            $tokScore += $best;
        }
        $tokScore = $tokScore / max(1, count($at));
        // Karakter düzeyi benzerlik (tam kod/kelime uzunluğu küçükse anlamlı).
        $len = max(strlen((string) $norm($a)), strlen((string) $norm($b)));
        $lev = $len > 0 ? 1 - (levenshtein(trim((string) $norm($a)), trim((string) $norm($b))) / $len) : 0.0;
        return max($tokScore, $lev);
    };
    // En iyi eşleşme: skoru en yüksek aktif oda tipi (eşik 0.45); altındaysa ilk aktif tip (eski davranış).
    $bestRoom = (int) $roomList[0]['id'];
    $bestScore = 0.0;
    $roomByName = [];
    foreach ($roomList as $rl) {
        $roomByName[(int) $rl['id']] = (string) $rl['name'];
    }
    $bestRoomFor = function (string $ext) use ($roomList, $nameSim, $bestRoom, &$bestScore): array {
        $pick = $bestRoom;
        $score = 0.0;
        foreach ($roomList as $rl) {
            $s = $nameSim($ext, (string) $rl['name']);
            if ($s > $score) { $score = $s; $pick = (int) $rl['id']; }
        }
        $bestScore = $score >= 0.45 ? $score : 0.0;
        return ['room' => $pick, 'score' => (int) round($bestScore * 100)];
    };
    // En iyi fiyat planı eşleşmesi: skoru en yüksek aktif plan (eşik 0.45).
    $planNames = [];
    foreach ($planRows as $pl) {
        $planNames[(int) $pl['id']] = (string) $pl['name'];
    }
    $bestPlanFor = function (string $ext) use ($planRows, $nameSim): array {
        $pick = (int) $planRows[0]['id'];
        $score = 0.0;
        foreach ($planRows as $pl) {
            $s = $nameSim($ext, (string) $pl['name']);
            if ($s > $score) { $score = $s; $pick = (int) $pl['id']; }
        }
        $use = $score >= 0.45 ? $score : 0.0;
        return ['plan' => $pick, 'score' => (int) round($use * 100)];
    };
    $suggestedCount = 0;
    // Tedarikçi bildirimi: öneri İLK kez oluştuğunda hangi kodun hangi oda tipine önerildiği panel bildirimi olarak gider.
    // Tekrar gelen aynı kod yalnızca suggestion_count artırır; bildirim tekrarlanmaz (spam yok).
    require_once __DIR__ . '/notifications.php';
    $supplierSt = $pdo->prepare('SELECT supplier_id FROM properties WHERE id=?');
    $supplierSt->execute([$propertyId]);
    $supplierId = (int) ($supplierSt->fetchColumn() ?: 0);
    $roomResolve = function (string $ext, ?string $planHint = null) use ($roomMap, $bestRoom, $blacklist, $suggestSt, $planSuggestSt, $planMap, $bestPlanFor, $planNames, $connId, $propertyId, $autoMap, &$suggestedCount, $supplierId, $bestRoomFor, $roomByName): array {
        if (isset($roomMap[$ext])) {
            return $roomMap[$ext];
        }
        // Reddedilmiş kod — yeniden öneri oluşturulmaz, veri yazılmaz (manuel eşleştirme gerekir).
        if (isset($blacklist['room'][$ext])) {
            $errors[] = 'blacklisted_room:' . $ext;
            $roomMap[$ext] = ['room' => 0, 'plan' => null];
            return ['room' => 0, 'plan' => null];
        }
        // Tanınmayan kod: ilk aktif oda tipine yazmak yerine isim benzerliğine göre EN İYİ
        // eşleşen oda tipine onay bekleyen öneri oluştur. Kanal plan ipucu (external_rate_plan_id)
        // varsa ilk aktif plan yerine ona göre plan önerisi de yapılır — onayda plan hazır gelir.
        if ($autoMap && $ext !== '') {
            $match = $bestRoomFor($ext);
            $planId = null;
            $planHintTrim = trim((string) ($planHint ?? ''));
            if ($planHintTrim !== '') {
                if (array_key_exists($planHintTrim, $planMap)) {
                    $planId = $planMap[$planHintTrim]; // onaylı plan eşleşmesi — doğrudan kullan
                } else {
                    $planMatch = $bestPlanFor($planHintTrim);
                    $planId = (int) $planMatch['plan'];
                    // Plan eşleşmesi de öneri olarak kaydedilir — dağıtım merkezi bölüm 3'te
                    // "onay bekleyen fiyat planı" listesinde görünür, onaylanana kadar yazılmaz.
                    $planSuggestSt->execute([$connId, $propertyId, $planId, $planHintTrim, $planMatch['score'] > 0 ? $planMatch['score'] : null]);
                    if ($supplierId > 0 && (int) $planSuggestSt->rowCount() === 1) {
                        notify_supplier_users_with_email($supplierId, 'channel_plan_mapping_suggestion',
                            'Kanal webhook\'undan tanınmayan fiyat planı kodu geldi: "' . $planHintTrim . '" → "' . ($planNames[$planId] ?? ('#' . $planId)) . '" için eşleştirme önerisi oluşturuldu (oda önerisiyle birlikte). Veri onaylanana kadar yazılmadı.',
                            '/nexustraveltech/tedarikci/dagitim-merkezi',
                            'NEXUS: onay bekleyen fiyat planı eşleştirme önerisi');
                    }
                }
            }
            $suggestSt->execute([$connId, $propertyId, $match['room'], $planId, $ext, $match['score'] > 0 ? $match['score'] : null]);
            if ($supplierId > 0 && (int) $suggestSt->rowCount() === 1) {
                // rowCount 1 = yeni INSERT (ilk kez); 2 = ON CONFLICT güncellemesi (tekrar) → bildirim yalnızca ilkinde.
                notify_supplier_users_with_email($supplierId, 'channel_mapping_suggestion',
                    'Kanal webhook\'undan tanınmayan oda kodu geldi: "' . $ext . '" → "' . ($roomByName[$match['room']] ?? ('#' . $match['room'])) . '" için eşleştirme önerisi oluşturuldu' . ($match['score'] > 0 ? ' (benzerlik %' . $match['score'] . ')' : '') . ($planId ? ' · plan: ' . ($planNames[$planId] ?? ('#' . $planId)) : '') . '. Veri onaylanana kadar yazılmadı.',
                    '/nexustraveltech/tedarikci/dagitim-merkezi',
                    'NEXUS: onay bekleyen eşleştirme önerisi');
            }
            $roomMap[$ext] = ['room' => 0, 'plan' => null]; // bu yükte bir daha deneme
            $suggestedCount++;
            return $roomMap[$ext];
        }
        // Ayar kapalı: eski davranış — ilk aktif oda tipine yaz (kalıcı eşleştirme oluşturulmaz).
        return ['room' => $bestRoom, 'plan' => null];
    };
    $suggestedPlanCount = 0;
    // Dış fiyat planı kodu çözümü: boşsa oda eşleştirmesindeki plan / ilk aktif plan;
    // tanınmayan kod (ayar açıkken) isim benzerliğine göre onay bekleyen öneri oluşturur, satır yazılmaz.
    $planResolve = function (string $ext) use (&$planMap, $plansById, $planSuggestSt, $connId, $propertyId, $autoMap, &$suggestedPlanCount, $supplierId, $bestPlanFor, $planNames, $blacklist): array {
        if ($ext === '') {
            return ['skip' => false, 'plan' => null];
        }
        if (array_key_exists($ext, $planMap)) {
            $pid = $planMap[$ext];
            if ($pid < 0) {
                return ['skip' => true, 'plan' => null]; // bu yükte aynı kod tekrar denendi
            }
            return ['skip' => false, 'plan' => isset($plansById[$pid]) ? $plansById[$pid] : null];
        }
        // Reddedilmiş fiyat planı kodu — yeniden öneri yok, veri yazılmaz.
        if (isset($blacklist['plan'][$ext])) {
            $errors[] = 'blacklisted_plan:' . $ext;
            $planMap[$ext] = -1;
            return ['skip' => true, 'plan' => null];
        }
        if ($autoMap) {
            $match = $bestPlanFor($ext);
            $planSuggestSt->execute([$connId, $propertyId, $match['plan'], $ext, $match['score'] > 0 ? $match['score'] : null]);
            if ($supplierId > 0 && (int) $planSuggestSt->rowCount() === 1) {
                // rowCount 1 = yeni INSERT (ilk kez); 2 = ON CONFLICT güncellemesi (tekrar) → bildirim yalnızca ilkinde.
                notify_supplier_users_with_email($supplierId, 'channel_plan_mapping_suggestion',
                    'Kanal webhook\'undan tanınmayan fiyat planı kodu geldi: "' . $ext . '" → "' . ($planNames[$match['plan']] ?? ('#' . $match['plan'])) . '" için eşleştirme önerisi oluşturuldu' . ($match['score'] > 0 ? ' (benzerlik %' . $match['score'] . ')' : '') . '. Veri onaylanana kadar yazılmadı.',
                    '/nexustraveltech/tedarikci/dagitim-merkezi',
                    'NEXUS: onay bekleyen fiyat planı eşleştirme önerisi');
            }
            $planMap[$ext] = -1; // bu yükte bir daha öneri/deneme yok
            $suggestedPlanCount++;
            return ['skip' => true, 'plan' => null];
        }
        // Ayar kapalı: eski davranış — oda eşleştirmesindeki plan / ilk aktif plan kullanılır.
        return ['skip' => false, 'plan' => null];
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
    $fxAudit = []; // dönüştürülen fiyatların orijinal/hedef birimi — denetim için channel_sync_logs.fx_audit'e yazılır.
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

        $roomRes = $roomResolve((string) ($entry['external_room_id'] ?? ''), (string) ($entry['external_rate_plan_id'] ?? ''));
        $roomId = $roomRes['room'];
        if ($roomId <= 0) {
            continue; // onay bekleyen öneri — veri yazılmadı
        }
        // Dış fiyat planı kodu (opsiyonel): kanal dış plan kodu -> NEXUS plan.
        // Tanınmayan kod, oda eşleştirmesiyle aynı onay akışına tabidir — öneri oluşturulur, satır yazılmaz.
        $planRes = $planResolve(trim((string) ($entry['external_rate_plan_id'] ?? '')));
        if ($planRes['skip']) {
            continue; // onay bekleyen fiyat planı önerisi — veri yazılmadı
        }
        // Eşleştirmede belirtilen fiyat planı kullanılır; yoksa ilk aktif plan.
        $entryPlan = $planRes['plan'] ?? ($roomRes['plan'] !== null && isset($plansById[$roomRes['plan']]) ? $plansById[$roomRes['plan']] : $fallbackPlan);
        $entryPlanId = (int) $entryPlan['id'];

        if ($scope === 'reservations') {
            $qty = max(1, (int) ($entry['qty'] ?? 1));
            $sellSt->execute([$qty, $roomId, $entryPlanId, $date]);
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
        $cur->execute([$roomId, $entryPlanId, $date]);
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
                // Hedef birim: bu giriş için seçilen fiyat planının birimi.
                $targetCurrency = strtoupper((string) ($entryPlan['currency'] ?? 'EUR'));
                if (!preg_match('/^[A-Z]{3}$/', $targetCurrency)) $targetCurrency = 'EUR';
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
                    // Denetim: orijinal ve dönüştürülmüş birim + kullanılan kur ve tutarlar.
                    $fxKey = $inCur . '->' . $targetCurrency;
                    if (!isset($fxAudit[$fxKey])) {
                        $fxAudit[$fxKey] = ['from' => $inCur, 'to' => $targetCurrency, 'rate' => $rate, 'count' => 0, 'original_total' => 0.0, 'converted_total' => 0.0, 'first_date' => $date, 'last_date' => $date];
                    }
                    $fxAudit[$fxKey]['count']++;
                    $fxAudit[$fxKey]['original_total'] += $rawPrice;
                    $fxAudit[$fxKey]['converted_total'] += $base['base_price'];
                    $fxAudit[$fxKey]['rate'] = $rate;
                    if ($date < $fxAudit[$fxKey]['first_date']) $fxAudit[$fxKey]['first_date'] = $date;
                    if ($date > $fxAudit[$fxKey]['last_date']) $fxAudit[$fxKey]['last_date'] = $date;
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

        $upsert->execute([$roomId, $entryPlanId, $date, $base['allotment'], 0, $base['base_price'], $base['min_stay'], $base['max_stay'], $base['stop_sale']]);
        $applied++;
    }

    if ($applied === 0 && $suggestedCount === 0 && $suggestedPlanCount === 0) {
        return ['ok' => false, 'message' => 'Hiçbir satır uygulanamadı. ' . implode('; ', array_slice($errors, 0, 5)), 'applied' => 0, 'errors' => $errors, 'fx_audit' => array_values($fxAudit)];
    }
    $suggestNote = '';
    if ($suggestedCount > 0) {
        $suggestNote .= ' (+' . $suggestedCount . ' tanınmayan oda kodu onay bekleyen öneri olarak kaydedildi — webhook satırı yazılmadı)';
    }
    if ($suggestedPlanCount > 0) {
        $suggestNote .= ' (+' . $suggestedPlanCount . ' tanınmayan fiyat planı kodu onay bekleyen öneri olarak kaydedildi — webhook satırı yazılmadı)';
    }
    return ['ok' => true, 'message' => $applied . ' gün ' . $scope . ' kapsamında uygulandı' . $suggestNote . '.', 'applied' => $applied, 'errors' => $errors, 'auto_mapped' => $suggestedCount, 'suggested' => $suggestedCount, 'suggested_plans' => $suggestedPlanCount, 'fx_audit' => array_values($fxAudit)];
}

/**
 * Neden bazlı akıllı retry: 'kalıcı' hatalar (yapılandırma/yük şeması) yeniden denenmez —
 * retry cron/retry-channel-webhooks.php yalnızca geçici sayılan başarısızlıkları kuyruğa geri alır.
 * Kodlar process-channel-webhooks tarafından hata mesajına [kod,...] olarak eklenir;
 * bilinmeyen/eksik kodlar geçici sayılır (retry serbest, maksimum deneme sınırı zaten var).
 */
function channel_error_is_permanent(string $message): bool
{
    $permanent = [
        'property_not_mapped', // ilan eşleştirmesi yok — tedarikçi eşlemeden retry başarısız kalır
        'unsupported_scope',   // desteklenmeyen kapsam — yük şeması bozuk
        'no_rooms',            // ilanda aktif oda/birim tipi yok
        'no_rate_plan',        // ilanda aktif fiyat planı yok
        'invalid_date',        // geçersiz tarih biçimi — yük verisi bozuk
        'invalid_schema',      // geçersiz yük şeması
        'malformed_payload',   // ayrıştırılamayan/bozuk yük
    ];
    foreach ($permanent as $code) {
        if (str_contains($message, $code)) {
            return true;
        }
    }
    return false;
}
