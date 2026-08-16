<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/webhooks.php';
require_once __DIR__ . '/settlements.php';
require_once __DIR__ . '/notifications.php';

/**
 * Acente rezervasyon talebini kaydeder; yeni kaydın id'sini döndürür.
 */
function insert_agency_booking_request(array $d): int
{
    $q = db()->prepare('INSERT INTO agency_booking_requests(agency_id,supplier_id,property_id,room_type_id,rate_plan_id,check_in,check_out,nights,adults,children,total_amount,currency,guest_first_name,guest_last_name,guest_email,guest_phone,agency_reference,note) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id');
    $q->execute([
        (int) $d['agency_id'], (int) $d['supplier_id'], (int) $d['property_id'],
        isset($d['room_type_id']) ? (int) $d['room_type_id'] : null,
        isset($d['rate_plan_id']) ? (int) $d['rate_plan_id'] : null,
        $d['check_in'], $d['check_out'], (int) $d['nights'], (int) $d['adults'], (int) $d['children'],
        (float) $d['total_amount'], $d['currency'] ?? 'EUR',
        $d['guest_first_name'], $d['guest_last_name'], $d['guest_email'] ?? null, $d['guest_phone'] ?? null,
        $d['agency_reference'] ?? null, $d['note'] ?? null,
    ]);
    $requestId = (int) $q->fetchColumn();
    notify_supplier_users((int) $d['supplier_id'], 'booking.request', 'Yeni acente rezervasyon talebi' . ($d['agency_reference'] !== null ? ' (' . $d['agency_reference'] . ')' : ''), '/nexustraveltech/tedarikci/acente-rezervasyonlari');
    return $requestId;
}

/**
 * Talebi onaylar; rezervasyon, misafir profili, oda kaydı, kontenjan düşümü,
 * mutabakat, misafir e-postası ve webhook bildirimini tek işlemde yürütür.
 */
function approve_agency_booking_request(int $requestId, int $supplierId, int $supplierUserId, ?string $note = null): array
{
    $pdo = db();
    $ownsTx = !$pdo->inTransaction();
    if ($ownsTx) $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM agency_booking_requests WHERE id=? AND supplier_id=? AND status=\'pending\' FOR UPDATE');
        $q->execute([$requestId, $supplierId]);
        $r = $q->fetch();
        if (!$r) throw new RuntimeException('Talep bulunamadı veya daha önce işlendi.');

        $nights = (int) $r['nights'];
        $nightly = round((float) $r['total_amount'] / ($nights > 0 ? $nights : 1), 2);
        $ref = 'NXR-' . date('ymdHis') . '-' . random_int(100, 999);

        $q = $pdo->prepare("INSERT INTO supplier_bookings(supplier_id,property_id,booking_reference,status,booking_status,check_in,check_out,total_amount,currency,source_code,notes,rate_plan_id) VALUES(?,?,?,?,'reserved',?,?,?,?,'agency_request',?,?) RETURNING id");
        $q->execute([$r['supplier_id'], $r['property_id'], $ref, 'confirmed', $r['check_in'], $r['check_out'], $r['total_amount'], $r['currency'], $note, $r['rate_plan_id'] ?: null]);
        $bookingId = (int) $q->fetchColumn();

        $g = $pdo->prepare('INSERT INTO guest_profiles(supplier_id,first_name,last_name,email,phone) VALUES(?,?,?,?,?) RETURNING id');
        $g->execute([$r['supplier_id'], $r['guest_first_name'], $r['guest_last_name'], $r['guest_email'] ?: null, $r['guest_phone'] ?: null]);
        $guestId = (int) $g->fetchColumn();

        $pdo->prepare('INSERT INTO booking_guests(booking_id,guest_id,is_primary) VALUES(?,?,true)')->execute([$bookingId, $guestId]);
        $pdo->prepare('INSERT INTO booking_rooms(booking_id,room_type_id,adults,children,nightly_rate,currency) VALUES(?,?,?,?,?,?)')
            ->execute([$bookingId, $r['room_type_id'] ?: null, $r['adults'], $r['children'], $nightly, $r['currency']]);

        if ($r['room_type_id'] && $r['rate_plan_id']) {
            $pdo->prepare('UPDATE inventory_calendar SET sold=sold+1 WHERE room_type_id=? AND rate_plan_id=? AND stay_date>=? AND stay_date<? AND sold<allotment')
                ->execute([$r['room_type_id'], $r['rate_plan_id'], $r['check_in'], $r['check_out']]);
        }

        upsert_booking_settlement($bookingId);

        $pdo->prepare("UPDATE agency_booking_requests SET status='approved',booking_id=?,responded_at=now(),responded_by=?,response_note=? WHERE id=?")->execute([$bookingId, $supplierUserId, $note, $requestId]);

        if ($r['guest_email']) {
            queue_email($r['guest_email'], 'Rezervasyon onayınız — ' . $ref,
                '<p>Sayın ' . htmlspecialchars($r['guest_first_name'] . ' ' . $r['guest_last_name']) . ',</p>'
                . '<p>Rezervasyonunuz onaylandı.</p>'
                . '<p><b>Referans:</b> ' . $ref . '<br><b>Giriş:</b> ' . $r['check_in'] . '<br><b>Çıkış:</b> ' . $r['check_out']
                . '<br><b>Tutar:</b> ' . number_format((float) $r['total_amount'], 2) . ' ' . htmlspecialchars($r['currency']) . '</p>'
                . '<p>NEXUS TravelTech</p>', 'booking_confirmation', $bookingId);
        }
        if ($ownsTx) $pdo->commit();
        notify_user('agency', (int) $r['agency_id'], 'booking.approved', 'Rezervasyonunuz onaylandı: ' . $ref, '/nexustraveltech/acente/');

        try {
            webhook_dispatch('booking.created', [
            'agency_id' => (int) $r['agency_id'],
            'booking_reference' => $ref,
            'booking_id' => $bookingId,
            'property_id' => (int) $r['property_id'],
            'check_in' => $r['check_in'],
            'check_out' => $r['check_out'],
            'total_amount' => (float) $r['total_amount'],
            'currency' => $r['currency'],
            ]);
        } catch (Throwable $webhookError) {
            // Webhook bildirimi best-effort'tur; rezervasyon akışını bozmaz.
        }

        return ['ok' => true, 'message' => 'Talep onaylandı; rezervasyon ' . $ref . ' oluşturuldu ve kontenjandan düşüldü.', 'booking_reference' => $ref];
    } catch (Throwable $e) {
        if ($ownsTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Talebi reddeder; acenteye webhook bildirimi gönderir.
 */
function reject_agency_booking_request(int $requestId, int $supplierId, int $supplierUserId, ?string $note = null): array
{
    $pdo = db();
    $ownsTx = !$pdo->inTransaction();
    if ($ownsTx) $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM agency_booking_requests WHERE id=? AND supplier_id=? AND status=\'pending\' FOR UPDATE');
        $q->execute([$requestId, $supplierId]);
        $r = $q->fetch();
        if (!$r) throw new RuntimeException('Talep bulunamadı veya daha önce işlendi.');
        $pdo->prepare("UPDATE agency_booking_requests SET status='rejected',responded_at=now(),responded_by=?,response_note=? WHERE id=?")->execute([$supplierUserId, $note, $requestId]);
        if ($ownsTx) $pdo->commit();
        notify_user('agency', (int) $r['agency_id'], 'booking.rejected', 'Rezervasyon talebiniz reddedildi.', '/nexustraveltech/acente/');
        try {
            webhook_dispatch('booking.request.rejected', [
            'agency_id' => (int) $r['agency_id'],
            'request_id' => $requestId,
            'property_id' => (int) $r['property_id'],
            'check_in' => $r['check_in'],
            'check_out' => $r['check_out'],
            'note' => $note,
            ]);
        } catch (Throwable $webhookError) {
            // Webhook bildirimi best-effort'tur; red akışını bozmaz.
        }
        return ['ok' => true, 'message' => 'Talep reddedildi; acenteye not iletildi.'];
    } catch (Throwable $e) {
        if ($ownsTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Fiyat planındaki iptal politikasından iade tutarını hesaplar.
 * Yapılandırılmış alan yoksa null döner (politika tanımsız).
 *
 * @return array{refundable: float|null, free: bool, policy_text: string}|null
 */
function booking_cancellation_policy(array $booking, ?PDO $pdo = null): ?array
{
    $pdo = $pdo ?? db();
    if (empty($booking['rate_plan_id'])) return null;
    $q = $pdo->prepare('SELECT free_cancel_before_days,cancel_fee_percent,cancellation_policy FROM rate_plans WHERE id=?');
    $q->execute([$booking['rate_plan_id']]);
    $rp = $q->fetch();
    if (!$rp || $rp['free_cancel_before_days'] === null) return null;

    $daysBefore = max(0, (int) ((strtotime((string) $booking['check_in']) - time()) / 86400));
    $free = $daysBefore >= (int) $rp['free_cancel_before_days'];
    $feePercent = $free ? 0 : max(0, min(100, (float) $rp['cancel_fee_percent']));
    $refundable = round((float) $booking['total_amount'] * (1 - $feePercent / 100), 2);
    $policyText = trim((string) ($rp['cancellation_policy'] ?? ''));
    return ['refundable' => $refundable, 'free' => $free, 'policy_text' => $policyText];
}

/**
 * Rezervasyonu iptal eder: durum güncellenir, kontenjan geri iade edilir,
 * mutabakat iade olarak işaretlenir, misafir/acenteye iade bilgisi gider ve
 * acenteye booking.cancelled webhook'u gönderilir.
 */
function cancel_booking(int $bookingId, int $supplierId, int $supplierUserId, string $reason): array
{
    $pdo = db();
    $ownsTx = !$pdo->inTransaction();
    if ($ownsTx) $pdo->beginTransaction();
    try {
        $q = $pdo->prepare("SELECT * FROM supplier_bookings WHERE id=? AND supplier_id=? AND status NOT IN ('cancelled','rejected') FOR UPDATE");
        $q->execute([$bookingId, $supplierId]);
        $b = $q->fetch();
        if (!$b) throw new RuntimeException('Rezervasyon bulunamadı veya zaten iptal edilmiş.');

        $pdo->prepare("UPDATE supplier_bookings SET status='cancelled',booking_status='cancelled',cancellation_reason=?,cancelled_at=now() WHERE id=?")
            ->execute([$reason, $bookingId]);
        $pdo->prepare("UPDATE booking_rooms SET status='cancelled' WHERE booking_id=? AND status <> 'cancelled'")->execute([$bookingId]);

        // Kontenjanı geri iade et (fiyat planı biliniyorsa hassas, değilse oda bazında).
        if ($b['check_in'] && $b['check_out']) {
            if ($b['rate_plan_id']) {
                $pdo->prepare('UPDATE inventory_calendar i SET sold=GREATEST(0,i.sold-1) WHERE i.room_type_id IN (SELECT room_type_id FROM booking_rooms WHERE booking_id=?) AND i.rate_plan_id=? AND i.stay_date>=? AND i.stay_date<? AND i.sold>0')
                    ->execute([$bookingId, $b['rate_plan_id'], $b['check_in'], $b['check_out']]);
            } else {
                $pdo->prepare('UPDATE inventory_calendar i SET sold=GREATEST(0,i.sold-1) WHERE i.room_type_id IN (SELECT room_type_id FROM booking_rooms WHERE booking_id=?) AND i.stay_date>=? AND i.stay_date<? AND i.sold>0')
                    ->execute([$bookingId, $b['check_in'], $b['check_out']]);
            }
        }

        $pdo->prepare("UPDATE supplier_settlements SET status='refunded' WHERE booking_id=?")->execute([$bookingId]);

        if ($ownsTx) $pdo->commit();

        $policy = booking_cancellation_policy($b, $pdo);
        $refundMessage = '';
        if ($policy !== null) {
            $refundMessage = ' İade tutarı: ' . number_format((float) $policy['refundable'], 2) . ' ' . $b['currency'] . ($policy['free'] ? ' (ücretsiz iptal penceresi içinde).' : ' (iptal ücreti düşüldü).');
        }

        try {
            $guestQ = $pdo->prepare('SELECT gp.email,gp.first_name,gp.last_name FROM booking_guests bg JOIN guest_profiles gp ON gp.id=bg.guest_id WHERE bg.booking_id=? AND bg.is_primary=true LIMIT 1');
            $guestQ->execute([$bookingId]);
            $guest = $guestQ->fetch();
            if ($guest && $guest['email']) {
                queue_email_with_template(
                    (string) $guest['email'],
                    'booking_cancelled',
                    [
                        'misafir_adi' => trim((string) $guest['first_name'] . ' ' . (string) $guest['last_name']),
                        'referans' => (string) $b['booking_reference'],
                        'iade' => $policy !== null ? number_format((float) $policy['refundable'], 2) . ' ' . $b['currency'] : 'tanımsız',
                        'neden' => $reason,
                    ],
                    'Rezervasyonunuz iptal edildi — ' . $b['booking_reference'],
                    '<p>Sayın ' . htmlspecialchars(trim((string) $guest['first_name'] . ' ' . (string) $guest['last_name'])) . ',</p>'
                    . '<p>Rezervasyonunuz iptal edildi. Referans: <b>' . htmlspecialchars((string) $b['booking_reference']) . '</b></p>'
                    . '<p>İade tutarı: <b>' . ($policy !== null ? number_format((float) $policy['refundable'], 2) . ' ' . $b['currency'] : 'İade politikası tanımsız') . '</b></p>'
                    . '<p>İptal nedeni: ' . htmlspecialchars($reason) . '</p><p>NEXUS TravelTech</p>',
                    'booking_cancelled',
                    $bookingId
                );
            }
        } catch (Throwable $guestEmailError) {
            // E-posta best-effort'tur.
        }

        try {
            $q = $pdo->prepare('SELECT agency_id FROM agency_booking_requests WHERE booking_id=? LIMIT 1');
            $q->execute([$bookingId]);
            $agencyId = $q->fetchColumn();
            if ($agencyId) {
                notify_user('agency', (int) $agencyId, 'booking.cancelled', 'Rezervasyonunuz iptal edildi: ' . $b['booking_reference'], '/nexustraveltech/acente/');
                webhook_dispatch('booking.cancelled', [
                    'agency_id' => (int) $agencyId,
                    'booking_reference' => $b['booking_reference'],
                    'booking_id' => $bookingId,
                    'property_id' => (int) $b['property_id'],
                    'check_in' => $b['check_in'],
                    'check_out' => $b['check_out'],
                    'reason' => $reason,
                ]);
            }
        } catch (Throwable $webhookError) {
            // Webhook bildirimi best-effort'tur.
        }
        return ['ok' => true, 'message' => 'Rezervasyon iptal edildi; kontenjan ve mutabakat iade edildi.' . $refundMessage];
    } catch (Throwable $e) {
        if ($ownsTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
