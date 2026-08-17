<?php

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/feature_lists.php';

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
  return property_feature_groups('amenity');
}

function hotel_themes(): array {
  return hotel_taxonomy('theme', ['Denize sıfır','Özel plajlı','Mavi bayraklı plaj','Şehir oteli','Termal otel','Balayı','Aile dostu','Çocuk dostu','Yetişkin oteli','Spa oteli','Golf oteli','Kayak oteli','Butik otel','Evcil hayvan dostu','Engelli dostu','Muhafazakâr tatil']);
}

function hotel_activity_groups(): array {
  return property_feature_groups('activity');
}

function hotel_event_groups(): array {
  return property_feature_groups('event');
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
