<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

function record_identity_verification_failure(int $supplierId, string $reason): void
{
    $reason = mb_substr(trim($reason), 0, 500);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE supplier_verifications SET identity_status='pending', identity_check_status='failed', identity_check_message=?, identity_checked_at=now(), review_status='pending', approved_product_types='[]'::jsonb WHERE supplier_id=?")
            ->execute([$reason ?: 'Kimlik doğrulaması eşleşmedi.', $supplierId]);
        $pdo->prepare("INSERT INTO admin_alerts (alert_type, supplier_id, title, body) VALUES ('identity_verification_failed', ?, 'Kimlik doğrulaması başarısız', ?)")
            ->execute([$supplierId, $reason ?: 'Tedarikçi kimlik doğrulama isteği eşleşmedi.']);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

function record_identity_verification_success(int $supplierId): void
{
    db()->prepare("UPDATE supplier_verifications SET identity_status='approved', identity_check_status='verified', identity_check_message=NULL, identity_checked_at=now() WHERE supplier_id=?")
        ->execute([$supplierId]);
}
