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
 * @return array{score: int, ready: bool, items: array<int, array{key: string, label: string, ok: bool, detail: string}>}
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

    $details = json_decode((string) ($property['product_details'] ?? '{}'), true) ?: [];
    $description = trim((string) ($details['description'] ?? '')) !== '' || trim((string) ($details['short_description'] ?? '')) !== '';
    $location = trim((string) ($details['latitude'] ?? '')) !== '' && trim((string) ($details['longitude'] ?? '')) !== '';

    $items = [
        ['key' => 'rooms', 'label' => 'Aktif oda / birim tipi', 'ok' => $rooms > 0, 'detail' => $rooms > 0 ? $rooms . ' tip' : 'Oda tipi yok'],
        ['key' => 'rates', 'label' => 'Aktif fiyat planı', 'ok' => $rates > 0, 'detail' => $rates > 0 ? $rates . ' plan' : 'Fiyat planı yok'],
        ['key' => 'inventory', 'label' => 'Gelecek tarihli fiyatlı takvim', 'ok' => $inv > 0, 'detail' => $inv > 0 ? $inv . ' gün' : 'Takvim boş — Fiyat & kontenjan sayfasından doldurun'],
        ['key' => 'media', 'label' => 'En az 1 görsel', 'ok' => $media > 0, 'detail' => $media > 0 ? $media . ' görsel' : 'Görsel yok'],
        ['key' => 'description', 'label' => 'Satış açıklaması', 'ok' => $description, 'detail' => $description ? 'Mevcut' : 'Açıklama yok'],
        ['key' => 'location', 'label' => 'Konum (enlem/boylam)', 'ok' => $location, 'detail' => $location ? 'Mevcut' : 'Konum girilmedi'],
        ['key' => 'rules', 'label' => 'Satış / kontrat kuralı', 'ok' => $rules > 0, 'detail' => $rules > 0 ? $rules . ' kural' : 'Kural yok (opsiyonel)'],
    ];

    $coreOk = 0;
    foreach ($items as $i) {
        if ($i['key'] !== 'rules' && $i['ok']) $coreOk++;
    }
    $okCount = count(array_filter($items, fn($i) => $i['ok']));
    return [
        'score' => (int) round($okCount / count($items) * 100),
        'ready' => $coreOk === 6,
        'items' => $items,
    ];
}
