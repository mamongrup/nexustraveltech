<?php

declare(strict_types=1);

// Çok dilli arayüz metinleri — hazırlık paneli link ipuçları (tooltip).
// Dil, admin → Kontrol merkezi → "Arayüz dili" (tooltip_language) ayarından
// seçilir; varsayılan 'tr'. Desteklenenler: tr, en, de, ru, ar, fr.

require_once __DIR__ . '/platform_settings.php';

const READINESS_LANGS = ['tr', 'en', 'de', 'ru', 'ar', 'fr'];

/** Kontrol merkezinden seçilen geçerli arayüz dili. */
function readiness_lang(): string
{
    $lang = strtolower(trim((string) platform_setting('tooltip_language', 'tr')));
    return in_array($lang, READINESS_LANGS, true) ? $lang : 'tr';
}

/**
 * Hazırlık kalemi → sayfa adı (dil bazında). Bölüm çapası olan kalemlerde
 * adın sonuna ' · sec-XX' eklenir (ör. 'Görseller · sec-05').
 */
function readiness_tooltips(): array
{
    $lang = readiness_lang();
    $names = [
        'rooms' => [
            'tr' => 'Oda / birim envanteri',
            'en' => 'Room / unit inventory',
            'de' => 'Zimmer- / Einheitenbestand',
            'ru' => 'Инвентарь номеров и единиц',
            'ar' => 'مخزون الغرف والوحدات',
            'fr' => 'Inventaire des chambres / unités',
        ],
        'rates' => [
            'tr' => 'Fiyat & kontenjan',
            'en' => 'Rates & availability',
            'de' => 'Preise & Verfügbarkeit',
            'ru' => 'Тарифы и наличие',
            'ar' => 'الأسعار والتوفر',
            'fr' => 'Tarifs & disponibilité',
        ],
        'inventory' => [
            'tr' => 'Fiyat & kontenjan',
            'en' => 'Rates & availability',
            'de' => 'Preise & Verfügbarkeit',
            'ru' => 'Тарифы и наличие',
            'ar' => 'الأسعار والتوفر',
            'fr' => 'Tarifs & disponibilité',
        ],
        'media' => [
            'tr' => 'Görseller',
            'en' => 'Photos',
            'de' => 'Fotos',
            'ru' => 'Фотографии',
            'ar' => 'الصور',
            'fr' => 'Photos',
        ],
        'description' => [
            'tr' => 'Satış içeriği',
            'en' => 'Sales content',
            'de' => 'Verkaufsinhalt',
            'ru' => 'Описание для продажи',
            'ar' => 'محتوى البيع',
            'fr' => 'Contenu de vente',
        ],
        'location' => [
            'tr' => 'Kimlik & konum',
            'en' => 'Identity & location',
            'de' => 'Identität & Lage',
            'ru' => 'Идентификация и расположение',
            'ar' => 'الهوية والموقع',
            'fr' => 'Identité & emplacement',
        ],
        'pool' => [
            'tr' => 'Kimlik & konum',
            'en' => 'Identity & location',
            'de' => 'Identität & Lage',
            'ru' => 'Идентификация и расположение',
            'ar' => 'الهوية والموقع',
            'fr' => 'Identité & emplacement',
        ],
        'home_port' => [
            'tr' => 'Kimlik & konum',
            'en' => 'Identity & location',
            'de' => 'Identität & Lage',
            'ru' => 'Идентификация и расположение',
            'ar' => 'الهوية والموقع',
            'fr' => 'Identité & emplacement',
        ],
        'crew' => [
            'tr' => 'Kimlik & konum',
            'en' => 'Identity & location',
            'de' => 'Identität & Lage',
            'ru' => 'Идентификация и расположение',
            'ar' => 'الهوية والموقع',
            'fr' => 'Identité & emplacement',
        ],
        'ical' => [
            'tr' => 'iCal takvimleri',
            'en' => 'iCal calendars',
            'de' => 'iCal-Kalender',
            'ru' => 'Календари iCal',
            'ar' => 'تقويمات iCal',
            'fr' => 'Calendriers iCal',
        ],
        'rules' => [
            'tr' => 'Satış kuralları',
            'en' => 'Sales rules',
            'de' => 'Verkaufsregeln',
            'ru' => 'Правила продаж',
            'ar' => 'قواعد البيع',
            'fr' => 'Règles de vente',
        ],
    ];
    $anchors = [
        'rooms' => 'sec-04',
        'media' => 'sec-05',
        'description' => 'sec-02',
        'location' => 'sec-01',
        'pool' => 'sec-01',
        'home_port' => 'sec-01',
        'crew' => 'sec-01',
    ];
    $out = [];
    foreach ($names as $key => $texts) {
        $base = $texts[$lang] ?? $texts['tr'];
        $out[$key] = $base . (isset($anchors[$key]) ? ' · ' . $anchors[$key] : '');
    }
    return $out;
}
