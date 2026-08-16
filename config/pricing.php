<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

/**
 * Kuralları pazar/uyruk/kanal/promosyon kodu bağlamına göre bellekte filtreler (saf fonksiyon).
 */
function filter_rate_rules(array $rules, array $context): array
{
    $matched = [];
    foreach ($rules as $rule) {
        $markets = json_decode((string) ($rule['markets'] ?? '[]'), true) ?: [];
        $nationalities = json_decode((string) ($rule['nationalities'] ?? '[]'), true) ?: [];
        $channels = json_decode((string) ($rule['channels'] ?? '[]'), true) ?: [];
        if ($markets && !in_array($context['market'] ?? '', $markets, true)) continue;
        if ($nationalities && !in_array($context['nationality'] ?? '', $nationalities, true)) continue;
        if ($channels && !in_array($context['channel'] ?? '', $channels, true)) continue;
        if ($rule['rule_type'] === 'promo_code'
            && (($context['promo_code'] ?? '') === '' || !hash_equals(mb_strtolower((string) $context['promo_code']), mb_strtolower((string) $rule['promo_code'])))) {
            continue;
        }
        $matched[] = $rule;
    }
    return $matched;
}

/**
 * Verilen bağlam için geçerli satış kurallarını (rate_rules) döndürür.
 *
 * Context alanları: stay_date (Y-m-d), booking_date (Y-m-d), advance_days (int),
 * market, nationality, channel, promo_code (opsiyonel).
 */
function matching_rate_rules(int $propertyId, ?int $ratePlanId, array $context): array
{
    $q = db()->prepare(
        "SELECT * FROM rate_rules
         WHERE property_id=? AND status='active'
           AND (rate_plan_id IS NULL OR rate_plan_id=?)
           AND (booking_start IS NULL OR booking_start<=?)
           AND (booking_end IS NULL OR booking_end>=?)
           AND (stay_start IS NULL OR stay_start<=?)
           AND (stay_end IS NULL OR stay_end>=?)
           AND min_advance_days<=?
         ORDER BY priority ASC, id ASC"
    );
    $q->execute([
        $propertyId,
        $ratePlanId ?? 0,
        $context['booking_date'],
        $context['booking_date'],
        $context['stay_date'],
        $context['stay_date'],
        (int) ($context['advance_days'] ?? 0),
    ]);

    return filter_rate_rules($q->fetchAll(), $context);
}

/**
 * Kuralları taban fiyata uygular; ['price' => float, 'applied' => string[]] döndürür (saf fonksiyon).
 * free_night kuralları gecelik fiyatı değiştirmez (gece sayısını etkiler).
 */
function compute_rate_after_rules(float $basePrice, array $rules): array
{
    $price = $basePrice;
    $applied = [];
    foreach ($rules as $rule) {
        if ($rule['rule_type'] === 'free_night') continue;
        $value = (float) $rule['value'];
        if (in_array($rule['rule_type'], ['percent', 'derived', 'promo_code'], true)) {
            $price = $price * (1 - $value / 100);
        } elseif ($rule['rule_type'] === 'fixed') {
            $price = $price - $value;
        }
        $price = max(0, round($price, 2));
        $applied[] = (string) $rule['name'];
        if (!$rule['stackable']) break;
    }
    return ['price' => $price, 'applied' => $applied];
}

/**
 * Taban fiyata kuralları uygular; ['price' => float, 'applied' => string[]] döndürür.
 */
function apply_rate_rules(int $propertyId, ?int $ratePlanId, float $basePrice, array $context): array
{
    return compute_rate_after_rules($basePrice, matching_rate_rules($propertyId, $ratePlanId, $context));
}
