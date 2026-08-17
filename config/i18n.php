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

/**
 * Diğer panel sayfaları (fiyat-kontenjan, satis-kurallari, ical-takvimler)
 * arasındaki geçiş bağlantılarının çok dilli araç ipuçları.
 * Bazı değerler sprintf şablonudur (%d = sayaç).
 */
function panel_link_tooltips(): array
{
    $lang = readiness_lang();
    $map = [
        'rooms_edit' => [
            'tr' => 'Oda envanteri bölümü',
            'en' => 'Room inventory section',
            'de' => 'Zimmerbestands-Abschnitt',
            'ru' => 'Раздел инвентаря номеров',
            'ar' => 'قسم مخزون الغرف',
            'fr' => 'Section inventaire des chambres',
        ],
        'units_edit' => [
            'tr' => 'Birim envanteri bölümü',
            'en' => 'Unit inventory section',
            'de' => 'Einheitenbestands-Abschnitt',
            'ru' => 'Раздел инвентаря единиц',
            'ar' => 'قسم مخزون الوحدات',
            'fr' => 'Section inventaire des unités',
        ],
        'row_view' => [
            'tr' => 'Takvim satırını gör',
            'en' => 'View calendar row',
            'de' => 'Kalenderzeile anzeigen',
            'ru' => 'Просмотреть строку календаря',
            'ar' => 'عرض صف التقويم',
            'fr' => 'Voir la ligne du calendrier',
        ],
        'row_inspect' => [
            'tr' => 'Satışı kapalı gün — takvimde incele',
            'en' => 'Stop-sale day — inspect in calendar',
            'de' => 'Verkaufsstopp-Tag — im Kalender prüfen',
            'ru' => 'День остановки продаж — проверить в календаре',
            'ar' => 'يوم إيقاف البيع — فحص في التقويم',
            'fr' => 'Jour de suspension — inspecter dans le calendrier',
        ],
        'rule_view' => [
            'tr' => 'Satış kuralını gör',
            'en' => 'View sales rule',
            'de' => 'Verkaufsregel anzeigen',
            'ru' => 'Просмотреть правило продаж',
            'ar' => 'عرض قاعدة البيع',
            'fr' => 'Voir la règle de vente',
        ],
        'rule_inspect' => [
            'tr' => 'Satış kuralını incele',
            'en' => 'Inspect sales rule',
            'de' => 'Verkaufsregel prüfen',
            'ru' => 'Проверить правило продаж',
            'ar' => 'فحص قاعدة البيع',
            'fr' => 'Inspecter la règle de vente',
        ],
        'ical_err24' => [
            'tr' => 'Son 24 saatte %d senkron hatası — bağlantıyı kontrol edin',
            'en' => '%d sync errors in the last 24 hours — check the connection',
            'de' => '%d Sync-Fehler in den letzten 24 Stunden — Verbindung prüfen',
            'ru' => '%d ошибок синхронизации за 24 часа — проверьте подключение',
            'ar' => '%d خطأ مزامنة خلال 24 ساعة — تحقق من الاتصال',
            'fr' => '%d erreurs de synchro sur 24 h — vérifiez la connexion',
        ],
    ];
    $out = [];
    foreach ($map as $key => $texts) {
        $out[$key] = $texts[$lang] ?? $texts['tr'];
    }
    return $out;
}
