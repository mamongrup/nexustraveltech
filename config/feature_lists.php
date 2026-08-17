<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

/**
 * Onaylı çöp kutusu temizliği — yedekler + katalog satırını kalıcı siler.
 * Temizlik görevi (cron/purge-feature-trash.php) ve onay sayfası
 * (admin/approve-trash-purge.php) bu fonksiyonu paylaşır.
 *
 * @param int[] $featureIds
 * @return array{count: int, ids: int[], names: string[]}
 */
function feature_trash_purge_approved(array $featureIds, PDO $pdo): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $featureIds))));
    if (!$ids) {
        return ['count' => 0, 'ids' => [], 'names' => []];
    }
    $idsSql = implode(',', $ids);
    $names = [];
    foreach ($pdo->query("SELECT id, label, code FROM property_feature_catalog WHERE id IN ({$idsSql})")->fetchAll() as $r) {
        $names[(int) $r['id']] = $r['label'] . ' (' . $r['code'] . ')';
    }
    if (!$names) {
        return ['count' => 0, 'ids' => [], 'names' => []];
    }
    $ids = array_values(array_intersect($ids, array_map('intval', array_keys($names))));
    $idsSql = implode(',', $ids);
    $pdo->exec("DELETE FROM feature_delete_backups WHERE feature_id IN ({$idsSql})");
    $pdo->exec("DELETE FROM property_feature_catalog WHERE id IN ({$idsSql})");
    $out = [];
    foreach ($ids as $fid) {
        $out[] = $names[$fid];
    }
    return ['count' => count($ids), 'ids' => $ids, 'names' => $out];
}

/**
 * Çöp kutusundaki bir özelliği geri yükler: katalog satırı + ilan bölümleri + denetim kaydı.
 * Özellik artık çöp kutusunda değilse hata mesajı döner.
 *
 * @return array{ok: bool, message: string, label: string, affected_count: int, restored_sections: int}
 */
function feature_restore(int $featureId, ?PDO $pdo = null, string $source = 'panel'): array
{
    $pdo = $pdo ?? db();
    $bkQ = $pdo->prepare('SELECT * FROM feature_delete_backups WHERE feature_id=? ORDER BY id DESC LIMIT 1');
    $bkQ->execute([$featureId]);
    $bk = $bkQ->fetch();
    if (!$bk) {
        return ['ok' => false, 'message' => 'Geri alınacak kayıt bulunamadı.', 'label' => '', 'affected_count' => 0, 'restored_sections' => 0];
    }
    $label = (string) $bk['label'];
    // 1) Katalog satırını geri getir (aynı id korunur, sıralama/durum geri gelir).
    $up = $pdo->prepare('UPDATE property_feature_catalog SET deleted_at=NULL, group_label=?, label=?, sort_order=?, is_active=? WHERE id=?');
    $up->execute([$bk['group_label'] ?? '', $label, (int) ($bk['sort_order'] ?? 100), (bool) ($bk['is_active'] ?? true), $featureId]);
    if ($up->rowCount() === 0) {
        $chk = $pdo->prepare('SELECT deleted_at FROM property_feature_catalog WHERE id=?');
        $chk->execute([$featureId]);
        $cur = $chk->fetch();
        if (!$cur || $cur['deleted_at'] === null) {
            return ['ok' => false, 'message' => 'Özellik artık çöp kutusunda değil (geri yüklenmiş veya kalıcı silinmiş).', 'label' => $label, 'affected_count' => 0, 'restored_sections' => 0];
        }
    }
    // Bekleyen "son şans" onay kaydını temizle (özellik çöp kutusundan çıktı).
    $pdo->prepare('DELETE FROM pending_trash_purges WHERE feature_id=?')->execute([$featureId]);
    // 2) İlanlara bölüm bazlı geri ekle (zaten varsa dokunma).
    $props = json_decode((string) ($bk['affected_properties'] ?? '[]'), true) ?: [];
    $restored = 0;
    $restoreSp = $pdo->prepare("UPDATE properties SET product_details = jsonb_set(product_details, '{service_pricing}', COALESCE(product_details -> 'service_pricing', '{}'::jsonb) || jsonb_build_object(?, ?), true) WHERE id=? AND NOT jsonb_exists(COALESCE(product_details -> 'service_pricing', '{}'::jsonb), ?)");
    $restoreSec = [
        'amenities' => $pdo->prepare("UPDATE properties SET product_details = jsonb_set(product_details, '{amenities}', COALESCE(product_details -> 'amenities', '[]'::jsonb) || ?::jsonb, true) WHERE id=? AND NOT (COALESCE(product_details -> 'amenities', '[]'::jsonb) @> ?::jsonb)"),
        'activities' => $pdo->prepare("UPDATE properties SET product_details = jsonb_set(product_details, '{activities}', COALESCE(product_details -> 'activities', '[]'::jsonb) || ?::jsonb, true) WHERE id=? AND NOT (COALESCE(product_details -> 'activities', '[]'::jsonb) @> ?::jsonb)"),
        'events' => $pdo->prepare("UPDATE properties SET product_details = jsonb_set(product_details, '{events}', COALESCE(product_details -> 'events', '[]'::jsonb) || ?::jsonb, true) WHERE id=? AND NOT (COALESCE(product_details -> 'events', '[]'::jsonb) @> ?::jsonb)"),
    ];
    foreach ($props as $pr) {
        $sections = is_array($pr['sections'] ?? null) ? $pr['sections'] : [];
        $pid = (int) ($pr['id'] ?? 0);
        $price = (string) ($pr['price'] ?? '');
        foreach ($sections as $sec) {
            if ($sec === 'service_pricing') {
                $restoreSp->execute([$label, $price, $pid, $label]);
                $restored++;
            } elseif (isset($restoreSec[$sec])) {
                $restoreSec[$sec]->execute([json_encode([$label]), $pid, json_encode([$label])]);
                $restored++;
            }
        }
    }
    if (function_exists('audit_log')) {
        audit_log('feature.restore', 'feature_catalog', $featureId, [
            'code' => $bk['code'] ?? '',
            'label' => $label,
            'affected_count' => count($props),
            'affected_listing_ids' => array_map(fn($pr) => (int) ($pr['id'] ?? 0), $props),
            'restored_sections' => $restored,
            'source' => $source,
        ]);
    }
    return ['ok' => true, 'message' => 'Özellik geri yüklendi' . ($props ? ' ve ' . count($props) . ' ilana tekrar eklendi.' : '.'), 'label' => $label, 'affected_count' => count($props), 'restored_sections' => $restored];
}

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
