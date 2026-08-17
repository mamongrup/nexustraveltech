<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

function listing_duplicate_key(string $type, string $name, string $city, string $country): string
{
    $value = mb_strtolower(trim($type . '|' . $name . '|' . $city . '|' . $country), 'UTF-8');
    $value = strtr($value, ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u']);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?: '';
    return hash('sha256', trim($value));
}

function record_audit_event(string $actorType, ?int $actorId, string $action, string $entityType, ?int $entityId, array $meta = []): void
{
    db()->prepare('INSERT INTO audit_logs (actor_type,actor_id,action,entity_type,entity_id,meta) VALUES (?,?,?,?,?,?::jsonb)')
        ->execute([$actorType, $actorId, $action, $entityType, $entityId, json_encode($meta, JSON_UNESCAPED_UNICODE)]);
}

/**
 * İlan hazırlık kontrolü — satışa açmadan önce eksik kalemleri ve skoru (0-100) döndürür.
 *
 * @return array{score: int, ready: bool, items: array<int, array{key: string, label: string, ok: bool, detail: string, warn?: bool, age_days?: ?int}>}
 */
function listing_readiness(array $property): array
{
    $pid = (int) ($property['id'] ?? 0);
    $pdo = db();

    $roomSt = $pdo->prepare('SELECT COUNT(*) FROM room_types WHERE property_id=? AND status=\'active\'');
    $roomSt->execute([$pid]);
    $rooms = (int) $roomSt->fetchColumn();

    $rateSt = $pdo->prepare('SELECT COUNT(*) FROM rate_plans WHERE property_id=? AND status=\'active\'');
    $rateSt->execute([$pid]);
    $rates = (int) $rateSt->fetchColumn();

    $mediaSt = $pdo->prepare('SELECT COUNT(*) FROM property_media WHERE property_id=?');
    $mediaSt->execute([$pid]);
    $media = (int) $mediaSt->fetchColumn();

    $ruleSt = $pdo->prepare('SELECT COUNT(*) FROM rate_rules WHERE property_id=?');
    $ruleSt->execute([$pid]);
    $rules = (int) $ruleSt->fetchColumn();

    $invSt = $pdo->prepare("SELECT COUNT(*) FROM inventory_calendar i JOIN room_types r ON r.id=i.room_type_id WHERE r.property_id=? AND i.stay_date >= current_date AND i.base_price > 0");
    $invSt->execute([$pid]);
    $inv = (int) $invSt->fetchColumn();

    $isIcalType = in_array($property['property_type'] ?? '', ['villa', 'yacht'], true);
    $icalActive = 0;
    $icalEvents = 0;
    if ($isIcalType) {
        $icalSt = $pdo->prepare("SELECT COUNT(*) FROM ical_connections WHERE property_id=? AND status='active'");
        $icalSt->execute([$pid]);
        $icalActive = (int) $icalSt->fetchColumn();
        $icalEvSt = $pdo->prepare("SELECT COUNT(*) FROM ical_events e JOIN ical_connections c ON c.id=e.ical_connection_id WHERE c.property_id=? AND e.starts_on >= current_date");
        $icalEvSt->execute([$pid]);
        $icalEvents = (int) $icalEvSt->fetchColumn();
    }
    $hasAvailability = $inv > 0 || ($isIcalType && $icalEvents > 0);
    $invDetail = $inv > 0 ? $inv . ' gün' : ($isIcalType && $icalEvents > 0 ? $icalEvents . ' iCal bloğu' : ($isIcalType ? 'Takvim boş — Fiyat & kontenjan veya iCal içe aktarma ile doldurun' : 'Takvim boş — Fiyat & kontenjan sayfasından doldurun'));

    $details = json_decode((string) ($property['product_details'] ?? '{}'), true) ?: [];
    $description = trim((string) ($details['description'] ?? '')) !== '' || trim((string) ($details['short_description'] ?? '')) !== '';
    $location = trim((string) ($details['latitude'] ?? '')) !== '' && trim((string) ($details['longitude'] ?? '')) !== '';

    $items = [
        ['key' => 'rooms', 'label' => 'Aktif oda / birim tipi', 'ok' => $rooms > 0, 'detail' => $rooms > 0 ? $rooms . ' tip' : 'Oda tipi yok'],
        ['key' => 'rates', 'label' => 'Aktif fiyat planı', 'ok' => $rates > 0, 'detail' => $rates > 0 ? $rates . ' plan' : 'Fiyat planı yok'],
        ['key' => 'inventory', 'label' => 'Müsaitlik verisi', 'ok' => $hasAvailability, 'detail' => $invDetail],
        ['key' => 'media', 'label' => 'En az 1 görsel', 'ok' => $media > 0, 'detail' => $media > 0 ? $media . ' görsel' : 'Görsel yok'],
        ['key' => 'description', 'label' => 'Satış açıklaması', 'ok' => $description, 'detail' => $description ? 'Mevcut' : 'Açıklama yok'],
        ['key' => 'location', 'label' => 'Konum (enlem/boylam)', 'ok' => $location, 'detail' => $location ? 'Mevcut' : 'Konum girilmedi'],
    ];

    // Tür bazlı zorunlu kalemler (skor paydasına girer): villa → havuz, yat → liman + mürettebat.
    if (($property['property_type'] ?? '') === 'villa') {
        $pool = trim((string) ($details['pool'] ?? '')) !== '';
        $items[] = ['key' => 'pool', 'label' => 'Havuz bilgisi', 'ok' => $pool, 'detail' => $pool ? 'Havuz: ' . $details['pool'] : 'Havuz tipi seçilmedi'];
    }
    if (($property['property_type'] ?? '') === 'yacht') {
        $homePort = trim((string) ($details['home_port'] ?? '')) !== '';
        $crew = trim((string) ($details['crew'] ?? '')) !== '';
        $items[] = ['key' => 'home_port', 'label' => 'Bağlama limanı', 'ok' => $homePort, 'detail' => $homePort ? $details['home_port'] : 'Liman bilgisi yok'];
        $items[] = ['key' => 'crew', 'label' => 'Mürettebat', 'ok' => $crew, 'detail' => $crew ? $details['crew'] : 'Mürettebat bilgisi yok'];
    }

    if ($isIcalType) {
        // Üç aşamalı senkron durumu: <7 gün → ✓ güncel, 7–30 gün → ⚠ sarı uyarı,
        // 30+ gün veya hiç senkron yok → ✗ kırmızı eksik (skoru düşürür, yayına engel).
        // "Hiç senkron yok" (bağlantı var ama içe aktarma hiç yapılmamış) ayrı mesajla gösterilir.
        $icalStale = false;
        $icalSyncOld30 = false;
        $icalNeverSynced = false;
        $icalLastSync = null;
        $icalAgeDays = null;
        if ($icalActive > 0) {
            $syncSt = $pdo->prepare("SELECT MAX(last_sync_at) FROM ical_connections WHERE property_id=? AND status='active'");
            $syncSt->execute([$pid]);
            $icalLastSync = $syncSt->fetchColumn();
            $icalNeverSynced = $icalLastSync === null || (string) $icalLastSync === '';
            $icalTs = $icalNeverSynced ? 0 : strtotime((string) $icalLastSync);
            $icalAgeDays = $icalTs > 0 ? (int) floor((time() - $icalTs) / 86400) : null;
            $icalSyncOld30 = $icalNeverSynced || $icalTs < time() - 30 * 86400;
            $icalStale = !$icalSyncOld30 && $icalTs > 0 && $icalTs < time() - 7 * 86400;
        }
        $icalExportUrls = [];
        $icalFirstConnId = 0; // ilk aktif İÇE aktarma bağlantısı — #sync-<id> odaklaması için
        if ($isIcalType) {
            $urlSt = $pdo->prepare("SELECT access_token FROM ical_connections WHERE property_id=? AND status='active' AND direction='export'");
            $urlSt->execute([$pid]);
            foreach ($urlSt->fetchAll(PDO::FETCH_COLUMN) as $icalToken) {
                $icalExportUrls[] = 'https://nexustraveltech.com/api/ical?token=' . urlencode((string) $icalToken);
            }
            $connSt = $pdo->prepare("SELECT id FROM ical_connections WHERE property_id=? AND status='active' AND direction='import' ORDER BY id LIMIT 1");
            $connSt->execute([$pid]);
            $icalFirstConnId = (int) $connSt->fetchColumn();
        }
        $items[] = [
            'key' => 'ical',
            'label' => 'Aktif iCal bağlantısı (içe/dışa aktarma)',
            'ok' => $icalActive > 0 && !$icalSyncOld30,
            'warn' => $icalActive > 0 && !$icalSyncOld30 && $icalStale,
            'age_days' => $icalAgeDays,
            'first_conn_id' => $icalFirstConnId,
            'urls' => $icalExportUrls,
            'detail' => $icalActive === 0
                ? 'Bağlantı yok — iCal takvimler sayfasından en az bir aktif içe/dışa aktarma ekleyin'
                : ($icalNeverSynced
                    ? $icalActive . ' bağlantı · hiç içe aktarma yapılmadı — "Şimdi içe aktar" ile ilk senkronu başlatın'
                    : ($icalSyncOld30
                        ? $icalActive . ' bağlantı · son senkron 30 günden eski (' . $icalLastSync . ')'
                        : ($icalStale
                            ? $icalActive . ' bağlantı · son senkron 7 günden eski (' . $icalLastSync . ')'
                            : $icalActive . ' bağlantı · son senkron güncel'))),
        ];
    }
    // Kanal dağıtımı: bu ürüne eşleştirilmiş bir kanal bağlantısı pasifse (error/paused/
    // draft/disabled) kırmızı kalem — skoru düşürür. Hiç kanal bağlantısı yoksa nötr (✓,
    // opsiyonel); pasif bir bağlantı varsa Doldur → dağıtım merkezi #channel-retry-<id>
    // adresine gider (iCal #sync deseniyle aynı odaklama).
    $badChannels = [];
    $channelCount = 0;
    $channelSt = $pdo->prepare("SELECT c.id, c.status, c.display_name FROM channel_property_mappings m JOIN channel_connections c ON c.id=m.channel_connection_id WHERE m.property_id=? ORDER BY c.id");
    $channelSt->execute([$pid]);
    foreach ($channelSt->fetchAll() as $ch) {
        $channelCount++;
        if ((string) ($ch['status'] ?? '') !== 'active') $badChannels[] = $ch;
    }
    $items[] = [
        'key' => 'channel',
        'label' => 'Kanal bağlantısı aktif',
        'ok' => $badChannels === [],
        'first_bad_id' => $badChannels !== [] ? (int) $badChannels[0]['id'] : 0,
        'detail' => $badChannels !== []
            ? count($badChannels) . ' pasif kanal: ' . implode(', ', array_map(fn($b) => (string) $b['display_name'] . ' (' . (string) $b['status'] . ')', $badChannels))
            : ($channelCount > 0 ? $channelCount . ' kanal aktif' : 'Kanal bağlantısı kurulmamış (opsiyonel)'),
    ];
    $items[] = ['key' => 'rules', 'label' => 'Satış / kontrat kuralı', 'ok' => $rules > 0, 'detail' => $rules > 0 ? $rules . ' kural' : 'Kural yok (opsiyonel)'];


    // Ağırlıklı skor: görsel/konum gibi kritik kalemler daha yüksek puan taşır.
    // Base ağırlıklar tür bazında normalize edilerek toplam tam 100'e çekilir;
    // satış kuralı opsiyoneldir ve paydaya girmez.
    $baseWeights = [
        'rooms' => 3, 'rates' => 3, 'inventory' => 3,
        'media' => 4, 'description' => 2, 'location' => 3,   // görsel + konum en ağır
        'channel' => 2, 'pool' => 2, 'home_port' => 2, 'crew' => 1, 'ical' => 2,
    ];
    $coreItems = array_values(array_filter($items, fn($i) => $i['key'] !== 'rules'));
    $coreTotal = count($coreItems);
    $totalBase = 0;
    foreach ($coreItems as $ci) $totalBase += $baseWeights[$ci['key']] ?? 2;
    // Her kalemin ağırlığı: 100 * base / toplam; kalan puanlar en büyük kesirli kısma dağıtılır.
    $wFloor = [];
    $wFrac = [];
    $floorSum = 0;
    foreach ($coreItems as $ci) {
        $k = $ci['key'];
        $v = $totalBase > 0 ? 100 * ($baseWeights[$k] ?? 2) / $totalBase : 0;
        $wFloor[$k] = (int) floor($v);
        $wFrac[$k] = $v - $wFloor[$k];
        $floorSum += $wFloor[$k];
    }
    $rem = 100 - $floorSum;
    if ($rem > 0) {
        arsort($wFrac); // PHP 8.0+ kararlı — eşit kesirlerde ekleme sırası korunur
        $n = 0;
        foreach (array_keys($wFrac) as $k) {
            if ($n >= $rem) break;
            $wFloor[$k]++;
            $n++;
        }
    }
    $coreOk = 0;
    $scoreSum = 0;
    foreach ($items as &$it) {
        if ($it['key'] === 'rules') { $it['weight'] = 0; continue; }
        $it['weight'] = $wFloor[$it['key']] ?? 0;
        if (!empty($it['ok'])) { $coreOk++; $scoreSum += $it['weight']; }
    }
    unset($it);
    return [
        'score' => $coreTotal > 0 ? min(100, $scoreSum) : 0,
        'ready' => $coreOk === $coreTotal,
        // Skor kartı özeti: tamamlanan ve kalan çekirdek kalem sayıları.
        // Kalanların tamamı bitince skor her zaman 100 olur (tüm çekirdekler ✓).
        'ok_count' => $coreOk,
        'missing_count' => $coreTotal - $coreOk,
        'items' => $items,
    ];
}

/**
 * Kritik eksik şeridindeki atlama bağlantısı etiketi (tür bazlı, TR).
 * Tesisler kartı ve otel/villa-detay panelleri ortak kullanır.
 */
function listing_missing_jump_label(string $key): string
{
    $labels = [
        'media' => 'Görsellere git', 'rooms' => 'Oda tiplerine git',
        'inventory' => 'Takvime git', 'rates' => 'Fiyat planına git',
        'description' => 'Açıklamaya git', 'location' => 'Konuma git',
        'pool' => 'Havuz bilgisine git', 'home_port' => 'Limana git',
        'crew' => 'Mürettebata git', 'ical' => 'iCal sayfasına git',
        'channel' => 'Dağıtım merkezine git', 'rules' => 'Satış kuralına git',
    ];
    return $labels[$key] ?? 'İlgili sayfaya git';
}

/**
 * İlk EKSİK çekirdek kalemin yerel düzenleyici bölümünü döndürür (örn. 'sec-05').
 * Öncelik sırası karşı karşıya ekranıyla aynı: location → description → media →
 * rooms → inventory → rates → villa/yat ekleri (pool/home_port/crew). Yalnızca
 * otel/villa-detay'da yerel bölümü olan kalemler eşlenir; iCal/kanal/kural gibi
 * başka sayfaya gidenler null döner. Hiç eksik yoksa null.
 */
function listing_first_missing_section(array $property): ?string
{
    $rd = listing_readiness($property);
    $secMap = [
        'location' => 'sec-01',
        'description' => 'sec-02',
        'media' => 'sec-05',
        'rooms' => 'sec-04',
        'inventory' => 'sec-04',
        'rates' => 'sec-04',
        'pool' => 'sec-01',
        'home_port' => 'sec-01',
        'crew' => 'sec-01',
    ];
    $order = ['location', 'description', 'media', 'rooms', 'inventory', 'rates', 'pool', 'home_port', 'crew'];
    foreach ($order as $key) {
        if (!isset($secMap[$key])) continue;
        foreach ($rd['items'] as $it) {
            if ($it['key'] === $key && empty($it['ok'])) {
                return $secMap[$key];
            }
        }
    }
    return null;
}
