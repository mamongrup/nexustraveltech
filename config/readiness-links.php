<?php
/**
 * Hazırlık panellerindeki Doldur → / İncele → /戈r → linklerini üreten ortak fonksiyon.
 *
 * Tek üretim noktası: otel-detay, villa-detay, tesisler aynı fonksiyonu çağırır.
 *
 * @param array  $item        Hazırlık kalemi ['key','ok','warn','label','detail','weight',...]
 * @param array  $linkFor     Ana linkler ['key' => url] (otel/villa: linkFor, tesisler: missLinks)
 * @param array  $warnLinks   Uyarı linkleri ['ical' => url] (varsa)
 * @param array  $tooltips    Araç ipuçları ['key' => text]
 * @param string $fallbackUrl Bulunamazsa kullanılacak varsayılan URL
 * @return string HTML
 */
function readiness_action_links(array $item, array $linkFor, array $warnLinks = [], array $tooltips = [], string $fallbackUrl = '#'): string
{
    $key = $item['key'] ?? '';
    $ok = !empty($item['ok']);
    $warn = !empty($item['warn']);

    $url = $linkFor[$key] ?? $fallbackUrl;
    $tooltip = htmlspecialchars($tooltips[$key] ?? '', ENT_QUOTES, 'UTF-8');
    $titleAttr = $tooltip !== '' ? ' title="' . $tooltip . '"' : '';

    // Kısa etiket (copy button için)
    $shortLabel = readiness_short_label($url);

    $html = '';

    $lbl = function_exists('readiness_labels') ? readiness_labels() : ['fill' => 'Doldur', 'inspect' => 'İncele', 'view' => 'Gör', 'copy' => 'Kopyala'];

    if (!$ok) {
        // Eksik kalem → Doldur →
        $html .= '<a' . $titleAttr . ' href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($lbl['fill']) . ' →</a>';
        $html .= readiness_copy_row($url, $shortLabel);
    } elseif ($warn) {
        // Uyarı → İncele → (warnLinks) +戈r → (linkFor)
        $inceleUrl = $warnLinks[$key] ?? $url;
        $html .= '<a' . $titleAttr . ' href="' . htmlspecialchars($inceleUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($lbl['inspect']) . ' →</a>';
        $html .= ' <a class="readiness-all-view"' . $titleAttr . ' href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($lbl['view']) . ' →</a>';
        $html .= readiness_copy_row($inceleUrl, readiness_short_label($inceleUrl));
    } else {
        // Tamam →戈r →
        $html .= '<a class="readiness-all-view"' . $titleAttr . ' href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($lbl['view']) . ' →</a>';
        $html .= readiness_copy_row($url, $shortLabel);
    }

    return $html;
}

/**
 * Copy butonu + kısa etiket satırı üretir.
 */
function readiness_copy_row(string $url, string $shortLabel): string
{
    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $safeShort = htmlspecialchars($shortLabel, ENT_QUOTES, 'UTF-8');
    $copyLabel = function_exists('readiness_labels') ? readiness_labels()['copy'] : 'Kopyala';
    return ' <span class="ical-copy-row">'
        . '<code title="' . $safeUrl . '">' . $safeShort . '</code>'
        . '<button type="button" class="ical-copy-btn" data-copy="' . $safeUrl . '">' . htmlspecialchars($copyLabel) . '</button>'
        . '</span>';
}

/**
 * URL'den kısa etiket üretir: sayfa adı + #çapa (query gizli).
 */
function readiness_short_label(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH) ?? '';
    $fragment = parse_url($url, PHP_URL_FRAGMENT);
    $label = basename($path);
    if ($fragment !== null && $fragment !== '') {
        $label .= ' #' . $fragment;
    }
    return $label ?: $url;
}

/**
 * $editorToc'tan secHint / secAnchor / secTitles'i otomatik üret.
 *
 * Yeni bölüm eklenince bu fonksiyon sayesinde sec numaraları
 * elle güncellenmez — yalnızca $keyMap güncellenir.
 *
 * @param array $editorToc    derive_editor_toc() çıktısı [{id, no, title, short}, ...]
 * @param array $editorSections  ['Tam başlık' => 'Kısa başlık', ...]
 * @param array $keyMap       Hazırlık kalemi key → bölüm kısa adı
 *                             Örn: ['media' => 'Görseller', 'location' => 'Kimlik & konum']
 * @return array ['secHint' => [...], 'secAnchor' => [...], 'secTitles' => [...]]
 */
function derive_sec_metadata(array $editorToc, array $editorSections, array $keyMap): array
{
    // Kısa ad → {id, short} eşleme
    $byShort = [];
    foreach ($editorToc as $toc) {
        $byShort[$toc['short']] = $toc;
    }

    $secHint = [];
    $secAnchor = [];
    $secTitles = [];

    foreach ($keyMap as $key => $shortTitle) {
        if (!isset($byShort[$shortTitle])) continue;
        $toc = $byShort[$shortTitle];
    $translated = function_exists('section_name') ? section_name($toc['short']) : $toc['short'];
    $secHint[$key] = $translated . ' ' . $toc['id'];
    $secAnchor[$key] = '#' . $toc['id'];
    $secTitles[$key] = $translated;
    }

    return compact('secHint', 'secAnchor', 'secTitles');
}

/**
 * Tek seferlik editorToc üretimi — her dosyada aynı kalıp.
 *
 * @param array $editorSections  ['Tam başlık' => 'Kısa başlık', ...]
 * @return array ['toc' => [...], 'sections' => [...], 'count' => int]
 */
function derive_editor_toc(array $editorSections): array
{
    $toc = [];
    $n = 0;
    foreach ($editorSections as $fullTitle => $shortTitle) {
        $n++;
        $toc[] = [
            'id'    => 'sec-' . str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'no'    => str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'title' => $fullTitle,
            'short' => $shortTitle,
        ];
    }
    return ['toc' => $toc, 'sections' => $editorSections, 'count' => $n];
}
