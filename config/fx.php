<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

/**
 * Bir tutarı kurdan geçirerek çevirir (saf fonksiyon).
 * Aynı para birimi veya geçersiz kur durumunda tutar aynen korunur.
 */
function fx_convert_amount(float $amount, string $from, string $to, float $rate): float
{
    $from = strtoupper($from);
    $to = strtoupper($to);
    if ($from === $to) {
        return round($amount, 2);
    }
    if ($rate <= 0) {
        return round($amount, 2);
    }
    return round($amount * $rate, 2);
}

/**
 * 1 birim FROM'un kaç birim TO ettiğini döndürür (varsa doğrudan, yoksa TRY üzerinden çapraz).
 */
function fx_rate(string $from, string $to, ?string $date = null): float
{
    $from = strtoupper($from);
    $to = strtoupper($to);
    if ($from === $to) {
        return 1.0;
    }
    $date = $date ?: date('Y-m-d');
    $q = db()->prepare('SELECT rate FROM fx_rates WHERE base_currency=? AND quote_currency=? AND rate_date<=? ORDER BY rate_date DESC LIMIT 1');
    $q->execute([$from, $to, $date]);
    $direct = $q->fetchColumn();
    if ($direct !== false) {
        return (float) $direct;
    }
    // Çapraz kur: her ikisi de TRY değilse TRY üzerinden hesapla.
    if ($from !== 'TRY' && $to !== 'TRY') {
        $toTry = fx_rate($from, 'TRY', $date);   // 1 FROM = ? TRY
        $fromTry = fx_rate($to, 'TRY', $date);   // 1 TO = ? TRY
        if ($toTry > 0 && $fromTry > 0) {
            return round($toTry / $fromTry, 6);
        }
    }
    return 0.0;
}

/**
 * Tarih bazlı tutar çevirisi; kur bulunamazsa tutar aynen döner.
 */
function fx_convert(float $amount, string $from, string $to, ?string $date = null): float
{
    return fx_convert_amount($amount, $from, $to, fx_rate($from, $to, $date));
}

/**
 * TCMB bugünkü kur XML'inden USD/EUR/GBP/CHF ↔ TRY paritelerini döndürür.
 *
 * @return array<int, array{base: string, quote: string, rate: float}>
 */
function fx_fetch_tcmb_today(): array
{
    $xml = @file_get_contents('https://www.tcmb.gov.tr/kurlar/today.xml');
    if ($xml === false) {
        throw new RuntimeException('TCMB bugünkü kur verisi alınamadı.');
    }
    $doc = @simplexml_load_string($xml);
    if (!$doc) {
        throw new RuntimeException('TCMB verisi çözümlenemedi.');
    }
    $rows = [];
    foreach ($doc->Currency as $currency) {
        $code = strtoupper((string) $currency['CurrencyCode']);
        $selling = (float) $currency->ForexSelling;
        if (!in_array($code, ['USD', 'EUR', 'GBP', 'CHF'], true) || $selling <= 0) {
            continue;
        }
        $rows[] = ['base' => $code, 'quote' => 'TRY', 'rate' => round($selling, 6)];
        $rows[] = ['base' => 'TRY', 'quote' => $code, 'rate' => round(1 / $selling, 6)];
    }
    if (!$rows) {
        throw new RuntimeException('TCMB verisinde işlenebilir kur bulunamadı.');
    }
    return $rows;
}
