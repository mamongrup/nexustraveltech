<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

/**
 * Varsayılan villa/yat özellik listeleri — tablo yoksa veya boşsa kullanılır.
 *
 * @return array<string, array<int, string>>|array<int, string>
 */
function property_feature_defaults(?string $code = null): array
{
    $lists = [
        'villa' => ['Özel havuz', 'Jakuzi', 'Klima', 'Wi-Fi', 'Televizyon', 'Mutfak', 'Bulaşık makinesi', 'Çamaşır makinesi', 'Bahçe', 'Teras', 'Mangal', 'Otopark', 'Güvenlik', 'Özel giriş', 'Deniz manzarası', 'Ebeveyn banyosu', 'Şömine', 'Isıtmalı havuz'],
        'yacht' => ['Güverte', 'Şezlong', 'Kabin TV', 'Klima', 'Müzik sistemi', 'Su sporları ekipmanı', 'Balıkçılık ekipmanı', 'Şnorkel', 'Dalış ekipmanı', 'Mutfak', 'Buzdolabı', 'Barbekü', 'Mürettebat', 'Yüzme merdiveni', 'Güneşlenme alanı', 'Wi-Fi'],
    ];
    return $code !== null ? ($lists[$code] ?? []) : $lists;
}

/**
 * Otel olanak/aktivite/etkinlik varsayılan grupları (grup adı => özellikler).
 *
 * @return array<string, array<int, string>>
 */
function property_hotel_feature_defaults(?string $category = null): array
{
    $groups = [
        'amenity' => [
            'Genel hizmetler' => ['Wi-Fi', 'Otopark', 'Vale', 'Resepsiyon 24 saat', 'Oda servisi', 'Çamaşırhane', 'Kuru temizleme', 'Elektrikli araç şarjı', 'Transfer hizmeti', 'Araç kiralama'],
            'Yeme & içme' => ['Ana restoran', 'A la carte restoran', 'Bar', 'Snack bar', 'Çocuk büfesi', 'Vegan menü', 'Glutensiz menü', 'Odaya kahvaltı'],
            'Havuz & plaj' => ['Özel plaj', 'Mavi bayraklı plaj', 'İskele', 'Açık havuz', 'Kapalı havuz', 'Isıtmalı havuz', 'Çocuk havuzu', 'Aquapark', 'Şezlong ve şemsiye'],
            'Spa & spor' => ['SPA merkezi', 'Fitness', 'Türk hamamı', 'Sauna', 'Buhar odası', 'Masaj', 'Jakuzi', 'Yoga', 'Tenis', 'Su sporları'],
            'Çocuk & aile' => ['Mini kulüp', 'Çocuk animasyonu', 'Çocuk oyun alanı', 'Bebek yatağı', 'Bebek bakım hizmeti', 'Çocuk menüsü'],
            'İş & erişilebilirlik' => ['Toplantı salonu', 'Konferans salonu', 'Engelli erişimi', 'Engelli odası', 'Asansör', 'Yetişkin oteli'],
        ],
        'activity' => [
            'Spor & su aktiviteleri' => ['Fitness dersi', 'Yoga / pilates', 'Tenis', 'Plaj voleybolu', 'Basketbol', 'Mini futbol', 'Okçuluk', 'Dalış', 'Şnorkel', 'Kano', 'Paddle board', 'Jet ski', 'Parasailing', 'Banana', 'Su kayağı'],
            'Çocuk & aile aktiviteleri' => ['Mini kulüp aktivitesi', 'Çocuk disko', 'Çocuk atölyesi', 'Bebek bakım hizmeti', 'Oyun salonu', 'Lunapark'],
            'Wellness & deneyim' => ['Türk hamamı ritüeli', 'Masaj terapisi', 'Cilt bakımı', 'Kişisel antrenör', 'Yemek atölyesi', 'Şarap tadımı', 'Çevre gezisi'],
        ],
        'event' => [
            'Gündüz & akşam programı' => ['Canlı müzik', 'DJ performansı', 'Gece şovu', 'Sahne gösterisi', 'Tema gecesi', 'Karaoke', 'Sinema gösterimi'],
            'Sezonluk & özel etkinlik' => ['Çocuk festivali', 'Bayram programı', 'Yılbaşı galası', 'Konser', 'Festival', 'Düğün / davet', 'Kurumsal etkinlik'],
        ],
    ];
    return $category !== null ? ($groups[$category] ?? []) : $groups;
}

/**
 * Otel hizmet gruplarını katalogdan okur (grup adı => özellikler).
 * Tablo yoksa/boşsa varsayılanlara döner.
 *
 * @return array<string, array<int, string>>
 */
function property_feature_groups(string $category): array
{
    try {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT group_label, label FROM property_feature_catalog WHERE code=? AND is_active AND deleted_at IS NULL ORDER BY group_label, sort_order, id');
        $stmt->execute([$category]);
        $rows = $stmt->fetchAll();
        if ($rows) {
            $out = [];
            foreach ($rows as $row) {
                $group = $row['group_label'] !== '' ? $row['group_label'] : 'Genel';
                $out[$group][] = $row['label'];
            }
            return $out;
        }
    } catch (Throwable) {
        // Katalog yok — varsayılanlar.
    }
    return property_hotel_feature_defaults($category);
}

/**
 * Villa/yat özellik listelerini admin'in düzenlediği katalogdan döndürür.
 * Tablo yoksa/boşsa varsayılan listeler kullanılır.
 *
 * @return array<string, array<int, string>>|array<int, string>
 */
function property_feature_lists(?string $code = null): array
{
    try {
        $pdo = db();
        if ($code !== null) {
            $stmt = $pdo->prepare("SELECT label FROM property_feature_catalog WHERE code=? AND is_active AND deleted_at IS NULL ORDER BY sort_order, id");
            $stmt->execute([$code]);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            return $rows ?: property_feature_defaults($code);
        }
        $rows = $pdo->query("SELECT code, label FROM property_feature_catalog WHERE is_active AND deleted_at IS NULL ORDER BY code, sort_order, id")->fetchAll();
        if ($rows) {
            $out = ['villa' => [], 'yacht' => []];
            foreach ($rows as $row) {
                $out[$row['code']][] = $row['label'];
            }
            return $out;
        }
    } catch (Throwable) {
        // Katalog yok — varsayılanlar.
    }
    return property_feature_defaults($code);
}
