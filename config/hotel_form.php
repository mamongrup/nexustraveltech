<?php

require_once __DIR__ . '/database.php';

function hotel_taxonomy_locale(): string {
  $locale = strtolower((string)($_GET['lang'] ?? $_SESSION['supplier_locale'] ?? 'tr'));
  return in_array($locale, ['tr','en','de','ru','ar','fr'], true) ? $locale : 'tr';
}

function hotel_taxonomy(string $type, array $fallback): array {
  try {
    $query = db()->prepare('SELECT COALESCE(t.name, h.name) AS name FROM hotel_taxonomies h LEFT JOIN hotel_taxonomy_translations t ON t.taxonomy_id=h.id AND t.locale=? WHERE h.taxonomy_type=? AND h.is_active=1 ORDER BY h.sort_order, h.name');
    $query->execute([hotel_taxonomy_locale(), $type]);
    $items = array_column($query->fetchAll(), 'name');
    return $items ?: $fallback;
  } catch (Throwable) { return $fallback; }
}

function hotel_property_types(): array {
  return hotel_taxonomy('property_type', ['Tatil köyü','Resort otel','Şehir oteli','Butik otel','Pansiyon','Apart otel','Bungalov tesisi','Termal otel','Kayak oteli','İş oteli','Motel']);
}

function hotel_star_ratings(): array {
  return hotel_taxonomy('star_rating', ['1 yıldız','2 yıldız','3 yıldız','4 yıldız','5 yıldız','Özel belgeli / yıldızsız']);
}

function hotel_amenity_groups(): array {
  return [
    'Genel hizmetler' => ['Wi-Fi','Otopark','Vale','Resepsiyon 24 saat','Oda servisi','Çamaşırhane','Kuru temizleme','Elektrikli araç şarjı','Transfer hizmeti','Araç kiralama'],
    'Yeme & içme' => ['Ana restoran','A la carte restoran','Bar','Snack bar','Çocuk büfesi','Vegan menü','Glutensiz menü','Odaya kahvaltı'],
    'Havuz & plaj' => ['Özel plaj','Mavi bayraklı plaj','İskele','Açık havuz','Kapalı havuz','Isıtmalı havuz','Çocuk havuzu','Aquapark','Şezlong ve şemsiye'],
    'Spa & spor' => ['SPA merkezi','Fitness','Türk hamamı','Sauna','Buhar odası','Masaj','Jakuzi','Yoga','Tenis','Su sporları'],
    'Çocuk & aile' => ['Mini kulüp','Çocuk animasyonu','Çocuk oyun alanı','Bebek yatağı','Bebek bakım hizmeti','Çocuk menüsü'],
    'İş & erişilebilirlik' => ['Toplantı salonu','Konferans salonu','Engelli erişimi','Engelli odası','Asansör','Yetişkin oteli'],
  ];
}

function hotel_themes(): array {
  return hotel_taxonomy('theme', ['Denize sıfır','Özel plajlı','Mavi bayraklı plaj','Şehir oteli','Termal otel','Balayı','Aile dostu','Çocuk dostu','Yetişkin oteli','Spa oteli','Golf oteli','Kayak oteli','Butik otel','Evcil hayvan dostu','Engelli dostu','Muhafazakâr tatil']);
}

function hotel_activity_groups(): array {
  return [
    'Spor & su aktiviteleri' => ['Fitness dersi','Yoga / pilates','Tenis','Plaj voleybolu','Basketbol','Mini futbol','Okçuluk','Dalış','Şnorkel','Kano','Paddle board','Jet ski','Parasailing','Banana','Su kayağı'],
    'Çocuk & aile aktiviteleri' => ['Mini kulüp aktivitesi','Çocuk disko','Çocuk atölyesi','Bebek bakım hizmeti','Oyun salonu','Lunapark'],
    'Wellness & deneyim' => ['Türk hamamı ritüeli','Masaj terapisi','Cilt bakımı','Kişisel antrenör','Yemek atölyesi','Şarap tadımı','Çevre gezisi'],
  ];
}

function hotel_event_groups(): array {
  return [
    'Gündüz & akşam programı' => ['Canlı müzik','DJ performansı','Gece şovu','Sahne gösterisi','Tema gecesi','Karaoke','Sinema gösterimi'],
    'Sezonluk & özel etkinlik' => ['Çocuk festivali','Bayram programı','Yılbaşı galası','Konser','Festival','Düğün / davet','Kurumsal etkinlik'],
  ];
}

function hotel_service_items(array $groups): array {
  return array_merge(...array_values($groups));
}

function csrf_token(): string {
  supplier_session();
  if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  return $_SESSION['csrf_token'];
}

function csrf_valid(?string $token): bool {
  supplier_session();
  return is_string($token) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
