<?php

declare(strict_types=1);

// Çok dilli arayüz metinleri — hazırlık paneli link ipuçları (tooltip).
// Dil, admin → Kontrol merkezi → "Arayüz dili" (tooltip_language) ayarından
// seçilir; varsayılan 'tr'. Desteklenenler: tr, en, de, ru, ar, fr.

require_once __DIR__ . '/platform_settings.php';

const READINESS_LANGS = ['tr', 'en', 'de', 'ru', 'ar', 'fr'];

/** Kullanıcının dil tercihini DB + session'da güncelle. */
function set_user_language(string $lang): void
{
    $lang = strtolower(trim($lang));
    if (!in_array($lang, READINESS_LANGS, true)) $lang = 'tr';
    if (isset($_SESSION['supplier_user']['id'])) {
        $uid = (int) $_SESSION['supplier_user']['id'];
        try {
            db()->prepare('UPDATE supplier_users SET language = ? WHERE id = ?')->execute([$lang, $uid]);
        } catch (Throwable $e) {}
    }
    $_SESSION['supplier_user']['language'] = $lang;
}

/** Mevcut kullanıcının dil tercihini döndür. */
function get_user_language(): string
{
    return readiness_lang();
}

/**
 * Geçerli arayüz dilini belirle.
 * Öncelik: 1) Kullanıcı profili (supplier_users.language)
 *          2) Admin genel ayarı (platform_setting tooltip_language)
 *          3) Varsayılan 'tr'
 */
function readiness_lang(): string
{
    // 1) Kullanıcı dil tercihi (login sırasında $_SESSION'a yazılır)
    $userLang = null;
    if (isset($_SESSION['supplier_user']['language']) && $_SESSION['supplier_user']['language'] !== null) {
        $userLang = strtolower(trim($_SESSION['supplier_user']['language']));
    }
    if ($userLang !== null && in_array($userLang, READINESS_LANGS, true)) {
        return $userLang;
    }
    // 2) Admin genel ayarı
    $platformLang = strtolower(trim((string) platform_setting('tooltip_language', 'tr')));
    if (in_array($platformLang, READINESS_LANGS, true)) {
        return $platformLang;
    }
    // 3) Varsayılan
    return 'tr';
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
        'channel' => [
            'tr' => 'Dağıtım merkezi — kanal bağlantısı',
            'en' => 'Distribution center — channel connection',
            'de' => 'Vertriebszentrum — Kanalverbindung',
            'ru' => 'Центр дистрибуции — подключение канала',
            'ar' => 'مركز التوزيع — اتصال القناة',
            'fr' => 'Centre de distribution — connexion de canal',
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

/* ─── Bölüm adları (secHint / secTitles için) ─── */

/** Kısa bölüm adının çok dilli karşılığı. */
function section_name(string $shortTr): string
{
    $lang = readiness_lang();
    $map = [
        'Kimlik & konum' => [
            'tr' => 'Kimlik & konum', 'en' => 'Identity & location',
            'de' => 'Identität & Lage', 'ru' => 'Идентификация и расположение',
            'ar' => 'الهوية والموقع', 'fr' => 'Identité & emplacement',
        ],
        'Satış içeriği' => [
            'tr' => 'Satış içeriği', 'en' => 'Sales content',
            'de' => 'Verkaufsinhalt', 'ru' => 'Описание для продажи',
            'ar' => 'محتوى البيع', 'fr' => 'Contenu de vente',
        ],
        'Olanaklar' => [
            'tr' => 'Olanaklar', 'en' => 'Facilities',
            'de' => 'Ausstattung', 'ru' => 'Удобства',
            'ar' => 'المرافق', 'fr' => 'Équipements',
        ],
        'Oda envanteri' => [
            'tr' => 'Oda envanteri', 'en' => 'Room inventory',
            'de' => 'Zimmerbestand', 'ru' => 'Инвентарь номеров',
            'ar' => 'مخزون الغرف', 'fr' => 'Inventaire des chambres',
        ],
        'Birim & fiyat' => [
            'tr' => 'Birim & fiyat', 'en' => 'Unit & pricing',
            'de' => 'Einheit & Preis', 'ru' => 'Единица и цена',
            'ar' => 'الوحدة والسعر', 'fr' => 'Unité & tarification',
        ],
        'Görseller' => [
            'tr' => 'Görseller', 'en' => 'Photos',
            'de' => 'Fotos', 'ru' => 'Фотографии',
            'ar' => 'الصور', 'fr' => 'Photos',
        ],
        'Komisyon & tahsilat' => [
            'tr' => 'Komisyon & tahsilat', 'en' => 'Commission & billing',
            'de' => 'Provision & Abrechnung', 'ru' => 'Комиссия и биллинг',
            'ar' => 'العمولة والفواتير', 'fr' => 'Commission & facturation',
        ],
        'İptal & iade' => [
            'tr' => 'İptal & iade', 'en' => 'Cancellation & refund',
            'de' => 'Stornierung & Erstattung', 'ru' => 'Отмена и возврат',
            'ar' => 'الإلغاء والاسترداد', 'fr' => 'Annulation & remboursement',
        ],
        'İptal & iade şartları' => [
            'tr' => 'İptal & iade şartları', 'en' => 'Cancellation & refund',
            'de' => 'Stornierung & Erstattung', 'ru' => 'Отмена и возврат',
            'ar' => 'الإلغاء والاسترداد', 'fr' => 'Annulation & remboursement',
        ],
    ];
    return ($map[$shortTr][$lang] ?? null) ?: $shortTr;
}

/** Birden fazla kısa adı topluca çevir. array_map ile secTitles'i güncelle. */
function translate_section_names(array $shortNamesTr): array
{
    $out = [];
    foreach ($shortNamesTr as $k => $v) {
        $out[$k] = section_name($v);
    }
    return $out;
}

/* ─── Hazırlık etiketleri (Doldur →, İncele →, Gör →, ...) ─── */

/** readiness-sec-hint / secTitles için metin + çapa üret. */
function readiness_sec_hint(string $shortTitle, string $anchor): string
{
    return section_name($shortTitle) . ' ' . $anchor;
}

/** Hazırlık kalemlerindeki nhưngon etiketlerini çok dilli yap. */
function readiness_labels(): array
{
    $lang = readiness_lang();
    $map = [
        'fill' => [
            'tr' => 'Doldur', 'en' => 'Fill in',
            'de' => 'Ausfüllen', 'ru' => 'Заполнить',
            'ar' => 'ملء', 'fr' => 'Remplir',
        ],
        'inspect' => [
            'tr' => 'İncele', 'en' => 'Inspect',
            'de' => 'Prüfen', 'ru' => 'Проверить',
            'ar' => 'فحص', 'fr' => 'Inspecter',
        ],
        'view' => [
            'tr' => 'Gör', 'en' => 'View',
            'de' => 'Ansehen', 'ru' => 'Просмотр',
            'ar' => 'عرض', 'fr' => 'Voir',
        ],
        'all' => [
            'tr' => 'Tümü', 'en' => 'All',
            'de' => 'Alle', 'ru' => 'Все',
            'ar' => 'الكل', 'fr' => 'Tout',
        ],
        'copy' => [
            'tr' => 'Kopyala', 'en' => 'Copy',
            'de' => 'Kopieren', 'ru' => 'Копировать',
            'ar' => 'نسخ', 'fr' => 'Copier',
        ],
        'optional' => [
            'tr' => 'opsiyonel', 'en' => 'optional',
            'de' => 'optional', 'ru' => 'необязательно',
            'ar' => 'اختياري', 'fr' => 'optionnel',
        ],
    ];
    $out = [];
    foreach ($map as $key => $texts) {
        $out[$key] = $texts[$lang] ?? $texts['tr'];
    }
    return $out;
}

/* ─── Görsel rozetleri ─── */

function media_badge_cover(): string
{
    $lang = readiness_lang();
    $map = [
        'tr' => 'KAPAK', 'en' => 'COVER',
        'de' => 'DECKBILD', 'ru' => 'ОБЛОЖКА',
        'ar' => 'غلاف', 'fr' => 'Pochette',
    ];
    return $map[$lang] ?? 'KAPAK';
}

/* ─── Skor kartı metni ─── */

/** @param int $done Tamamlanan kalem sayısı */
function score_done_text(int $done): string
{
    $lang = readiness_lang();
    $map = [
        'tr' => '%d kalem tamam', 'en' => '%d items complete',
        'de' => '%d Punkte erfüllt', 'ru' => '%d пунктов готово',
        'ar' => '%d عنصر مكتمل', 'fr' => '%d éléments complétés',
    ];
    return sprintf($map[$lang] ?? $map['tr'], $done);
}

/** @param int $remain Kalan kalem sayısı */
function score_remain_text(int $remain): string
{
    $lang = readiness_lang();
    $map = [
        'tr' => 'kalan %d kalem tamamlanınca 100 olur',
        'en' => '%d remaining to reach 100',
        'de' => '%d Punkte fehlen für 100',
        'ru' => 'осталось %d пунктов до 100',
        'ar' => '%d عنصر متبقي للوصول إلى 100',
        'fr' => '%d éléments restants pour atteindre 100',
    ];
    return sprintf($map[$lang] ?? $map['tr'], $remain);
}

/** Hazırlık kalemine ait ağırlık rozeti tooltip'i */
function readiness_gain_tooltip(): string
{
    $lang = readiness_lang();
    $map = [
        'tr' => 'Bu kalem tamamlanınca hazırlık skoruna eklenir',
        'en' => 'This item adds to the readiness score when complete',
        'de' => 'Dieser Punkt wird zum Vorbereitungsscore hinzugefügt',
        'ru' => 'Этот пункт добавляется в рейтинг готовности',
        'ar' => 'يتم إضافة هذا العنصر إلى درجة الاستعداد عند اكتماله',
        'fr' => 'Cet élément ajoute au score de préparation',
    ];
    return $map[$lang] ?? $map['tr'];
}
/* ─── Panel başlık & buton etiketleri ─── */

function readline_yayin(): string {
    $lang = readiness_lang();
    $map = [
        "tr" => "YAYINA ALMA AKIŞI", "en" => "PUBLISHING FLOW",
        "de" => "VERÖFFENTLICHUNGSABLAUF", "ru" => "ПРОЦЕСС ПУБЛИКАЦИИ",
        "ar" => "عملية النشر", "fr" => "FLUX DE PUBLICATION",
    ];
    return $map[$lang] ?? $map["tr"];
}

function readline_active(): string {
    $lang = readiness_lang();
    $map = [
        "tr" => "YAYINDA", "en" => "LIVE", "de" => "AKTIV",
        "ru" => "ОПУБЛИКОВАНО", "ar" => "منشور", "fr" => "EN LIGNE",
    ];
    return $map[$lang] ?? $map["tr"];
}

function readline_publish(): string {
    $lang = readiness_lang();
    $map = [
        "tr" => "Yayına al", "en" => "Publish",
        "de" => "Veröffentlichen", "ru" => "Опубликовать",
        "ar" => "نشر", "fr" => "Publier",
    ];
    return $map[$lang] ?? $map["tr"];
}

function readline_publish_ready_tip(): string {
    $lang = readiness_lang();
    $map = [
        "tr" => "Tüm kalemler tamam — yayına alabilirsiniz",
        "en" => "All items complete — you can publish",
        "de" => "Alle Punkte erfüllt — Sie können veröffentlichen",
        "ru" => "Все пункты готовы — можно опубликовать",
        "ar" => "جميع العناصر مكتملة — يمكنك النشر",
        "fr" => "Tous les éléments sont complets — vous pouvez publier",
    ];
    return $map[$lang] ?? $map["tr"];
}

function readline_publish_disabled_tip(): string {
    $lang = readiness_lang();
    $map = [
        "tr" => "Eksik kalemler tamamlanmadan yayına alınamaz",
        "en" => "Cannot publish until missing items are filled",
        "de" => "Kann nicht veröffentlicht werden, bis fehlende Punkte ausgefüllt sind",
        "ru" => "Невозможно опубликовать до заполнения недостающих пунктов",
        "ar" => "لا يمكن النشر حتى تكتمل العناصر الناقصة",
        "fr" => "Impossible de publier tant que les éléments manquants ne sont pas remplis",
    ];
    return $map[$lang] ?? $map["tr"];
}

function readline_all(): string {
    $lang = readiness_lang();
    $map = [
        "tr" => "Tümü", "en" => "All", "de" => "Alle",
        "ru" => "Все", "ar" => "الكل", "fr" => "Tout",
    ];
    return $map[$lang] ?? $map["tr"];
}

function readiness_header(): string {
    $lang = readiness_lang();
    $map = [
        "tr" => "Hazırlık kontrol listesi",
        "en" => "Readiness checklist",
        "de" => "Vorbereitungs-Checkliste",
        "ru" => "Чек-лист готовности",
        "ar" => "قائمة التحقق من الاستعداد",
        "fr" => "Liste de contrôle",
    ];
    return $map[$lang] ?? $map["tr"];
}

/* ─── AI Widget UI metinleri ─── */

/** Chat widget'ındaki tüm arayüz metinlerini döndür. */
function ai_widget_ui_texts(): array
{
    $lang = readiness_lang();
    $texts = [
        'welcome' => [
            'tr' => 'Merhaba 👋 Size nasıl yardımcı olabilirim? Örn. "Bugün kaç misafir geliyor?" veya "Son hataları göster" yazabilirsiniz.',
            'en' => 'Hello 👋 How can I help you? Try "How many guests arriving today?" or "Show recent errors".',
            'de' => 'Hallo 👋 Wie kann ich Ihnen helfen? Versuchen Sie z.B. "Wie viele Gäste kommen heute?" oder "Zeige letzte Fehler".',
            'ru' => 'Здравствуйте 👋 Чем могу помочь? Например: "Сколько гостей прибудет сегодня?" или "Покажи последние ошибки".',
            'ar' => 'مرحبًا 👋 كيف يمكنني مساعدتك؟ جرب: "كم ضيفًا يصل اليوم؟" أو "أظهر الأخطاء الأخيرة".',
            'fr' => 'Bonjour 👋 Comment puis-je vous aider? Essayez "Combien d\'arrivées aujourd\'hui?" ou "Montre les erreurs récentes".',
        ],
        'placeholder' => [
            'tr' => 'Sorunuzu yazın…',
            'en' => 'Type your question…',
            'de' => 'Ihre Frage eingeben…',
            'ru' => 'Введите вопрос…',
            'ar' => 'اكتب سؤالك…',
            'fr' => 'Tapez votre question…',
        ],
        'send' => [
            'tr' => 'Gönder',
            'en' => 'Send',
            'de' => 'Senden',
            'ru' => 'Отправить',
            'ar' => 'إرسال',
            'fr' => 'Envoyer',
        ],
        'title' => [
            'tr' => 'NEXUS AI asistan',
            'en' => 'NEXUS AI assistant',
            'de' => 'NEXUS KI-Assistent',
            'ru' => 'NEXUS AI-ассистент',
            'ar' => 'مساعد NEXUS الذكي',
            'fr' => 'Assistant NEXUS AI',
        ],
        'subtitle' => [
            'tr' => 'Soruları yanıtlar, yönlendirir, güvenli işlemleri yapar',
            'en' => 'Answers questions, navigates, performs safe actions',
            'de' => 'Beantwortet Fragen, navigiert, führt sichere Aktionen aus',
            'ru' => 'Отвечает на вопросы, направляет, выполняет безопасные действия',
            'ar' => 'يجيب على الأسئلة ويوجه ويؤدي إجراءات آمنة',
            'fr' => 'Répond aux questions, navigue, effectue des actions sûres',
        ],
        'error_connection' => [
            'tr' => '⚠ Bağlantı hatası. Lütfen tekrar deneyin.',
            'en' => '⚠ Connection error. Please try again.',
            'de' => '⚠ Verbindungsfehler. Bitte versuchen Sie es erneut.',
            'ru' => '⚠ Ошибка соединения. Пожалуйста, попробуйте снова.',
            'ar' => '⚠ خطأ في الاتصال. يرجى المحاولة مرة أخرى.',
            'fr' => '⚠ Erreur de connexion. Veuillez réessayer.',
        ],
        'error_parse' => [
            'tr' => '⚠ Yanıt okunamadı',
            'en' => '⚠ Could not read response',
            'de' => '⚠ Antwort konnte nicht gelesen werden',
            'ru' => '⚠ Не удалось прочитать ответ',
            'ar' => '⚠ لم يتمكن من قراءة الرد',
            'fr' => '⚠ Impossible de lire la réponse',
        ],
        'lang_hint' => [
            'tr' => 'Diliniz: Türkçe',
            'en' => 'Your language: English',
            'de' => 'Ihre Sprache: Deutsch',
            'ru' => 'Ваш язык: Русский',
            'ar' => 'لغتك: العربية',
            'fr' => 'Votre langue: Français',
        ],
    ];
    $out = [];
    foreach ($texts as $key => $map) {
        $out[$key] = $map[$lang] ?? $map['tr'];
    }
    return $out;
}

/* ─── Panel genel etiketleri ─── */

/** Tüm panel sayfalarındaki ortak etiketleri merkezi olarak döndür. */
function panel_labels(): array
{
    $lang = readiness_lang();
    $t = [
        'fill' => ['tr'=>'Doldur','en'=>'Fill in','de'=>'Ausfüllen','ru'=>'Заполнить','ar'=>'ملء','fr'=>'Remplir'],
        'inspect' => ['tr'=>'İncele','en'=>'Inspect','de'=>'Prüfen','ru'=>'Проверить','ar'=>'فحص','fr'=>'Inspecter'],
        'view' => ['tr'=>'Gör','en'=>'View','de'=>'Ansehen','ru'=>'Просмотр','ar'=>'عرض','fr'=>'Voir'],
        'all' => ['tr'=>'Tümü','en'=>'All','de'=>'Alle','ru'=>'Все','ar'=>'الكل','fr'=>'Tout'],
        'copy' => ['tr'=>'Kopyala','en'=>'Copy','de'=>'Kopieren','ru'=>'Копировать','ar'=>'نسخ','fr'=>'Copier'],
        'optional' => ['tr'=>'opsiyonel','en'=>'optional','de'=>'optional','ru'=>'необязательно','ar'=>'اختياري','fr'=>'optionnel'],
        'save' => ['tr'=>'Kaydet','en'=>'Save','de'=>'Speichern','ru'=>'Сохранить','ar'=>'حفظ','fr'=>'Enregistrer'],
        'save_all' => ['tr'=>'Tüm detayları kaydet','en'=>'Save all details','de'=>'Alle Details speichern','ru'=>'Сохранить все данные','ar'=>'حفظ جميع التفاصيل','fr'=>'Enregistrer tous les détails'],
        'later' => ['tr'=>'Daha sonra tamamla','en'=>'Complete later','de'=>'Später abschließen','ru'=>'Завершить позже','ar'=>'إكمال لاحقًا','fr'=>'Compléter plus tard'],
        'approve' => ['tr'=>'Onayla','en'=>'Approve','de'=>'Genehmigen','ru'=>'Одобрить','ar'=>'موافقة','fr'=>'Approuver'],
        'reject' => ['tr'=>'Reddet','en'=>'Reject','de'=>'Ablehnen','ru'=>'Отклонить','ar'=>'رفض','fr'=>'Rejeter'],
        'activate' => ['tr'=>'Aktifleştir','en'=>'Activate','de'=>'Aktivieren','ru'=>'Активировать','ar'=>'تفعيل','fr'=>'Activer'],
        'deactivate' => ['tr'=>'Pasifleştir','en'=>'Deactivate','de'=>'Deaktivieren','ru'=>'Деактивировать','ar'=>'تعطيل','fr'=>'Désactiver'],
        'delete' => ['tr'=>'Sil','en'=>'Delete','de'=>'Löschen','ru'=>'Удалить','ar'=>'حذف','fr'=>'Supprimer'],
        'confirm' => ['tr'=>'Onayla','en'=>'Confirm','de'=>'Bestätigen','ru'=>'Подтвердить','ar'=>'تأكيد','fr'=>'Confirmer'],
        'cancel' => ['tr'=>'İptal','en'=>'Cancel','de'=>'Abbrechen','ru'=>'Отмена','ar'=>'إلغاء','fr'=>'Annuler'],
        'retry' => ['tr'=>'Yeniden dene','en'=>'Retry','de'=>'Erneut versuchen','ru'=>'Повторить','ar'=>'إعادة المحاولة','fr'=>'Réessayer'],
        'reset_token' => ['tr'=>'Tokenı sıfırla','en'=>'Reset token','de'=>'Token zurücksetzen','ru'=>'Сбросить токен','ar'=>'إعادة تعيين الرمز','fr'=>'Réinitialiser le jeton'],
        'export_csv' => ['tr'=>'CSV indir','en'=>'Download CSV','de'=>'CSV herunterladen','ru'=>'Скачать CSV','ar'=>'تنزيل CSV','fr'=>'Télécharger CSV'],
        'new_item' => ['tr'=>'+ Yeni ürün','en'=>'+ New listing','de'=>'+ Neues Angebot','ru'=>'+ Новый объект','ar'=>'+ إدراج جديد','fr'=>'+ Nouvelle annonce'],
        'back' => ['tr'=>'← Ürün listesine dön','en'=>'← Back to listings','de'=>'← Zurück zur Liste','ru'=>'← Назад к списку','ar'=>'← العودة إلى القائمة','fr'=>'← Retour à la liste'],
        'dashboard' => ['tr'=>'Genel bakış','en'=>'Dashboard','de'=>'Übersicht','ru'=>'Главная','ar'=>'لوحة التحكم','fr'=>'Tableau de bord'],
        'verification' => ['tr'=>'Hesap doğrulama','en'=>'Account verification','de'=>'Kontoüberprüfung','ru'=>'Проверка аккаунта','ar'=>'التحقق من الحساب','fr'=>'Vérification du compte'],
        'properties' => ['tr'=>'Tesisler & ürünler','en'=>'Properties & listings','de'=>'Objekte & Angebote','ru'=>'Объекты и публикации','ar'=>'المنشآت والإدراجات','fr'=>'Propriétés & annonces'],
        'inventory' => ['tr'=>'Fiyat & kontenjan','en'=>'Pricing & availability','de'=>'Preise & Verfügbarkeit','ru'=>'Цены и наличие','ar'=>'الأسعار والتوفر','fr'=>'Tarifs & disponibilité'],
        'rate_rules' => ['tr'=>'Satış kuralları','en'=>'Sales rules','de'=>'Verkaufsregeln','ru'=>'Правила продаж','ar'=>'قواعد البيع','fr'=>'Règles de vente'],
        'distribution' => ['tr'=>'Dağıtım & kanallar','en'=>'Distribution & channels','de'=>'Vertrieb & Kanäle','ru'=>'Дистрибуция и каналы','ar'=>'التوزيع والقنوات','fr'=>'Distribution & canaux'],
        'ical' => ['tr'=>'iCal takvimler','en'=>'iCal calendars','de'=>'iCal-Kalender','ru'=>'Календари iCal','ar'=>'تقويمات iCal','fr'=>'Calendriers iCal'],
        'notifications' => ['tr'=>'Bildirimler','en'=>'Notifications','de'=>'Benachrichtigungen','ru'=>'Уведомления','ar'=>'الإشعارات','fr'=>'Notifications'],
        'pending_approval' => ['tr'=>'onay bekleyen','en'=>'pending approval','de'=>'Genehmigung ausstehend','ru'=>'ожидает одобрения','ar'=>'بانتظار الموافقة','fr'=>'en attente'],
        'live_label' => ['tr'=>'● GÜNCEL','en'=>'● LIVE','de'=>'● AKTUELL','ru'=>'● АКТУАЛЬНО','ar'=>'● مباشر','fr'=>'● EN DIRECT'],
    ];
    $out = [];
    foreach ($t as $key => $map) {
        $out[$key] = $map[$lang] ?? $map['tr'];
    }
    return $out;
}

/** Tek etiket al — hız için tekillsştirilmiş. */
function pl(string $key): string
{
    static $cache = null;
    if ($cache === null) $cache = panel_labels();
    return $cache[$key] ?? $key;
}

/* ─── Modül akış navigasyonu ─── */

/** Modül akış sırası: her modülünonceki ve sonraki sayfası. */
function module_flows(): array
{
    $lang = readiness_lang();
    $names = [
        'distribution' => ['tr'=>'Dağıtım & kanallar','en'=>'Distribution','de'=>'Vertrieb','ru'=>'Дистрибуция','ar'=>'التوزيع','fr'=>'Distribution'],
        'inventory'    => ['tr'=>'Fiyat & kontenjan','en'=>'Pricing & availability','de'=>'Preise & Verfügbarkeit','ru'=>'Цены и наличие','ar'=>'الأسعار والتوفر','fr'=>'Tarifs'],
        'rate_rules'   => ['tr'=>'Satış kuralları','en'=>'Sales rules','de'=>'Verkaufsregeln','ru'=>'Правила продаж','ar'=>'قواعد البيع','fr'=>'Règles de vente'],
        'ical'         => ['tr'=>'iCal takvimler','en'=>'iCal calendars','de'=>'iCal-Kalender','ru'=>'Календари iCal','ar'=>'تقويمات iCal','fr'=>'Calendriers iCal'],
        'properties'   => ['tr'=>'Tesisler','en'=>'Properties','de'=>'Objekte','ru'=>'Объекты','ar'=>'المنشآت','fr'=>'Propriétés'],
    ];
    $translated = [];
    foreach ($names as $k => $m) $translated[$k] = $m[$lang] ?? $m['tr'];

    return [
        'distribution' => ['prev' => null, 'next' => 'inventory', 'label' => $translated['distribution']],
        'inventory'    => ['prev' => 'distribution', 'next' => 'rate_rules', 'label' => $translated['inventory']],
        'rate_rules'   => ['prev' => 'inventory', 'next' => 'ical', 'label' => $translated['rate_rules']],
        'ical'         => ['prev' => 'rate_rules', 'next' => null, 'label' => $translated['ical']],
    ];
}

/**
 * Modül akış çubuğu — sayfanın altına yerleştirilen önceki/sonraki navigasyonu.
 * Aynı çok dilli tooltip deseniyle çalışır.
 *
 * @param string $currentKey Bu sayfanın anahtarı (ör. 'inventory')
 * @param int|null $propertyId Ürün ID'si (query string için)
 * @return string HTML
 */
function next_module_nav(string $currentKey, ?int $propertyId = null): string
{
    $flows = module_flows();
    $flow = $flows[$currentKey] ?? null;
    if (!$flow) return '';

    $tt = panel_link_tooltips();
    $lang = readiness_lang();

    $pageMap = [
        'distribution' => 'dagitim-merkezi',
        'inventory'    => 'fiyat-kontenjan',
        'rate_rules'   => 'satis-kurallari',
        'ical'         => 'ical-takvimler',
    ];

    $prevTipLabels = [
        'tr' => 'Önceki modül', 'en' => 'Previous module', 'de' => 'Vorheriges Modul',
        'ru' => 'Предыдущий модуль', 'ar' => 'الوحدة السابقة', 'fr' => 'Module précédent',
    ];
    $nextTipLabels = [
        'tr' => 'Sonraki modül', 'en' => 'Next module', 'de' => 'Nächstes Modul',
        'ru' => 'Следующий модуль', 'ar' => 'الوحدة التالية', 'fr' => 'Module suivant',
    ];
    $prevTip = $prevTipLabels[$lang] ?? $prevTipLabels['tr'];
    $nextTip = $nextTipLabels[$lang] ?? $nextTipLabels['tr'];

    $html = '<div class="module-flow-nav">';

    if ($flow['prev']) {
        $prevPage = $pageMap[$flow['prev']] ?? '';
        $prevLabel = $flows[$flow['prev']]['label'] ?? $flow['prev'];
        $href = '/nexustraveltech/tedarikci/' . $prevPage;
        if ($propertyId) $href .= '?property=' . $propertyId;
        $html .= '<a class="module-flow-prev" href="' . htmlspecialchars($href) . '" title="' . htmlspecialchars($prevTip . ': ' . $prevLabel) . '">← ' . htmlspecialchars($prevLabel) . '</a>';
    } else {
        $html .= '<span></span>';
    }

    if ($flow['next']) {
        $nextPage = $pageMap[$flow['next']] ?? '';
        $nextLabel = $flows[$flow['next']]['label'] ?? $flow['next'];
        $href = '/nexustraveltech/tedarikci/' . $nextPage;
        if ($propertyId) $href .= '?property=' . $propertyId;
        $html .= '<a class="module-flow-next" href="' . htmlspecialchars($href) . '" title="' . htmlspecialchars($nextTip . ': ' . $nextLabel) . '">' . htmlspecialchars($nextLabel) . ' →</a>';
    } else {
        $html .= '<span></span>';
    }

    $html .= '</div>';
    return $html;
}

