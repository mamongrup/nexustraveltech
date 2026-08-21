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

/**
 * Belirli bir tarih aralığı veya tek gün için tesisin gerçek doluluk oranını (%) hesaplar.
 */
function calculate_property_occupancy(int $propertyId, string $date): float
{
    $pdo = db();
    try {
        // Toplam kontenjan (allotment)
        $qAllotment = $pdo->prepare("SELECT COALESCE(SUM(allotment), 0) FROM inventory_calendar ic JOIN room_types rt ON rt.id = ic.room_type_id WHERE rt.property_id = ? AND ic.stay_date = ?");
        $qAllotment->execute([$propertyId, $date]);
        $totalAllotment = (int) $qAllotment->fetchColumn();

        if ($totalAllotment <= 0) {
            // Eğer takvimde kontenjan girilmemişse aktif oda tiplerinin varsayılan oda sayısını al
            $qRooms = $pdo->prepare("SELECT COUNT(*) FROM room_types WHERE property_id = ? AND status = 'active'");
            $qRooms->execute([$propertyId]);
            $totalAllotment = max(1, (int) $qRooms->fetchColumn());
        }

        // Aktif rezervasyon sayısı (o gece konaklayanlar)
        $qBookings = $pdo->prepare("SELECT COUNT(*) FROM supplier_bookings WHERE property_id = ? AND checkin_date <= ? AND checkout_date > ? AND status NOT IN ('cancelled', 'rejected')");
        $qBookings->execute([$propertyId, $date, $date]);
        $bookedCount = (int) $qBookings->fetchColumn();

        return round(min(100.0, ($bookedCount / max(1, $totalAllotment)) * 100), 2);
    } catch (Throwable $e) {
        return 0.0;
    }
}

/**
 * Tesis için akıllı dinamik fiyat ve kısıt önerileri üretir veya otomatik uygular.
 * @return array{generated: int, applied: int, recommendations: array}
 */
function run_dynamic_revenue_engine(int $propertyId, bool $autoApply = false): array
{
    $pdo = db();
    $today = new DateTimeImmutable('today');
    $generated = 0;
    $applied = 0;
    $recs = [];

    // Aktif fiyat planları ve temel odaları al
    $plansSt = $pdo->prepare("SELECT rp.id rate_plan_id, rp.name rate_plan_name, rp.currency, rt.id room_type_id, rt.name room_type_name
                              FROM rate_plans rp
                              JOIN room_types rt ON rt.property_id = rp.property_id
                              WHERE rp.property_id = ? AND rp.status = 'active' AND rt.status = 'active'");
    $plansSt->execute([$propertyId]);
    $plans = $plansSt->fetchAll();
    if (!$plans) return ['generated' => 0, 'applied' => 0, 'recommendations' => []];

    // Gelecek 30 günü tara
    for ($i = 1; $i <= 30; $i++) {
        $targetDate = $today->modify("+{$i} day")->format('Y-m-d');
        $advanceDays = $i;
        $occupancy = calculate_property_occupancy($propertyId, $targetDate);

        foreach ($plans as $p) {
            // Mevcut takvim kaydını al
            $curSt = $pdo->prepare("SELECT base_price, allotment, min_stay FROM inventory_calendar WHERE room_type_id = ? AND rate_plan_id = ? AND stay_date = ?");
            $curSt->execute([$p['room_type_id'], $p['rate_plan_id'], $targetDate]);
            $current = $curSt->fetch();
            $basePrice = $current ? (float) $current['base_price'] : 100.0;
            if ($basePrice <= 0) $basePrice = 100.0;

            $actionType = null;
            $newPrice = $basePrice;
            $reason = '';
            $confidence = 80;

            // Kural 1: Yüksek Doluluk Algoritması (Doluluk >= %80 -> Fiyat +%15)
            if ($occupancy >= 80.0) {
                $actionType = 'raise_rate';
                $newPrice = round($basePrice * 1.15, 2);
                $reason = "Yüksek doluluk (%{$occupancy}). Fiyat %15 artırıldı.";
                $confidence = 90;
            }
            // Kural 2: Son Dakika Düşük Doluluk (Kalan gün <= 3 ve Doluluk <= %30 -> Fiyat -%12)
            elseif ($advanceDays <= 3 && $occupancy <= 30.0) {
                $actionType = 'lower_rate';
                $newPrice = round($basePrice * 0.88, 2);
                $reason = "Son 3 gün ve düşük doluluk (%{$occupancy}). Son dakika satışı için %12 indirim.";
                $confidence = 85;
            }
            // Kural 3: Erken Rezervasyon / Uzun Dönem (Kalan gün >= 45 ve Doluluk <= %15 -> Fiyat -%10)
            elseif ($advanceDays >= 45 && $occupancy <= 15.0) {
                $actionType = 'early_bird';
                $newPrice = round($basePrice * 0.90, 2);
                $reason = "Erken rezervasyon fırsatı (45+ gün). Talebi çekmek için %10 erken rezervasyon indirimi.";
                $confidence = 75;
            }

            if ($actionType && abs($newPrice - $basePrice) >= 0.5) {
                $generated++;
                $recItem = [
                    'property_id' => $propertyId,
                    'rate_plan_id' => $p['rate_plan_id'],
                    'stay_date' => $targetDate,
                    'type' => $actionType,
                    'old_price' => $basePrice,
                    'recommended_value' => $newPrice,
                    'currency' => $p['currency'] ?? 'EUR',
                    'confidence' => $confidence,
                    'reason' => $reason,
                ];

                if ($autoApply) {
                    // Takvime doğrudan uygula
                    $pdo->prepare("UPDATE inventory_calendar SET base_price = ? WHERE room_type_id = ? AND rate_plan_id = ? AND stay_date = ?")
                        ->execute([$newPrice, $p['room_type_id'], $p['rate_plan_id'], $targetDate]);
                    $applied++;
                } else {
                    // Öneri tablosuna kaydet
                    $chk = $pdo->prepare("SELECT id FROM revenue_recommendations WHERE property_id = ? AND rate_plan_id = ? AND stay_date = ? AND status = 'new'");
                    $chk->execute([$propertyId, $p['rate_plan_id'], $targetDate]);
                    if (!$chk->fetch()) {
                        $pdo->prepare("INSERT INTO revenue_recommendations(property_id, rate_plan_id, stay_date, recommendation_type, recommended_value, currency, confidence, reason, status)
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'new')")
                            ->execute([$propertyId, $p['rate_plan_id'], $targetDate, $actionType, $newPrice, $p['currency'] ?? 'EUR', $confidence, $reason]);
                    }
                }
                $recs[] = $recItem;
            }
        }
    }

    return ['generated' => $generated, 'applied' => $applied, 'recommendations' => $recs];
}
