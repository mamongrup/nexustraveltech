<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/notifications.php';

function payment_link_url(string $token): string
{
    return 'https://nexustraveltech.com/misafir/odeme?token=' . rawurlencode($token);
}

/**
 * Rezervasyon için token'lı ödeme bağlantısı oluşturur.
 */
function create_payment_link(int $supplierId, ?int $bookingId, float $amount, string $currency, bool $testMode = true, int $expiresInDays = 30): array
{
    if ($amount <= 0) {
        throw new RuntimeException('Ödeme tutarı sıfırdan büyük olmalı.');
    }
    $token = bin2hex(random_bytes(32));
    db()->prepare("INSERT INTO payment_links(supplier_id,booking_id,token,amount,currency,test_mode,expires_at) VALUES(?,?,?,?,?,?,now() + make_interval(days => ?))")
        ->execute([
            $supplierId,
            $bookingId ?: null,
            $token,
            round($amount, 2),
            mb_strtoupper(mb_substr($currency, 0, 3)),
            $testMode ? true : false,
            max(1, min(90, $expiresInDays)),
        ]);
    return ['token' => $token, 'url' => payment_link_url($token)];
}

/**
 * Geçerli (süresi dolmamış, bekleyen) ödeme bağlantısını token ile bulur.
 */
function payment_link_by_token(string $token): ?array
{
    $q = db()->prepare("SELECT * FROM payment_links WHERE token=? AND status='pending' AND (expires_at IS NULL OR expires_at>now())");
    $q->execute([$token]);
    $row = $q->fetch();
    return $row ?: null;
}

/**
 * Ödeme bağlantısı üzerinden tahsilatı kaydeder; folyo varsa tahsisat ve folyo hareketi oluşturur.
 * Test modunda gerçek bir ödeme geçidi çağrılmaz; entegratör sözleşmesi sonrası aynı fonksiyon
 * sağlayıcı onayı ile çağrılabilir.
 */
function record_payment_link_payment(string $token, string $method = 'test_card'): array
{
    $pdo = db();
    $ownsTx = !$pdo->inTransaction();
    if ($ownsTx) {
        $pdo->beginTransaction();
    }
    try {
        $q = $pdo->prepare("SELECT * FROM payment_links WHERE token=? AND status='pending' AND (expires_at IS NULL OR expires_at>now()) FOR UPDATE");
        $q->execute([$token]);
        $link = $q->fetch();
        if (!$link) {
            throw new RuntimeException('Ödeme bağlantısı geçersiz, süresi dolmuş veya zaten ödenmiş.');
        }

        $reference = 'PAY-' . date('ymdHis') . '-' . random_int(100, 999);
        $q = $pdo->prepare('INSERT INTO payment_records(supplier_id,booking_id,payment_reference,payment_method,amount,currency,status,provider_transaction_id) VALUES(?,?,?,?,?,?,\'captured\',?) RETURNING id');
        $q->execute([
            $link['supplier_id'],
            $link['booking_id'] ?: null,
            $reference,
            $method,
            (float) $link['amount'],
            $link['currency'],
            ($link['test_mode'] ? 'TEST-' : '') . mb_substr((string) $link['token'], 0, 16),
        ]);
        $paymentId = (int) $q->fetchColumn();

        if ($link['booking_id']) {
            $folioQuery = $pdo->prepare("SELECT id FROM booking_folios WHERE booking_id=? AND status='open' ORDER BY id LIMIT 1");
            $folioQuery->execute([$link['booking_id']]);
            $folioId = $folioQuery->fetchColumn();
            if ($folioId) {
                $pdo->prepare('INSERT INTO payment_allocations(payment_id,folio_id,amount) VALUES(?,?,?)')
                    ->execute([$paymentId, (int) $folioId, (float) $link['amount']]);
                $pdo->prepare("INSERT INTO folio_transactions(folio_id,transaction_type,description,amount) VALUES(?,'payment',?,?)")
                    ->execute([(int) $folioId, 'Ödeme linki tahsilatı (' . $reference . ')', -1 * (float) $link['amount']]);
            }
        }

        $pdo->prepare("UPDATE payment_links SET status='paid',paid_at=now(),payment_record_id=? WHERE id=?")
            ->execute([$paymentId, (int) $link['id']]);

        if ($ownsTx) {
            $pdo->commit();
        }
        notify_supplier_users((int) $link['supplier_id'], 'payment.received', 'Ödeme alındı: ' . $reference . ' (' . number_format((float) $link['amount'], 2) . ' ' . $link['currency'] . ')', '/nexustraveltech/tedarikci/odeme-linkleri');
        return [
            'ok' => true,
            'reference' => $reference,
            'amount' => (float) $link['amount'],
            'currency' => (string) $link['currency'],
            'test_mode' => (bool) $link['test_mode'],
        ];
    } catch (Throwable $e) {
        if ($ownsTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
