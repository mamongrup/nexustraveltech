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
            $stmt = $pdo->prepare("SELECT label FROM property_feature_catalog WHERE code=? AND is_active ORDER BY sort_order, id");
            $stmt->execute([$code]);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            return $rows ?: property_feature_defaults($code);
        }
        $rows = $pdo->query("SELECT code, label FROM property_feature_catalog WHERE is_active ORDER BY code, sort_order, id")->fetchAll();
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
