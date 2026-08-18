<?php
/**
 * trash-helpers.php — Çöp kutusu silme/geri yükleme rozetleri için ortak yardımcılar.
 *
 * Tekil silme, toplu silme, denetim kayıtları ve onay sayfalarında kullanılan
 * tekrarlayan badge hesaplamalarını tek yerden yönetir.
 *
 * Kullanım:
 *   require_once __DIR__ . '/trash-helpers.php';
 *   $purge = trash_effective_purge($featureRow, $ttlDays);
 *   echo trash_remain_badge($purge['remain_days'], $purge['custom']);
 *   echo trash_custom_date_badge('2026-08-20', 'purgeBadgeSingle');
 *   echo trash_ttl_badge(30);
 *   echo trash_status_badge('pending', '5 gün kaldı');
 */

if (!function_exists('trash_effective_purge')) {

/**
 * Bir özelliğin etkin kalıcı silme tarihini hesaplar.
 *
 * @param array{deleted_at: string, purge_at?: string} $feat  DB satırı
 * @param int $ttlDays  Varsayılan TTL (gün); 0 geçilirse platform_setting'den okunur
 * @return array{purge_ts: int, date: string, remain_days: int, custom: bool}
 */
function trash_effective_purge(array $feat, int $ttlDays = 0): array
{
    if ($ttlDays <= 0) {
        $ttlDays = max(7, (int) platform_setting('feature_trash_ttl_days', 30));
    }
    $delTs = strtotime((string) ($feat['deleted_at'] ?? '')) ?: time();
    $custom = !empty($feat['purge_at']);
    $purgeTs = $custom ? (strtotime((string) $feat['purge_at']) ?: 0) : 0;
    if ($purgeTs <= 0) {
        $purgeTs = $delTs + $ttlDays * 86400;
    }
    return [
        'purge_ts'    => $purgeTs,
        'date'        => date('Y-m-d', $purgeTs),
        'remain_days' => max(0, (int) ceil(($purgeTs - time()) / 86400)),
        'custom'      => $custom,
    ];
}

} // trash_effective_purge


if (!function_exists('trash_remain_badge')) {

/**
 * Kalan gün rozetini HTML olarak döndürür.
 *
 * Renk kodları:
 *   ≤ 0 gün  → kırmızı (#ffe2de / #b0301a) "⚠ Vade doldu"
 *   1-6 gün  → turuncu (#fff3cd / #8a6100)   "N gün kaldı"
 *   7+ gün   → yeşil   (#e6f8c7 / #2e7d32)   "N gün kaldı"
 *
 * @param int    $remainDays  Kalan gün sayısı
 * @param bool   $custom      Özel tarih mi?
 * @param string $extraText   Yanına eklenecek opsiyonel metin (ör. tarih)
 * @return string HTML span
 */
function trash_remain_badge(int $remainDays, bool $custom = false, string $extraText = ''): string
{
    if ($remainDays <= 0) {
        $bg = '#ffe2de'; $fg = '#b0301a'; $label = '⚠ Vade doldu';
    } elseif ($remainDays < 7) {
        $bg = '#fff3cd'; $fg = '#8a6100'; $label = $remainDays . ' gün kaldı';
    } else {
        $bg = '#e6f8c7'; $fg = '#2e7d32'; $label = $remainDays . ' gün kaldı';
    }
    $html = '<span style="display:inline-block;background:' . $bg . ';color:' . $fg
          . ';border-radius:12px;padding:2px 10px;font-size:11px;font-weight:bold;vertical-align:middle"'
          . ' title="Kalıcı silmeye kalan süre">'
          . htmlspecialchars($label) . '</span>';
    if ($extraText !== '') {
        $html .= ' <small style="color:#6b7774">(' . htmlspecialchars($extraText) . ')</small>';
    }
    return $html;
}

} // trash_remain_badge


if (!function_exists('trash_custom_date_badge')) {

/**
 * "özel tarih: GG-AA" rozeti (onay sayfalarında tarih seçimiyle senkron).
 *
 * JS onchang e ile element visibility/text güncellenir.
 *
 * @param string $date      Seçili tarih (YYYY-MM-DD) veya boş
 * @param string $elementId Badge span'in HTML id'si
 * @return string HTML span (display:none veya inline-block)
 */
function trash_custom_date_badge(string $date, string $elementId): string
{
    $visible = $date !== '' ? 'inline-block' : 'none';
    $text = $date !== '' ? htmlspecialchars(substr($date, 0, 10)) : '';
    return '<span id="' . htmlspecialchars($elementId) . '"'
         . ' style="display:' . $visible . ';background:#8a6100;color:#fff;'
         . 'border-radius:10px;padding:2px 8px;font-size:11px;font-weight:bold">'
         . 'özel tarih: ' . htmlspecialchars($text) . '</span>';
}

} // trash_custom_date_badge


if (!function_exists('trash_ttl_badge')) {

/**
 * "🗑 Çöp kutusunda N gün" rozeti (silme onay ekranları için).
 *
 * @param int $ttlDays  TTL süresi
 * @return string HTML span
 */
function trash_ttl_badge(int $ttlDays): string
{
    return '<span style="display:inline-block;background:#8a6100;color:#fff;'
         . 'border-radius:12px;padding:2px 10px;font-size:12px;font-weight:bold;vertical-align:middle"'
         . ' title="Silinen özellik çöp kutusunda durur ve bu süre boyunca geri yüklenebilir">'
         . '🗑 Çöp kutusunda ' . (int) $ttlDays . ' gün</span>';
}

} // trash_ttl_badge


if (!function_exists('trash_status_badge')) {

/**
 * Tekil/onay durumu rozeti (pending/rejected/confirmed/approved).
 *
 * @param string $status   pending|rejected|confirmed|approved
 * @param string $label    Rozet metni (boşsa status'u kullan)
 * @return string HTML span
 */
function trash_status_badge(string $status, string $label = ''): string
{
    $map = [
        'pending'   => ['#fff3cd', '#8a6100', '⏳'],
        'rejected'  => ['#ffe2de', '#b0301a', '✕'],
        'confirmed' => ['#e6f8c7', '#2e7d32', '✓'],
        'approved'  => ['#e6f8c7', '#2e7d32', '✓'],
    ];
    [$bg, $fg, $icon] = $map[$status] ?? ['#f4f6f1', '#10211f', '•'];
    $text = $label !== '' ? $label : $status;
    return '<span style="display:inline-block;background:' . $bg . ';color:' . $fg
         . ';border-radius:12px;padding:2px 10px;font-size:12px;font-weight:bold;vertical-align:middle">'
         . $icon . ' ' . htmlspecialchars($text) . '</span>';
}

} // trash_status_badge


if (!function_exists('trash_purge_date_js')) {

/**
 * purge_at input onChange handler'ı — badge'i senkronize eder.
 *
 * @param string $inputId   Input elementinin id'si (ör. purgeAtSingle)
 * @param string $badgeId   Badge span'in id'si (ör. purgeBadge)
 * @return string JS snippet (inline onchange attribute için)
 */
function trash_purge_date_js(string $inputId, string $badgeId): string
{
    return "var b=document.getElementById('" . $badgeId . "');"
         . "if(this.value){b.style.display='inline-block';b.textContent='özel tarih: '+this.value}"
         . "{b.style.display='none'}";
}

} // trash_purge_date_js
