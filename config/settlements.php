<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

/**
 * Tesisin ilan detaylarından komisyon oranını ve tahsilat modelini okur.
 *
 * @return array{rate: float, collection_model: string}
 */
function property_commission_rate(int $propertyId): array
{
    $q = db()->prepare('SELECT product_details FROM properties WHERE id=?');
    $q->execute([$propertyId]);
    $details = json_decode((string) ($q->fetchColumn() ?: '{}'), true) ?: [];
    return [
        'rate' => (float) ($details['commission_rate'] ?? 0),
        'collection_model' => (string) ($details['collection_model'] ?? 'agency_collects_deposit'),
    ];
}

/**
 * Brüt tutardan komisyon ve net tutarı hesaplar.
 *
 * @return array{gross: float, commission_amount: float, net_amount: float}
 */
function settlement_calculation(float $gross, float $commissionRate): array
{
    $commission = round($gross * max(0, min(100, $commissionRate)) / 100, 2);
    return [
        'gross' => round($gross, 2),
        'commission_amount' => $commission,
        'net_amount' => round($gross - $commission, 2),
    ];
}

/**
 * Rezervasyon için mutabakat kaydını hesaplar ve oluşturur (idempotent).
 */
function upsert_booking_settlement(int $bookingId, string $transactionType = 'booking'): array
{
    $q = db()->prepare('SELECT id,supplier_id,property_id,total_amount,currency FROM supplier_bookings WHERE id=?');
    $q->execute([$bookingId]);
    $booking = $q->fetch();
    if (!$booking) {
        throw new RuntimeException('Rezervasyon bulunamadı.');
    }
    $rate = property_commission_rate((int) $booking['property_id'])['rate'];
    $calc = settlement_calculation((float) $booking['total_amount'], $rate);
    db()->prepare("INSERT INTO supplier_settlements(supplier_id,booking_id,transaction_type,status,gross_amount,commission_amount,net_amount,currency) VALUES(?,?,?,'pending',?,?,?,?) ON CONFLICT (booking_id) DO NOTHING")
        ->execute([$booking['supplier_id'], $bookingId, $transactionType, $calc['gross'], $calc['commission_amount'], $calc['net_amount'], $booking['currency']]);
    return $calc;
}
